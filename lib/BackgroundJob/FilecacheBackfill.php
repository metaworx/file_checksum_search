<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies the checksums Nextcloud's filecache already carries into this
 * app's index, a slice per cron run.
 *
 * Installing or enabling the app used to do this inline, and Nextcloud runs
 * both in the request that asked for them: on an instance whose sync
 * clients had stored checksums for 300,000 files, enabling from the Apps
 * page read every one of them in one web request, for sixteen minutes, long
 * after the proxy had given the browser up. Queued instead, the request
 * returns at once and the copy follows under cron.
 *
 * Each run reads pages until {@see TIME_BUDGET} seconds have passed, keeps
 * its place in {@see CURSOR}, and queues itself again; the last run clears
 * the cursor. The copy is idempotent — it never overwrites a hash the app
 * already holds — so a run cut short repeats nothing harmful.
 */
class FilecacheBackfill
    extends
    QueuedJob
{

//  constants

	/** App config key: the last file id a run read, zero before the first. */
	public const CURSOR = 'filecache_backfill_after';

	/** Seconds one run spends reading pages before it hands over to the next. */
	public const TIME_BUDGET = 30;

	/** Filecache rows per page. */
	public const PAGE_SIZE = 1000;


//  constructor

	public function __construct(
		ITimeFactory                      $time,
		private readonly HashIndexService $hashIndexService,
		private readonly IAppConfig       $appConfig,
		private readonly IJobList         $jobList,
		private readonly JobStatsService  $jobStats,
		private readonly LoggerInterface  $logger,
	)
	{
		parent::__construct( $time );
	}


//  static methods

	/**
	 * Queue a copy from the start, unless one is already queued.
	 *
	 * @return bool  Whether this call queued it; false when a copy was
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

		$appConfig->setValueInt( Application::APP_ID, self::CURSOR, 0 );
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

		try
		{
			$result = $this->hashIndexService->backfillFromFilecache(
				null,
				self::PAGE_SIZE,
				$after,
				fn (): bool => $this->time->getTime() < $deadline,
			);
		}
		catch ( Throwable $e )
		{
			// Left in place: the next repair or install queues it again, and
			// the copy picks up where the cursor says.
			$this->logger->error(
				'FCIAS FilecacheBackfill: the copy stopped after file {after}',
				[
					'app'       => Application::APP_ID,
					'after'     => $after,
					'exception' => $e,
				],
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
			JobStatsService::JOB_FILECACHE_BACKFILL,
			[
				'copied' => (int) $result['hashes'],
				'files'  => (int) $result['files'],
				'done'   => $result['done']
					? 1
					: 0,
			],
		);

		$this->logger->info(
			'FCIAS FilecacheBackfill: copied {hashes} checksums for {files} files, up to file {last}{rest}',
			[
				'app'    => Application::APP_ID,
				'hashes' => $result['hashes'],
				'files'  => $result['files'],
				'last'   => $result['last'],
				'rest'   => $result['done'] ? '; done' : '; continuing on the next run',
			],
		);
	}
}
