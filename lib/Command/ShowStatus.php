<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TriggerInitializationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ShowStatus
	extends
	Command
{

	public function __construct(
		private readonly StatusService                $statusService,
		private readonly TriggerInitializationService $triggerInitService,
		private readonly LoggerInterface              $logger,
	) {

		parent::__construct();
	}


	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:status' )
		     ->setDescription( 'Display FCIAS app status and compatibility information' )
		     ->addOption(
			     'output',
			     'o',
			     InputOption::VALUE_REQUIRED,
			     'Output format: plain, json, json_pretty (default: plain)',
			     'plain',
		     )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$outFmt = $input->getOption( 'output' );

		$this->logger->info(
			'FCIAS ShowStatus command: invoked',
			[ 'app' => Application::APP_ID ],
		);

		if ( $outFmt === 'json' || $outFmt === 'json_pretty' )
		{
			$output->writeln(
				json_encode(
					[
						'app_version'       => $this->statusService->getAppVersion(),
						'db_version'        => $this->statusService->getDbVersion(),
						'trigger_privilege' => $this->triggerInitService->checkTriggerPrivilege(),
						'hash_rows'         => $this->statusService->getHashRowCount(),
						'pending_rows'      => $this->statusService->getPendingRowCount(),
						'tables'            => $this->statusService->getTableStatus(),
						'stored_procedure'  => $this->statusService->getProcedureStatus(),
						'triggers'          => $this->statusService->getTriggerStatus(),
					],
					$outFmt === 'json_pretty'
						? JSON_PRETTY_PRINT
						: 0,
				),
			);

			return Command::SUCCESS;
		}

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
					$table['ok']
						? 'OK'
						: 'MISSING',
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
				$sp['ok']
					? 'OK'
					: 'MISSING',
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
					$trigger['ok']
						? 'OK'
						: 'MISSING',
				),
			);
		}

		return Command::SUCCESS;
	}

}
