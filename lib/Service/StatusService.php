<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Centralized status/health-check queries used by both the CLI status
 * command and the admin settings HTTP API.
 *
 * All DB access is delegated to DatabaseService.
 */
readonly class StatusService
{

//  constants

	/** Seconds the checksum count may age, where the config holds none. */
	public const CHECKSUM_COUNT_DEFAULT_INTERVAL = 3600;


//  constructor

	public function __construct(
		private DatabaseService  $databaseService,
		private TableNameService $tables,
		private IAppManager      $appManager,
		private MetadataService  $metadataService,
		private JobStatsService  $jobStats,
		private ITimeFactory     $time,
		private IAppConfig       $appConfig,
		private MimeTypeService  $mimeTypes,
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
	 * The stored count while it is younger than the interval: counting reads
	 * every hash row of the index, which on a large instance whose cache has
	 * gone cold takes seconds (8.4 s for 406,419 rows on one). An older one,
	 * or none, is counted now and stored ({@see recountHashRows()}). With the
	 * background count on, RuleProcessingJob keeps it younger than that, and
	 * this never counts.
	 *
	 * The stored MIME types are counted with them, and kept the same way: a
	 * count of their own rows, which reads as many index entries as there
	 * are files with a type.
	 *
	 * @param bool $recount Count now whatever the stored count's age: the
	 *                      panel's Refresh.
	 *
	 * @return array{rows: int, mimeTypes: int, at: int}  The counts, and when
	 *                                                    they were taken
	 *                                                    (Unix time).
	 */
	public function getHashRowCount( bool $recount = false ): array
	{
		$run = $this->jobStats->lastRuns()[ JobStatsService::JOB_CHECKSUM_COUNT ] ?? null;
		$at  = $run['lastRun'] ?? null;

		// A count kept before the types were counted is taken again.
		if ( ! $recount
			&& $at !== null
			&& isset( $run['counts']['rows'], $run['counts']['mime_types'] )
			&& $this->isYoung( $at ) )
		{
			return [
				'rows'      => (int) $run['counts']['rows'],
				'mimeTypes' => (int) $run['counts']['mime_types'],
				'at'        => $at,
			];
		}

		return $this->recountHashRows();
	}

	/**
	 * Where MIME type detection stands, and how many types are stored, as
	 * of when they were counted: counted now while a deletion is requested or
	 * running, so that "files left" goes down as it goes, rather than staying
	 * at the kept count until the next one. One key's rows: a deletion makes
	 * them fewer with every page.
	 *
	 * @param  array{rows: int, mimeTypes: int, at: int}  $count  What
	 *                                                           {@see getHashRowCount()}
	 *                                                           answered the
	 *                                                           caller: asked
	 *                                                           again, it would
	 *                                                           count again
	 *                                                           where the count
	 *                                                           was due.
	 *
	 * @return array{state: string, rows: int, at: int, deleteRequestedAt: int|null}
	 *         `state` is one of {@see MimeTypeService}'s `STATE_*`.
	 */
	public function getMimeTypeStatus( array $count ): array
	{
		$deleteRequestedAt = $this->mimeTypes->getDeleteRequestedAt();

		return [
			'state'             => $this->mimeTypes->getState(),
			'rows'              => $deleteRequestedAt !== null
				? $this->metadataService->countMimeTypeRows()
				: $count['mimeTypes'],
			'at'                => $deleteRequestedAt !== null
				? $this->time->getTime()
				: $count['at'],
			'deleteRequestedAt' => $deleteRequestedAt,
		];
	}

	/**
	 * The background jobs' last runs, as the status lists them. The checksum
	 * count's record is kept however the count was taken, but it is a
	 * background job only while the background count is switched on.
	 *
	 * @return array<string, array{lastRun: int|null, counts: array<string, int>, attempt: array{at: int, ok: bool, durationMs: int|null, reason: string|null}|null}>
	 */
	public function getListedJobs(): array
	{
		$jobs = $this->jobStats->lastRuns();

		if ( ! $this->isHashRowCountInBackground() )
		{
			unset( $jobs[ JobStatsService::JOB_CHECKSUM_COUNT ] );
		}

		return $jobs;
	}

	/** Seconds the stored checksum count may age before it is taken again. */
	public function getHashRowCountInterval(): int
	{
		return $this->appConfig->getValueInt(
			Application::APP_ID,
			ConfigLexicon::CHECKSUM_COUNT_INTERVAL,
			self::CHECKSUM_COUNT_DEFAULT_INTERVAL,
		);
	}

	/** Whether RuleProcessingJob takes the checksum count when it is due. */
	public function isHashRowCountInBackground(): bool
	{
		return $this->appConfig->getValueBool(
			Application::APP_ID,
			ConfigLexicon::CHECKSUM_COUNT_BACKGROUND,
		);
	}

	/** Whether the stored checksum count is missing or older than the interval. */
	public function isHashRowCountDue(): bool
	{
		$lastRun = $this->jobStats->lastRuns()[ JobStatsService::JOB_CHECKSUM_COUNT ]['lastRun'] ?? null;

		return $lastRun === null || ! $this->isYoung( $lastRun );
	}


//  other non-static methods

	/**
	 * Count the indexed checksums and the stored MIME types now, and keep
	 * the counts as the checksum count's job stats, which is where
	 * {@see getHashRowCount()} reads them.
	 *
	 * @return array{rows: int, mimeTypes: int, at: int}
	 */
	public function recountHashRows(): array
	{
		$started   = hrtime( true );
		$rows      = $this->metadataService->countHashEntries();
		$mimeTypes = $this->metadataService->countMimeTypeRows();

		$this->jobStats->record(
			JobStatsService::JOB_CHECKSUM_COUNT,
			[
				'rows'       => $rows,
				'mime_types' => $mimeTypes,
			],
			JobStatsService::millisecondsSince( $started ),
		);

		return [
			'rows'      => $rows,
			'mimeTypes' => $mimeTypes,
			'at'        => $this->time->getTime(),
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

	/** Whether a count taken at $at (Unix time) is younger than the interval. */
	private function isYoung( int $at ): bool
	{
		return $this->time->getTime() - $at < $this->getHashRowCountInterval();
	}
}
