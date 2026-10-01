<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Centralized status/health-check queries used by both the CLI status
 * command and the admin settings HTTP API.
 *
 * All DB access is delegated to DatabaseService.
 */
readonly class StatusService
{

//  constructor

	public function __construct(
		private DatabaseService  $databaseService,
		private TableNameService $tables,
		private IAppManager      $appManager,
		private MetadataService  $metadataService,
		private JobStatsService  $jobStats,
		private ITimeFactory     $time,
	) {
	}


//  getters / setters / is* / has*

	/** @noinspection PhpUnused */
	public function getAppVersion(): string
	{
		return $this->appManager->getAppVersion( 'file_checksum_search' );
	}

	public function getDbVersion( ?OutputInterface $output = null ): string
	{
		return $this->databaseService->getDatabaseVersion( $output );
	}

	/**
	 * How many checksums are indexed, as of when they were last counted.
	 *
	 * The stored count, not a fresh one: counting reads every hash row of the
	 * index, which on a large instance whose cache has gone cold takes
	 * seconds (8.4 s for 406,419 rows on one), and the status is opened after
	 * exactly the quiet hour that lets it go cold. RuleProcessingJob recounts
	 * hourly ({@see recountHashRows()}). Only where nothing was ever counted
	 * does this count, once, so a fresh install shows a number rather than
	 * none.
	 *
	 * @return array{rows: int, at: int}  The count, and when it was taken
	 *                                    (Unix time).
	 */
	public function getHashRowCount(): array
	{
		$run = $this->jobStats->lastRuns()[ JobStatsService::JOB_CHECKSUM_COUNT ] ?? null;

		if ( $run !== null && $run['lastRun'] !== null && isset( $run['counts']['rows'] ) )
		{
			return [
				'rows' => (int) $run['counts']['rows'],
				'at'   => $run['lastRun'],
			];
		}

		return $this->recountHashRows();
	}


//  other non-static methods

	/**
	 * Count the indexed checksums now, and keep the count as the checksum
	 * count's job stats, which is where {@see getHashRowCount()} reads it.
	 *
	 * @return array{rows: int, at: int}
	 */
	public function recountHashRows(): array
	{
		$rows = $this->metadataService->countHashEntries();

		$this->jobStats->record( JobStatsService::JOB_CHECKSUM_COUNT, [ 'rows' => $rows ] );

		return [
			'rows' => $rows,
			'at'   => $this->time->getTime(),
		];
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
