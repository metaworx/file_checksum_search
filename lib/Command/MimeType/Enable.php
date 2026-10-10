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
 * Switch MIME type detection on, under the rules the admin settings follow
 * ({@see MimeTypeService::refusalToEnable()}).
 *
 * @noinspection PhpUnused
 */
class Enable
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
		$this->setName( 'file-checksum-search:mimetype:enable' )
		     ->setAliases( [ 'fcias:mimetype:enable' ] )
		     ->setDescription( 'Detect each file\'s MIME type from its content while it is hashed' )
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command switches MIME type detection on: the read that hashes
a file names its type from its first 64 KiB, and stores it beside the hashes.

Files hashed from now on get their type. <comment>fcias:mimetype:refresh</comment> queues the files
hashed before, for their first 64 KiB alone.

It is refused while a deletion of the types runs, and where PHP's Fileinfo
extension is not loaded.
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
		$refusal = $this->mimeTypes->refusalToEnable();

		if ( $refusal !== null )
		{
			$output->writeln(
				'<error>'
				. ( $refusal === MimeTypeService::STATE_DELETING
					? sprintf(
						'The MIME types are being deleted, since %s; detection can be switched on once that is done (fcias:status shows how many are left).',
						date( 'Y-m-d H:i:s T', (int) $this->mimeTypes->getDeleteRequestedAt() ),
					)
					: 'PHP\'s Fileinfo extension is not loaded, so no MIME type can be detected.' )
				. '</error>',
			);

			return Command::FAILURE;
		}

		$this->mimeTypes->enable();

		$output->writeln( 'MIME type detection is on. Files hashed from now on get their type; fcias:mimetype:refresh queues the files hashed before.' );

		return Command::SUCCESS;
	}
}
