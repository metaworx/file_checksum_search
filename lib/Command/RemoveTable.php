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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveTable
	extends
	Command
{

	public function __construct(
		private readonly HashIndexService $hashIndexService,
		private readonly LoggerInterface  $logger,
	) {

		parent::__construct();
	}


	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:remove-table' )
		     ->setDescription( 'Drop the FCIAS hash table (run teardown first)' )
		     ->addOption( 'force', 'f', InputOption::VALUE_NONE, 'Required safety flag to confirm table removal' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$this->logger->info(
			'FCIAS RemoveTable command: invoked',
			[ 'app' => Application::APP_ID ],
		);

		if ( ! $input->getOption( 'force' ) )
		{
			$output->writeln( '<error>This will permanently delete the hash table. Use --force to confirm.</error>' );

			return Command::FAILURE;
		}

		$this->hashIndexService->removeTable();

		$output->writeln( 'Hash table dropped.' );

		return Command::SUCCESS;
	}

}
