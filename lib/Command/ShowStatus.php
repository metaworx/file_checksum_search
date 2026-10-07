<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\StatusService;
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
		private readonly StatusService   $status,
		private readonly LoggerInterface $logger,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	/** @noinspection PhpUnused */
	#[\Override]
	protected function configure(): void
	{
		$this->setName( 'file-checksum-search:status' )
		     ->setAliases( [ 'fcias:status' ] )
		     ->setDescription( 'Display FCIAS app status, metadata index statistics and the background jobs\' last runs' )
		     ->addOption(
			     'output',
			     'o',
			     InputOption::VALUE_REQUIRED,
			     'Output format: plain, json, json_pretty (default: plain)',
			     'plain',
		     )
		     ->addOption(
			     'full',
			     null,
			     InputOption::VALUE_NONE,
			     'Also count the filecache entries and the stamp rows, and count the indexed checksums anew. '
			     . 'Each reads a whole table or index, which takes up to a minute on a large instance whose cache has gone cold.',
		     )
		;
	}

	/** @noinspection PhpUnused */
	#[\Override]
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

		// The filecache and stamp counts read a whole table or index each: 52 s
		// and 7 s cold on one large instance, for figures an operator rarely
		// needs. Only when asked; the checksum count is the kept one unless so.
		$full           = (bool) $input->getOption( 'full' );
		$appVersion     = $this->getAppVersion();
		$checksums      = $this->status->getHashRowCount( $full );
		$filecacheCount = $full ? $this->getFilecacheCount() : null;
		$metadataCount  = $full ? $this->getMetadataCount() : null;
		$pendingStats   = $this->metadataService->getPendingStats();
		$totalPending   = array_sum( $pendingStats );
		$pendingFailed  = $this->metadataService->countFailingQueued();
		$staleStats     = $this->metadataService->getStaleStats();
		$totalStale     = array_sum( $staleStats );
		$jobs           = $this->status->getListedJobs();

		if ( $outFmt === 'json' || $outFmt === 'json_pretty' )
		{
			$output->writeln(
				json_encode(
					[
						'app_version'      => $appVersion,
						'checksum_rows'    => $checksums['rows'],
						'checksum_rows_at' => $checksums['at'],
					]
					+ ( $full
						? [
							'filecache_rows' => $filecacheCount,
							'metadata_rows'  => $metadataCount,
						]
						: [] )
					+ [
						'pending_total'       => $totalPending,
						'pending_by_mode'     => $pendingStats,
						'pending_failed'      => $pendingFailed,
						'untrusted_total'     => $totalStale,
						'untrusted_by_reason' => $staleStats,
						'jobs'                => $jobs,
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
		$output->writeln(
			sprintf(
				'Indexed checksums:      %d   (counted %s)',
				$checksums['rows'],
				date( 'Y-m-d H:i:s T', $checksums['at'] ),
			),
		);
		$output->writeln(
			$filecacheCount === null
				? 'Filecache entries:      (with --full)'
				: sprintf( 'Filecache entries:      %d', $filecacheCount ),
		);
		$output->writeln(
			$metadataCount === null
				? 'Metadata updated_at:    (with --full)'
				: sprintf( 'Metadata updated_at:    %d', $metadataCount ),
		);
		$output->writeln( sprintf( 'Pending total:          %d', $totalPending ) );

		if ( $pendingFailed > 0 )
		{
			$output->writeln(
				sprintf( '  failed at least once:   %d   (they wait behind the rest)', $pendingFailed ),
			);
		}

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

		$output->writeln( '' );
		$output->writeln( 'Background jobs:' );

		foreach ( $jobs as $job => $run )
		{
			// The last attempt's time where one was recorded: a run that
			// failed is the newest thing to know about the job.
			$attempt = $run['attempt'];
			$at      = $attempt['at'] ?? $run['lastRun'];

			$output->writeln(
				rtrim(
					sprintf(
						'  %-25s %-25s %s',
						JobStatsService::LABELS[ $job ] ?? $job,
						$at === null
							? 'never ran yet'
							: date( 'Y-m-d H:i:s T', $at ),
						$attempt !== null && ! $attempt['ok']
							? sprintf(
							'FAILED%s: %s; last success %s',
							self::took( $attempt['durationMs'], ' after ' ),
							$attempt['reason'] ?? 'unknown',
							$run['lastRun'] === null
								? 'never'
								: date( 'Y-m-d H:i:s T', $run['lastRun'] ),
						)
							: implode(
								', ',
								array_map(
									static fn (
										string     $name,
										int|string $value,
									): string => $name . ' ' . $value,
									array_keys( $run['counts'] ),
									$run['counts'],
								),
							)
							. self::took( $attempt['durationMs'] ?? null, '   (took ', ')' ),
					),
				),
			);
		}

		return Command::SUCCESS;
	}


//  static methods

	/**
	 * A run's duration between $before and $after, or nothing for a run
	 * whose duration was not recorded.
	 */
	private static function took(
		?int   $durationMs,
		string $before,
		string $after = '',
	): string
	{
		if ( $durationMs === null )
		{
			return '';
		}

		return $before
			. ( $durationMs < 1000
				? sprintf( '%d ms', $durationMs )
				: sprintf( '%.1f s', $durationMs / 1000 ) )
			. $after;
	}

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
