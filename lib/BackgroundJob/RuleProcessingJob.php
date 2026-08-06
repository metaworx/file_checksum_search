<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 * @author    metaworx
 */

namespace OCA\FileChecksumSearch\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\CronJobService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Evaluates configured hash-generation rules and marks matching files
 * as pending:{mode} via MetadataService for deferred processing.
 *
 * Registered in Application::boot() via IJobList.
 */
class RuleProcessingJob
	extends
	TimedJob
{

	public function __construct(
		ITimeFactory                     $time,
		private readonly CronJobService  $cronJobService,
		private readonly MetadataService $metadataService,
		private readonly IAppConfig      $appConfig,
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
			$definitions = $this->cronJobService->listDefinitions();
			$marked      = 0;

			foreach ( $definitions as $def )
			{
				if ( empty( $def['enabled'] ) )
				{
					continue;
				}

				$algo = $def['algo'] ?? HashIndexService::getDefaultAlgo();
				$mode = 'pending:auto';

				// Mark files in scope via seedIndex to ensure metadata entries exist
				// for all files, then ProcessPendingUpdates will compute the hashes.
				$inserted = $this->metadataService->seedIndex();

				$this->logger->debug(
					'FCIAS RuleProcessingJob: rule processed',
					[
						'app'       => Application::APP_ID,
						'userScope' => $def['userScope'] ?? 'all',
						'algo'      => $algo,
						'path'      => $def['path'] ?? '',
						'mode'      => $mode,
						'inserted'  => $inserted,
					],
				);

				$marked += $inserted;
			}

			$this->logger->info(
				'FCIAS RuleProcessingJob: evaluation complete',
				[
					'app'    => Application::APP_ID,
					'rules'  => count( $definitions ),
					'marked' => $marked,
				],
			);
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
	}

}
