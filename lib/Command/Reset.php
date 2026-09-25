<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\DB\Exception;
use OCA\FileChecksumSearch\Service\AppConfigService;
use OCA\FileChecksumSearch\Service\ExportService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\JsonFormat;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Give back everything this app owns.
 *
 * The command reports and changes nothing until it is told twice: once by
 * naming what to reset, and once by `--force`. Everything it touches is
 * irrecoverable by any other means — the hashes took an instance-wide read to
 * compute — so the default is a plan, not an act.
 *
 * @noinspection PhpUnused
 */
class Reset
    extends
    Command
{

//  constructor

	public function __construct(
		private readonly AppConfigService $appConfigService,
		private readonly MetadataService  $metadataService,
		private readonly ExportService    $exportService,
		private readonly LoggerInterface  $logger,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	/**
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{
		$this->setName( 'file-checksum-search:reset' )
		     ->setAliases( [ 'fcias:reset' ] )
		     ->setDescription( 'Give back this app\'s configuration, queue state and stored hashes' )
		     ->addOption(
			     'config',
			     null,
			     InputOption::VALUE_NONE,
			     'Forget the app\'s configuration keys, returning them to their defaults',
		     )
		     ->addOption(
			     'status',
			     null,
			     InputOption::VALUE_NONE,
			     'Forget the queue: every pending and stale marker',
		     )
		     ->addOption(
			     'hashes',
			     null,
			     InputOption::VALUE_NONE,
			     'Disown every stored checksum',
		     )
		     ->addOption(
			     'force',
			     null,
			     InputOption::VALUE_NONE,
			     'Actually do it. Without this the command only reports what it would do',
		     )
		     ->addOption(
			     'backup',
			     'b',
			     InputOption::VALUE_REQUIRED,
			     'Write a backup here first, and do nothing at all if that fails',
		     )
		     ->addOption(
			     'now',
			     null,
			     InputOption::VALUE_NONE,
			     'Clear the hashes in the foreground instead of leaving them to the background job',
		     )
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command gives back everything this app owns.

With no slice named it resets all three; naming any restricts it to those:

  <info>--config</info>   forget the configuration keys, back to their declared defaults
  <info>--status</info>   forget the queue — every pending and stale marker
  <info>--hashes</info>   disown every stored checksum

<comment>It reports and changes nothing until you add --force.</comment> What it removes cannot be
recovered by any other means: the hashes took an instance-wide read to compute.

Resetting hashes <comment>disowns</comment> them rather than deleting them there and then. Each
file is marked, which takes them out of search and out of duplicate groups
immediately, and the background job clears them as it goes — one database write
per thousand files rather than one document rewrite each. <info>--now</info> does the
clearing in the foreground instead, which is what a test fixture wants and what
an impatient operator with a small instance may prefer.

Examples:

  <info>occ fcias:reset</info>                                  what would happen, in full
  <info>occ fcias:reset --hashes --force</info>                 disown every checksum
  <info>occ fcias:reset --force --backup=/backups/before.json</info>
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
	): int
	{
		$slices = $this->slices( $input );

		try
		{
			$this->reportPlan( $slices, $output );
		}
		catch ( Throwable $e )
		{
			// A plan that cannot be computed is not a reason to show a stack
			// trace: the run changed nothing either way.
			$output->writeln( '<error>Could not work out what there is to reset: '
				. $e->getMessage() . '</error>' );

			return Command::FAILURE;
		}

		if ( ! $input->getOption( 'force' ) )
		{
			$output->writeln( '' );
			$output->writeln(
				'<comment>Nothing was changed. Add --force to carry this out.</comment>',
			);

			return Command::SUCCESS;
		}

		$backup = $input->getOption( 'backup' );

		// First, and abortively: a safety net that tears is worse than none,
		// because the operator went ahead believing they had one.
		if ( is_string( $backup ) && ! $this->writeBackup( $backup, $slices, $output ) )
		{
			$output->writeln( '<error>Nothing was reset.</error>' );

			return Command::FAILURE;
		}

		$this->logger->warning(
			'FCIAS: reset carried out by {actor} on {slices}.',
			[
				'app'    => Application::APP_ID,
				'actor'  => $this->actor(),
				'slices' => implode( ', ', $slices ),
				'backup' => is_string( $backup )
					? $backup
					: null,
			],
		);

		return $this->carryOut( $slices, (bool) $input->getOption( 'now' ), $output );
	}


//  other non-static methods

	/**
	 * What is there to lose, before anything is done about it.
	 *
	 * @param  list<string>  $slices
	 *
	 * @throws Exception
	 */
	private function reportPlan(
		array           $slices,
		OutputInterface $output,
	): void
	{
		foreach ( $slices as $slice )
		{
			match ( $slice )
			{
				ExportService::SLICE_CONFIG => $output->writeln(
					sprintf(
						'  config   %d keys would be forgotten, returning to their defaults',
						count( $this->appConfigService->export() ),
					),
				),
				ExportService::SLICE_STATUS => $output->writeln(
					sprintf(
						'  status   %d queued and %d untrusted markers would be cleared',
						array_sum( $this->metadataService->getPendingStats() ),
						array_sum( $this->metadataService->getStaleStats() ),
					),
				),
				ExportService::SLICE_HASHES => $output->writeln(
					sprintf(
						'  hashes   %d files would lose their checksums',
						$this->metadataService->countHashedFiles(),
					),
				),
				default => null,
			};
		}
	}

	/**
	 * @param  list<string>  $slices
	 */
	private function carryOut(
		array           $slices,
		bool            $now,
		OutputInterface $output,
	): int
	{
		$output->writeln( '' );

		try
		{
			foreach ( $slices as $slice )
			{
				match ( $slice )
				{
					ExportService::SLICE_CONFIG => $output->writeln(
						sprintf( '  config   %d keys forgotten', $this->appConfigService->clear() ),
					),
					ExportService::SLICE_STATUS => $output->writeln(
						sprintf( '  status   %d markers cleared', $this->metadataService->clearQueueState() ),
					),
					ExportService::SLICE_HASHES => $output->writeln( $this->resetHashes( $now ) ),
					default                     => null,
				};
			}
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS reset: failed part way through.',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);
			$output->writeln( '<error>' . $e->getMessage() . '</error>' );

			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}

	/**
	 * Disown the hashes, or clear them outright.
	 *
	 * @throws Exception
	 */
	private function resetHashes( bool $now ): string
	{
		if ( $now )
		{
			return sprintf(
				'  hashes   %d files cleared',
				$this->metadataService->clearHashesNow(),
			);
		}

		return sprintf(
			'  hashes   %d files disowned; the background job clears them as it goes',
			$this->metadataService->markAllStale(),
		);
	}

	/**
	 * @param  list<string>  $slices
	 */
	private function writeBackup(
		string          $path,
		array           $slices,
		OutputInterface $output,
	): bool
	{
		$stream = @fopen( $path, 'w' );

		if ( $stream === false )
		{
			$output->writeln( sprintf( '<error>Cannot write the backup to %s.</error>', $path ) );

			return false;
		}

		try
		{
			// Always the backup format: a safety net that cannot be restored
			// from is not one, and only json carries every slice.
			$this->exportService->export(
				new JsonFormat(),
				$slices,
				$stream,
				new FormatOptions(),
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS reset: the backup failed, so nothing was reset.',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);
			$output->writeln( '<error>The backup failed: ' . $e->getMessage() . '</error>' );

			return false;
		}
		finally
		{
			fclose( $stream );
		}

		$output->writeln( sprintf( 'Backed up to %s first.', $path ) );

		return true;
	}

	/**
	 * Which slices were asked for — all three when none was named.
	 *
	 * @return list<string>
	 */
	private function slices( InputInterface $input ): array
	{
		$named = array_values(
			array_filter(
				ExportService::SLICES,
				static fn(
					string $slice,
				): bool => (bool) $input->getOption( $slice ),
			),
		);

		return $named === []
			? ExportService::SLICES
			: $named;
	}

	/**
	 * Who to name in the audit line.
	 *
	 * `occ` runs as whoever invoked it, so that is the only actor there is
	 * to record — under `sudo`, the account behind it rather than the one it
	 * became.
	 */
	private function actor(): string
	{
		$sudo = getenv( 'SUDO_USER' );

		if ( is_string( $sudo ) && $sudo !== '' )
		{
			return $sudo;
		}

		if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) )
		{
			$user = posix_getpwuid( posix_geteuid() );

			if ( is_array( $user ) && isset( $user['name'] ) )
			{
				return (string) $user['name'];
			}
		}

		return get_current_user()
			?: 'unknown';
	}
}
