<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\IAppConfig;
use OCP\IDBConnection;
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
		private readonly IDBConnection    $db,
		private readonly MetadataService  $metadataService,
		private readonly IAppConfig       $appConfig,
		private readonly LoggerInterface  $logger,
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
		     ->setDescription( 'Display FCIAS app status and metadata index statistics' )
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

		$appVersion     = $this->getAppVersion();
		$filecacheCount = $this->getFilecacheCount();
		$metadataCount  = $this->getMetadataCount();
		$pendingStats   = $this->metadataService->getPendingStats();
		$totalPending   = array_sum( $pendingStats );

		if ( $outFmt === 'json' || $outFmt === 'json_pretty' )
		{
			$output->writeln(
				json_encode(
					[
						'app_version'      => $appVersion,
						'filecache_rows'   => $filecacheCount,
						'metadata_rows'    => $metadataCount,
						'pending_total'    => $totalPending,
						'pending_by_mode'  => $pendingStats,
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

		$output->writeln( sprintf( 'App version:            %s', $appVersion ) );
		$output->writeln( sprintf( 'Filecache entries:      %d', $filecacheCount ) );
		$output->writeln( sprintf( 'Metadata updated_at:    %d', $metadataCount ) );
		$output->writeln( sprintf( 'Pending total:          %d', $totalPending ) );

		if ( ! empty( $pendingStats ) )
		{
			$output->writeln( '' );
			$output->writeln( 'Pending by mode:' );

			foreach ( $pendingStats as $mode => $count )
			{
				$output->writeln(
					sprintf( '  %-25s %d', $mode, $count ),
				);
			}
		}

		return Command::SUCCESS;
	}

	private function getAppVersion(): string
	{

		return $this->appConfig->getValueString(
			Application::APP_ID,
			'installed_version',
			'unknown',
		);
	}

	private function getFilecacheCount(): int
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select(
			$qb->func()
			   ->count( '*', 'cnt' ),
		)
		   ->from( 'filecache' )
		;

		return (int) $qb->executeQuery()
		                ->fetchOne()
		;
	}

	private function getMetadataCount(): int
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select(
			$qb->func()
			   ->count( '*', 'cnt' ),
		)
		   ->from( MetadataService::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq(
				      MetadataService::FIELD_META_KEY,
				      $qb->createNamedParameter( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT ),
			      ),
		   )
		;

		return (int) $qb->executeQuery()
		                ->fetchOne()
		;
	}

}
