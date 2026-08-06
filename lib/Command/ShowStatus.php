<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\CronJobService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TriggerInitializationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class ShowStatus
	extends
	Command
{

	public function __construct(
		private readonly StatusService                $statusService,
		private readonly TriggerInitializationService $triggerInitService,
		private readonly CronJobService               $cronJobService,
		private readonly LoggerInterface              $logger,
	) {

		parent::__construct();
	}


	/**
	 * Configure the status command.
	 *
	 * @noinspection PhpUnused
	 */
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


	/**
	 * Execute the status command.
	 *
	 * @noinspection PhpUnused
	 */
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
						'db_version'        => $this->statusService->getDbVersion( $output ),
						'trigger_privilege' => $this->triggerInitService->checkTriggerPrivilege(),
						'hash_rows'         => $this->statusService->getHashRowCount( $output ),
						'pending_rows'      => $this->statusService->getPendingRowCount( $output ),
						'tables'            => $this->statusService->getTableStatus( $output ),
						'stored_procedure'  => $this->statusService->getProcedureStatus( $output ),
						'triggers'          => $this->statusService->getTriggerStatus( $output ),
						'migrations'        => $this->statusService->getMigrationStatus( $output ),
						'cron_jobs'         => $this->cronJobService->listDefinitions(),
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

		$output->writeln( sprintf( 'MariaDB version: %s', $this->statusService->getDbVersion( $output ) ) );

		$hasTrigger = $this->triggerInitService->checkTriggerPrivilege();
		$output->writeln(
			sprintf(
				'TRIGGER priv:    %s',
				$hasTrigger
					? 'OK'
					: 'MISSING',
			),
		);

		$output->writeln( sprintf( 'Hash rows:       %d', $this->statusService->getHashRowCount( $output ) ) );
		$output->writeln( sprintf( 'Pending rows:    %d', $this->statusService->getPendingRowCount( $output ) ) );

		$output->writeln( '' );
		$output->writeln( 'Tables:' );

		foreach ( $this->statusService->getTableStatus( $output ) as $table )
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

		$sp = $this->statusService->getProcedureStatus( $output );
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

		foreach ( $this->statusService->getTriggerStatus( $output ) as $trigger )
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

		$output->writeln( '' );
		$output->writeln( 'Migrations:' );

		foreach ( $this->statusService->getMigrationStatus( $output ) as $migration )
		{
			$output->writeln(
				sprintf(
					'  %-45s %s',
					$migration['name'],
					$migration['ok']
						? 'OK'
						: 'MISSING',
				),
			);
		}

		$cronJobs = $this->cronJobService->listDefinitions();

		$output->writeln( '' );
		$output->writeln(
			sprintf(
				'Cron Jobs (%d CronGenerateHashes definition%s):',
				count( $cronJobs ),
				count( $cronJobs ) === 1
					? ''
					: 's',
			),
		);

		if ( empty( $cronJobs ) )
		{
			$output->writeln( '  (none)' );
		}
		else
		{
			foreach ( $cronJobs as $job )
			{
				$enabled = ! empty( $job['enabled'] );
				$output->writeln(
					sprintf(
						'  %-45s %s (path=%s)',
						sprintf(
							'#%s %s/%s/%ds',
							$job['id'] ?? '?',
							$job['userScope'] ?? '?',
							$job['algo'] ?? '?',
							(int) ( $job['interval'] ?? 0 ),
						),
						$enabled
							? 'enabled'
							: 'disabled',
						$job['path'] ?? '?',
					),
				);
			}
		}

		return Command::SUCCESS;
	}

}
