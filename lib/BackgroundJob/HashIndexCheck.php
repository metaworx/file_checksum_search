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
 * Checks that every hash a metadata document holds has its index row, and
 * writes the ones that are missing, a slice per cron run.
 *
 * A whole repair used to ask this inline, and Nextcloud runs a whole repair
 * in the request that enables or upgrades the app — twice, when an installed
 * app is enabled from the Apps page. The question alone is two counts that
 * read every stamped metadata document: on an instance with 450,000 index
 * rows it took a minute, and found nothing to do. Queued instead, the
 * request returns and the check follows under cron.
 *
 * The first run asks {@see MetadataService::hashIndexIsComplete()} and stops
 * there when the answer is yes. Otherwise it walks the stamped documents
 * until {@see TIME_BUDGET} seconds have passed, keeps its place in
 * {@see CURSOR}, and queues itself again; later runs carry on without asking,
 * and the last one clears the cursor. The walk rewrites only files whose
 * rows disagree with their document, so a run cut short repeats nothing
 * harmful.
 */
class HashIndexCheck
    extends
    QueuedJob
{

//  constants

	/** App config key: the last file id the walk read; absent until it starts. */
	public const CURSOR = 'hash_index_check_after';

	/** Seconds one run spends walking before it hands over to the next. */
	public const TIME_BUDGET = 30;

	/** Metadata documents per page. */
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
	 * Queue a check from the start, unless one is already queued.
	 *
	 * @return bool  Whether this call queued it; false when a check was
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
	protected function run( $argument ): void
	{
		$walking = $this->appConfig->hasKey( Application::APP_ID, self::CURSOR );
		$after   = $walking
			? $this->appConfig->getValueInt( Application::APP_ID, self::CURSOR, 0 )
			: 0;

		try
		{
			if ( ! $walking && $this->metadataService->hashIndexIsComplete() )
			{
				$this->finish( 0, true );

				return;
			}

			// After the question, so the walk gets its whole budget.
			$deadline = $this->time->getTime() + self::TIME_BUDGET;
			$result   = $this->metadataService->reindexHashesAfter(
				$after,
				self::PAGE_SIZE,
				fn (): bool => $this->time->getTime() < $deadline,
			);
		}
		catch ( Throwable $e )
		{
			// The cursor stays where it was: the next repair queues the check
			// again, and it carries on from there.
			$this->logger->error(
				'FCIAS HashIndexCheck: the check stopped after file {after}',
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

		$this->finish( $result['fixed'], $result['done'], $result['last'] );
	}


//  other non-static methods

	//  private methods
	private function finish(
		int  $repaired,
		bool $done,
		int  $last = 0,
	): void
	{
		$this->jobStats->record(
			JobStatsService::JOB_HASH_INDEX_CHECK,
			[
				'repaired' => $repaired,
				'done'     => $done
					? 1
					: 0,
			],
		);

		$this->logger->info(
			'FCIAS HashIndexCheck: rewrote the index rows of {repaired} files{rest}',
			[
				'app'      => Application::APP_ID,
				'repaired' => $repaired,
				'rest'     => $done
					? '; every stored hash is indexed'
					: sprintf( ', up to file %d; continuing on the next run', $last ),
			],
		);
	}
}
