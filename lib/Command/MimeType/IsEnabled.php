<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command\MimeType;

use OCA\FileChecksumSearch\Service\MimeTypeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Whether MIME types are detected, for a script to ask.
 *
 * Prints `on`, `off`, or `unavailable` (switched on, and PHP's Fileinfo
 * extension not loaded); nothing under `--quiet`, which Symfony silences by
 * itself. Exits 0 for on and 3 otherwise — the status code
 * `systemctl is-active` gives for not running — so that 1 is left to an
 * error and 2 to an invalid argument or option.
 *
 * @noinspection PhpUnused
 */
class IsEnabled
    extends
    Command
{

//  constants

	/** Not detecting: off, or switched on without PHP able to. */
	public const NO = 3;


//  constructor

	public function __construct(
		private readonly MimeTypeService $mimeTypes,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	/**
	 * Symfony binds the input before execute() and throws on an argument or
	 * option it does not know, which its application turns into exit code 1.
	 * Caught here, it is 2, as the command promises.
	 *
	 * @noinspection PhpUnused
	 */
	#[\Override]
	public function run(
		InputInterface  $input,
		OutputInterface $output,
	): int
	{
		try
		{
			return parent::run( $input, $output );
		}
		catch ( ExceptionInterface $e )
		{
			$error = $output instanceof ConsoleOutputInterface
				? $output->getErrorOutput()
				: $output;

			$error->writeln( '<error>' . $e->getMessage() . '</error>' );

			return Command::INVALID;
		}
	}

	/**
	 * @noinspection PhpUnused
	 */
	#[\Override]
	protected function configure(): void
	{
		$this->setName( 'file-checksum-search:mimetype:is-enabled' )
		     ->setAliases( [ 'fcias:mimetype:is-enabled' ] )
		     ->setDescription( 'Say whether MIME types are detected: on, off or unavailable' )
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command prints <info>on</info>, <info>off</info>, or <info>unavailable</info> where detection is
switched on and PHP's Fileinfo extension is not loaded. <info>--quiet</info> prints nothing.

It exits with:
  0  on
  1  an error
  2  an invalid argument or option
  3  off or unavailable

  <info>occ %command.name% --quiet && echo detecting</info>
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
		try
		{
			$enabled   = $this->mimeTypes->isEnabled();
			$available = $this->mimeTypes->isAvailable();
		}
		catch ( Throwable $e )
		{
			$error = $output instanceof ConsoleOutputInterface
				? $output->getErrorOutput()
				: $output;

			$error->writeln( '<error>' . $e->getMessage() . '</error>' );

			return Command::FAILURE;
		}

		if ( ! $enabled )
		{
			$output->writeln( 'off' );

			return self::NO;
		}

		if ( ! $available )
		{
			$output->writeln( 'unavailable' );

			return self::NO;
		}

		$output->writeln( 'on' );

		return Command::SUCCESS;
	}
}
