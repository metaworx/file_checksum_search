<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Migration;

use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Server;

/**
 * Integration tests for the initial schema migrations.
 *
 * Tests table creation and column structure against a real MariaDB
 * using LifecycleHandler (which mirrors the migration DDL).
 */
class Version010000Test
	extends
	DatabaseTestCase
{

	private LifecycleHandler $lifecycle;


	protected function setUp(): void
	{

		parent::setUp();

		$this->lifecycle = Server::get( LifecycleHandler::class );
	}


	protected function tearDown(): void
	{

		try
		{
			$this->getRawConnection()
			     ->executeStatement(
				     'DROP TABLE IF EXISTS ' . $this->getHashTableName(),
			     )
			;
		}
		catch ( \Throwable )
		{
		}

		try
		{
			$this->getRawConnection()
			     ->executeStatement(
				     'DROP TABLE IF EXISTS ' . $this->getPendingTableName(),
			     )
			;
		}
		catch ( \Throwable )
		{
		}

		parent::tearDown();
	}


	// ─── hashes table ────────────────────────────────────────────────

	public function testCreateTablesCreatesHashesTable(): void
	{

		$this->lifecycle->createTables();

		$this->assertTableExists( $this->getHashTableName() );
	}


	public function testHashesTableHasRequiredColumns(): void
	{

		$this->lifecycle->createTables();

		$this->assertColumnExists( $this->getHashTableName(), 'fileid' );
		$this->assertColumnExists( $this->getHashTableName(), 'algo' );
		$this->assertColumnExists( $this->getHashTableName(), 'hash_value' );
	}


	public function testHashesTableIsIdempotent(): void
	{

		// First call
		$this->lifecycle->createTables();
		$this->assertTableExists( $this->getHashTableName() );

		// Second call — should not throw
		$this->lifecycle->createTables();
		$this->assertTableExists( $this->getHashTableName() );
	}


	// ─── pending table ───────────────────────────────────────────────

	public function testCreateTablesCreatesPendingTable(): void
	{

		$this->lifecycle->createTables();

		$this->assertTableExists( $this->getPendingTableName() );
	}


	public function testPendingTableHasRequiredColumns(): void
	{

		$this->lifecycle->createTables();

		$this->assertColumnExists( $this->getPendingTableName(), 'fileid' );
		$this->assertColumnExists( $this->getPendingTableName(), 'job_id' );
		$this->assertColumnExists( $this->getPendingTableName(), 'created_at' );
		$this->assertColumnExists( $this->getPendingTableName(), 'event_type' );
	}


	public function testPendingTableIsIdempotent(): void
	{

		$this->lifecycle->createTables();
		$this->assertTableExists( $this->getPendingTableName() );

		$this->lifecycle->createTables();
		$this->assertTableExists( $this->getPendingTableName() );
	}

}
