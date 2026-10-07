<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gives every file whose hashes carry no stamp one, a slice per cron run.
 *
 * A recalculation used to save hashes without `file-checksum-updated_at`:
 * only the queue stamped. Such a file counts as never hashed, and a single
 * `occ fcias:hash` run could leave tens of thousands of them. The stamp is
 * {@see MetadataService::stampUnstampedAfter()}'s to decide; this keeps the
 * work out of the request that upgrades the app.
 *
 * Each run walks until {@see TIME_BUDGET} seconds have passed, keeps its
 * place in {@see CURSOR}, and queues itself again; the last one clears the
 * cursor. Stamping a file takes it out of the set the walk reads, so a run
 * cut short repeats nothing.
 */
class StampCheck
    extends
    QueuedJob
{

//  constants

	/** App config key: the last file id the walk read; absent until it starts. */
	public const CURSOR = 'stamp_check_after';

	/** Seconds one run spends walking before it hands over to the next. */
	public const TIME_BUDGET = 30;

	/** Files per page. */
	public const PAGE_SIZE = 500;


//  constructor

	public function __construct(
		ITimeFactory                     $time,
		private readonly MetadataService $metadataService,
		private readonly IAppConfig      $appConfig,
		private readonly IJobList        $jobList,
		private readonly JobStatsService $jobStats,
		private readonly LoggerInterface $logger,
	)
	{
		parent::__construct( $time );
	}


//  static methods

	/**
	 * Queue a walk from the start, unless one is already queued.
	 *
	 * @return bool  Whether this call queued it; false when a walk was
	 *               already waiting, which keeps its place.
	 */
	public static function queue(
		IJobList   $jobList,
		IAppConfig $appConfig,
	): bool
	{
		if ( $jobList->has( self::class, null ) )
		{
			return false;
		}

		$appConfig->deleteKey( Application::APP_ID, self::CURSOR );
		$jobList->add( self::class );

		return true;
	}


//  config/init/exe/run methods

	/**
	 * @param  mixed  $argument  Unused; the place is kept in app config.
	 */
	#[\Override]
	protected function run( $argument ): void
	{
		$after    = $this->appConfig->getValueInt( Application::APP_ID, self::CURSOR, 0 );
		$deadline = $this->time->getTime() + self::TIME_BUDGET;
		$started  = hrtime( true );

		try
		{
			$result = $this->metadataService->stampUnstampedAfter(
				$after,
				self::PAGE_SIZE,
				fn (): bool => $this->time->getTime() < $deadline,
			);
		}
		catch ( Throwable $e )
		{
			// The cursor stays where it was: the next repair queues the walk
			// again, and it carries on from there.
			$this->logger->error(
				'FCIAS StampCheck: the walk stopped after file {after}',
				[
					'app'       => Application::APP_ID,
					'after'     => $after,
					'exception' => $e,
				],
			);

			$this->jobStats->recordFailure(
				JobStatsService::JOB_STAMP_CHECK,
				$e,
				JobStatsService::millisecondsSince( $started ),
			);

			return;
		}

		if ( $result['done'] )
		{
			$this->appConfig->deleteKey( Application::APP_ID, self::CURSOR );
		}
		else
		{
			$this->appConfig->setValueInt( Application::APP_ID, self::CURSOR, $result['last'] );
			$this->jobList->add( self::class );
		}

		$this->jobStats->record(
			JobStatsService::JOB_STAMP_CHECK,
			[
				'stamped' => $result['stamped'],
				'queued'  => $result['queued'],
				'done'    => $result['done']
					? 1
					: 0,
			],
			JobStatsService::millisecondsSince( $started ),
		);

		$this->logger->info(
			'FCIAS StampCheck: stamped the hashes of {stamped} files, {queued} of them queued to be computed again{rest}',
			[
				'app'     => Application::APP_ID,
				'stamped' => $result['stamped'],
				'queued'  => $result['queued'],
				'rest'    => $result['done']
					? '; every stored hash carries a stamp'
					: sprintf( ', up to file %d; continuing on the next run', $result['last'] ),
			],
		);
	}
}
