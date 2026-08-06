<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class TestPerformance
	extends
	Command
{

	public function __construct(
		private readonly IDBConnection   $db,
		private readonly MetadataService $metadataService,
	) {

		parent::__construct();
	}

	/**
	 * Configure the test-perf command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:test-perf' )
		     ->setDescription( 'Benchmark metadata index hash lookup vs filecache LIKE scan' )
		;
	}

	/**
	 * Execute the test-perf command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$testHash = 'deadbeef0123456789abcdef0123456789abcdef';

		$output->writeln( '=== FCIAS Performance Benchmark ===' );
		$output->writeln( '' );
		$output->writeln( 'Comparing oc_files_metadata_index lookup vs filecache LIKE scan.' );
		$output->writeln( '' );

		// 1. Benchmark indexed lookup via MetadataService
		$startIndexed = microtime( true );

		for ( $i = 0; $i < 100; $i ++ )
		{
			$this->metadataService->queryByHash( $testHash, null, 1 );
		}
		$indexedTime = ( microtime( true ) - $startIndexed ) * 1000;

		// 2. Benchmark unindexed LIKE scan on filecache
		$startUnindexed = microtime( true );

		for ( $i = 0; $i < 100; $i ++ )
		{
			$qb = $this->db->getQueryBuilder();
			$qb->select( 'fileid' )
			   ->from( 'filecache' )
			   ->where(
				   $qb->expr()
				      ->like(
					      'checksum',
					      $qb->createNamedParameter( '%' . $testHash . '%' ),
				      ),
			   )
			   ->setMaxResults( 1 )
			;
			$qb->executeQuery()
			   ->fetchAll()
			;
		}
		$unindexedTime = ( microtime( true ) - $startUnindexed ) * 1000;

		$output->writeln(
			sprintf(
				'Metadata index lookup x100:  %.2f ms (%.2f ms/query)',
				$indexedTime,
				$indexedTime / 100,
			),
		);
		$output->writeln(
			sprintf(
				'Filecache LIKE scan x100:     %.2f ms (%.2f ms/query)',
				$unindexedTime,
				$unindexedTime / 100,
			),
		);
		$output->writeln(
			sprintf(
				'Speedup:                      %.1fx',
				$unindexedTime / max( $indexedTime, 0.001 ),
			),
		);
		$output->writeln( '' );

		// 3. Get counts for reference
		$qb = $this->db->getQueryBuilder();
		$qb->select(
			$qb->func()
			   ->count( '*', 'cnt' ),
		)
		   ->from( 'filecache' )
		;
		$filecacheRows = (int) $qb->executeQuery()
		                          ->fetchOne()
		;

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
		$metadataRows = (int) $qb->executeQuery()
		                         ->fetchOne()
		;

		$output->writeln( sprintf( 'Filecache entries:            %d', $filecacheRows ) );
		$output->writeln( sprintf( 'Metadata updated_at entries:  %d', $metadataRows ) );

		return Command::SUCCESS;
	}

}
