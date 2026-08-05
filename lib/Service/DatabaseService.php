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
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class DatabaseService
{

	public function __construct(
		private readonly IDBConnection   $db,
		private readonly LoggerInterface $logger,
	) {
	}


	public function getDatabaseVersion( ?OutputInterface $output = null ): string
	{

		return $this->safeString(
			fn() => $this->getRawConnection()
			             ->executeQuery( 'SELECT VERSION() AS version' )
			             ->fetchOne(),
			$output,
		);
	}


	public function getRawConnection(): Connection
	{

		return $this->db->getInner();
	}


	public function getSchemaManager(): AbstractSchemaManager
	{

		return $this->getRawConnection()
		            ->createSchemaManager()
		;
	}


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


	public function storedProcedureExists(
		string           $spName,
		?OutputInterface $output = null,
	): bool {

		return $this->safeBool(
			function () use
			(
				$spName,
			): bool
			{

				$qb = $this->db->getQueryBuilder();
				$qb->automaticTablePrefix( false );

				$qb->select(
					$qb->func()
					   ->count( '*', 'cnt' ),
				)
				   ->from( 'INFORMATION_SCHEMA.ROUTINES' )
				   ->where(
					   $qb->expr()
					      ->eq( 'ROUTINE_NAME', $qb->createNamedParameter( $spName ) ),
					   $qb->expr()
					      ->eq( 'ROUTINE_TYPE', $qb->createNamedParameter( 'PROCEDURE' ) ),
				   )
				;

				return (int) $qb->executeQuery()
				                ->fetchOne() > 0;
			},
			$output,
		);
	}


	/**
	 * @return string[] Installed migration version strings
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


	public function triggerExists(
		string           $triggerName,
		?OutputInterface $output = null,
	): bool {

		return $this->safeBool(
			function () use
			(
				$triggerName,
			): bool
			{

				$qb = $this->db->getQueryBuilder();
				$qb->automaticTablePrefix( false );

				$qb->select(
					$qb->func()
					   ->count( '*', 'cnt' ),
				)
				   ->from( 'INFORMATION_SCHEMA.TRIGGERS' )
				   ->where(
					   $qb->expr()
					      ->eq( 'TRIGGER_NAME', $qb->createNamedParameter( $triggerName ) ),
				   )
				;

				return (int) $qb->executeQuery()
				                ->fetchOne() > 0;
			},
			$output,
		);
	}

}
