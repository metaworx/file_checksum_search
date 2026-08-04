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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateTable
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

		$this->setName( 'file-checksum-search:create-table' )
		     ->setDescription( 'Create the FCIAS hash table (idempotent — uses IF NOT EXISTS)' )
		     ->addOption( 'force', 'f', InputOption::VALUE_NONE, 'Required safety flag to confirm table creation' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		if ( ! $input->getOption( 'force' ) )
		{
			$output->writeln(
				'<error>This will create the FCIAS hash table. Use --force to confirm.</error>',
			);

			return Command::FAILURE;
		}

		$this->hashIndexService->createTable();

		$output->writeln( 'Hash table created (IF NOT EXISTS).' );

		return Command::SUCCESS;
	}

}
