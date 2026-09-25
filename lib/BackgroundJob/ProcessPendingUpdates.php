<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 * @author    metaworx
 */

namespace OCA\FileChecksumSearch\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashCalculationService;
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

	protected function run( $argument ): void
	{
		$this->logger->info(
			'FCIAS ProcessPendingUpdates: run() called.',
			[
				'app'      => Application::APP_ID,
				'argument' => $argument,
			],
		);

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
					[
						'processed' => 0,
						'failed'    => 0,
						'total'     => 0,
						'disowned'  => $disowned,
					],
				);

				if ( $disowned > 0 )
				{
					// More may be waiting: this pass took one batch of them.
					$this->jobList->add( self::class );
				}

				return;
			}

			$processed = 0;
			$failed    = 0;

			foreach ( $pendingRows as $row )
			{
				$fileId = $row[ MetadataService::FIELD_FILE_ID ];
				$status = $row[ MetadataService::FIELD_META_VALUE_STRING ];

				$mode = MetadataService::parseMode( $status );

				try
				{
					// Algorithms come from the governing rule, resolved at
					// action time — an ignore/exclude/no-rule verdict drops
					// the mark instead of hashing.
					$this->hashCalc->processFile( $fileId, $mode );

					$processed ++;
				}
				catch ( Throwable $e )
				{
					$failed ++;

					$this->logger->warning(
						'FCIAS ProcessPendingUpdates: processFile failed for fileId {fileId}',
						[
							'app'       => Application::APP_ID,
							'fileId'    => $fileId,
							'mode'      => $mode,
							'exception' => $e,
						],
					);
				}
			}

			$this->jobStats->record(
				JobStatsService::JOB_PENDING_DRAIN,
				[
					'processed' => $processed,
					'failed'    => $failed,
					'total'     => count( $pendingRows ),
					'disowned'  => $disowned,
				],
			);

			// Re-dispatch when either queue was full: more of it is waiting.
			if ( count( $pendingRows ) >= $batchLimit || $disowned >= $batchLimit )
			{
				$this->jobList->add( self::class );
			}

			$this->logger->info(
				'FCIAS ProcessPendingUpdates: batch complete',
				[
					'app'       => Application::APP_ID,
					'processed' => $processed,
					'failed'    => $failed,
					'total'     => count( $pendingRows ),
					'disowned'  => $disowned,
				],
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
		}
	}
}
