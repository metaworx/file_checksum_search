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

//  constructor

	public function __construct(
		private readonly IDBConnection   $db,
		private readonly MetadataService $metadataService,
		private readonly IAppConfig      $appConfig,
		private readonly LoggerInterface $logger,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	/** @noinspection PhpUnused */
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

	/** @noinspection PhpUnused */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int
	{
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
		$staleStats     = $this->metadataService->getStaleStats();
		$totalStale     = array_sum( $staleStats );

		if ( $outFmt === 'json' || $outFmt === 'json_pretty' )
		{
			$output->writeln(
				json_encode(
					[
						'app_version'         => $appVersion,
						'filecache_rows'      => $filecacheCount,
						'metadata_rows'       => $metadataCount,
						'pending_total'       => $totalPending,
						'pending_by_mode'     => $pendingStats,
						'untrusted_total'     => $totalStale,
						'untrusted_by_reason' => $staleStats,
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
		$output->writeln( sprintf( 'Untrusted total:        %d', $totalStale ) );

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

		if ( ! empty( $staleStats ) )
		{
			$output->writeln( '' );
			$output->writeln( 'Untrusted by reason:' );

			foreach ( $staleStats as $state => $count )
			{
				$output->writeln(
					sprintf( '  %-25s %d   %s', $state, $count, self::reasonFor( $state ) ),
				);
			}
		}

		return Command::SUCCESS;
	}


//  static methods

	/**
	 * What a reason means, in the terms an operator has to act on.
	 *
	 * The two are opposites in what they leave behind: erosion has already
	 * thrown the hashes away, a reset has not yet — which is why one heals
	 * itself and the other is waiting for something to happen.
	 */
	private static function reasonFor( string $state ): string
	{
		return match ( $state )
		{
			MetadataService::STATE_ERODED => 'hashes dropped on write, no rule maintains them; '
				. 'heals once a rule covers the file again',
			MetadataService::STATE_RESET => 'disowned by a reset; the background job clears them, '
				. 'or an import replaces them first',
			default => 'unknown reason',
		};
	}


//  getters / setters / is* / has*

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
