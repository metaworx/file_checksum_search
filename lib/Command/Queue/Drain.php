<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command\Queue;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\DrainOutcome;
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

It counts what it did with the files it took: hashed, their MIME type read,
nothing to compute, emptied under <info>lazy</info>, dropped unread because no include rule
wants them any more, gone from their storage though the filecache lists them,
or failed. <info>-v</info> names each file it did not hash, and why; <info>-vv</info> names the
hashed ones too.

A file whose storage answers, on two failed reads in a row, that it does not have
it leaves the queue as <comment>stale:gone</comment> rather than being tried for ever: <comment>occ files:scan</comment>
brings the filecache in line, where it can reach the file; see the FAQ.

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

		$all   = (bool) $input->getOption( 'all' );
		$tally = array_fill_keys( DrainOutcome::KINDS, 0 );

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
					// A failure leaves the file queued, its failed attempt
					// already counted.
					$outcome = $this->hashCalc->processFile( $fileId, MetadataService::parseMode( $marker ) );
				}
				catch ( Throwable $e )
				{
					// One file that will not hash must not stop the queue:
					// it stays queued, behind the files that failed fewer
					// times, and a later run tries it again.
					$this->recordFailure( $fileId, $marker, $e );

					$outcome = DrainOutcome::failed( $e->getMessage() );
				}

				$tally[ $outcome->kind ] ++;

				// What it did with a file it did not hash, and why; the hashed
				// ones only on asking twice.
				$output->writeln(
					sprintf(
						$outcome->kind === DrainOutcome::FAILED
							? '<comment>  %s</comment>'
							: '  %s',
						$outcome->describe( $fileId ),
					),
					$outcome->kind === DrainOutcome::HASHED
						? OutputInterface::VERBOSITY_VERY_VERBOSE
						: OutputInterface::VERBOSITY_VERBOSE,
				);
			}

			if ( ! $all )
			{
				break;
			}
		}

		if ( array_sum( $tally ) === 0 )
		{
			$output->writeln( 'Nothing was waiting in the queue.' );

			return Command::SUCCESS;
		}

		$output->writeln( self::summary( $tally ) );

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


//  static methods

	/**
	 * The run in one line: the files it took, hashed and failed always, and
	 * whatever else it did with them where it did it at all.
	 *
	 * @param  array<string, int>  $tally  Files by {@see DrainOutcome} kind.
	 */
	private static function summary( array $tally ): string
	{
		$parts = [ sprintf( '%d hashed', $tally[ DrainOutcome::HASHED ] ) ];

		foreach (
			[
				DrainOutcome::TYPED   => '%d MIME types read',
				DrainOutcome::STAMPED => '%d with nothing to compute',
				DrainOutcome::EMPTIED => '%d emptied (lazy)',
				DrainOutcome::DROPPED => '%d dropped unread',
				DrainOutcome::GONE    => '%d gone from their storage',
			] as $kind => $format
		)
		{
			if ( $tally[ $kind ] > 0 )
			{
				$parts[] = sprintf( $format, $tally[ $kind ] );
			}
		}

		$parts[] = sprintf( '%d failed', $tally[ DrainOutcome::FAILED ] );

		return sprintf( 'Took %d files: %s.', array_sum( $tally ), implode( ', ', $parts ) );
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
