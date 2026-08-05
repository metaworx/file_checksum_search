<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class RebuildIndex
	extends
	Command
{

	public function __construct(
		private readonly HashIndexService $hashIndexService,
		private readonly LoggerInterface  $logger,
	) {

		parent::__construct();
	}


	/**
	 * Configure the rebuild command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:rebuild' )
		     ->setDescription( 'Rebuild the checksum index from existing filecache checksums' )
		;
	}


	/**
	 * Execute the rebuild command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$this->logger->info(
			'FCIAS RebuildIndex command: invoked',
			[ 'app' => Application::APP_ID ],
		);

		$result = $this->hashIndexService->rebuildIndex( $output );

		$output->writeln( sprintf( 'Done. %d files indexed.', $result['processed'] ) );

		return Command::SUCCESS;
	}

}
