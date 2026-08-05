<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Base class for FCIAS integration tests that need a real database.
 *
 * Provides:
 * - NC-bootstrapped IDBConnection via \OCP\Server
 * - Table prefix helper
 * - assertTableExists / assertColumnExists / assertTableNotExists
 * - Transaction-wrapped setUp/tearDown (subclasses opt in via beginTransaction)
 *
 * Extend this for any test that needs real MariaDB access through
 * the Nextcloud ddev container.
 */
abstract class DatabaseTestCase
	extends
	TestCase
{

	protected IDBConnection $db;

	private bool $inTransaction = false;

	/** Cache for dbtableprefix (lazy-loaded). */
	private ?string $tablePrefix = null;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db = Server::get( IDBConnection::class );
	}


	/**
	 * Begin a transaction that will be rolled back in tearDown().
	 *
	 * Call this from your test's setUp() or at the start of each test
	 * method when you need isolation.
	 */
	protected function beginTransaction(): void
	{

		if ( $this->inTransaction )
		{
			return;
		}

		$this->db->beginTransaction();
		$this->inTransaction = true;
	}


	protected function tearDown(): void
	{

		if ( $this->inTransaction )
		{
			try
			{
				$this->db->rollBack();
			}
			catch ( \Throwable )
			{
				// Connection may already be closed — ignore
			}

			$this->inTransaction = false;
		}

		parent::tearDown();
	}


	// ─── connection helpers (typed, matching DatabaseService pattern) ─

	protected function getRawConnection(): Connection
	{

		return $this->db->getInner();
	}


	protected function getSchemaManager(): AbstractSchemaManager
	{

		return $this->getRawConnection()
		            ->createSchemaManager()
		;
	}


	// ─── naming helpers ──────────────────────────────────────────────

	protected function getTablePrefix(): string
	{

		if ( $this->tablePrefix === null )
		{
			/** @var \OCP\IConfig $config */
			$config            = Server::get( \OCP\IConfig::class );
			$this->tablePrefix = $config->getSystemValueString( 'dbtableprefix', 'oc_' );
		}

		return $this->tablePrefix;
	}


	protected function getHashTableName(): string
	{

		return $this->getTablePrefix() . 'file_checksum_search_hashes';
	}


	protected function getPendingTableName(): string
	{

		return $this->getTablePrefix() . 'file_checksum_search_pending';
	}


	protected function getFilecacheTableName(): string
	{

		return $this->getTablePrefix() . 'filecache';
	}


	protected function getSpName(): string
	{

		return $this->getTablePrefix() . 'fcias_parse_file_hashes';
	}


	protected function getTriggerName( string $suffix ): string
	{

		return $this->getTablePrefix() . 't_fcias_after_' . $suffix;
	}


	// ─── assertions ──────────────────────────────────────────────────

	protected function assertTableExists( string $tableName ): void
	{

		$this->assertTrue(
			$this->getSchemaManager()
			     ->tablesExist( [ $tableName ] ),
			"Table '{$tableName}' should exist.",
		);
	}


	protected function assertTableNotExists( string $tableName ): void
	{

		$this->assertFalse(
			$this->getSchemaManager()
			     ->tablesExist( [ $tableName ] ),
			"Table '{$tableName}' should NOT exist.",
		);
	}


	protected function assertColumnExists(
		string $tableName,
		string $columnName,
	): void {

		$columns = $this->getSchemaManager()
		                ->listTableColumns( $tableName )
		;
		$names   = array_map(
			static fn( $col ) => $col->getName(),
			$columns,
		);

		$this->assertContains(
			$columnName,
			$names,
			"Column '{$columnName}' should exist in table '{$tableName}'.",
		);
	}


	protected function assertColumnNotExists(
		string $tableName,
		string $columnName,
	): void {

		$columns = $this->getSchemaManager()
		                ->listTableColumns( $tableName )
		;
		$names   = array_map(
			static fn( $col ) => $col->getName(),
			$columns,
		);

		$this->assertNotContains(
			$columnName,
			$names,
			"Column '{$columnName}' should NOT exist in table '{$tableName}'.",
		);
	}


	/**
	 * Execute raw SQL via the Doctrine connection.
	 *
	 * Use sparingly — prefer IDBConnection::getQueryBuilder() for
	 * portable queries.  This is intended for DDL helpers and cleanup.
	 */
	protected function executeRawSql( string $sql ): void
	{

		$this->getRawConnection()
		     ->executeStatement( $sql )
		;
	}


	/**
	 * Count rows in a table (simple convenience wrapper).
	 */
	protected function countRows( string $tableName ): int
	{

		$qb = $this->db->getQueryBuilder();

		$qb->select( $qb->func()->count( '*', 'cnt' ) )
		   ->from( $tableName )
		;

		return (int) $qb->executeQuery()
		                ->fetchOne()
		;
	}

}
