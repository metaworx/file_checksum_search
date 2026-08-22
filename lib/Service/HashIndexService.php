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
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class HashIndexService
{

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
		string           $algo,
		?string          $pathPattern = null,
		int              $batchSize = 100,
		?OutputInterface $output = null,
	): array {

		return $this->hashCalc->generateMissingHashes(
			$userId,
			$algo,
			$pathPattern,
			$batchSize,
			$output,
		);
	}


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
