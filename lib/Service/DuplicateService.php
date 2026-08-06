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
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class DuplicateService
{

	public function __construct(
		private readonly MetadataService  $metadataService,
		private readonly FilecacheService $filecacheService,
	) {
	}


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
		int     $limit = 50,
		int     $offset = 0,
	): array {

		$rows = $this->metadataService->queryDuplicates( $algo, $minCount, $limit, $offset );

		return array_map( function (
			array $row,
		): array {

			$algo = str_replace(
				MetadataService::KEY_FILE_CHECKSUM_PREFIX,
				'',
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
	 * @return array<int, array{fileid: int, algo: string, hash_value: string, path: string, name: string}>
	 */
	public function findByHash(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
	): array {

		$rows = $this->metadataService->queryByHash( $hash, $algo, $limit );

		if ( empty( $rows ) )
		{
			return [];
		}

		$fileIds = array_map( function (
			array $row,
		): int {

			return (int) $row[ MetadataService::FIELD_FILE_ID ];
		}, $rows );

		$fcPaths = $this->filecacheService->batchLookupFilecachePaths( $fileIds );

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
			];
		}

		return $results;
	}

}
