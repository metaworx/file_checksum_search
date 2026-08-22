<?php

declare( strict_types=1 );

/**
 * @copyright    Copyright (c) 2026 metaworx
 * @license      AGPL-3.0-or-later
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */

namespace OCA\FileChecksumSearch\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Generic cross-DB database abstraction layer.
 *
 * All query methods below route through one of the private safeBool() /
 * safeInt() / safeString() / safeArray() helpers, which catch any
 * Throwable, log it as a warning, optionally echo it to $output, and
 * return a fixed sentinel instead of propagating the exception:
 *
 * - safeBool()   -> false
 * - safeInt()    -> 0
 * - safeString() -> 'unknown'
 * - safeArray()  -> []
 *
 * This means the sentinel is indistinguishable from a genuine result —
 * countRows() returning 0 could mean "the table is empty" or "the query
 * failed"; columnExists() returning false could mean "no such column"
 * or "the schema lookup threw". Callers that need to tell those apart
 * must check the logs (or $output, in CLI contexts) rather than the
 * return value alone.
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class DatabaseService
{

	public function __construct(
		private readonly IDBConnection   $db,
		private readonly LoggerInterface $logger,
	) {
	}


	/**
	 * The database server's version string (e.g. "10.11.6-MariaDB").
	 *
	 * @return string  The version string, or 'unknown' if the query failed.
	 */
	public function getDatabaseVersion( ?OutputInterface $output = null ): string
	{

		return $this->safeString(
			fn() => $this->getRawConnection()
			             ->executeQuery( 'SELECT VERSION() AS version' )
			             ->fetchOne(),
			$output,
		);
	}


	/** The underlying Doctrine DBAL connection, for calls IDBConnection doesn't expose. */
	public function getRawConnection(): Connection
	{

		return $this->db->getInner();
	}


	/** Doctrine's schema introspection manager (tablesExist(), listTableColumns(), etc.). */
	public function getSchemaManager(): AbstractSchemaManager
	{

		return $this->getRawConnection()
		            ->createSchemaManager()
		;
	}


	/**
	 * Whether $tableName has a column named $columnName.
	 *
	 * @return bool  False both for "no such column" and "the schema
	 *               lookup failed" — see the class docblock.
	 */
	public function columnExists(
		string           $tableName,
		string           $columnName,
		?OutputInterface $output = null,
	): bool {

		return $this->safeBool(
			function () use
			(
				$tableName,
				$columnName,
			): bool
			{

				foreach (
					$this->getSchemaManager()
					     ->listTableColumns( $tableName ) as $column
				)
				{
					if ( $column->getName() === $columnName )
					{
						return true;
					}
				}

				return false;
			},
			$output,
		);
	}


	/**
	 * Row count for $tableName.
	 *
	 * @return int  0 both for "the table is empty" and "the query
	 *              failed" — see the class docblock.
	 */
	public function countRows(
		string           $tableName,
		?OutputInterface $output = null,
	): int {

		return $this->safeInt(
			function () use
			(
				$tableName,
			): int
			{

				$qb = $this->db->getQueryBuilder();

				$qb->select(
					$qb->func()
					   ->count( '*', 'cnt' ),
				)
				   ->from( $tableName )
				;

				return (int) $qb->executeQuery()
				                ->fetchOne()
				;
			},
			$output,
		);
	}


	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function safeArray(
		callable         $fn,
		?OutputInterface $output,
	): array {

		try
		{
			return $fn();
		}
		catch ( Throwable $e )
		{
			$output?->writeln( sprintf( '<error>%s</error>', $e->getMessage() ) );

			$this->logger->warning(
				'FCIAS: database query failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return [];
		}
	}


	private function safeBool(
		callable         $fn,
		?OutputInterface $output,
	): bool {

		try
		{
			return $fn();
		}
		catch ( Throwable $e )
		{
			$output?->writeln( sprintf( '<error>%s</error>', $e->getMessage() ) );

			$this->logger->warning(
				'FCIAS: database query failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return false;
		}
	}


	private function safeInt(
		callable         $fn,
		?OutputInterface $output,
	): int {

		try
		{
			return $fn();
		}
		catch ( Throwable $e )
		{
			$output?->writeln( sprintf( '<error>%s</error>', $e->getMessage() ) );

			$this->logger->warning(
				'FCIAS: database query failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return 0;
		}
	}


	private function safeString(
		callable         $fn,
		?OutputInterface $output,
	): string {

		try
		{
			return $fn();
		}
		catch ( Throwable $e )
		{
			$output?->getErrorOutput()
			       ->writeln( sprintf( '<error>%s</error>', $e->getMessage() ) )
			;

			$this->logger->warning(
				'FCIAS: database query failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return 'unknown';
		}
	}


	/**
	 * @return string[]  Installed migration version strings for $appId, or
	 *                   [] both for "none installed" and "the query
	 *                   failed" — see the class docblock.
	 */
	public function getInstalledMigrations(
		string           $appId,
		?OutputInterface $output = null,
	): array {

		return $this->safeArray(
			function () use
			(
				$appId,
			): array
			{

				$qb = $this->db->getQueryBuilder();

				$qb->select( 'version' )
				   ->from( 'migrations' )
				   ->where(
					   $qb->expr()
					      ->eq( 'app', $qb->createNamedParameter( $appId ) ),
				   )
				   ->orderBy( 'version' )
				;

				$rows = $qb->executeQuery()
				           ->fetchAll()
				;

				return array_map(
					fn(
						array $row,
					): string => $row['version'],
					$rows,
				);
			},
			$output,
		);
	}


	/**
	 * Whether $tableName exists.
	 *
	 * @return bool  False both for "no such table" and "the schema
	 *               lookup failed" — see the class docblock.
	 */
	public function tableExist(
		string           $tableName,
		?OutputInterface $output = null,
	): bool {

		return $this->safeBool(
			fn() => $this->getSchemaManager()
			             ->tablesExist( [ $tableName ] ),
			$output,
		);
	}

}
