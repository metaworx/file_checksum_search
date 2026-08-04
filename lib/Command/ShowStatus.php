<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TriggerInitializationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowStatus
	extends
	Command
{

	public function __construct(
		private readonly StatusService                $statusService,
		private readonly TriggerInitializationService $triggerInitService,
	) {

		parent::__construct();
	}


	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:status' )
		     ->setDescription( 'Display FCIAS app status and compatibility information' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$output->writeln( '=== FCIAS Status ===' );
		$output->writeln( '' );

		$output->writeln( sprintf( 'App version:     %s', $this->statusService->getAppVersion() ) );

		$output->writeln( sprintf( 'MariaDB version: %s', $this->statusService->getDbVersion() ) );

		$hasTrigger = $this->triggerInitService->checkTriggerPrivilege();
		$output->writeln(
			sprintf(
				'TRIGGER priv:    %s',
				$hasTrigger
					? 'OK'
					: 'MISSING',
			),
		);

		$output->writeln( sprintf( 'Hash rows:       %d', $this->statusService->getHashRowCount() ) );

		$output->writeln(
			sprintf(
				'SP installed:    %s',
				$this->statusService->isSpInstalled()
					? 'YES'
					: 'NO',
			),
		);

		$output->writeln(
			sprintf(
				'Triggers:        %d/3 installed',
				$this->statusService->getTriggerCount(),
			),
		);

		return Command::SUCCESS;
	}

}
