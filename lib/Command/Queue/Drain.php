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
use Psr\Log\LogLevel;
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

//  constructor

	public function __construct(
		private readonly MetadataService        $metadataService,
		private readonly HashCalculationService $hashCalc,
		private readonly IAppConfig             $appConfig,
		private readonly LoggerInterface        $logger,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	/**
	 * @noinspection PhpUnused
	 */
	#[\Override]
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
			     'Take every file waiting, once each',
		     )
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command does now what the background job does every minute:
for each file waiting, it resolves the rule that governs the file at this moment
and computes the hashes that rule calls for.

Reach for it after a large import, or when cron is not running and you would
rather not wait. Without <info>--all</info> it takes one batch and stops, so a long queue is
worked through in steps you control rather than in one run you cannot interrupt.

A file that cannot be hashed now — unreadable, locked, written to while it was
read — stays queued, behind the files that failed fewer times. <info>--all</info> walks
the queue once, by file id: it tries every file waiting, passes one that fails
rather than taking it again, and stops at the end of the queue.

This reads file content, which is why it is not one of <comment>fcias:repair</comment>'s steps:
those reconcile what the instance already holds, and run on every upgrade.
HELP,
		     )
		;
	}

	/**
	 * @noinspection PhpUnused
	 */
	#[\Override]
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int
	{
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

		// With --all, one walk through the queue by file id: every file
		// waiting is tried once, and one that fails is passed rather than
		// taken again. Without, one batch in the queue's own order.
		$walkedTo = 0;

		while ( true )
		{
			$rows = $this->metadataService->fetchPendingBatch(
				$batchSize,
				$all
					? $walkedTo
					: null,
			);

			if ( $rows === [] )
			{
				break;
			}

			foreach ( $rows as $row )
			{
				$fileId   = (int) $row[ MetadataService::FIELD_FILE_ID ];
				$marker   = (string) $row[ MetadataService::FIELD_META_VALUE_STRING ];
				$walkedTo = $fileId;

				try
				{
					// False: the file is still queued, its failed attempt
					// already counted.
					if ( $this->hashCalc->processFile( $fileId, MetadataService::parseMode( $marker ) ) )
					{
						$processed ++;

						continue;
					}
				}
				catch ( Throwable $e )
				{
					// One file that will not hash must not stop the queue:
					// it stays queued, behind the files that failed fewer
					// times, and a later run tries it again.
					$this->recordFailure( $fileId, $marker, $e );
				}

				$failed ++;
				$output->writeln(
					sprintf( '<comment>  fileId %d could not be hashed.</comment>', $fileId ),
					OutputInterface::VERBOSITY_VERBOSE,
				);
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
				'<comment>%d still waiting — queued behind this run, or files it could not hash, '
				. 'which wait behind the rest. Run again to take them.</comment>',
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


//  other non-static methods

	/**
	 * Count a failed attempt at a file whose hashing threw, and log it: a
	 * warning the first time, debug for the retries after it.
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
			'FCIAS queue:drain: could not hash fileId {fileId} (failed attempts: {attempts}); continuing.',
			[
				'app'       => Application::APP_ID,
				'fileId'    => $fileId,
				'attempts'  => $attempts,
				'exception' => $e,
			],
		);
	}
}
