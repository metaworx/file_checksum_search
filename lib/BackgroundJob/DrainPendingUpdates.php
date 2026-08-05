<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drains the pending hash update queue at regular intervals.
 *
 * Processes up to 50 entries per run. Registered in Application::boot()
 * with a 60-second interval via IJobList.
 */
class DrainPendingUpdates
	extends
	TimedJob
{

	public function __construct(
		ITimeFactory                     $time,
		private readonly HashIndexService $hashIndexService,
		private readonly LoggerInterface  $logger,
	) {

		parent::__construct( $time );

		$this->setInterval( 60 );
		$this->setTimeSensitivity( self::TIME_INSENSITIVE );

		$this->logger->debug(
			'FCIAS DrainPendingUpdates: job instance constructed (interval=60s).',
			[ 'app' => Application::APP_ID ],
		);
	}


	protected function run( $argument ): void
	{

		$this->logger->info(
			'FCIAS DrainPendingUpdates: run() called.',
			[
				'app'      => Application::APP_ID,
				'argument' => $argument,
			],
		);

		try
		{
			$result = $this->hashIndexService->drainPending( 50 );

			$this->logger->info(
				'FCIAS DrainPendingUpdates: drain complete',
				[
					'app'       => Application::APP_ID,
					'processed' => $result['processed'],
					'deleted'   => $result['deleted'],
				],
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS DrainPendingUpdates: drain failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);
		}
	}

}
