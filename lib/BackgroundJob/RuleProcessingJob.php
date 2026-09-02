<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 * @author    metaworx
 */

namespace OCA\FileChecksumSearch\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Evaluates configured hash-generation rules and marks matching files
 * as pending:{mode} for deferred processing.
 *
 * Thin orchestrator — all rule evaluation logic lives in RuleService.
 *
 * Registered in Application::boot() via IJobList.
 */
class RuleProcessingJob
	extends
	TimedJob
{

	/** App-config key: when the orphan purge last ran, in epoch seconds. Zero means "due now". */
	public const ORPHAN_PURGE_LAST_RUN = 'orphan_purge_last_run';

	/** App-config key: seconds the purge waits between runs. */
	public const ORPHAN_PURGE_INTERVAL = 'orphan_purge_interval';

	public const ORPHAN_PURGE_DEFAULT_INTERVAL = 86400;

	/** Batches per run, so one run stays bounded whatever the backlog. */
	public const ORPHAN_PURGE_MAX_BATCHES = 20;

	public function __construct(
		ITimeFactory                     $time,
		private readonly RuleService     $ruleService,
		private readonly MetadataService $metadataService,
		private readonly IAppConfig      $appConfig,
		private readonly IJobList        $jobList,
		private readonly JobStatsService $jobStats,
		private readonly LoggerInterface $logger,
	) {

		parent::__construct( $time );

		$interval = $this->appConfig->getValueInt(
			Application::APP_ID,
			'rule_processing_interval',
			300,
		);

		$this->setInterval( $interval );
		$this->setTimeSensitivity( self::TIME_INSENSITIVE );

		$this->logger->debug(
			'FCIAS RuleProcessingJob: job instance constructed (interval={interval}s).',
			[
				'app'      => Application::APP_ID,
				'interval' => $interval,
			],
		);
	}


	protected function run( $argument ): void
	{

		$this->logger->info(
			'FCIAS RuleProcessingJob: run() called.',
			[
				'app'      => Application::APP_ID,
				'argument' => $argument,
			],
		);

		try
		{
			$result = $this->ruleService->evaluateRules();

			$this->jobStats->record(
				JobStatsService::JOB_RULE_SWEEP,
				[
					'matched' => $result['matched'],
					'marked'  => $result['marked'],
				],
			);

			if ( $result['marked'] > 0 )
			{
				$this->jobList->add( ProcessPendingUpdates::class );
			}
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS RuleProcessingJob: evaluation failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);
		}

		$this->purgeOrphansIfDue();
	}


	/**
	 * Forget files that no longer exist — once a day, riding this job.
	 *
	 * The sweep is an anti-join over the metadata tables, which is not work
	 * for every five-minute tick, so it keeps its own clock in app config
	 * rather than getting a job of its own: one job to register, one
	 * heartbeat to watch, and a user deletion can make it due at once by
	 * zeroing the clock ({@see \OCA\FileChecksumSearch\Listener\UserDeletedListener}).
	 * Bounded per run; a backlog larger than that is finished on the next
	 * tick, because a partial batch means the next run is not yet due but a
	 * full one leaves the clock alone.
	 *
	 * Never throws: this rides a job whose own work must not be lost to a
	 * housekeeping failure.
	 */
	private function purgeOrphansIfDue(): void
	{

		$now      = $this->time->getTime();
		$interval = $this->appConfig->getValueInt(
			Application::APP_ID,
			self::ORPHAN_PURGE_INTERVAL,
			self::ORPHAN_PURGE_DEFAULT_INTERVAL,
		);
		$lastRun  = $this->appConfig->getValueInt( Application::APP_ID, self::ORPHAN_PURGE_LAST_RUN, 0 );

		if ( $now - $lastRun < $interval )
		{
			return;
		}

		$batchLimit = $this->appConfig->getValueInt( Application::APP_ID, 'pending_batch_limit', 50 );
		$purged     = 0;
		$batches    = 0;
		$exhausted  = false;

		try
		{
			do
			{
				$n = $this->metadataService->purgeOrphanedMetadata( $batchLimit );
				$purged += $n;
				$batches ++;
			}
			while ( $n >= $batchLimit && $batches < self::ORPHAN_PURGE_MAX_BATCHES );

			$exhausted = $n < $batchLimit;
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS RuleProcessingJob: orphan purge failed; it will be retried on the next run.',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);
		}

		// A run that emptied the backlog books the day; one that hit its cap
		// leaves the clock alone so the next tick carries on.
		if ( $exhausted )
		{
			$this->appConfig->setValueInt( Application::APP_ID, self::ORPHAN_PURGE_LAST_RUN, $now );
		}

		$this->jobStats->record(
			JobStatsService::JOB_ORPHAN_PURGE,
			[
				'purged'  => $purged,
				'batches' => $batches,
			],
		);
	}

}
