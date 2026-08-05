<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\StatusService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class PurgeIndex
	extends
	Command
{

	public function __construct(
		private readonly HashIndexService $hashIndexService,
		private readonly StatusService    $statusService,
		private readonly LoggerInterface  $logger,
	) {

		parent::__construct();
	}


	/**
	 * Configure the purge command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:purge' )
		     ->setDescription( 'Truncate the checksum hash index table' )
		     ->addOption( 'force', 'f', InputOption::VALUE_NONE, 'Required safety flag to confirm purge' )
		;
	}


	/**
	 * Execute the purge command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$this->logger->info(
			'FCIAS PurgeIndex command: invoked',
			[ 'app' => Application::APP_ID ],
		);

		if ( ! $input->getOption( 'force' ) )
		{
			$count = $this->statusService->getHashRowCount();
			$output->writeln(
				sprintf(
					'<error>This will delete %d checksum index record(s). Use --force to confirm.</error>',
					$count,
				),
			);

			return Command::FAILURE;
		}

		$result = $this->hashIndexService->purgeIndex();

		$output->writeln( sprintf( 'Rows before purge: %d', $result['before'] ) );
		$output->writeln( sprintf( 'Rows after purge:  %d', $result['after'] ) );
		$output->writeln( sprintf( 'Purged:            %d rows', $result['before'] - $result['after'] ) );

		return Command::SUCCESS;
	}

}
