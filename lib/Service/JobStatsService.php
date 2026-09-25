<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use JsonException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Last-run bookkeeping for the background jobs (D17).
 *
 * Two app-config values per job — a timestamp and a small JSON counts
 * object — written by the job at the end of each run and read by the
 * status surface. App-config-sized by design: no schema, no history,
 * just "when did it last run and what did it do". An old timestamp is
 * itself the signal that a job stopped running.
 *
 * Not readonly: the jobs' tests double this class, and PHPUnit cannot
 * mock readonly classes (TESTING.md §6.1).
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class JobStatsService
{

//  constants

	/** The periodic rule sweep (RuleProcessingJob). */
	public const JOB_RULE_SWEEP = 'rule_sweep';

	/** The pending-queue drain (ProcessPendingUpdates). */
	public const JOB_PENDING_DRAIN = 'pending_drain';

	/** The daily purge of metadata for files that no longer exist (rides RuleProcessingJob). */
	public const JOB_ORPHAN_PURGE = 'orphan_purge';

	public const JOBS
		 = [
			self::JOB_RULE_SWEEP,
			self::JOB_PENDING_DRAIN,
			self::JOB_ORPHAN_PURGE,
		];


//  constructor

	public function __construct(
		private readonly IAppConfig      $appConfig,
		private readonly ITimeFactory    $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}


//  other non-static methods

	/**
	 * Record one completed run. Never throws: bookkeeping must not be able
	 * to fail the job it books.
	 *
	 * @param  array<string, int>  $counts
	 */
	public function record(
		string $job,
		array  $counts,
	): void
	{
		try
		{
			$this->appConfig->setValueInt(
				Application::APP_ID,
				sprintf( 'stats_%s_last_run', $job ),
				$this->timeFactory->getTime(),
			);
			$this->appConfig->setValueString(
				Application::APP_ID,
				sprintf( 'stats_%s_last_counts', $job ),
				json_encode( $counts, JSON_THROW_ON_ERROR ),
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS: could not record job stats for {job}.',
				[
					'app'       => Application::APP_ID,
					'job'       => $job,
					'exception' => $e,
				],
			);
		}
	}

	/**
	 * Every job's last run, for the status surface.
	 *
	 * @return array<string, array{lastRun: int|null, counts: array<string, int>}>
	 */
	public function lastRuns(): array
	{
		$runs = [];

		foreach ( self::JOBS as $job )
		{
			$lastRun = $this->appConfig->getValueInt(
				Application::APP_ID,
				sprintf( 'stats_%s_last_run', $job ),
			);

			try
			{
				$counts = json_decode(
					$this->appConfig->getValueString(
						Application::APP_ID,
						sprintf( 'stats_%s_last_counts', $job ),
						'[]',
					),
					true,
					flags: JSON_THROW_ON_ERROR,
				);
			}
			catch ( JsonException )
			{
				$counts = [];
			}

			$runs[ $job ] = [
				'lastRun' => $lastRun > 0
					? $lastRun
					: null,
				'counts'  => is_array( $counts )
					? $counts
					: [],
			];
		}

		return $runs;
	}
}
