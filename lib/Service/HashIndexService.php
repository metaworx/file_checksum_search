<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\Files\File;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Facade for hash index operations.
 *
 * Delegates to focused service classes:
 * - HashCalculationService (hash computation, recalculation)
 * - DuplicateService (duplicate detection, hash lookup, path resolution)
 * - MetadataService (metadata queries and index management)
 * - FilecacheService (filecache operations)
 *
 * Directly handles: user resolution.
 */
class HashIndexService
{

	/**
	 * How many duplicate groups to fetch before per-user filtering.
	 *
	 * See {@see listDuplicatesForUser()} for why the caller's limit cannot be
	 * pushed down into the query.
	 */
	private const UNFILTERED_GROUP_FETCH_LIMIT = 10000;


	public function __construct(
		private readonly HashCalculationService $hashCalc,
		private readonly DuplicateService       $duplicates,
		private readonly MetadataService        $metadataService,
		private readonly FilecacheService       $filecacheService,
	) {
	}


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

		return $this->hashCalc->recalcAllExistingAlgos( $fileId );
	}


	public function generateMissingHashes(
		string           $userId,
		string|array     $algo,
		?string          $pathPattern = null,
		int              $batchSize = 100,
		?OutputInterface $output = null,
		?RuleOverrides   $overrides = null,
	): array {

		return $this->hashCalc->generateMissingHashes(
			$userId,
			$algo,
			$pathPattern,
			$batchSize,
			$output,
			$overrides,
		);
	}


	/**
	 * @return array{algo: string, hash_value: string, file_count: int, fileids: int[]}[]
	 */
	public function findAllDuplicates(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
	): array {

		return $this->duplicates->findAllDuplicates( $algo, $minCount, $limit, $offset );
	}


	/**
	 * The duplicate-group listing for one user, ready to be returned as-is.
	 *
	 * Duplicate groups are found instance-wide and only then filtered to the
	 * files $userId can see, which is why the limit cannot be pushed down into
	 * the query: a group of ten files might contribute one file to this user or
	 * none at all, so how many groups survive is unknown until after filtering.
	 * The query therefore over-fetches, the caller's $limit is applied to what
	 * is left, and a group that drops below $minCount for this user disappears
	 * rather than being reported as a duplicate of itself.
	 *
	 * @param  string  $userId  Whose files the groups are filtered to.
	 *
	 * @return array{duplicates: array<int, array{algo: string, hash_value: string, file_count: int, files:
	 *                            array<int, array{fileid: int, path: string, name: string}>}>, total_groups: int,
	 *                            pagination: array{offset: int, limit: int}}
	 */
	public function listDuplicatesForUser(
		string  $userId,
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
	): array {

		$pagination = [
			'offset' => $offset,
			'limit'  => $limit,
		];

		$groups = $this->findAllDuplicates( $algo, $minCount, self::UNFILTERED_GROUP_FETCH_LIMIT, $offset );

		if ( $groups === [] )
		{
			return [
				'duplicates'   => [],
				'total_groups' => 0,
				'pagination'   => $pagination,
			];
		}

		$allFileIds = [];

		foreach ( $groups as $group )
		{
			foreach ( $group['fileids'] as $fileId )
			{
				$allFileIds[] = $fileId;
			}
		}

		$fcPaths = $this->batchLookupFilecachePaths( $allFileIds, $userId );

		$result = [];

		foreach ( $groups as $group )
		{
			$files = [];

			foreach ( $group['fileids'] as $fileId )
			{
				if ( isset( $fcPaths[ $fileId ] ) )
				{
					$files[] = [
						'fileid' => $fileId,
						'path'   => $fcPaths[ $fileId ]['path'],
						'name'   => $fcPaths[ $fileId ]['name'],
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
			'pagination'   => $pagination,
		];
	}


	/**
	 * @param  int[]  $fileIds
	 *
	 * @return array<int, array{path: string, name: string, storage_id: string, user: string}>
	 */
	public function batchLookupFilecachePaths(
		array   $fileIds,
		?string $userName = null,
	): array {

		return $this->filecacheService->batchLookupFilecachePaths( $fileIds, $userName );
	}


	/**
	 * @param  string|null  $userName  When provided, results are restricted to
	 *                                 files in that user's home storage.
	 *
	 * @return array<int, array{fileid: int, algo: string, hash_value: string, path: string, name: string}>
	 */
	public function findByHash(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
		?string $userName = null,
	): array {

		return $this->duplicates->findByHash( $hash, $algo, $limit, $userName );
	}


	/**
	 * Count metadata index entries for a given file_id.
	 */
	public function countHashes( int $fileId ): int
	{

		return $this->metadataService->countByFileId( $fileId );
	}


	/**
	 * Invalidate hashes for a file by clearing its metadata.
	 *
	 * The ProcessPendingUpdates job will recalculate hashes later.
	 * This replaces the old custom-table DELETE with a metadata clear.
	 */
	public function deleteHashes( int $fileId ): int
	{

		$this->metadataService->clearMetadata( $fileId );

		return 1;
	}

}
