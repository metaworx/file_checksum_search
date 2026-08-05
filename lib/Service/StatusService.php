<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\App\IAppManager;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Centralized status/health-check queries used by both the CLI status
 * command and the admin settings HTTP API.
 *
 * All DB access is delegated to DatabaseService.
 */
readonly class StatusService
{

	public function __construct(
		private DatabaseService  $databaseService,
		private TableNameService $tables,
		private IAppManager      $appManager,
	) {
	}


	/** @noinspection PhpUnused */
	public function getAppVersion(): string
	{

		return $this->appManager->getAppVersion( 'file_checksum_search' );
	}


	public function getDbVersion( ?OutputInterface $output = null ): string
	{

		return $this->databaseService->getDatabaseVersion( $output );
	}


	public function getHashRowCount( ?OutputInterface $output = null ): int
	{

		return $this->databaseService->countRows( TableNameService::TABLE_FILE_CHECKSUM_SEARCH_HASHES, $output );
	}


	public function getPendingRowCount( ?OutputInterface $output = null ): int
	{

		return $this->databaseService->countRows( TableNameService::TABLE_FILE_CHECKSUM_SEARCH_PENDING, $output );
	}


	/**
	 * @return array{name: string, ok: bool}
	 */
	public function getProcedureStatus( ?OutputInterface $output = null ): array
	{

		return [
			'name' => $this->tables->getSpName(),
			'ok'   => $this->databaseService->storedProcedureExists( $this->tables->getSpName(), $output ),
		];
	}


	/**
	 * @return array<array{name: string, ok: bool}>
	 */
	public function getTableStatus( ?OutputInterface $output = null ): array
	{

		return [
			[
				'name' => $this->tables->getHashTableName(),
				'ok'   => $this->hasHashTable( $output ),
			],
			[
				'name' => $this->tables->getPendingTableName(),
				'ok'   => $this->hasPendingTable( $output ),
			],
		];
	}


	/**
	 * @return array<array{name: string, ok: bool}>
	 */
	public function getTriggerStatus( ?OutputInterface $output = null ): array
	{

		$results = [];

		foreach (
			[
				$this->tables->getTriggerName( 'insert' ),
				$this->tables->getTriggerName( 'update' ),
				$this->tables->getTriggerName( 'delete' ),
			] as $triggerName
		)
		{
			$results[] = [
				'name' => $triggerName,
				'ok'   => $this->databaseService->triggerExists( $triggerName, $output ),
			];
		}

		return $results;
	}


	/**
	 * Compare source migration files against installed migrations.
	 *
	 * Scans lib/Migration/ for Version*Date*.php files, extracts
	 * the class name, and checks against the oc_migrations table.
	 *
	 * @return array<array{name: string, ok: bool}>
	 */
	public function getMigrationStatus( ?OutputInterface $output = null ): array
	{

		$installed = $this->databaseService->getInstalledMigrations(
			'file_checksum_search',
			$output,
		);

		$sourceFiles = glob( __DIR__ . '/../Migration/Version*Date*.php' )
			?: [];

		$results = [];

		foreach ( $sourceFiles as $filePath )
		{
			// Source files are Version010000Date..., DB stores 010000Date...
			$className  = basename( $filePath, '.php' );
			$dbVersion  = str_replace( 'Version', '', $className );
			$results[] = [
				'name' => $className,
				'ok'   => in_array( $dbVersion, $installed, true ),
			];
		}

		return $results;
	}


	public function hasChecksumColumn( ?OutputInterface $output = null ): bool
	{

		return $this->databaseService->columnExists( $this->tables->getFilecacheTableName(), 'checksum', $output );
	}


	public function hasHashTable( ?OutputInterface $output = null ): bool
	{

		return $this->databaseService->tableExist( $this->tables->getHashTableName(), $output );
	}


	public function hasPendingTable( ?OutputInterface $output = null ): bool
	{

		return $this->databaseService->tableExist( $this->tables->getPendingTableName(), $output );
	}

}
