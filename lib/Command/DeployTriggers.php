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

/**
 * @noinspection PhpUnused
 */
class DeployTriggers
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
	 * Configure the deploy-triggers command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:deploy-triggers' )
		     ->setDescription( 'Create FCIAS triggers and stored procedure (idempotent)' )
		     ->addOption( 'force', 'f', InputOption::VALUE_NONE, 'Required safety flag to confirm deployment' )
		;
	}


	/**
	 * Execute the deploy-triggers command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$this->logger->info(
			'FCIAS DeployTriggers command: invoked',
			[ 'app' => Application::APP_ID ],
		);

		if ( ! $input->getOption( 'force' ) )
		{
			$output->writeln(
				'<error>This will create FCIAS triggers and stored procedure. Use --force to confirm.</error>',
			);

			return Command::FAILURE;
		}

		$this->hashIndexService->deployTriggers();

		$output->writeln( 'Triggers created: t_fcias_after_insert, t_fcias_after_update, t_fcias_after_delete' );
		$output->writeln( 'SP created:      fcias_parse_file_hashes' );

		return Command::SUCCESS;
	}

}
