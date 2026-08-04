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

class CronGenerateHashes
	extends
	TimedJob
{

	public const ARG_VERSION = 1;


	public function __construct(
		ITimeFactory                      $time,
		private readonly HashIndexService $hashIndexService,
		private readonly LoggerInterface  $logger,
	) {

		parent::__construct( $time );

		$this->setInterval( 300 );
		$this->setTimeSensitivity( self::TIME_INSENSITIVE );

		$this->logger->debug(
			'FCIAS CronGenerateHashes: job instance constructed (interval=300s).',
			[ 'app' => Application::APP_ID ],
		);
	}


	protected function run( $argument ): void
	{

		$this->logger->info(
			'FCIAS CronGenerateHashes: run() called.',
			[
				'app'      => Application::APP_ID,
				'argument' => $argument,
			],
		);

		if ( ! is_array( $argument ) || empty( $argument['userScope'] ) )
		{
			$this->logger->warning(
				'FCIAS CronGenerateHashes: run() called without valid definition argument.',
				[ 'app' => Application::APP_ID ],
			);

			return;
		}

		$definition = $argument;

		if ( ( $definition['_v'] ?? 0 ) < self::ARG_VERSION )
		{
			$this->logger->warning(
				'FCIAS CronGenerateHashes: argument version mismatch, skipping.',
				[
					'app'      => Application::APP_ID,
					'expected' => self::ARG_VERSION,
					'actual'   => $definition['_v'] ?? 0,
				],
			);

			return;
		}

		if ( empty( $definition['enabled'] ) )
		{
			$this->logger->info(
				'FCIAS CronGenerateHashes: definition is disabled, skipping.',
				[
					'app'       => Application::APP_ID,
					'userScope' => $definition['userScope'] ?? '?',
					'algo'      => $definition['algo'] ?? '?',
				],
			);

			return;
		}

		$userScope = $definition['userScope'] ?? 'all';
		$algo      = $definition['algo'] ?? HashIndexService::getDefaultAlgo();
		$path      = $definition['path'] ?? '/';
		$batchSize = (int) ( $definition['batchSize'] ?? 100 );

		if ( ! in_array( $algo, HashIndexService::SUPPORTED_ALGOS, true ) )
		{
			$this->logger->warning(
				'FCIAS CronGenerateHashes: unsupported algorithm in definition.',
				[
					'app'  => Application::APP_ID,
					'algo' => $algo,
				],
			);

			return;
		}

		$users = $this->hashIndexService->resolveUsers( $userScope );

		if ( empty( $users ) )
		{
			$this->logger->info(
				'FCIAS CronGenerateHashes: no users to process.',
				[
					'app'       => Application::APP_ID,
					'userScope' => $userScope,
				],
			);

			return;
		}

		$this->logger->info(
			'FCIAS CronGenerateHashes: starting batch',
			[
				'app'       => Application::APP_ID,
				'algo'      => $algo,
				'batchSize' => $batchSize,
				'users'     => $users,
			],
		);

		$totalProcessed = 0;
		$totalSkipped   = 0;
		$pathFilter     = $path !== '' && $path !== '/'
			? $path
			: null;

		foreach ( $users as $userId )
		{
			if ( $totalProcessed >= $batchSize )
			{
				break;
			}

			try
			{
				$result = $this->hashIndexService->generateMissingHashes(
					$userId,
					$algo,
					$pathFilter,
					$batchSize - $totalProcessed,
				);
			}
			catch ( Throwable $e )
			{
				$this->logger->error(
					'FCIAS CronGenerateHashes: generateMissingHashes failed for user.',
					[
						'app'       => Application::APP_ID,
						'userId'    => $userId,
						'algo'      => $algo,
						'exception' => $e,
					],
				);

				continue;
			}

			$totalProcessed += $result['processed'];
			$totalSkipped   += $result['skipped'];
		}

		$this->logger->info(
			'FCIAS CronGenerateHashes: batch complete',
			[
				'app'       => Application::APP_ID,
				'processed' => $totalProcessed,
				'skipped'   => $totalSkipped,
			],
		);
	}

}
