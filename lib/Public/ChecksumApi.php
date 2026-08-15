<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Public;

use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;

/**
 * Stable public API for checksum operations.
 *
 * This is the single public contract for all three consumer surfaces:
 * - HTTP REST (via PublicApiController)
 * - PHP DI (via constructor injection in other NC apps)
 * - PHP Bootstrap (via \OC::$server->get() after require_once base.php)
 *
 * All methods are read-only except recalcHash(). Internal lifecycle
 * operations (rebuild, purge, teardown, etc.) are NOT exposed here.
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class ChecksumApi
{

	public function __construct(
		private readonly HashIndexService $hashIndexService,
		private readonly MetadataService  $metadataService,
		private readonly StatusService    $statusService,
		private readonly IRootFolder      $rootFolder,
		private readonly IUserSession     $userSession,
	) {
	}


	/**
	 * Get all checksums for a File object.
	 *
	 * Convenience method that resolves the file ID from the File node.
	 *
	 * @param  File  $file  A Nextcloud File node
	 *
	 * @return array{fileid: int, hashes: array<int, array{algo: string, hash: string}>}
	 */
	public function getHashesByFile( File $file ): array
	{

		return $this->getHashesByFileId( $file->getId() );
	}


	/**
	 * Get all checksums for a file by its filecache ID.
	 *
	 * @param  int  $fileId  The filecache fileid
	 *
	 * @return array{fileid: int, hashes: array<int, array{algo: string, hash: string}>}
	 */
	public function getHashesByFileId( int $fileId ): array
	{

		$hashes    = $this->metadataService->getHashes( $fileId );
		$updatedAt = $this->metadataService->getUpdatedAt( $fileId );

		$result = [];

		foreach ( $hashes as $algo => $hash )
		{
			$result[] = [
				'algo'       => $algo,
				'hash'       => $hash,
				'updated_at' => $updatedAt !== null
					? date( 'c', $updatedAt )
					: null,
			];
		}

		return [
			'hashes' => $result,
			'fileid' => $fileId,
		];
	}


	/**
	 * Get checksums by filesystem path.
	 *
	 * Path resolution rules:
	 * - $user is null: path is treated as absolute filesystem path
	 *   or relative to the Nextcloud data root.
	 * - $user is provided: path is relative to that user's home folder.
	 *
	 * @param  string       $path  Filesystem path
	 * @param  string|null  $user  If provided, path is relative to this user's home
	 *
	 * @return array{fileid: int, path: string, hashes: array<int, array{algo: string, hash: string}>}
	 * @throws NotFoundException  If the path cannot be resolved to a file
	 */
	public function getHashesByPath(
		string  $path,
		?string $user = null,
	): array {

		if ( $user !== null )
		{
			$userFolder = $this->rootFolder->getUserFolder( $user );
			$node       = $userFolder->get( $path );
			$relative   = $path;
		}
		else
		{
			$node     = $this->rootFolder->get( $path );
			$relative = $node->getPath();
		}

		if ( ! $node instanceof File )
		{
			throw new NotFoundException( 'Path does not resolve to a file: ' . $path );
		}

		$fileId         = $node->getId();
		$result         = $this->getHashesByFileId( $fileId );
		$result['path'] = $relative;

		return $result;
	}


	/**
	 * Read-only health/status snapshot.
	 *
	 * @return array{version: string, dbVersion: string, rowCount: int, pendingRows: int}
	 */
	public function getStatus(): array
	{

		return [
			'version'     => $this->statusService->getAppVersion(),
			'dbVersion'   => $this->statusService->getDbVersion(),
			'rowCount'    => $this->statusService->getHashRowCount(),
			'pendingRows' => $this->statusService->getPendingRowCount(),
		];
	}


	/**
	 * Search for files by hash value, with optional algorithm filter.
	 *
	 * @param  string       $hash   Hex-encoded hash value
	 * @param  string|null  $algo   Optional algorithm filter (sha1, md5, sha256, sha512, sha3-256, sha3-512, crc32)
	 * @param  int          $limit  Max results (1–500)
	 *
	 * @return array{results: array<int, array{fileid: int, algo: string, hash: string, path: string, name: string}>}
	 */
	public function findByHash(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
	): array {

		$hash = trim( $hash );

		if ( $hash === '' )
		{
			throw new \InvalidArgumentException( 'Hash parameter is required.' );
		}

		$limit = max( 1, min( $limit, 500 ) );

		$rows = $this->hashIndexService->findByHash( $hash, $algo, $limit );

		$results = array_map( function (
			array $row,
		): array {

			return [
				'fileid' => (int) $row['fileid'],
				'algo'   => $row['algo'],
				'hash'   => $row['hash_value'],
				'path'   => $row['path'],
				'name'   => $row['name'],
			];
		}, $rows );

		return [ 'results' => $results ];
	}


	/**
	 * Find all duplicate hash groups across the system.
	 *
	 * @param  string|null  $algo      Optional algorithm filter
	 * @param  int          $minCount  Minimum files per group (default 2)
	 * @param  int          $limit     Max groups (1–500, default 50)
	 * @param  int          $offset    Pagination offset (default 0)
	 *
	 * @return array{duplicates: array, total_groups: int, pagination: array{offset: int, limit: int}}
	 */
	public function findDuplicates(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = 50,
		int     $offset = 0,
	): array {

		$limit = max( 1, min( $limit, 500 ) );

		$user = $this->userSession->getUser();
		$uid  = $user?->getUID();

		if ( $uid === null )
		{
			return [
				'duplicates'   => [],
				'total_groups' => 0,
				'pagination'   => [
					'offset' => $offset,
					'limit'  => $limit,
				],
			];
		}

		$groups = $this->hashIndexService->findAllDuplicates( $algo, $minCount, 10000, $offset );

		if ( empty( $groups ) )
		{
			return [
				'duplicates'   => [],
				'total_groups' => 0,
				'pagination'   => [
					'offset' => $offset,
					'limit'  => $limit,
				],
			];
		}

		$allFileIds = [];

		foreach ( $groups as $group )
		{
			foreach ( $group['fileids'] as $fid )
			{
				$allFileIds[] = $fid;
			}
		}

		$fcPaths = $this->hashIndexService->batchLookupFilecachePaths( $allFileIds, $uid );

		$result = [];

		foreach ( $groups as $group )
		{
			$files = [];

			foreach ( $group['fileids'] as $fid )
			{
				if ( isset( $fcPaths[ $fid ] ) )
				{
					$files[] = [
						'fileid' => $fid,
						'path'   => $fcPaths[ $fid ]['path'],
						'name'   => $fcPaths[ $fid ]['name'],
					];
				}
			}

			if ( count( $files ) < $minCount )
			{
				continue;
			}

			$result[] = [
				'algo'       => $group['algo'],
				'hash_value' => $group['hash_value'],
				'file_count' => count( $files ),
				'files'      => $files,
			];
		}

		if ( count( $result ) > $limit )
		{
			$result = array_slice( $result, 0, $limit );
		}

		return [
			'duplicates'   => $result,
			'total_groups' => count( $result ),
			'pagination'   => [
				'offset' => $offset,
				'limit'  => $limit,
			],
		];
	}


	/**
	 * Find other files sharing the same hash values as a given file.
	 *
	 * @param  int  $fileId  The filecache fileid of the reference file
	 *
	 * @return array{duplicates: array<int, array{algo: string, hash_value: string, files: array<int, array{fileid:
	 *                           int, path: string, name: string}>}>}
	 */
	public function findSameHash( int $fileId ): array
	{

		$hashes = $this->metadataService->getHashes( $fileId );

		if ( empty( $hashes ) )
		{
			return [ 'duplicates' => [] ];
		}

		$user       = $this->userSession->getUser();
		$userFolder = $user !== null
			? $this->rootFolder->getUserFolder( $user->getUID() )
			: null;

		$grouped = [];

		foreach ( $hashes as $algo => $hashValue )
		{
			$rows = $this->metadataService->queryByHash( $hashValue, $algo );

			foreach ( $rows as $row )
			{
				$dupFileId = (int) $row[ MetadataService::FIELD_FILE_ID ];

				if ( $dupFileId === $fileId )
				{
					continue;
				}

				$resolvedPath = '';
				$resolvedName = '';

				if ( $userFolder !== null )
				{
					$nodes = $userFolder->getById( $dupFileId );

					if ( empty( $nodes ) )
					{
						continue;
					}

					$node     = $nodes[0];
					$relative = $userFolder->getRelativePath( $node->getPath() );

					if ( $relative === null )
					{
						continue;
					}

					$resolvedPath = $relative;
					$resolvedName = $node->getName();
				}

				$key = $algo . "\0" . $hashValue;

				$grouped[ $key ] ??= [
					'algo'       => $algo,
					'hash_value' => $hashValue,
					'files'      => [],
				];

				$grouped[ $key ]['files'][] = [
					'fileid' => $dupFileId,
					'path'   => $resolvedPath,
					'name'   => $resolvedName,
				];
			}
		}

		return [ 'duplicates' => array_values( $grouped ) ];
	}


	/**
	 * Trigger hash recalculation for a file.
	 *
	 * This is the only mutating operation in the public API.
	 *
	 * @param  int          $fileId  The filecache fileid
	 * @param  string|null  $algo    Algorithm (default: sha1)
	 *
	 * @return array{success: bool, algo?: string, hash?: string, fileid?: int, error?: string}
	 */
	public function recalcHash(
		int     $fileId,
		?string $algo = null,
	): array {

		$algo ??= HashCalculationService::getDefaultAlgo();

		return $this->hashIndexService->recalcHash( $fileId, $algo );
	}

}
