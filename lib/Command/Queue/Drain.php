<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command\Queue;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Work through the queue now, instead of waiting for the background job.
 *
 * Not a repair step, and deliberately: every step of `fcias:repair`
 * reconciles state the instance already holds, and runs on every upgrade
 * because `maintenance:repair` does. This reads file content and computes
 * hashes, which is neither cheap nor something an upgrade should start.
 *
 * @noinspection PhpUnused
 */
class Drain
	extends
	Command
{

	public function __construct(
		private readonly MetadataService        $metadataService,
		private readonly HashCalculationService $hashCalc,
		private readonly IAppConfig             $appConfig,
		private readonly LoggerInterface        $logger,
	) {

		parent::__construct();
	}


	/**
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:queue:drain' )
		     ->setAliases( [ 'fcias:queue:drain' ] )
		     ->setDescription( 'Compute the hashes the rules have asked for, without waiting for cron' )
		     ->addOption(
			     'batch-size',
			     'b',
			     InputOption::VALUE_REQUIRED,
			     'How many files to take (default: the pending_batch_limit setting)',
		     )
		     ->addOption(
			     'all',
			     null,
			     InputOption::VALUE_NONE,
			     'Keep taking batches until the queue is empty',
		     )
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command does now what the background job does every minute:
for each file waiting, it resolves the rule that governs the file at this moment
and computes the hashes that rule calls for.

Reach for it after a large import, or when cron is not running and you would
rather not wait. Without <info>--all</info> it takes one batch and stops, so a long queue is
worked through in steps you control rather than in one run you cannot interrupt.

This reads file content, which is why it is not one of <comment>fcias:repair</comment>'s steps:
those reconcile what the instance already holds, and run on every upgrade.
HELP,
		     )
		;
	}


	/**
	 * @noinspection PhpUnused
	 */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$given     = $input->getOption( 'batch-size' );
		$batchSize = $given === null
			? max( 1, $this->appConfig->getValueInt( Application::APP_ID, 'pending_batch_limit', 50 ) )
			: max( 1, (int) $given );

		// Where the limit came from, so that an unfinished run can say what
		// stopped it rather than only that it stopped.
		$limitFrom = $given === null
			? sprintf( 'the pending_batch_limit setting (%d)', $batchSize )
			: sprintf( '--batch-size (%d)', $batchSize );

		$all       = (bool) $input->getOption( 'all' );
		$processed = 0;
		$failed    = 0;

		while ( true )
		{
			$rows = $this->metadataService->fetchPendingBatch( $batchSize );

			if ( $rows === [] )
			{
				break;
			}

			foreach ( $rows as $row )
			{
				$fileId = (int) $row[ MetadataService::FIELD_FILE_ID ];

				try
				{
					$this->hashCalc->processFile(
						$fileId,
						MetadataService::parseMode( (string) $row[ MetadataService::FIELD_META_VALUE_STRING ] ),
					);
					$processed ++;
				}
				catch ( Throwable $e )
				{
					// One file that will not hash must not stop the queue:
					// it stays marked, and the next run tries it again.
					$this->logger->warning(
						'FCIAS queue:drain: could not hash fileId {fileId}; continuing.',
						[
							'app'       => Application::APP_ID,
							'fileId'    => $fileId,
							'exception' => $e,
						],
					);
					$failed ++;
					$output->writeln(
						sprintf( '<comment>  fileId %d could not be hashed.</comment>', $fileId ),
						OutputInterface::VERBOSITY_VERBOSE,
					);
				}
			}

			if ( ! $all )
			{
				break;
			}
		}

		if ( $processed === 0 && $failed === 0 )
		{
			$output->writeln( 'Nothing was waiting in the queue.' );

			return Command::SUCCESS;
		}

		$output->writeln( sprintf( 'Hashed %d files, %d failed.', $processed, $failed ) );

		$remaining = array_sum( $this->metadataService->getPendingStats() );

		if ( $remaining === 0 )
		{
			return Command::SUCCESS;
		}

		// Say what stopped it, not only that something is left: a run that
		// took the default and said nothing about it reads as though the
		// queue were shorter than it is.
		$output->writeln(
			$all
				? sprintf(
				'<comment>%d still waiting — queued while this ran, or left by a file that '
				. 'would not hash. Run again to take them.</comment>',
				$remaining,
			)
				: sprintf(
				'<comment>%d still waiting. This run stopped at %s; use --batch-size <n> to '
				. 'take more per run, or --all to work through the queue.</comment>',
				$remaining,
				$limitFrom,
			),
		);

		return Command::SUCCESS;
	}

}
