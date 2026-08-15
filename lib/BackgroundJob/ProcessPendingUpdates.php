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
use OCA\FileChecksumSearch\Service\MetadataService;
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
 * Registered in Application::boot() via IJobList.
 */
class ProcessPendingUpdates
	extends
	TimedJob
{

	public function __construct(
		ITimeFactory                            $time,
		private readonly HashCalculationService $hashCalc,
		private readonly MetadataService        $metadataService,
		private readonly IAppConfig             $appConfig,
		private readonly IJobList               $jobList,
		private readonly LoggerInterface        $logger,
	) {

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

			$pendingRows = $this->metadataService->fetchPendingBatch( $batchLimit );

			if ( empty( $pendingRows ) )
			{
				$this->logger->debug(
					'FCIAS ProcessPendingUpdates: no pending rows to process.',
					[ 'app' => Application::APP_ID ],
				);

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
					$this->hashCalc->processFile(
						$fileId,
						$mode,
						HashCalculationService::SUPPORTED_ALGOS,
					);

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

			// Re-dispatch when batch was full to process remaining pending rows
			if ( count( $pendingRows ) >= $batchLimit )
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
