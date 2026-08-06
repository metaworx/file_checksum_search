<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 * @author    metaworx
 */

namespace OCA\FileChecksumSearch\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Periodically seeds the files_metadata_index with file-checksum-updated_at
 * entries for files that don't have one, marking them as pending:new.
 *
 * Runs every 21 hours (75600 seconds). Registered in Application::boot()
 * via IJobList.
 */
class SeedPendingUpdates
	extends
	TimedJob
{

	public function __construct(
		ITimeFactory                     $time,
		private readonly MetadataService $metadataService,
		private readonly LoggerInterface $logger,
	) {

		parent::__construct( $time );

		$this->setInterval( 75600 );
		$this->setTimeSensitivity( self::TIME_INSENSITIVE );

		$this->logger->debug(
			'FCIAS SeedPendingUpdates: job instance constructed (interval=21h).',
			[ 'app' => Application::APP_ID ],
		);
	}


	protected function run( $argument ): void
	{

		$this->logger->info(
			'FCIAS SeedPendingUpdates: run() called.',
			[
				'app'      => Application::APP_ID,
				'argument' => $argument,
			],
		);

		try
		{
			$inserted = $this->metadataService->seedIndex();

			$this->logger->info(
				'FCIAS SeedPendingUpdates: seeding complete',
				[
					'app'      => Application::APP_ID,
					'inserted' => $inserted,
				],
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS SeedPendingUpdates: seeding failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);
		}
	}

}
