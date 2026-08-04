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
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Shared business logic for hash index operations.
 *
 * Used by CLI commands, SettingsController, CronGenerateHashes,
 * and other service classes.
 */
readonly class HashIndexService
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
	 * Compute a hash for a File node and write it to the filecache.
	 *
	 * @return array{success: bool, algo: string, hash: string, existed: bool}
	 */
	public function recalcFileHash(
		File   $file,
		string $algo,
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
		$cache->update( $file->getId(), [ 'checksum' => $newChecksum ] );

		return [
			'success' => true,
			'algo'    => $algo,
			'hash'    => $hash,
			'existed' => false,
		];
	}


	public function recalcHash(
		int    $fileId,
		string $algo,
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

		return $this->recalcFileHash( $node, $algo );
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

				if ( $result['existed'] )
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

}
