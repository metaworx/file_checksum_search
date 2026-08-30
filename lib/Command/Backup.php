<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\ExportService;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\FormatRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Write out everything this app owns.
 *
 * @noinspection PhpUnused
 */
class Backup
	extends
	Command
{

	public function __construct(
		private readonly ExportService   $exportService,
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

		$this->setName( 'file-checksum-search:backup' )
		     ->setAliases( [ 'fcias:backup' ] )
		     ->setDescription( 'Write out this app\'s configuration, queue state and stored hashes' )
		     ->addOption(
			     'config',
			     null,
			     InputOption::VALUE_NONE,
			     'Include the app\'s configuration keys',
		     )
		     ->addOption(
			     'status',
			     null,
			     InputOption::VALUE_NONE,
			     'Include what each file is waiting for, or why its hashes are not to be trusted',
		     )
		     ->addOption(
			     'hashes',
			     null,
			     InputOption::VALUE_NONE,
			     'Include the stored checksums and their freshness stamps',
		     )
		     ->addOption(
			     'format',
			     'f',
			     InputOption::VALUE_REQUIRED,
			     'json, csv or sum (default: guessed from --output, else json)',
		     )
		     ->addOption(
			     'algo',
			     'a',
			     InputOption::VALUE_REQUIRED,
			     'Which algorithm a checksum listing holds; required by --format=sum',
		     )
		     ->addOption(
			     'output',
			     'o',
			     InputOption::VALUE_REQUIRED,
			     'Write here instead of to standard output',
		     )
		     ->addOption(
			     'pretty',
			     null,
			     InputOption::VALUE_NONE,
			     'Indent the output, where the format has an opinion about whitespace',
		     )
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command writes out everything this app owns.

With no slice named it writes all three; naming any restricts it to those:

  <info>--config</info>   the app's own configuration keys
  <info>--status</info>   what each file is waiting for, or why its hashes are untrusted
  <info>--hashes</info>   the stored checksums and their freshness stamps

Only <info>--format=json</info> is a <comment>backup</comment>. It alone carries the header — schema
version, app version, instance id — that lets a later restore refuse a file it
cannot honour, and it alone can hold the configuration and the queue state. The
other two are hash tables and refuse those slices with that reason:

  <info>csv</info>   storage, path, algorithm, hash, stamp — everything but the header
  <info>sum</info>   what <comment>sha1sum</comment> writes: one algorithm, hash and path, nothing else

Examples:

  <info>occ fcias:backup -o /backups/fcias.json</info>
  <info>occ fcias:backup --hashes --format=sum --algo=sha256 -o SHA256SUMS</info>
  <info>occ fcias:backup --config --pretty</info>
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
		$slices = $this->slices( $input );
		$path   = $input->getOption( 'output' );
		$format = $input->getOption( 'format' )
			?: ( is_string( $path )
				? $this->formatRegistry->guessFromPath( $path )
				: null )
				?: FormatRegistry::FORMAT_JSON;

		try
		{
			$implementation = $this->formatRegistry->get( (string) $format );
		}
		catch ( Throwable $e )
		{
			$errors->writeln( '<error>' . $e->getMessage() . '</error>' );

			return Command::INVALID;
		}

		$algo = $input->getOption( 'algo' );

		if ( $format === FormatRegistry::FORMAT_SUM && ! is_string( $algo ) )
		{
			$errors->writeln(
				'<error>A checksum listing holds one algorithm and cannot name it; '
				. 'say which one with --algo.</error>',
			);

			return Command::INVALID;
		}

		// Every other format names the algorithm on each record, so --algo has
		// nothing to do there. Saying so beats writing a document the operator
		// believes is narrower than it is.
		if ( is_string( $algo ) && $format !== FormatRegistry::FORMAT_SUM )
		{
			$errors->writeln(
				sprintf(
					'<comment>--algo only narrows a checksum listing; %s names the algorithm '
					. 'on every record, so every one is written.</comment>',
					$format,
				),
			);
		}

		// Checked before a single row is read: discovering an unwritable path
		// after exporting an instance's worth of hashes wastes the whole run,
		// and on a large instance that is not a short wait.
		$stream = $this->openOutput( $path, $errors );

		if ( $stream === null )
		{
			return Command::FAILURE;
		}

		try
		{
			$counts = $this->exportService->export(
				$implementation,
				$slices,
				$stream,
				new FormatOptions(
					algo: is_string( $algo )
						? $algo
						: null,
					pretty: (bool) $input->getOption( 'pretty' ),
				),
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS backup: export failed.',
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

		$this->report( $implementation->losses(), $counts, $path, $output );

		return Command::SUCCESS;
	}


	/**
	 * Where a diagnostic goes.
	 *
	 * Standard error where the console offers it: with no `--output` the
	 * document *is* standard output, and a warning written into it would
	 * corrupt the file the operator is piping somewhere.
	 */
	private function errors( OutputInterface $output ): OutputInterface
	{

		return $output instanceof ConsoleOutputInterface
			? $output->getErrorOutput()
			: $output;
	}


	/**
	 * Which slices were asked for — all three when none was named, because a
	 * backup that quietly held something back would not be one.
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
	 * @return resource|null
	 */
	private function openOutput(
		mixed           $path,
		OutputInterface $output,
	) {

		if ( ! is_string( $path ) )
		{
			return fopen( 'php://stdout', 'w' );
		}

		$stream = @fopen( $path, 'w' );

		if ( $stream === false )
		{
			$output->writeln(
				sprintf( '<error>Cannot write to %s.</error>', $path ),
			);

			return null;
		}

		return $stream;
	}


	/**
	 * @param  list<string>        $losses
	 * @param  array<string, int>  $counts
	 */
	private function report(
		array           $losses,
		array           $counts,
		mixed           $path,
		OutputInterface $output,
	): void {

		// To standard output the document *is* the output; a summary printed
		// alongside it would land in the same stream and corrupt the file.
		if ( ! is_string( $path ) )
		{
			return;
		}

		foreach ( $counts as $slice => $count )
		{
			$output->writeln( sprintf( '  %-8s %d', $slice, $count ) );
		}

		$output->writeln( sprintf( 'Written to %s.', $path ) );

		if ( $losses === [] )
		{
			return;
		}

		$output->writeln( '<comment>This format does not carry:</comment>' );

		foreach ( $losses as $loss )
		{
			$output->writeln( '  - ' . $loss );
		}
	}

}
