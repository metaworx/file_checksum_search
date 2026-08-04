<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared business logic for hash index operations.
 *
 * Used by both CLI commands and the SettingsController HTTP API
 * to eliminate duplication of rebuild/purge/teardown/remove logic.
 */
readonly class HashIndexService
{

	public function __construct(
		private IDBConnection    $db,
		private TableNameService $tables,
		private LifecycleHandler $lifecycleHandler,
		private IRootFolder      $rootFolder,
		private LoggerInterface  $logger,
	) {
	}


	/**
	 * Rebuild the checksum hash index from filecache checksums.
	 *
	 * @return array{total: int, processed: int}
	 */
	public function rebuildIndex( ?OutputInterface $output = null ): array
	{

		$spName    = $this->tables->getSpName();
		$hashTable = $this->tables->getHashTableName();
		$fcTable   = $this->tables->getFilecacheTableName();

		// Clean up orphaned entries: files whose checksum was cleared
		// but still have stale rows in the hash table

		$output?->writeln( '  Deleting orphaned index entries …' );
		$this->logger->debug(
			'FCIAS: rebuildIndex deleting orphaned index entries.',
			[
				'app' => Application::APP_ID,
			],
		);

		$deleted = $this->db->executeStatement(
			"DELETE FROM `{$hashTable}` WHERE `fileid` NOT IN (SELECT `fileid` FROM `{$fcTable}` WHERE `checksum` IS NOT NULL AND `checksum` != '')",
		);

		if ( $deleted > 0 )
		{
			$output?->writeln( sprintf( '  Deleted %d orphaned index entries.', $deleted ) );
			$this->logger->debug(
				'FCIAS: rebuildIndex cleaned up orphaned entries',
				[
					'app'     => Application::APP_ID,
					'deleted' => $deleted,
				],
			);
		}

		$countQb = $this->db->getQueryBuilder();
		$countQb->select(
			$countQb->func()
			        ->count( '*', 'total' ),
		)
		        ->from( 'filecache' )
		        ->where(
			        $countQb->expr()
			                ->isNotNull( 'checksum' ),
			        $countQb->expr()
			                ->neq( 'checksum', $countQb->createNamedParameter( '' ) ),
		        )
		;
		$total = (int) $countQb->executeQuery()
		                       ->fetchOne()
		;

		$selectQb = $this->db->getQueryBuilder();
		$selectQb->select( 'fileid', 'checksum' )
		         ->from( 'filecache' )
		         ->where(
			         $selectQb->expr()
			                  ->isNotNull( 'checksum' ),
			         $selectQb->expr()
			                  ->neq( 'checksum', $selectQb->createNamedParameter( '' ) ),
		         )
		;

		$rows      = $selectQb->executeQuery();
		$processed = 0;
		$statement = $this->db->prepare( "CALL `{$spName}`(?, ?)" );

		$output?->writeln( sprintf( '  Processing %d files …', $total ) );
		$this->logger->debug(
			'FCIAS: rebuildIndex processing filecache entries.',
			[
				'app'   => Application::APP_ID,
				'total' => $total,
			],
		);

		while ( ( $row = $rows->fetch() ) !== false )
		{
			$statement->execute(
				[
					(int) $row['fileid'],
					$row['checksum'],
				],
			);
			$processed ++;

			if ( $processed % 1000 === 0 )
			{
				$output?->writeln( sprintf( '  %d / %d files processed …', $processed, $total ) );

				$this->logger->debug(
					'FCIAS: rebuildIndex processing filecache entries.',
					[
						'app'       => Application::APP_ID,
						'total'     => $total,
						'processed' => $processed,
					],
				);
			}
		}
		$rows->closeCursor();

		$this->logger->debug(
			'FCIAS: rebuildIndex completed',
			[
				'app'       => Application::APP_ID,
				'total'     => $total,
				'processed' => $processed,
			],
		);

		return [
			'total'     => $total,
			'processed' => $processed,
		];
	}


	/**
	 * Truncate the checksum hash index table.
	 *
	 * @return array{before: int, after: int}
	 */
	public function purgeIndex(): array
	{

		$hashTable = $this->tables->getHashTableName();

		$before = (int) $this->db->executeQuery( "SELECT COUNT(*) FROM `{$hashTable}`" )
		                         ->fetchOne()
		;
		$this->db->executeStatement( "TRUNCATE TABLE `{$hashTable}`" );
		$after = (int) $this->db->executeQuery( "SELECT COUNT(*) FROM `{$hashTable}`" )
		                        ->fetchOne()
		;

		$this->logger->debug(
			'FCIAS: purgeIndex completed',
			[
				'app'    => Application::APP_ID,
				'before' => $before,
				'after'  => $after,
			],
		);

		return [
			'before' => $before,
			'after'  => $after,
		];
	}


	/**
	 * Remove SP + triggers, preserve shadow table and data.
	 */
	public function teardownTriggers(): void
	{

		$this->lifecycleHandler->stripTriggers();

		$this->logger->debug(
			'FCIAS: teardownTriggers completed',
			[ 'app' => Application::APP_ID ],
		);
	}


	/**
	 * Full cleanup: strip triggers + drop shadow table.
	 */
	public function removeTable(): void
	{

		$this->lifecycleHandler->purgeShadowTable();

		$this->logger->debug(
			'FCIAS: removeTable completed',
			[ 'app' => Application::APP_ID ],
		);
	}


	/**
	 * Deploy SP + 3 triggers. Idempotent — uses DROP IF EXISTS
	 * before CREATE, so it is safe to call even when triggers
	 * already exist.
	 */
	public function deployTriggers(): void
	{

		$this->lifecycleHandler->deployTriggers();

		$this->logger->debug(
			'FCIAS: deployTriggers completed',
			[ 'app' => Application::APP_ID ],
		);
	}


	/**
	 * Create the hash table if it does not exist.
	 *
	 * Mirrors the schema from Version010000Date20260731000000.
	 */
	public function createTable(): void
	{

		$hashTable = $this->tables->getHashTableName();

		$this->db->executeStatement(
			<<<SQL
CREATE TABLE IF NOT EXISTS `{$hashTable}` (
	   `fileid`     BIGINT UNSIGNED NOT NULL,
	   `algo`       VARCHAR(10) NOT NULL,
	   `hash_value` VARCHAR(64) NOT NULL,
	   PRIMARY KEY (`fileid`, `algo`),
	   INDEX `idx_fcias_hash_lookup` (`hash_value`, `algo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
SQL,
		);

		$this->logger->debug(
			'FCIAS: createTable completed',
			[ 'app' => Application::APP_ID ],
		);
	}

}
