<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\ExportService;
use OCA\FileChecksumSearch\Service\ImportService;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\FormatRegistry;
use OCA\FileChecksumSearch\State\ImportPolicy;
use OCA\FileChecksumSearch\State\ImportReport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Put back what a backup holds — or bring in hashes computed elsewhere.
 *
 * @noinspection PhpUnused
 */
class Import
	extends
	Command
{

	public function __construct(
		private readonly ImportService   $importService,
		private readonly FormatRegistry  $formatRegistry,
		private readonly LoggerInterface $logger,
	) {

		parent::__construct();
	}


	/**
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:import' )
		     ->setAliases( [ 'fcias:import' ] )
		     ->setDescription( 'Read back a backup, or bring in checksums computed elsewhere' )
		     ->addOption(
			     'merge',
			     null,
			     InputOption::VALUE_NONE,
			     'Add only what is missing, leaving every stored hash alone',
		     )
		     ->addOption(
			     'replace',
			     null,
			     InputOption::VALUE_NONE,
			     'Overwrite what is stored with what the file holds',
		     )
		     ->addOption(
			     'config',
			     null,
			     InputOption::VALUE_NONE,
			     'Restore the app\'s configuration keys (json backups only)',
		     )
		     ->addOption(
			     'hashes',
			     null,
			     InputOption::VALUE_NONE,
			     'Restore the checksums',
		     )
		     ->addOption(
			     'status',
			     null,
			     InputOption::VALUE_NONE,
			     'Refused: the queue is derived, not restored',
		     )
		     ->addOption(
			     'format',
			     'f',
			     InputOption::VALUE_REQUIRED,
			     'json, csv or sum (default: guessed from the filename, else json)',
		     )
		     ->addOption(
			     'algo',
			     'a',
			     InputOption::VALUE_REQUIRED,
			     'Which algorithm a checksum listing holds; required by --format=sum',
		     )
		     ->addOption(
			     'user',
			     'u',
			     InputOption::VALUE_REQUIRED,
			     'Paths are relative to this user\'s files directory',
		     )
		     ->addOption(
			     'storage',
			     's',
			     InputOption::VALUE_REQUIRED,
			     'Paths are relative to this storage\'s root',
		     )
		     ->addOption(
			     'stamp',
			     null,
			     InputOption::VALUE_REQUIRED,
			     'source (default), mtime, or now — when each hash claims to have been computed',
			     ImportPolicy::STAMP_SOURCE,
		     )
		     ->addOption(
			     'allow-stale',
			     null,
			     InputOption::VALUE_NONE,
			     'Import hashes older than the file they describe, which nothing will ever correct',
		     )
		     ->addOption(
			     'strict',
			     null,
			     InputOption::VALUE_NONE,
			     'Stop at the first path this instance does not have',
		     )
		     ->addOption(
			     'dry-run',
			     null,
			     InputOption::VALUE_NONE,
			     'Report what would happen and write nothing',
		     )
		     ->addOption(
			     'input',
			     'i',
			     InputOption::VALUE_REQUIRED,
			     'Read from here instead of standard input',
		     )
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command reads back what <comment>fcias:backup</comment> wrote, or brings in
checksums something else computed.

<comment>One of --merge or --replace is required.</comment> There is no safe default: merging
keeps what this instance worked out for itself, replacing prefers the file, and
guessing wrong is silent either way.

  <info>--merge</info>     add only algorithms a file does not already have
  <info>--replace</info>   overwrite what is stored — what restoring over a reset means

<comment>The timestamp is the dangerous part.</comment> Freshness here is <comment>updated_at >= mtime</comment>, so
a hash stamped later than the content it describes is invisible to every
correction path this app has: no sweep will notice it, no rule will recompute it.

  <info>--stamp=source</info>   the record's own stamp; refuses one older than the file <comment>(default)</comment>
  <info>--stamp=mtime</info>    the file's mtime — right for a sumfile you just ran
  <info>--stamp=now</info>      this moment. Claims more than the data supports

A <comment>sum</comment> listing names no storage, so say what its paths are measured from with
<info>--user</info> or <info>--storage</info>. A record that names its own storage is never re-anchored,
so a backup re-imported with <info>--user</info> set still lands where it belongs.

Examples:

  <info>occ fcias:import --replace --hashes -i /backups/fcias.json</info>
  <info>occ fcias:import --merge -i SHA256SUMS --algo=sha256 --user=alice --stamp=mtime</info>
  <info>occ fcias:import --replace -i /backups/fcias.json --dry-run</info>
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

		$errors = $this->errors( $output );

		if ( $input->getOption( 'status' ) )
		{
			$errors->writeln(
				'<error>The queue cannot be imported: it says what this instance is about to do, '
				. 'which is worked out from the rules and the files, not restored from a file.</error>',
			);

			return Command::INVALID;
		}

		$merge   = (bool) $input->getOption( 'merge' );
		$replace = (bool) $input->getOption( 'replace' );

		// No default is safe here: merging keeps what this instance worked
		// out for itself, replacing prefers the file, and guessing wrong is
		// silent either way.
		if ( $merge === $replace )
		{
			$errors->writeln(
				'<error>Say which: --merge keeps every stored hash and adds what is missing, '
				. '--replace overwrites them.</error>',
			);

			return Command::INVALID;
		}

		$path   = $input->getOption( 'input' );
		$format = $input->getOption( 'format' )
			?: ( is_string( $path )
				? $this->formatRegistry->guessFromPath( $path )
				: null )
				?: FormatRegistry::FORMAT_JSON;

		$algo = $input->getOption( 'algo' );

		if ( $format === FormatRegistry::FORMAT_SUM && ! is_string( $algo ) )
		{
			$errors->writeln(
				'<error>A checksum listing does not name its algorithm; say which one with --algo.</error>',
			);

			return Command::INVALID;
		}

		try
		{
			$implementation = $this->formatRegistry->get( (string) $format );
			$options        = new FormatOptions(
				algo: is_string( $algo )
					? $algo
					: null,
				userId: is_string( $input->getOption( 'user' ) )
					? $input->getOption( 'user' )
					: null,
				storageId: is_string( $input->getOption( 'storage' ) )
					? $input->getOption( 'storage' )
					: null,
			);
			$policy         = new ImportPolicy(
				merge: $merge,
				stamp: (string) $input->getOption( 'stamp' ),
				allowOutdated: (bool) $input->getOption( 'allow-stale' ),
				strict: (bool) $input->getOption( 'strict' ),
				dryRun: (bool) $input->getOption( 'dry-run' ),
			);
		}
		catch ( Throwable $e )
		{
			$errors->writeln( '<error>' . $e->getMessage() . '</error>' );

			return Command::INVALID;
		}

		$this->warn( $policy, $errors );

		$stream = $this->openInput( $path, $errors );

		if ( $stream === null )
		{
			return Command::FAILURE;
		}

		[
			$wantsConfig,
			$wantsHashes,
		]
			= $this->slices( $input, $implementation->carriesConfig() );

		try
		{
			$report = $this->importService->import(
				$implementation,
				$stream,
				$options,
				$policy,
				$wantsConfig,
				$wantsHashes,
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS import: failed.',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);
			$errors->writeln( '<error>' . $e->getMessage() . '</error>' );

			return Command::FAILURE;
		}
		finally
		{
			if ( is_string( $path ) )
			{
				fclose( $stream );
			}
		}

		$this->report( $report, $policy, $output );

		return Command::SUCCESS;
	}


	/**
	 * Which slices to take from the backup document.
	 *
	 * Naming none takes everything the file could hold — which for a hash
	 * table is the hashes and nothing else. Defaulting to both regardless
	 * would make a plain `--format=sum` import fail on a configuration slice
	 * the file was never able to carry.
	 *
	 * Asking for `--config` on such a format is still refused, with that
	 * reason: a slice the caller named and did not get is a different thing
	 * from a slice nobody asked for.
	 *
	 * @return array{bool, bool}
	 */
	private function slices(
		InputInterface $input,
		bool           $carriesConfig,
	): array {

		$config = (bool) $input->getOption( ExportService::SLICE_CONFIG );
		$hashes = (bool) $input->getOption( ExportService::SLICE_HASHES );

		if ( $config || $hashes )
		{
			return [
				$config,
				$hashes,
			];
		}

		return [
			$carriesConfig,
			true,
		];
	}


	/**
	 * Say out loud what a policy asserts that its data does not support.
	 */
	private function warn(
		ImportPolicy    $policy,
		OutputInterface $errors,
	): void {

		if ( ! $policy->warrantsWarning() )
		{
			return;
		}

		if ( $policy->stamp === ImportPolicy::STAMP_NOW )
		{
			$errors->writeln(
				'<comment>--stamp=now marks every imported hash as computed just now. If any of them '
				. 'is wrong, nothing in this app will ever notice: a hash newer than its file is '
				. 'never recomputed.</comment>',
			);
		}

		if ( $policy->allowOutdated )
		{
			$errors->writeln(
				'<comment>--allow-stale imports hashes older than the files they describe. They will '
				. 'be wrong from the moment they land, and the drain will correct them only when '
				. 'a rule governs the file.</comment>',
			);
		}
	}


	/**
	 * @return resource|null
	 */
	private function openInput(
		mixed           $path,
		OutputInterface $errors,
	) {

		if ( ! is_string( $path ) )
		{
			return fopen( 'php://stdin', 'r' );
		}

		$stream = @fopen( $path, 'r' );

		if ( $stream === false )
		{
			$errors->writeln( sprintf( '<error>Cannot read %s.</error>', $path ) );

			return null;
		}

		return $stream;
	}


	private function report(
		ImportReport    $report,
		ImportPolicy    $policy,
		OutputInterface $output,
	): void {

		if ( $policy->dryRun )
		{
			$output->writeln( '<comment>Dry run — nothing was written.</comment>' );
		}

		foreach ( $report->toArray() as $label => $count )
		{
			if ( $count === 0 )
			{
				continue;
			}

			$output->writeln( sprintf( '  %-18s %d', str_replace( '_', ' ', (string) $label ), $count ) );
		}

		if ( $report->configRefused !== [] )
		{
			$output->writeln(
				'<comment>Refused, because this version does not declare them: '
				. implode( ', ', $report->configRefused ) . '</comment>',
			);
		}

		if ( $report->configNotPortable !== [] )
		{
			$output->writeln(
				'<comment>Refused, because they record what an instance has done rather than how it '
				. 'is configured: ' . implode( ', ', $report->configNotPortable ) . '</comment>',
			);
		}
	}


	/**
	 * Where a diagnostic goes — standard error where the console offers it,
	 * so a report piped somewhere stays what it says it is.
	 */
	private function errors( OutputInterface $output ): OutputInterface
	{

		return $output instanceof ConsoleOutputInterface
			? $output->getErrorOutput()
			: $output;
	}

}
