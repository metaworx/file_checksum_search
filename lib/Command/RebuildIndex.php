<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class RebuildIndex
	extends
	Command
{

	public function __construct(
		private readonly MetadataService        $metadataService,
		private readonly HashCalculationService $hashCalc,
		private readonly HashIndexService       $hashIndexService,
		private readonly LoggerInterface        $logger,
	) {

		parent::__construct();
	}


	/**
	 * Configure the rebuild command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:rebuild' )
		     ->setDescription( 'Rebuild the checksum metadata index from filecache' )
		     ->addOption(
			     'batch-size',
			     null,
			     InputOption::VALUE_OPTIONAL,
			     'Maximum files to process per run',
			     100,
		     )
		;
	}


	/**
	 * Execute the rebuild command.
	 *
	 * Seeds unprocessed files into the metadata index, then processes the
	 * pending queue using HashCalculationService::processFile().
	 *
	 * @noinspection PhpUnused
	 */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$batchSize = (int) $input->getOption( 'batch-size' );
		$batchSize = max( 1, $batchSize );

		$this->logger->info(
			'FCIAS RebuildIndex command: invoked',
			[
				'app'       => Application::APP_ID,
				'batchSize' => $batchSize,
			],
		);

		// Phase 1: Seed unprocessed files into the pending index
		$output->writeln( 'Seeding unprocessed files into metadata index …' );

		$seeded = $this->metadataService->seedIndex();
		$output->writeln( sprintf( '  %d new files added to index.', $seeded ) );

		// Phase 2: Show current pending stats
		$pendingStats = $this->metadataService->getPendingStats();
		$totalPending = array_sum( $pendingStats );

		$output->writeln( sprintf( '  %d files pending processing.', $totalPending ) );

		if ( $totalPending === 0 )
		{
			$output->writeln( 'No pending files to process.' );

			return Command::SUCCESS;
		}

		// Phase 3: Process pending files
		$output->writeln( sprintf( 'Processing up to %d pending files …', $batchSize ) );

		$pendingRows = $this->metadataService->fetchPendingBatch( $batchSize );
		$processed   = 0;
		$failed      = 0;

		foreach ( $pendingRows as $row )
		{
			$fileId = $row[ MetadataService::FIELD_FILE_ID ];
			$mode   = (string) $row[ MetadataService::FIELD_META_VALUE_STRING ];

			$mode = MetadataService::parseMode( $mode );

			try
			{
				$this->hashCalc->processFile(
					$fileId,
					$mode,
					HashCalculationService::SUPPORTED_ALGOS,
				);
				$processed ++;
			}
			catch ( \Throwable $e )
			{
				$this->logger->error(
					'FCIAS RebuildIndex: processFile failed',
					[
						'app'       => Application::APP_ID,
						'fileId'    => $fileId,
						'mode'      => $mode,
						'exception' => $e,
					],
				);
				$failed ++;
			}
		}

		$output->writeln(
			sprintf(
				'Done. %d files processed, %d failed.',
				$processed,
				$failed,
			),
		);

		return $failed > 0
			? Command::FAILURE
			: Command::SUCCESS;
	}

}
