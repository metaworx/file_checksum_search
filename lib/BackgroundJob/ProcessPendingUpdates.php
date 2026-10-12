<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 * @author    metaworx
 */

namespace OCA\FileChecksumSearch\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\DrainOutcome;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Fetches pending hash updates from the metadata index and processes
 * them via HashCalculationService::processFile().
 *
 * Scheduled from appinfo/info.xml's <background-jobs>, which is what puts
 * it in the job list; the app's boot() registers nothing.
 */
class ProcessPendingUpdates
    extends
    TimedJob
{

//  constructor

	public function __construct(
		ITimeFactory                            $time,
		private readonly HashCalculationService $hashCalc,
		private readonly MetadataService        $metadataService,
		private readonly RuleService            $ruleService,
		private readonly IAppConfig             $appConfig,
		private readonly IJobList               $jobList,
		private readonly JobStatsService        $jobStats,
		private readonly LoggerInterface        $logger,
	)
	{
		parent::__construct( $time );

		$interval = $this->appConfig->getValueInt(
			Application::APP_ID,
			'process_pending_interval',
			60,
		);

		$this->setInterval( $interval );
		$this->setTimeSensitivity( self::TIME_INSENSITIVE );

		$this->logger->debug(
			'FCIAS ProcessPendingUpdates: job instance constructed (interval={interval}s).',
			[
				'app'      => Application::APP_ID,
				'interval' => $interval,
			],
		);
	}


//  config/init/exe/run methods

	#[\Override]
	protected function run( $argument ): void
	{
		$this->logger->info(
			'FCIAS ProcessPendingUpdates: run() called.',
			[
				'app'      => Application::APP_ID,
				'argument' => $argument,
			],
		);

		$started = hrtime( true );

		try
		{
			$batchLimit = $this->appConfig->getValueInt(
				Application::APP_ID,
				'pending_batch_limit',
				50,
			);

			// Disowned files first: they are the cheapest work in the queue —
			// a metadata document rewrite, no hashing — and clearing one may
			// put it straight back as pending:<mode>, which this same run then
			// picks up rather than leaving for the next.
			$disowned = $this->ruleService->clearDisownedFiles( $batchLimit );

			$pendingRows = $this->metadataService->fetchPendingBatch( $batchLimit );

			if ( empty( $pendingRows ) )
			{
				$this->logger->debug(
					'FCIAS ProcessPendingUpdates: no pending rows to process.',
					[ 'app' => Application::APP_ID ],
				);

				// An empty run is still a run: the heartbeat on the status
				// page is the point.
				$this->jobStats->record(
					JobStatsService::JOB_PENDING_DRAIN,
					self::counts( array_fill_keys( DrainOutcome::KINDS, 0 ), $disowned ),
					JobStatsService::millisecondsSince( $started ),
				);

				if ( $disowned > 0 )
				{
					// More may be waiting: this pass took one batch of them.
					$this->jobList->add( self::class );
				}

				return;
			}

			$tally = array_fill_keys( DrainOutcome::KINDS, 0 );

			foreach ( $pendingRows as $row )
			{
				$fileId = $row[ MetadataService::FIELD_FILE_ID ];
				$status = $row[ MetadataService::FIELD_META_VALUE_STRING ];

				$mode = MetadataService::parseMode( $status );

				try
				{
					// Algorithms come from the governing rule, resolved at
					// action time — an ignore/exclude/no-rule verdict drops
					// the mark instead of hashing. A failure leaves the file
					// queued, its failed attempt already counted.
					$tally[ $this->hashCalc->processFile( $fileId, $mode )->kind ] ++;
				}
				catch ( Throwable $e )
				{
					$this->recordFailure( $fileId, $status, $e );

					$tally[ DrainOutcome::FAILED ] ++;
				}
			}

			$counts = self::counts( $tally, $disowned );

			$this->jobStats->record(
				JobStatsService::JOB_PENDING_DRAIN,
				$counts,
				JobStatsService::millisecondsSince( $started ),
			);

			// Re-dispatch when either queue was full: more of it is waiting.
			// Not after a full batch in which no file left the queue: those
			// files keep failing, and the next tick tries them again.
			$left = count( $pendingRows ) - $tally[ DrainOutcome::FAILED ];

			if ( ( count( $pendingRows ) >= $batchLimit && $left > 0 ) || $disowned >= $batchLimit )
			{
				$this->jobList->add( self::class );
			}

			$this->logger->info(
				'FCIAS ProcessPendingUpdates: batch complete',
				[ 'app' => Application::APP_ID, ...$counts ],
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS ProcessPendingUpdates: processing failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			$this->jobStats->recordFailure(
				JobStatsService::JOB_PENDING_DRAIN,
				$e,
				JobStatsService::millisecondsSince( $started ),
			);
		}
	}


//  static methods

	/**
	 * A run's counts as the status lists them: the files by outcome, every
	 * outcome, zeros included, so that a reader needs no list of the keys
	 * that may be missing; then the files it took, and the disowned ones it
	 * cleared.
	 *
	 * @param  array<string, int>  $tally  Files by {@see DrainOutcome} kind.
	 *
	 * @return array<string, int>
	 */
	private static function counts(
		array $tally,
		int   $disowned,
	): array
	{
		return [
			'hashed'   => $tally[ DrainOutcome::HASHED ],
			'typed'    => $tally[ DrainOutcome::TYPED ],
			'stamped'  => $tally[ DrainOutcome::STAMPED ],
			'emptied'  => $tally[ DrainOutcome::EMPTIED ],
			'dropped'  => $tally[ DrainOutcome::DROPPED ],
			'gone'     => $tally[ DrainOutcome::GONE ],
			'failed'   => $tally[ DrainOutcome::FAILED ],
			'total'    => array_sum( $tally ),
			'disowned' => $disowned,
		];
	}


//  other non-static methods

	/**
	 * Count a failed attempt at a file whose hashing threw, and log it: a
	 * warning the first time, debug for the retries after it, which come
	 * on every run until the file reads again.
	 */
	private function recordFailure(
		int       $fileId,
		string    $marker,
		Throwable $e,
	): void
	{
		try
		{
			$attempts = $this->metadataService->recordFailedAttempt( $fileId, $marker );
		}
		catch ( Throwable )
		{
			$attempts = 1;
		}

		$this->logger->log(
			$attempts > 1
				? LogLevel::DEBUG
				: LogLevel::WARNING,
			'FCIAS ProcessPendingUpdates: processFile failed for fileId {fileId} (failed attempts: {attempts})',
			[
				'app'       => Application::APP_ID,
				'fileId'    => $fileId,
				'marker'    => $marker,
				'attempts'  => $attempts,
				'exception' => $e,
			],
		);
	}
}
