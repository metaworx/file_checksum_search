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
use OCP\Files\Storage\IStorage;
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

	public const CHUNK_SIZE = 8192;


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
	 * Whether $algo is a string present in SUPPORTED_ALGOS.
	 *
	 * Accepts mixed so it can validate a raw, untrusted request value
	 * directly (e.g. one entry of a request body's `algos` array).
	 */
	public static function isValidAlgo( mixed $algo ): bool
	{

		return is_string( $algo ) && in_array( $algo, self::SUPPORTED_ALGOS, true );
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
		array   $algos,
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

		$prefixes = array_map(
			static fn(
				string $algo,
			): string => strtoupper( $algo ) . ':',
			$algos,
		);

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
					$algos,
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
			$hasAll           = true;

			foreach ( $prefixes as $prefix )
			{
				$found = false;

				foreach ( explode( ' ', $existingChecksum ) as $pair )
				{
					if ( str_starts_with( $pair, $prefix ) )
					{
						$found = true;

						break;
					}
				}

				if ( ! $found )
				{
					$hasAll = false;

					break;
				}
			}

			if ( ! $hasAll )
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
	 * @param  int  $batchSize  Maximum files to collect (a value <= 0 means unlimited)
	 *
	 * @return array{processed: int, skipped: int}
	 */
	public function generateMissingHashes(
		string           $userId,
		string|array     $algo,
		?string          $pathPattern = null,
		int              $batchSize = 100,
		?OutputInterface $output = null,
	): array {

		$algos     = array_values( array_unique( array_map( 'strtolower', (array) $algo ) ) );
		$algoLabel = implode( ',', $algos );

		$userFolderPath = $this->filecacheService->getUserFolderPath( $userId );

		// Phase 1: collect
		$files = [];
		$stats = [];
		$this->collectFilesForUser(
			$userFolderPath,
			$userId,
			$algos,
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
					'algo'   => $algoLabel,
					'count'  => $collected,
				],
			);

			$output?->writeln(
				sprintf(
					'  Collected %d files without %s checksums.',
					$collected,
					$algoLabel,
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
					$algoLabel,
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
				$batch = $this->recalcHashes( $file, $algos, true );

				if ( $batch['locked'] )
				{
					$skipped ++;

					if ( $output !== null && $output->isVeryVerbose() )
					{
						$output->writeln(
							sprintf( '    Skipped fileId %d (locked).', $file->getId() ),
						);
					}

					continue;
				}

				$anyHashed = false;
				foreach ( $batch['results'] as $result )
				{
					if ( $result['success'] && ! $result['existed'] )
					{
						$anyHashed = true;

						break;
					}
				}

				if ( $anyHashed )
				{
					$processed ++;

					if ( $output !== null && $output->isVeryVerbose() )
					{
						$output->writeln(
							sprintf( '    Hashed fileId %d (%s).', $file->getId(), $algoLabel ),
						);
					}
					elseif ( $processed % 10 == 0 && $output !== null )
					{
						$output->writeln(
							sprintf( '    %d files processed …', $processed ),
						);
					}
				}
				else
				{
					$skipped ++;

					if ( $output !== null && $output->isVeryVerbose() )
					{
						$output->writeln(
							sprintf( '    Skipped fileId %d (already hashed).', $file->getId() ),
						);
					}
				}
			}
			catch ( Throwable $e )
			{
				$this->logger->error(
					'FCIAS: recalcHashes failed in generateMissingHashes',
					[
						'app'       => Application::APP_ID,
						'fileId'    => $file->getId(),
						'algo'      => $algoLabel,
						'exception' => $e,
					],
				);

				$output?->warning(
					sprintf(
						'  WARNING: recalcHashes failed for fileId %d: %s',
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
				$rule = $this->ruleService->findFirstMatchingRule(
					$file->getPath(),
					$file->getOwner()
					     ?->getUID(),
				);

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
			if ( ! $this->applyBatchResults(
				$this->recalcHashes( $file ?? $fileId, $algos, true, $metadata ),
				$algos,
				$fileId,
				$mode,
				$metadata,
			) )
			{
				return;
			}

			$metadata->setInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, time(), true );

			break;

		case 'auto':
			$algosToRecalc = [];
			foreach ( $algos as $algo )
			{
				if ( $metadata->hasKey( MetadataService::getHashKey( $algo ) ) )
				{
					$algosToRecalc[] = $algo;
				}
			}

			if ( $algosToRecalc !== [] )
			{
				if ( ! $this->applyBatchResults(
					$this->recalcHashes( $file ?? $fileId, $algosToRecalc, true, $metadata ),
					$algosToRecalc,
					$fileId,
					$mode,
					$metadata,
				) )
				{
					return;
				}
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
	 * Persist a recalcHashes() result set for one mode, marking the file
	 * pending and returning false on the first failing algorithm.
	 *
	 * @param  array<string, array{success: bool, hash: string, existed: bool, error?: string}>  $batch
	 * @param  string[]                                                                          $algos
	 *
	 * @return bool  True when every algorithm was applied successfully
	 */
	private function applyBatchResults(
		array          $batch,
		array          $algos,
		int            $fileId,
		string         $mode,
		IFilesMetadata $metadata,
	): bool {

		foreach ( $algos as $algo )
		{
			$result = $batch['results'][ $algo ] ?? null;

			if ( $result === null || ! $result['success'] )
			{
				$this->logger->warning(
					'FCIAS: processFile recalcHashes failed for algo {algo}',
					[
						'app'    => Application::APP_ID,
						'fileId' => $fileId,
						'algo'   => $algo,
						'error'  => $result['error'] ?? 'unknown',
					],
				);

				$this->metadataService->markPending( $fileId, MetadataService::PENDING_PREFIX . $mode );

				return false;
			}

			$metadata->setString(
				MetadataService::KEY_FILE_CHECKSUM_PREFIX . $algo,
				$result['hash'],
				true,
			);
		}

		return true;
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

		$batch = $this->recalcHashes( $file, array_values( $algos ), true, $metadata );

		$processed = 0;

		foreach ( array_values( $algos ) as $algo )
		{
			$result = $batch['results'][ $algo ] ?? null;

			if ( $result !== null && $result['success'] )
			{
				$processed ++;
			}
		}

		$this->metadataService->saveMetadata( $metadata );

		return [
			'processed' => $processed,
			'algos'     => $algos,
			'locked'    => $batch['locked'],
		];
	}


	/**
	 * Compute hashes for one or more algorithms in a single pass.
	 *
	 * For a single algorithm the existing per-algo path is used unchanged
	 * (hash_file() for local storage, a single streamed read otherwise).
	 * For two or more algorithms the file is opened once and each read
	 * chunk is fanned out into one hash context per algorithm, so remote
	 * or external storage is read only once instead of once per algorithm.
	 *
	 * @return array{
	 *   results: array<string, array{success: bool, hash: string, existed: bool, error?: string}>,
	 *   locked: bool
	 * }
	 */
	public function recalcHashes(
		int|File        $file,
		array           $algos,
		bool            $skipExisting = true,
		?IFilesMetadata $metadata = null,
	): array {

		$algos = array_values( array_unique( array_map( 'strtolower', $algos ) ) );

		// Validate first; invalid algos fail without touching the file.
		$results = [];
		$valid   = [];
		foreach ( $algos as $algo )
		{
			if ( in_array( $algo, self::SUPPORTED_ALGOS, true ) )
			{
				$valid[] = $algo;

				continue;
			}

			$results[ $algo ] = [
				'success' => false,
				'hash'    => '',
				'existed' => false,
				'error'   => 'Unsupported algorithm: ' . $algo,
			];
		}

		if ( ! $file instanceof File )
		{
			try
			{
				$file = $this->filecacheService->getNodeById( $file );
			}
			catch ( NotFoundException )
			{
				foreach ( $valid as $algo )
				{
					$results[ $algo ] = [
						'success' => false,
						'hash'    => '',
						'existed' => false,
						'error'   => 'File not found.',
					];
				}

				return [
					'results' => $results,
					'locked'  => false,
				];
			}

			if ( ! $file instanceof File )
			{
				foreach ( $valid as $algo )
				{
					$results[ $algo ] = [
						'success' => false,
						'hash'    => '',
						'existed' => false,
						'error'   => 'Node is not a file.',
					];
				}

				return [
					'results' => $results,
					'locked'  => false,
				];
			}
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

		$mtime  = $file->getMTime();
		$needed = [];
		foreach ( $valid as $algo )
		{
			$metaKey = MetadataService::getHashKey( $algo );
			if ( $skipExisting && $metadata->hasKey( $metaKey ) && $this->isHashUpToDate( $metadata, $mtime ) )
			{
				$results[ $algo ] = [
					'success' => true,
					'hash'    => $metadata->getString( $metaKey ),
					'existed' => true,
				];

				continue;
			}

			$needed[] = $algo;
		}

		if ( $needed === [] )
		{
			return [
				'results' => $results,
				'locked'  => false,
			];
		}

		if ( ! $this->acquireLock( $fileId ) )
		{
			foreach ( $needed as $algo )
			{
				$results[ $algo ] = [
					'success' => false,
					'hash'    => '',
					'existed' => false,
				];
			}

			return [
				'results' => $results,
				'locked'  => true,
			];
		}

		try
		{
			$storage = $file->getStorage();

			foreach ( $needed as $algo )
			{
				$metadata->unset( MetadataService::getHashKey( $algo ) );
			}

			$hashes = count( $needed ) === 1
				? [ $needed[0] => $this->computeSingleHash( $file, $storage, $needed[0] ) ]
				: $this->computeMultiHash( $file, $needed );

			foreach ( $hashes as $algo => $hash )
			{
				$metadata->setString( MetadataService::getHashKey( $algo ), $hash, true );
				$results[ $algo ] = [
					'success' => true,
					'hash'    => $hash,
					'existed' => false,
				];
			}
		}
		catch ( Throwable $e )
		{
			foreach ( $needed as $algo )
			{
				$results[ $algo ] = [
					'success' => false,
					'hash'    => '',
					'existed' => false,
					'error'   => $e->getMessage(),
				];
			}

			// Do not persist unset keys when hashing failed.
			$needsSave = false;
		}
		finally
		{
			if ( $needsSave )
			{
				$this->metadataService->saveMetadata( $metadata );
			}

			$this->releaseLock( $fileId );
		}

		return [
			'results' => $results,
			'locked'  => false,
		];
	}


	/**
	 * Compute a single algorithm's hash using the pre-batch fast paths.
	 */
	private function computeSingleHash(
		File     $file,
		IStorage $storage,
		string   $algo,
	): string {

		if ( $storage->isLocal() )
		{
			$absolutePath = $storage->getLocalFile( $file->getInternalPath() );

			return hash_file( $algo, $absolutePath );
		}

		$handle = $file->fopen( 'rb' );

		if ( $handle === false )
		{
			throw new \RuntimeException( 'Unable to open file for reading.' );
		}

		try
		{
			$ctx = hash_init( $algo );
			hash_update_stream( $ctx, $handle );

			return hash_final( $ctx );
		}
		finally
		{
			fclose( $handle );
		}
	}


	/**
	 * Stream a file once and feed each chunk into one hash context per
	 * algorithm.
	 *
	 * @param  string[]  $algos
	 *
	 * @return array<string, string>  Algo => hex hash
	 */
	private function computeMultiHash(
		File  $file,
		array $algos,
	): array {

		$handle = $file->fopen( 'rb' );

		if ( $handle === false )
		{
			throw new \RuntimeException( 'Unable to open file for reading.' );
		}

		try
		{
			$contexts = [];
			foreach ( $algos as $algo )
			{
				$contexts[ $algo ] = hash_init( $algo );
			}

			while ( ! feof( $handle ) )
			{
				$chunk = fread( $handle, self::CHUNK_SIZE );

				if ( $chunk === false )
				{
					break;
				}

				foreach ( $contexts as $ctx )
				{
					hash_update( $ctx, $chunk );
				}
			}

			$hashes = [];
			foreach ( $contexts as $algo => $ctx )
			{
				$hashes[ $algo ] = hash_final( $ctx );
			}

			return $hashes;
		}
		finally
		{
			fclose( $handle );
		}
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

		$algo   = strtolower( $algo );
		$result = $this->recalcHashes( $file, [ $algo ], $skipExisting, $metadata );
		$single = $result['results'][ $algo ];

		$out = [
			'success' => $single['success'],
			'algo'    => $algo,
			'hash'    => $single['hash'],
			'existed' => $single['existed'],
		];

		if ( $result['locked'] )
		{
			$out['locked'] = true;
		}
		elseif ( ( $single['error'] ?? '' ) !== '' )
		{
			$out['error'] = $single['error'];
		}

		return $out;
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
