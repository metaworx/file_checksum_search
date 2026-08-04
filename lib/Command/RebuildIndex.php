<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\Service\HashIndexService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RebuildIndex
	extends
	Command
{

	public function __construct(
		private readonly HashIndexService $hashIndexService,
	) {

		parent::__construct();
	}


	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:rebuild' )
		     ->setDescription( 'Rebuild the checksum index from existing filecache checksums' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$result = $this->hashIndexService->rebuildIndex( $output );

		$output->writeln( sprintf( 'Done. %d files indexed.', $result['processed'] ) );

		return Command::SUCCESS;
	}

}
