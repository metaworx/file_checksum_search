<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command\MimeType;

use OCA\FileChecksumSearch\Service\MimeTypeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Switch MIME type detection off. The types stored are kept.
 *
 * @noinspection PhpUnused
 */
class Disable
    extends
    Command
{

//  constructor

	public function __construct(
		private readonly MimeTypeService $mimeTypes,
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
		$this->setName( 'file-checksum-search:mimetype:disable' )
		     ->setAliases( [ 'fcias:mimetype:disable' ] )
		     ->setDescription( 'Stop detecting MIME types; the types stored are kept' )
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command switches MIME type detection off. No type is detected
from then on, and a refresh queued stops.

The types stored are kept, for as long as the content they describe: a file
written to loses its type when it is hashed again. <comment>fcias:mimetype:delete</comment> deletes
them all.
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
		$this->mimeTypes->disable();

		$output->writeln( 'MIME type detection is off. The types stored are kept; fcias:mimetype:delete deletes them.' );

		return Command::SUCCESS;
	}
}
