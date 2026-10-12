<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command\MimeType;

use LogicException;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\MimeTypeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete the stored MIME types, now rather than through the background job
 * alone ({@see MetadataService::deleteMimeTypes()}).
 *
 * The deletion is requested, and its job queued, before the command starts:
 * cut short, the job finishes it. The command clears the request when it is
 * done, and the job then finds nothing to do.
 *
 * @noinspection PhpUnused
 */
class Delete
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


//  static methods

	/**
	 * Why a deletion is refused, for the state that refuses it.
	 */
	private static function refusal( string $state ): string
	{
		return $state === MimeTypeService::STATE_DELETING
			? 'The MIME types are being deleted already; fcias:status shows how many are left.'
			: 'MIME type detection is on; switch it off first (fcias:mimetype:disable), or the types it detects would be deleted with the rest.';
	}


//  config/init/exe/run methods

	/**
	 * @noinspection PhpUnused
	 */
	#[\Override]
	protected function configure(): void
	{
		$this->setName( 'file-checksum-search:mimetype:delete' )
		     ->setAliases( [ 'fcias:mimetype:delete' ] )
		     ->setDescription( 'Delete every stored MIME type, while detection is off' )
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command deletes every stored MIME type: out of each file's
metadata document, then out of the index. The hashes are left as they are.

Detection must be off (<comment>fcias:mimetype:disable</comment>): while it is on, the types it
detects would be deleted with the rest. It cannot be switched on again until
the deletion is done. Cut short, the deletion goes on in the background.
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
		$refusal = $this->mimeTypes->refusalToDelete();

		if ( $refusal !== null )
		{
			$output->writeln( '<error>' . self::refusal( $refusal ) . '</error>' );

			return Command::FAILURE;
		}

		// Switched on between the check and here, it is refused all the same.
		try
		{
			$this->mimeTypes->requestDelete();
		}
		catch ( LogicException )
		{
			$output->writeln( '<error>' . self::refusal( $this->mimeTypes->getState() ) . '</error>' );

			return Command::FAILURE;
		}

		$output->writeln( sprintf( 'Deleting the MIME types of %d files…', $this->metadataService->countMimeTypeRows() ) );

		$result = $this->metadataService->deleteMimeTypes();

		$this->mimeTypes->setDeleteRequestedAt( null );

		$output->writeln( sprintf( 'Deleted the MIME types of %d files. Detection can be switched on again.', $result['deleted'] ) );

		return Command::SUCCESS;
	}
}
