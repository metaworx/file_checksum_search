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
		private MetadataService  $metadataService,
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


	public function getHashRowCount(): int
	{

		return $this->metadataService->countHashEntries();
	}


	public function getPendingRowCount(): int
	{

		return array_sum( $this->metadataService->getPendingStats() );
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
			$className = basename( $filePath, '.php' );
			$dbVersion = str_replace( 'Version', '', $className );
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

}
