<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

/**
 * Duplicate detection and hash lookup queries.
 *
 * Delegates search to MetadataService against oc_files_metadata_index.
 * Path resolution via FilecacheService.
 */
class DuplicateService
{

//  constants

	/**
	 * Default page size for duplicate-group listings (findAllDuplicates()
	 * and the callers that route through it). Unrelated to
	 * MetadataService::fetchPendingBatch()'s pending-queue batch size,
	 * which happens to share the same numeric default today but is a
	 * separate, independently-tunable concern.
	 */
	public const DEFAULT_DUPLICATE_LIMIT = 50;


//  constructor

	public function __construct(
		private readonly MetadataService  $metadataService,
		private readonly FilecacheService $filecacheService,
		private readonly ReachResolver    $reach,
	) {
	}


//  other non-static methods

	/**
	 * Find all duplicate hash groups across the entire system.
	 *
	 * Groups files by (meta_key, meta_value_string) where more than one
	 * file shares the same hash. Delegates to MetadataService::queryDuplicates().
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
		int     $limit = self::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?string $hash = null,
		bool    $anywhere = false,
	): array
	{
		$rows = $this->metadataService->queryDuplicates( $algo, $minCount, $limit, $offset, $hash, $anywhere );

		return array_map( function(
			array $row,
		): array
		{
			// Through algorithmFromKey(), never stripped inline: taking
			// only `file-checksum-` off `file-checksum-hash-sha1` leaves
			// `hash-sha1`, which is not an algorithm anyone can recalculate —
			// and recalculating is exactly what the page does next.
			$algo = MetadataService::algorithmFromKey(
				$row[ MetadataService::FIELD_META_KEY ],
			);

			return [
				'algo'       => $algo,
				'hash_value' => $row[ MetadataService::FIELD_META_VALUE_STRING ],
				'file_count' => (int) $row['file_count'],
				'fileids'    => $row['file_ids'],
			];
		}, $rows );
	}

	/**
	 * Find hash rows matching a given hash value, with optional algo filter.
	 *
	 * Delegates to MetadataService::queryByHash() for the search, then
	 * batch-looks up filecache paths for each matched file_id.
	 *
	 * @param  string|null  $userName       When provided, results are restricted to
	 *                                      files in that user's home storage.
	 * @param  bool         $withLocalPath  Each row gains `local_path`, as
	 *                                      {@see FilecacheService::batchLookupFilecachePaths()}.
	 *
	 * @return array<int, array{fileid: int, algo: string, hash_value: string, path: string, name: string, owner: ?string, location: string, local_path?: ?string}>
	 */
	public function findByHash(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
		?string $userName = null,
		bool    $withLocalPath = false,
	): array
	{
		$rows = $this->metadataService->queryByHash( $hash, $algo, $limit );

		if ( empty( $rows ) )
		{
			return [];
		}

		$fileIds = array_map( function(
			array $row,
		): int
		{
			return (int) $row[ MetadataService::FIELD_FILE_ID ];
		}, $rows );

		$fcPaths = $this->filecacheService->batchLookupFilecachePaths(
			$fileIds,
			$this->reach->mountsFor( $userName ),
			$withLocalPath,
		);

		// queryByHash() compares against the index, which holds at most 63
		// characters, so a long-hash lookup can return a file that only
		// shares that prefix. One confirmation, shared with every other
		// caller ({@see MetadataService::confirmFullHash()}).
		$rows = $this->metadataService->confirmFullHash( $rows, $hash );

		$results = [];

		foreach ( $rows as $row )
		{
			$fileId = (int) $row[ MetadataService::FIELD_FILE_ID ];

			if ( ! isset( $fcPaths[ $fileId ] ) )
			{
				continue;
			}

			// Read authoritative hash from oc_files_metadata.json
			$extracted = $this->metadataService->extractAlgorithm( $fileId, $row );

			$results[] = [
				'fileid'     => $fileId,
				'algo'       => $extracted['algo'],
				'hash_value' => $extracted['hash'] ?? $hash,
				'path'       => $fcPaths[ $fileId ]['path'],
				'name'       => $fcPaths[ $fileId ]['name'],
				'owner'      => $fcPaths[ $fileId ]['owner'] ?? null,
				'location'   => $fcPaths[ $fileId ]['location'] ?? '',
			] + ( $withLocalPath ? [ 'local_path' => $fcPaths[ $fileId ]['local_path'] ?? null ] : [] );
		}

		return $results;
	}
}
