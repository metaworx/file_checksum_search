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
		$output->writeln( sprintf( 'Pending rows:    %d', $this->statusService->getPendingRowCount() ) );

		$output->writeln( '' );
		$output->writeln( 'Tables:' );

		foreach ( $this->statusService->getTableStatus() as $table )
		{
			$output->writeln(
				sprintf(
					'  %-45s %s',
					$table['name'],
					$table['ok'] ? 'OK' : 'MISSING',
				),
			);
		}

		$output->writeln( '' );
		$output->writeln( 'Stored Procedure:' );

		$sp = $this->statusService->getProcedureStatus();
		$output->writeln(
			sprintf(
				'  %-45s %s',
				$sp['name'],
				$sp['ok'] ? 'OK' : 'MISSING',
			),
		);

		$output->writeln( '' );
		$output->writeln( 'Triggers:' );

		foreach ( $this->statusService->getTriggerStatus() as $trigger )
		{
			$output->writeln(
				sprintf(
					'  %-45s %s',
					$trigger['name'],
					$trigger['ok'] ? 'OK' : 'MISSING',
				),
			);
		}

		return Command::SUCCESS;
	}

}
