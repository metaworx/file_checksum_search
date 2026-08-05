<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCP\Files\File;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Facade for hash index operations.
 *
 * Delegates to focused service classes:
 * - HashCalculationService (hash computation, recalculation)
 * - PendingQueueService (deferred update queue)
 * - DuplicateService (duplicate detection, hash lookup, path resolution)
 * - FileOperationService (hash row CRUD, filecache checksum copy)
 *
 * Directly handles: index lifecycle (rebuild/purge/teardown/deploy/create/remove),
 * user resolution, pending drain orchestration.
 */
class HashIndexService
{

	public const SUPPORTED_ALGOS
		= [
			'sha1',
			'md5',
			'sha256',
			'sha512',
			'sha3-256',
			'sha3-512',
			'crc32',
		];

	public const EVENT_TYPE_WRITE  = 'write';
	public const EVENT_TYPE_CREATE = 'create';


	public static function getDefaultAlgo(): string
	{

		return self::SUPPORTED_ALGOS[0];
	}


	public function __construct(
		private IDBConnection          $db,
		private TableNameService       $tables,
		private LifecycleHandler       $lifecycleHandler,
		private HashCalculationService $hashCalc,
		private PendingQueueService    $pendingQueue,
		private DuplicateService       $duplicates,
		private FileOperationService   $fileOps,
		private IUserManager           $userManager,
		private LoggerInterface        $logger,
	) {
	}


	// ─── Index Lifecycle ───────────────────────────────────────────────


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

		$output?->writeln( '  Deleting orphaned index entries …' );
		$this->logger->debug(
			'FCIAS: rebuildIndex deleting orphaned index entries.',
			[ 'app' => Application::APP_ID ],
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
	 * Create both shadow tables if they do not exist.
	 */
	public function createTable(): void
	{

		$this->lifecycleHandler->createTables();

		$this->logger->debug(
			'FCIAS: createTable completed',
			[ 'app' => Application::APP_ID ],
		);
	}


	// ─── User Resolution ───────────────────────────────────────────────


	/**
	 * @return string[]
	 */
	public function resolveUsers( string $userScope ): array
	{

		if ( $userScope === 'all' )
		{
			$allUsers = [];

			$this->userManager->callForAllUsers(
				function (
					$user,
				) use
				(
					&
					$allUsers,
				): void
				{

					$allUsers[] = $user->getUID();
				},
			);

			return $allUsers;
		}

		$user = $this->userManager->get( $userScope );

		if ( $user === null )
		{
			$this->logger->warning(
				'FCIAS: resolveUsers — user not found.',
				[
					'app'       => Application::APP_ID,
					'userScope' => $userScope,
				],
			);

			return [];
		}

		return [ $user->getUID() ];
	}


	// ─── Delegated: Hash Calculation ───────────────────────────────────


	public function recalcFileHash(
		File   $file,
		string $algo,
		bool   $skipExisting = true,
	): array {

		return $this->hashCalc->recalcFileHash( $file, $algo, $skipExisting );
	}


	public function recalcHash(
		int    $fileId,
		string $algo,
		bool   $skipExisting = true,
	): array {

		return $this->hashCalc->recalcHash( $fileId, $algo, $skipExisting );
	}


	public function recalcAllExistingAlgos( int $fileId ): array
	{

		return $this->hashCalc->recalcAllExistingAlgos( $fileId, $this->tables, $this->db );
	}


	public function generateMissingHashes(
		string           $userId,
		string           $algo,
		?string          $pathPattern = null,
		int              $batchSize = 100,
		?OutputInterface $output = null,
	): array {

		return $this->hashCalc->generateMissingHashes( $userId, $algo, $this->rootFolder ?? throw new \RuntimeException( 'rootFolder not available' ), $pathPattern, $batchSize, $output );
	}


	// ─── Delegated: Pending Queue ──────────────────────────────────────


	public function addPending(
		int    $fileId,
		string $eventType,
	): void {

		$this->pendingQueue->addPending( $fileId, $eventType );
	}


	/**
	 * Process pending hash updates from the queue table.
	 *
	 * Fetches pending rows, recalculates hashes, deletes successes.
	 *
	 * @return array{processed: int, deleted: int}
	 */
	public function drainPending( int $limit = 50 ): array
	{

		$defaultAlgo = self::getDefaultAlgo();
		$pendingRows = $this->pendingQueue->fetchPending( $limit );

		$processed  = 0;
		$successIds = [];

		foreach ( $pendingRows as $row )
		{
			$fileId = $row['fileid'];

			try
			{
				$result = $this->recalcHash( $fileId, $defaultAlgo );

				if ( $result['success'] )
				{
					$processed ++;
					$successIds[] = $fileId;
				}
			}
			catch ( Throwable $e )
			{
				$this->logger->warning(
					'FCIAS: drainPending recalcHash failed for fileid {fileId}',
					[
						'app'       => Application::APP_ID,
						'fileId'    => $fileId,
						'exception' => $e,
					],
				);
			}
		}

		$deleted = $this->pendingQueue->deletePending( $successIds );

		return [
			'processed' => $processed,
			'deleted'   => $deleted,
		];
	}


	public function getPendingRowCount(): int
	{

		return $this->pendingQueue->getPendingRowCount();
	}


	// ─── Delegated: Duplicates & Lookups ───────────────────────────────


	/**
	 * @return array{algo: string, hash_value: string, file_count: int, fileids: int[]}[]
	 */
	public function findAllDuplicates(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = 50,
		int     $offset = 0,
	): array {

		return $this->duplicates->findAllDuplicates( $algo, $minCount, $limit, $offset );
	}


	/**
	 * @param  int[]        $fileIds
	 *
	 * @return array<int, array{path: string, name: string, storage_id: string, user: string}>
	 */
	public function batchLookupFilecachePaths(
		array   $fileIds,
		?string $userName = null,
	): array {

		return $this->duplicates->batchLookupFilecachePaths( $fileIds, $userName );
	}


	/**
	 * @return array<int, array{fileid: int, algo: string, hash_value: string, path: string, name: string}>
	 */
	public function findByHash(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
	): array {

		return $this->duplicates->findByHash( $hash, $algo, $limit );
	}


	// ─── Delegated: File Operations ────────────────────────────────────


	public function copyHashes(
		int $sourceFileId,
		int $targetFileId,
	): void {

		$this->fileOps->copyHashes( $sourceFileId, $targetFileId );
	}


	public function deleteHashes( int $fileId ): int
	{

		return $this->fileOps->deleteHashes( $fileId );
	}


	public function countHashes( int $fileId ): int
	{

		return $this->fileOps->countHashes( $fileId );
	}


	public function copyFilecacheChecksum(
		File $source,
		File $target,
	): void {

		$this->fileOps->copyFilecacheChecksum( $source, $target );
	}

}
