<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestPerformance
	extends
	Command
{

	private IDBConnection $db;

	private TableNameService $tables;


	public function __construct( IDBConnection $db, TableNameService $tables )
	{

		parent::__construct();
		$this->db     = $db;
		$this->tables = $tables;
	}


	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:test-perf' )
		     ->setDescription( 'Benchmark checksum lookup performance' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$fcTable   = $this->tables->getFilecacheTableName();
		$hashTable = $this->tables->getHashTableName();
		$spName    = $this->tables->getSpName();

		$testFileId = 999999999;
		$testChecksum
		            = 'sha1:deadbeef0123456789abcdef0123456789abcdef sha256:abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';
		$testHash   = 'deadbeef0123456789abcdef0123456789abcdef';

		$output->writeln( '=== FCIAS Performance Benchmark ===' );
		$output->writeln( '' );

		try
		{
			// 1. Insert test row into filecache (without trigger firing for simplicity)
			// Use raw INSERT to measure trigger overhead
			$startInsert = microtime( true );
			$this->db->executeStatement(
				"INSERT INTO `{$fcTable}` (fileid, checksum) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)",
				[
					$testFileId,
					$testChecksum,
				],
			);
			$insertTime = ( microtime( true ) - $startInsert ) * 1000;

			// Wait a moment for triggers
			usleep( 10000 );

			// Read back from shadow table
			$qb = $this->db->getQueryBuilder();
			$qb->select(
				$qb->func()
				   ->count( '*', 'cnt' ),
			)
			   ->from( 'file_checksum_search_hashes' )
			   ->where(
				   $qb->expr()
				      ->eq( 'fileid', $qb->createNamedParameter( $testFileId, \PDO::PARAM_INT ) ),
			   )
			;
			$shadowCount = (int) $qb->executeQuery()
			                        ->fetchOne()
			;

			$output->writeln( sprintf( 'Trigger insert overhead: %.2f ms', $insertTime ) );
			$output->writeln( sprintf( 'Shadow rows created: %d', $shadowCount ) );
			$output->writeln( '' );

			// 2. Benchmark indexed lookup
			$startIndexed = microtime( true );

			for ( $i = 0; $i < 100; $i ++ )
			{
				$qb = $this->db->getQueryBuilder();
				$qb->select( 'fileid' )
				   ->from( 'file_checksum_search_hashes' )
				   ->where(
					   $qb->expr()
					      ->eq( 'hash_value', $qb->createNamedParameter( $testHash ) ),
				   )
				;
				$qb->executeQuery()
				   ->fetchAll()
				;
			}
			$indexedTime = ( microtime( true ) - $startIndexed ) * 1000;

			// 3. Benchmark unindexed LIKE scan on filecache
			$startUnindexed = microtime( true );

			for ( $i = 0; $i < 100; $i ++ )
			{
				$qb = $this->db->getQueryBuilder();
				$qb->select( 'fileid' )
				   ->from( 'filecache' )
				   ->where(
					   $qb->expr()
					      ->like( 'checksum', $qb->createNamedParameter( '%' . $testHash . '%' ) ),
				   )
				;
				$qb->executeQuery()
				   ->fetchAll()
				;
			}
			$unindexedTime = ( microtime( true ) - $startUnindexed ) * 1000;

			$output->writeln(
				sprintf( 'Indexed lookup x100:   %.2f ms (%.2f ms/query)', $indexedTime, $indexedTime / 100 ),
			);
			$output->writeln(
				sprintf( 'LIKE scan x100:        %.2f ms (%.2f ms/query)', $unindexedTime, $unindexedTime / 100 ),
			);
			$output->writeln( sprintf( 'Speedup:               %.1fx', $unindexedTime / max( $indexedTime, 0.001 ) ) );
			$output->writeln( '' );

			// 4. Cleanup
			$this->db->executeStatement( "DELETE FROM `{$fcTable}` WHERE fileid = ?", [ $testFileId ] );
			$this->db->executeStatement( "DELETE FROM `{$hashTable}` WHERE fileid = ?", [ $testFileId ] );
			$output->writeln( 'Test data cleaned up.' );

		}
		finally
		{
			// Ensure cleanup even on error
			$this->db->executeStatement( "DELETE FROM `{$fcTable}` WHERE fileid = ?", [ $testFileId ] );
			$this->db->executeStatement( "DELETE FROM `{$hashTable}` WHERE fileid = ?", [ $testFileId ] );
		}

		return Command::SUCCESS;
	}

}
