<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Shared business logic for hash index operations.
 *
 * Used by CLI commands, SettingsController, CronGenerateHashes,
 * and other service classes.
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
		private IDBConnection    $db,
		private TableNameService $tables,
		private LifecycleHandler $lifecycleHandler,
		private IRootFolder      $rootFolder,
		private IUserManager     $userManager,
		private ILockingProvider $lockingProvider,
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


	/**
	 * Compute a hash for a File node if it does not already exist
	 * in the filecache, then write it back.
	 *
	 * If the checksum already exists for this algo, it is returned
	 * without recomputation.  For forced recalculation, use
	 * {@see forceRecalcFileHash()}.
	 *
	 * @return array{success: bool, algo: string, hash: string, existed: bool}
	 */
	public function recalcFileHash(
		File   $file,
		string $algo,
		bool   $skipExisting = true,
	): array {

		$algo = strtolower( $algo );

		if ( ! in_array( $algo, self::SUPPORTED_ALGOS, true ) )
		{
			return [
				'success' => false,
				'algo'    => $algo,
				'hash'    => '',
				'existed' => false,
				'error'   => 'Unsupported algorithm: ' . $algo,
			];
		}

		$existingChecksum = $file->getChecksum() ?? '';
		$prefix           = strtoupper( $algo ) . ':';

		if ( $skipExisting )
		{
			foreach ( explode( ' ', $existingChecksum ) as $pair )
			{
				if ( str_starts_with( $pair, $prefix ) )
				{
					return [
						'success' => true,
						'algo'    => $algo,
						'hash'    => substr( $pair, strlen( $prefix ) ),
						'existed' => true,
					];
				}
			}
		}

		$fileId = $file->getId();

		if ( ! $this->acquireLock( $fileId ) )
		{
			return [
				'success' => false,
				'algo'    => $algo,
				'hash'    => '',
				'existed' => false,
				'locked'  => true,
			];
		}

		try
		{
			$storage = $file->getStorage();

			if ( $storage->isLocal() )
			{
				$absolutePath = $storage->getLocalFile( $file->getInternalPath() );
				$hash         = hash_file( $algo, $absolutePath );
			}
			else
			{
				$handle = $file->fopen( 'rb' );
				$ctx    = hash_init( $algo );
				hash_update_stream( $ctx, $handle );
				fclose( $handle );
				$hash = hash_final( $ctx );
			}

			$formattedChecksum = strtoupper( $algo ) . ':' . $hash;
			$newChecksum       = $existingChecksum === ''
				? $formattedChecksum
				: $existingChecksum . ' ' . $formattedChecksum;

			$cache = $storage->getCache();
			$cache->update( $fileId, [ 'checksum' => $newChecksum ] );

			return [
				'success' => true,
				'algo'    => $algo,
				'hash'    => $hash,
				'existed' => false,
			];
		}
		finally
		{
			$this->releaseLock( $fileId );
		}
	}


	public function recalcHash(
		int    $fileId,
		string $algo,
		bool   $skipExisting = true,
	): array {

		$algo = strtolower( $algo );

		$nodes = $this->rootFolder->getById( $fileId );

		if ( empty( $nodes ) )
		{
			return [
				'success' => false,
				'algo'    => $algo,
				'hash'    => '',
				'existed' => false,
				'error'   => 'File not found.',
			];
		}

		$node = $nodes[0];

		if ( ! $node instanceof File )
		{
			return [
				'success' => false,
				'algo'    => $algo,
				'hash'    => '',
				'existed' => false,
				'error'   => 'Node is not a file.',
			];
		}

		return $this->recalcFileHash( $node, $algo, $skipExisting );
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
	 *
	 * Delegates to LifecycleHandler for SQL.
	 */
	public function createTable(): void
	{

		$this->lifecycleHandler->createTables();

		$this->logger->debug(
			'FCIAS: createTable completed',
			[ 'app' => Application::APP_ID ],
		);
	}


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


	/**
	 * Two-phase hash generation: collect files needing hashes,
	 * then process them. Avoids interleaving reads and writes
	 * to oc_filecache (dirty reads in NC v33 debug mode).
	 *
	 * @return array{processed: int, skipped: int}
	 */
	public function generateMissingHashes(
		string           $userId,
		string           $algo,
		?string          $pathPattern = null,
		int              $batchSize = 100,
		?OutputInterface $output = null,
	): array {

		$userFolder     = $this->rootFolder->getUserFolder( $userId );
		$userFolderPath = $userFolder->getPath();

		// Phase 1: collect
		$files = [];
		$this->collectFilesForUser(
			$userFolderPath,
			$userId,
			$algo,
			$pathPattern,
			$userFolderPath,
			$files,
			$batchSize,
		);

		$collected = count( $files );

		if ( $collected > 0 )
		{
			$this->logger->debug(
				'FCIAS: generateMissingHashes collected {count} files.',
				[
					'app'    => Application::APP_ID,
					'userId' => $userId,
					'algo'   => $algo,
					'count'  => $collected,
				],
			);

			$output?->writeln(
				sprintf(
					'  Collected %d files without %s checksums.',
					$collected,
					$algo,
				),
			);
		}

		// Phase 2: process
		$processed = 0;
		$skipped   = 0;

		foreach ( $files as $file )
		{
			try
			{
				$result = $this->recalcFileHash( $file, $algo );

				if ( $result['locked'] ?? false )
				{
					$skipped ++;
				}
				elseif ( $result['existed'] )
				{
					$skipped ++;
				}
				else
				{
					$processed ++;

					if ( $processed % 10 == 0 && $output !== null )
					{
						$output->writeln(
							sprintf( '    %d files processed …', $processed ),
						);
					}
				}
			}
			catch ( Throwable $e )
			{
				$this->logger->error(
					'FCIAS: recalcFileHash failed in generateMissingHashes',
					[
						'app'       => Application::APP_ID,
						'fileId'    => $file->getId(),
						'algo'      => $algo,
						'exception' => $e,
					],
				);

				$output?->warning(
					sprintf(
						'  WARNING: recalcFileHash failed for fileId %d: %s',
						$file->getId(),
						$e->getMessage(),
					),
				);

				continue;
			}
		}

		return [
			'processed' => $processed,
			'skipped'   => $skipped,
		];
	}


	private function collectFilesForUser(
		string  $folderPath,
		string  $userId,
		string  $algo,
		?string $pathPattern,
		string  $userFolderPath,
		array   &$collected,
		int     $batchSize,
	): void {

		if ( count( $collected ) >= $batchSize )
		{
			return;
		}

		$userFolder = $this->rootFolder->getUserFolder( $userId );
		$relPath    = $this->relativeHashPath( $folderPath, $userFolderPath );

		try
		{
			$node = $userFolder->get( $relPath );
		}
		catch ( NotFoundException )
		{
			return;
		}

		if ( ! $node instanceof Folder )
		{
			return;
		}

		$prefix = strtoupper( $algo ) . ':';

		foreach ( $node->getDirectoryListing() as $child )
		{
			if ( count( $collected ) >= $batchSize )
			{
				return;
			}

			if ( $child instanceof Folder )
			{
				$this->collectFilesForUser(
					$child->getPath(),
					$userId,
					$algo,
					$pathPattern,
					$userFolderPath,
					$collected,
					$batchSize,
				);

				continue;
			}

			if ( ! ( $child instanceof File ) )
			{
				continue;
			}

			$relativePath = $this->relativeHashPath(
				$child->getPath(),
				$userFolderPath,
			);

			if ( $pathPattern !== null && ! fnmatch( $pathPattern, $relativePath ) )
			{
				continue;
			}

			$existingChecksum = $child->getChecksum() ?? '';
			$alreadyHas       = false;

			foreach ( explode( ' ', $existingChecksum ) as $pair )
			{
				if ( str_starts_with( $pair, $prefix ) )
				{
					$alreadyHas = true;

					break;
				}
			}

			if ( ! $alreadyHas )
			{
				$collected[] = $child;
			}
		}
	}


	private function relativeHashPath(
		string $path,
		string $basePath,
	): string {

		$basePath = rtrim( $basePath, '/' );

		if ( $path === $basePath )
		{
			return '';
		}

		$basePath .= '/';

		if ( str_starts_with( $path, $basePath ) )
		{
			return substr( $path, strlen( $basePath ) );
		}

		return ltrim( $path, '/' );
	}


	/**
	 * Queue a file for deferred hash recalculation.
	 *
	 * INSERT IGNORE ensures duplicate events for the same fileid
	 * within the drain interval are silently dropped (debounce).
	 */
	public function addPending(
		int    $fileId,
		string $eventType,
	): void {

		$pendingTable = $this->tables->getPendingTableName();

		$this->db->executeStatement(
			"INSERT IGNORE INTO `{$pendingTable}` (`fileid`, `created_at`, `event_type`) VALUES (?, UNIX_TIMESTAMP(), ?)",
			[
				$fileId,
				$eventType,
			],
		);
	}


	/**
	 * Process pending hash updates from the queue table.
	 *
	 * For each pending row, recalculates the default algo hash.
	 * Processed rows are deleted from the queue.
	 *
	 * @return array{processed: int, deleted: int}
	 */
	public function drainPending( int $limit = 50 ): array
	{

		$defaultAlgo = self::getDefaultAlgo();

		$selectQb = $this->db->getQueryBuilder();
		$selectQb->select( 'fileid', 'event_type' )
		         ->from( TableNameService::TABLE_FILE_CHECKSUM_SEARCH_PENDING )
		         ->orderBy( 'created_at', 'ASC' )
		         ->setMaxResults( $limit )
		;

		$rows       = $selectQb->executeQuery();
		$processed  = 0;
		$successIds = [];

		while ( ( $row = $rows->fetch() ) !== false )
		{
			$fileId = (int) $row['fileid'];

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
		$rows->closeCursor();

		$deleted = 0;

		if ( ! empty( $successIds ) )
		{
			$deleteQb = $this->db->getQueryBuilder();
			$deleteQb->delete( TableNameService::TABLE_FILE_CHECKSUM_SEARCH_PENDING )
			         ->where(
				         $deleteQb->expr()
				                  ->in(
					                  'fileid',
					                  $deleteQb->createNamedParameter( $successIds, IQueryBuilder::PARAM_INT_ARRAY ),
				                  ),
			         )
			;
			$deleted = $deleteQb->executeStatement();
		}

		return [
			'processed' => $processed,
			'deleted'   => $deleted,
		];
	}


	/**
	 * Copy all hash rows from a source file to a target file.
	 *
	 * Used by NodeCopiedEvent — identical content, identical hashes.
	 */
	public function copyHashes(
		int $sourceFileId,
		int $targetFileId,
	): void {

		$hashTable = $this->tables->getHashTableName();

		$this->db->executeStatement(
			"INSERT IGNORE INTO `{$hashTable}` (`fileid`, `algo`, `hash_value`) SELECT ?, `algo`, `hash_value` FROM `{$hashTable}` WHERE `fileid` = ?",
			[
				$targetFileId,
				$sourceFileId,
			],
		);
	}


	/**
	 * Delete all hash rows for a given fileid.
	 *
	 * @return int Number of deleted rows
	 */
	public function deleteHashes( int $fileId ): int
	{

		$hashTable = $this->tables->getHashTableName();

		return $this->db->executeStatement(
			"DELETE FROM `{$hashTable}` WHERE `fileid` = ?",
			[ $fileId ],
		);
	}


	/**
	 * Count hash rows for a given fileid.
	 */
	public function countHashes( int $fileId ): int
	{

		$hashTable = $this->tables->getHashTableName();

		return (int) $this->db->executeQuery(
			"SELECT COUNT(*) FROM `{$hashTable}` WHERE `fileid` = ?",
			[ $fileId ],
		)
		                      ->fetchOne()
		;
	}


	/**
	 * Recalculate all currently-indexed algos for a file.
	 *
	 * If no prior hash rows exist, falls back to the default algo.
	 *
	 * @return array{processed: int, algos: string[]}
	 */
	public function recalcAllExistingAlgos( int $fileId ): array
	{

		$hashTable = $this->tables->getHashTableName();

		$rows = $this->db->executeQuery(
			"SELECT DISTINCT `algo` FROM `{$hashTable}` WHERE `fileid` = ?",
			[ $fileId ],
		);

		$algos = [];

		while ( ( $row = $rows->fetch() ) !== false )
		{
			$algos[] = $row['algo'];
		}
		$rows->closeCursor();

		if ( empty( $algos ) )
		{
			$algos = [ self::getDefaultAlgo() ];
		}

		$processed = 0;
		$locked    = false;

		foreach ( $algos as $algo )
		{
			$result = $this->recalcHash( $fileId, $algo );

			if ( $result['success'] )
			{
				$processed ++;
			}
			elseif ( $result['locked'] ?? false )
			{
				$locked = true;
			}
		}

		return [
			'processed' => $processed,
			'algos'     => $algos,
			'locked'    => $locked,
		];
	}


	/**
	 * Copy the filecache checksum from source to target file.
	 *
	 * Used by NodeCopiedEvent. Updating the target's checksum triggers
	 * the AFTER UPDATE trigger on oc_filecache, which in turn calls the
	 * fcias_parse_file_hashes SP to populate the hash table.
	 */
	public function copyFilecacheChecksum(
		File $source,
		File $target,
	): void {

		$checksum = $source->getChecksum();

		if ( $checksum === null || $checksum === '' )
		{
			return;
		}

		$targetStorage = $target->getStorage();
		$targetCache   = $targetStorage->getCache();
		$targetCache->update( $target->getId(), [ 'checksum' => $checksum ] );
	}


	/**
	 * Count pending rows in the queue table.
	 */
	public function getPendingRowCount(): int
	{

		$pendingTable = $this->tables->getPendingTableName();

		try
		{
			return (int) $this->db->executeQuery( "SELECT COUNT(*) FROM `{$pendingTable}`" )
			                      ->fetchOne()
			;
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS: pending row count query failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return 0;
		}
	}


	/**
	 * Find all duplicate hash groups across the entire system.
	 *
	 * Groups files by (algo, hash_value) where more than one file shares
	 * the same hash.  Returns raw fileid lists — path resolution and
	 * access filtering are the caller's responsibility.
	 *
	 * @param  string|null  $algo      Optional algorithm filter
	 * @param  int          $minCount  Minimum files per group (default 2)
	 * @param  int          $limit     Max groups to return
	 * @param  int          $offset    Pagination offset
	 *
	 * @return array{algo: string, hash_value: string, file_count: int, fileids: int[]}[]
	 */
	public function findAllDuplicates(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = 50,
		int     $offset = 0,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select( 'algo', 'hash_value' )
		   ->selectAlias(
			   $qb->func()
			      ->count( 'fileid' ),
			   'cnt',
		   )
		   ->selectAlias(
			   $qb->func()
			      ->groupConcat( 'fileid' ),
			   'fileids',
		   )
		   ->from( TableNameService::TABLE_FILE_CHECKSUM_SEARCH_HASHES, 'h' )
		   ->groupBy( 'algo' )
		   ->addGroupBy( 'hash_value' )
		;

		if ( $algo !== null && $algo !== '' )
		{
			$qb->andWhere(
				$qb->expr()
				   ->eq( 'algo', $qb->createNamedParameter( $algo ) ),
			);
		}

		$qb->having(
			$qb->expr()
			   ->gte( 'cnt', $qb->createNamedParameter( $minCount, IQueryBuilder::PARAM_INT ) ),
		)
		   ->orderBy( 'cnt', 'DESC' )
		   ->setMaxResults( $limit )
		   ->setFirstResult( $offset )
		;

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		return array_map( function (
			array $row,
		): array {

			$fileidStr = (string) $row['fileids'];
			$fileids   = $fileidStr !== ''
				? array_map( 'intval', explode( ',', $fileidStr ) )
				: [];

			return [
				'algo'       => $row['algo'],
				'hash_value' => $row['hash_value'],
				'file_count' => (int) $row['cnt'],
				'fileids'    => $fileids,
			];
		}, $rows );
	}


	/**
	 * Batch-lookup filecache paths for a list of file IDs.
	 *
	 * Joins storages to resolve the storage ID for each file.
	 * When $userName is provided, only files from that user's home
	 * storage are returned (matched via storages.id = 'home::{uid}').
	 *
	 * @param  int[]        $fileIds
	 * @param  string|null  $userName
	 *
	 * @return array<int, array{path: string, name: string, storage_id: string, user: string}>
	 */
	public function batchLookupFilecachePaths(
		array   $fileIds,
		?string $userName = null,
	): array {

		if ( empty( $fileIds ) )
		{
			return [];
		}

		$qb = $this->db->getQueryBuilder();

		$qb->select( 'fc.fileid', 'fc.path', 'fc.name', 's.id' )
		   ->from( 'filecache', 'fc' )
		   ->innerJoin(
			   'fc',
			   'storages',
			   's',
			   'fc.storage = s.numeric_id',
		   )
		;

		if ( $userName !== null )
		{
			$qb->where(
				$qb->expr()
				   ->eq(
					   's.id',
					   $qb->createNamedParameter( 'home::' . $userName ),
				   ),
			)
			   ->andWhere(
				   $qb->expr()
				      ->in(
					      'fc.fileid',
					      $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
				      ),
			   )
			;
		}
		else
		{
			$qb->where(
				$qb->expr()
				   ->in(
					   'fc.fileid',
					   $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
				   ),
			);
		}

		$result = $qb->executeQuery();
		$paths  = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$sid  = (string) $row['id'];
			$user = '';

			if ( str_starts_with( $sid, 'home::' ) )
			{
				$user = substr( $sid, 6 );
			}
			elseif ( str_starts_with( $sid, 'local::' ) )
			{
				$user = basename( $sid );
			}
			else
			{
				$user = $sid;
			}

			$paths[ (int) $row['fileid'] ] = [
				'path'       => (string) $row['path'],
				'name'       => (string) $row['name'],
				'storage_id' => $sid,
				'user'       => $user,
			];
		}
		$result->closeCursor();

		return $paths;
	}


	/**
	 * Find hash rows matching a given hash value, with optional algo filter.
	 *
	 * Returns rows from file_checksum_search_hashes joined with filecache.
	 *
	 * @return array<int, array{fileid: int, algo: string, hash_value: string, path: string, name: string}>
	 */
	public function findByHash(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select( 'h.fileid', 'h.algo', 'h.hash_value', 'fc.path', 'fc.name' )
		   ->from( TableNameService::TABLE_FILE_CHECKSUM_SEARCH_HASHES, 'h' )
		   ->innerJoin( 'h', 'filecache', 'fc', 'h.fileid = fc.fileid' )
		   ->where(
			   $qb->expr()
			      ->eq( 'h.hash_value', $qb->createNamedParameter( $hash ) ),
		   )
		;

		if ( $algo !== null && $algo !== '' )
		{
			$qb->andWhere(
				$qb->expr()
				   ->eq( 'h.algo', $qb->createNamedParameter( $algo ) ),
			);
		}

		$qb->setMaxResults( $limit );

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}


	private function acquireLock( int $fileId ): bool
	{

		try
		{
			$this->lockingProvider->acquireLock(
				'files/' . $fileId,
				ILockingProvider::LOCK_EXCLUSIVE,
			);

			return true;
		}
		catch ( LockedException )
		{
			return false;
		}
	}


	private function releaseLock( int $fileId ): void
	{

		$this->lockingProvider->releaseLock(
			'files/' . $fileId,
			ILockingProvider::LOCK_EXCLUSIVE,
		);
	}

}
