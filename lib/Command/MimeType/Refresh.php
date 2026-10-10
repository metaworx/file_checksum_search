<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command\MimeType;

use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\MimeTypeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Queue the hashed files for their MIME type, now rather than through the
 * background job the admin settings queue
 * ({@see MetadataService::markForMimeTypeAfter()}).
 *
 * @noinspection PhpUnused
 */
class Refresh
    extends
    Command
{

//  constructor

	public function __construct(
		private readonly MimeTypeService $mimeTypes,
		private readonly MetadataService $metadataService,
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
		$this->setName( 'file-checksum-search:mimetype:refresh' )
		     ->setAliases( [ 'fcias:mimetype:refresh' ] )
		     ->setDescription( 'Queue the hashed files that have no MIME type yet, or all of them, for their type' )
		     ->addOption(
			     'all',
			     null,
			     InputOption::VALUE_NONE,
			     'Every hashed file, those with a type as well',
		     )
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command queues the hashed files that have no MIME type yet,
or with <info>--all</info> every hashed file, for their type: the queue drain reads each one's
first 64 KiB and nothing more. <comment>fcias:queue:drain --all</comment> reads them now.

<info>--all</info> is for after PHP was upgraded, whose libmagic can name some content
differently. A file already queued, for its hashes or otherwise, is left as
it is: reading it for its hashes detects its type as well.

Detection must be on.
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
		$refusal = $this->mimeTypes->refusalToRefresh();

		if ( $refusal !== null )
		{
			$output->writeln(
				'<error>'
				. match ( $refusal )
				{
					MimeTypeService::STATE_UNAVAILABLE => 'PHP\'s Fileinfo extension is not loaded, so no MIME type can be detected.',
					MimeTypeService::STATE_DELETING    => 'The MIME types are being deleted; nothing can be refreshed until that is done and detection is on again.',
					default                            => 'MIME type detection is off; fcias:mimetype:enable switches it on.',
				}
				. '</error>',
			);

			return Command::FAILURE;
		}

		$result = $this->metadataService->markForMimeTypeAfter( 0, 1000, (bool) $input->getOption( 'all' ) );

		$output->writeln(
			sprintf(
				'Queued %d files for their MIME type. The queue drain reads them; fcias:queue:drain --all reads them now.',
				$result['marked'],
			),
		);

		return Command::SUCCESS;
	}
}
