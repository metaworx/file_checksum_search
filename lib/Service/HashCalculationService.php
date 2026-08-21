<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Hash computation and recalculation logic.
 *
 * Handles file-level hash operations: computing new hashes,
 * recalculating existing ones, and the two-phase collection+generate
 * pattern for batch hash generation.
 */
class HashCalculationService
{

	public const SUPPORTED_ALGOS
		= [
			'sha1',
			'md5',
			'adler32',
			'crc32',
			'sha256',
			'sha512',
			'sha3-256',
			'sha3-512',
		];


	public function __construct(
		private readonly FilecacheService $filecacheService,
		private readonly ILockingProvider $lockingProvider,
		private readonly MetadataService  $metadataService,
		private readonly RuleService      $ruleService,
		private readonly LoggerInterface  $logger,
	) {
	}


	public static function getDefaultAlgo(): string
	{

		return self::SUPPORTED_ALGOS[0];
	}


	/**
	 * Check whether the hash table row for (fileid, algo) has an
	 * updated_at timestamp that is equal to or newer than the file's
	 * mtime, meaning the hash is still fresh.
	 *
	 * Returns false if no row exists, updated_at is NULL, or the
	 * timestamp is older than mtime.
	 */
	private function isHashUpToDate(
		int|File|IFilesMetadata $fileOrMetadata,
		int                     $mtime,
	): bool {

		$updatedAt = $this->metadataService->getUpdatedAt( $fileOrMetadata );

		return $updatedAt !== null && $updatedAt >= $mtime;
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


	private function collectFilesForUser(
		string  $folderPath,
		string  $userId,
		string  $algo,
		?string $pathPattern,
		string  $userFolderPath,
		array   &$collected,
		int     $batchSize,
		?array  &$stats = null,
	): void {

		// A non-positive batch size means "no limit" (the generate
		// command passes 0 when --batch-size is omitted).
		$unlimited = $batchSize <= 0;

		if ( ! $unlimited && count( $collected ) >= $batchSize )
		{
			return;
		}

		$userFolder = $this->filecacheService->getUserFolder( $userId );
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
			if ( ! $unlimited && count( $collected ) >= $batchSize )
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
					$stats,
				);

				continue;
			}

			if ( ! ( $child instanceof File ) )
			{
				continue;
			}

			if ( $stats !== null )
			{
				$stats['files'] = ( $stats['files'] ?? 0 ) + 1;
			}

			$relativePath = $this->relativeHashPath(
				$child->getPath(),
				$userFolderPath,
			);

			if ( $pathPattern !== null && ! PathUtil::matchesGlob( $pathPattern, $relativePath ) )
			{
				if ( $stats !== null )
				{
					$stats['globSkipped'] = ( $stats['globSkipped'] ?? 0 ) + 1;
				}

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
			elseif ( $stats !== null )
			{
				$stats['alreadyHashed'] = ( $stats['alreadyHashed'] ?? 0 ) + 1;
			}
		}
	}


	/**
	 * Two-phase hash generation: collect files needing hashes,
	 * then process them. Avoids interleaving reads and writes
	 * to oc_filecache (dirty reads in NC v33 debug mode).
	 *
	 * @param int $batchSize Maximum files to collect (a value <= 0 means unlimited)
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

		$userFolderPath = $this->filecacheService->getUserFolderPath( $userId );

		// Phase 1: collect
		$files = [];
		$stats = [];
		$this->collectFilesForUser(
			$userFolderPath,
			$userId,
			$algo,
			$pathPattern,
			$userFolderPath,
			$files,
			$batchSize,
			$stats,
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
		elseif ( $output !== null && $output->isVerbose() )
		{
			$output->writeln(
				sprintf(
					'  No files collected (user: %s, path: %s, algo: %s).',
					$userId,
					$pathPattern ?? '**',
					$algo,
				),
			);
			$output->writeln(
				sprintf(
					'    Scan stats: %d file(s) seen, %d already hashed, %d glob-mismatched.',
					$stats['files'] ?? 0,
					$stats['alreadyHashed'] ?? 0,
					$stats['globSkipped'] ?? 0,
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

					if ( $output !== null && $output->isVeryVerbose() )
					{
						$output->writeln(
							sprintf( '    Skipped fileId %d (locked).', $file->getId() ),
						);
					}
				}
				elseif ( $result['existed'] )
				{
					$skipped ++;

					if ( $output !== null && $output->isVeryVerbose() )
					{
						$output->writeln(
							sprintf( '    Skipped fileId %d (already hashed).', $file->getId() ),
						);
					}
				}
				else
				{
					$processed ++;

					if ( $output !== null && $output->isVeryVerbose() )
					{
						$output->writeln(
							sprintf( '    Hashed fileId %d (%s).', $file->getId(), $algo ),
						);
					}
					elseif ( $processed % 10 == 0 && $output !== null )
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


	/**
	 * Centralized processing logic for a single file.
	 *
	 * ALWAYS removes all existing algo keys first (rules may have changed).
	 * Then computes required algos based on mode.
	 *
	 * @param  string[]  $algos  Algos to process (from rule or caller)
	 *
	 * @throws \OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException
	 * @throws \OCP\FilesMetadata\Exceptions\FilesMetadataException
	 */
	public function processFile(
		int    $fileId,
		string $mode,
		array  $algos,
	): void {

		$metadata = $this->metadataService->getMetadata( $fileId );
		$file     = null;

		if ( $mode === MetadataService::PENDING_MODE_NEW )
		{
			try
			{
				$file = $this->filecacheService->getFile( $fileId );
				$rule = $this->ruleService->findFirstMatchingRule( $file->getPath() );

				if ( $rule !== null )
				{
					$mode  = $rule['mode'] ?? MetadataService::PENDING_MODE_AUTO;
					$algos = $rule['algos'] ?? self::SUPPORTED_ALGOS;
				}
				else
				{
					$mode  = MetadataService::PENDING_MODE_AUTO;
					$algos = self::SUPPORTED_ALGOS;
				}
			}
			catch ( Throwable $e )
			{
				$this->logger->warning(
					'FCIAS: processFile unable to resolve rule for fileId {fileId}, defaulting to auto.',
					[
						'app'       => Application::APP_ID,
						'fileId'    => $fileId,
						'exception' => $e,
					],
				);

				$mode  = MetadataService::PENDING_MODE_AUTO;
				$algos = self::SUPPORTED_ALGOS;
			}
		}

		switch ( $mode )
		{
		case 'lazy':
			$this->metadataService->clearMetadata( $metadata, false );
			break;

			/**
			 * 'force' mode: clear all existing metadata, then intentionally
			 * fall through to 'missing' mode to recompute every algo.
			 *
			 * @noinspection PhpMissingBreakStatementInspection
			 */
		case 'force':
			$this->metadataService->clearMetadata( $metadata, false );

		case 'missing':
			foreach ( $algos as $algo )
			{
				$result = $this->recalcHash( $file ?? $fileId, $algo, true, $metadata );

				if ( ! $result['success'] )
				{
					$this->logger->warning(
						'FCIAS: processFile recalcHash failed for algo {algo}',
						[
							'app'    => Application::APP_ID,
							'fileId' => $fileId,
							'algo'   => $algo,
							'error'  => $result['error'] ?? 'unknown',
						],
					);

					$this->metadataService->markPending( $fileId, MetadataService::PENDING_PREFIX . $mode );

					return;
				}

				$metadata->setString(
					MetadataService::KEY_FILE_CHECKSUM_PREFIX . $algo,
					$result['hash'],
					true,
				);
			}
			$metadata->setInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, time(), true );

			break;

		case 'auto':
			foreach ( $algos as $algo )
			{
				$metaKey = MetadataService::getHashKey( $algo );
				if ( ! $metadata->hasKey( $metaKey ) )
				{
					continue;
				}

				$result = $this->recalcHash( $file ?? $fileId, $algo, true, $metadata );

				if ( ! $result['success'] )
				{
					$this->logger->warning(
						'FCIAS: processFile recalcHash failed for algo {algo}',
						[
							'app'    => Application::APP_ID,
							'fileId' => $fileId,
							'algo'   => $algo,
							'error'  => $result['error'] ?? 'unknown',
						],
					);

					$this->metadataService->markPending( $fileId, MetadataService::PENDING_PREFIX . $mode );

					return;
				}

				$metadata->setString(
					MetadataService::KEY_FILE_CHECKSUM_PREFIX . $algo,
					$result['hash'],
					true,
				);
			}
			$metadata->setInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, time(), true );

			break;
		}

		$this->metadataService->saveMetadata( $metadata );

		$this->logger->debug(
			'FCIAS HashCalculationService: processFile completed',
			[
				'app'    => Application::APP_ID,
				'fileId' => $fileId,
				'mode'   => $mode,
				'algos'  => $algos,
			],
		);
	}


	/**
	 * Recalculate all currently-indexed algos for a file.
	 *
	 * If no prior hash rows exist, falls back to the default algo.
	 *
	 * @return array{processed: int, algos: string[], locked: bool}
	 */
	public function recalcAllExistingAlgos( int|File $file ): array
	{

		$file     = $this->filecacheService->getFile( $file );
		$metadata = $this->metadataService->getMetadata( $file );

		// Check if any hash keys exist for this file in metadata
		$count = $this->metadataService->countByFileId( $file->getId() );

		if ( $count === 0 )
		{
			$algos = [ self::getDefaultAlgo() ];
		}
		else
		{
			$algos = $this->metadataService->getHashes( $metadata );
		}

		$processed = 0;
		$locked    = false;

		foreach ( $algos as $algo )
		{
			$result = $this->recalcHash( $file, $algo, metadata: $metadata );

			if ( $result['success'] )
			{
				$processed ++;
			}
			elseif ( $result['locked'] ?? false )
			{
				$locked = true;
			}
		}

		$this->metadataService->saveMetadata( $metadata );

		return [
			'processed' => $processed,
			'algos'     => $algos,
			'locked'    => $locked,
		];
	}


	/**
	 * Compute a hash for a File node if it does not already exist
	 * in the filecache, then write it back.
	 *
	 * If the checksum already exists for this algo, it is returned
	 * without recomputation.  For forced recalculation, use
	 * {@see recalcFileHash()} with skipExisting = false.
	 *
	 * @return array{success: bool, algo: string, hash: string, existed: bool}
	 */
	public function recalcFileHash(
		File            $file,
		string          $algo,
		bool            $skipExisting = true,
		?IFilesMetadata $metadata = null,
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

		$fileId    = $file->getId();
		$needsSave = $this->metadataService->ensureMetadata( $fileId, $metadata );
		$checksums = $this->filecacheService->getChecksums( $file );

		// Sync: copy hash from filecache.checksum → metadata
		foreach ( $checksums as $prefix => $hexHash )
		{
			$metaKey = MetadataService::getHashKey( $prefix );
			if ( ! $metadata->hasKey( $metaKey ) )
			{
				$metadata->setString( $metaKey, $hexHash, true );
			}
		}

		// Sync: copy hash from metadata → filecache.checksum happens on metadata save
		$metaKey = MetadataService::getHashKey( $algo );

		// Check metadata for existing hash (checksum field limited to 256 chars)
		if ( $skipExisting && $metadata->hasKey( $metaKey ) )
		{
			if ( $this->isHashUpToDate( $metadata, $file->getMTime() ) )
			{
				return [
					'success' => true,
					'algo'    => $algo,
					'hash'    => $metadata->getString( $metaKey ),
					'existed' => true,
				];
			}
		}

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
			$metadata->unset( $metaKey );

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

			$metadata->setString( $metaKey, $hash, true );

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

			if ( $needsSave )
			{
				$this->metadataService->saveMetadata( $metadata );
			}
		}
	}


	/**
	 * Recalculate hash for a file by fileId, resolving through rootFolder.
	 */
	public function recalcHash(
		int|File        $file,
		string          $algo,
		bool            $skipExisting = true,
		?IFilesMetadata $metadata = null,
	): array {

		$algo = strtolower( $algo );

		if ( ! $file instanceof File )
		{
			try
			{
				$file = $this->filecacheService->getNodeById( $file );
			}
			catch ( NotFoundException )
			{
				return [
					'success' => false,
					'algo'    => $algo,
					'hash'    => '',
					'existed' => false,
					'error'   => 'File not found.',
				];
			}

			if ( ! $file instanceof File )
			{
				return [
					'success' => false,
					'algo'    => $algo,
					'hash'    => '',
					'existed' => false,
					'error'   => 'Node is not a file.',
				];
			}
		}

		return $this->recalcFileHash( $file, $algo, $skipExisting, $metadata );
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


	private function releaseLock( int $fileId ): void
	{

		$this->lockingProvider->releaseLock(
			'files/' . $fileId,
			ILockingProvider::LOCK_EXCLUSIVE,
		);
	}

}
