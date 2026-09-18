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
	 * The most raw groups {@see listDuplicatesForUser()} will scan across all
	 * its pages before giving up — the safety bound for a caller who can see
	 * almost none of what the query returns. The paging stops long before
	 * this whenever the caller's own limit is met.
	 */
	private const UNFILTERED_GROUP_FETCH_LIMIT = 10000;

	/**
	 * Raw groups fetched per round of the per-user filter. A round reads this
	 * many, keeps the caller's, and the loop stops once the caller's limit is
	 * in hand — so an ordinary request reads about one round, not the bound.
	 */
	private const DUPLICATE_PAGE_SIZE = 200;


	public function __construct(
		private readonly HashCalculationService $hashCalc,
		private readonly DuplicateService       $duplicates,
		private readonly MetadataService        $metadataService,
		private readonly FilecacheService       $filecacheService,
		private readonly ReachResolver          $reach,
	) {
	}


	/**
	 * {@see HashCalculationService::recalcFileHash()}, which is where the
	 * behaviour and the return shape are described.
	 */
	public function recalcFileHash(
		File   $file,
		string $algo,
		bool   $skipExisting = true,
	): array {

		return $this->hashCalc->recalcFileHash( $file, $algo, $skipExisting );
	}


	/**
	 * {@see HashCalculationService::recalcHash()}.
	 *
	 * The delegate also accepts an already-loaded metadata document; this
	 * does not, so a caller coming through the façade pays for one more
	 * load. That is the price of the façade, not an oversight.
	 */
	public function recalcHash(
		int    $fileId,
		string $algo,
		bool   $skipExisting = true,
	): array {

		return $this->hashCalc->recalcHash( $fileId, $algo, $skipExisting );
	}


	/**
	 * {@see HashCalculationService::recalcAllExistingAlgos()}.
	 */
	public function recalcAllExistingAlgos( int $fileId ): array
	{

		return $this->hashCalc->recalcAllExistingAlgos( $fileId );
	}


	/**
	 * {@see HashCalculationService::generateMissingHashes()}.
	 *
	 * A $batchSize of 0 or less means "no limit", which is what the generate
	 * command passes when its --batch-size option is omitted.
	 */
	public function generateMissingHashes(
		string           $userId,
		string|array     $algo,
		?string          $pathPattern = null,
		int              $batchSize = 100,
		?OutputInterface $output = null,
		?RuleOverrides   $overrides = null,
		string           $mode = MetadataService::PENDING_MODE_MISSING,
	): array {

		return $this->hashCalc->generateMissingHashes(
			$userId,
			$algo,
			$pathPattern,
			$batchSize,
			$output,
			$overrides,
			$mode,
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
		?string $hash = null,
		bool    $anywhere = false,
	): array {

		return $this->duplicates->findAllDuplicates( $algo, $minCount, $limit, $offset, $hash, $anywhere );
	}


	/**
	 * Copy every checksum the filecache already knows into the metadata
	 * index, without reading any file content.
	 *
	 * This is the whole of what installing the app does to existing data:
	 * hashes Nextcloud (or another app) has already computed become
	 * searchable immediately, and nothing is read or computed that was not
	 * already there. Idempotent — existing metadata hashes are never
	 * overwritten, and a re-run adds only what is still absent.
	 *
	 * Keyset-paged, so it holds one page of rows at a time regardless of
	 * instance size.
	 *
	 * @return array{files: int, hashes: int}  Files touched / hash keys added
	 */
	public function backfillFromFilecache(
		?OutputInterface $output = null,
		int              $pageSize = 1000,
	): array {

		$lastFileId = 0;
		$files      = 0;
		$hashes     = 0;

		while ( true )
		{
			$page = $this->filecacheService->pageFileidChecksums( $lastFileId, $pageSize );

			if ( $page === [] )
			{
				break;
			}

			foreach ( $page as $fileId => $row )
			{
				$lastFileId = $fileId;
				$parsed     = FilecacheService::parseChecksumString( $row['checksum'] );

				if ( $parsed === [] )
				{
					continue;
				}

				$added = $this->metadataService->backfillHashes( $fileId, $parsed, $row['mtime'] );

				if ( $added > 0 )
				{
					$files ++;
					$hashes += $added;
				}
			}

			$output?->writeln(
				sprintf( '  … %d files backfilled (%d hashes) so far.', $files, $hashes ),
				OutputInterface::VERBOSITY_VERBOSE,
			);
		}

		return [
			'files'  => $files,
			'hashes' => $hashes,
		];
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
	 * @param  string|list<string>|null  $userId  Whose files the groups are
	 *                                            filtered to: one account,
	 *                                            several (the cross-account
	 *                                            picker names a set), or null
	 *                                            for the whole instance.
	 *
	 * @return array{duplicates: array<int, array{algo: string, hash_value: string, file_count: int, files:
	 *                            array<int, array{fileid: int, path: string, name: string}>}>, total_groups: int,
	 *                            pagination: array{offset: int, limit: int}}
	 */
	public function listDuplicatesForUser(
		string|array|null $userId,
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?string $hash = null,
		bool    $anywhere = false,
	): array {

		// The one chokepoint both controllers and the public API reach — so
		// the clamp lives here, not in each caller. A group is two files or
		// more by definition; minCount below 2 makes every hashed file its
		// own "group" and turns the listing into a whole-index scan on
		// demand. The occ command clamps the same way.
		$minCount = max( 2, $minCount );

		$pagination = [
			'offset' => $offset,
			'limit'  => $limit,
		];

		// How many groups survive the per-user filter is unknown until it
		// runs, so the query cannot carry the caller's limit. But it need not
		// fetch a fixed 10 000 either: read a page, keep what the caller can
		// see, and stop the moment $limit groups are in hand. A caller who
		// owns most of what they ask for reads roughly one page; the scan is
		// still bounded, at UNFILTERED_GROUP_FETCH_LIMIT raw groups, for the
		// caller who owns little.
		$pageSize  = max( $limit, self::DUPLICATE_PAGE_SIZE );
		$result    = [];
		$rawOffset = $offset;
		$scanned   = 0;

		while ( count( $result ) < $limit && $scanned < self::UNFILTERED_GROUP_FETCH_LIMIT )
		{
			$groups = $this->findAllDuplicates( $algo, $minCount, $pageSize, $rawOffset, $hash, $anywhere );

			if ( $groups === [] )
			{
				break;
			}

			$rawOffset += count( $groups );
			$scanned   += count( $groups );

			$pageFileIds = [];

			foreach ( $groups as $group )
			{
				foreach ( $group['fileids'] as $fileId )
				{
					$pageFileIds[] = $fileId;
				}
			}

			$fcPaths = $this->batchLookupFilecachePaths( $pageFileIds, $userId );

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

			if ( count( $groups ) < $pageSize )
			{
				// The page came back short: the raw groups are exhausted.
				break;
			}
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
	 * @param  int[]                     $fileIds
	 * @param  string|list<string>|null  $userName  One account, several, or
	 *                                              null for every file.
	 *
	 * @return array<int, array{path: string, name: string, storage_id: string, user: string}>
	 */
	public function batchLookupFilecachePaths(
		array             $fileIds,
		string|array|null $userName = null,
	): array {

		// Accounts in, mounts down: the one place the listing's reach is
		// resolved, so the listing, the set listing and the occ command all
		// mean the same thing by "whose files".
		return $this->filecacheService->batchLookupFilecachePaths(
			$fileIds,
			$this->reach->mountsFor( $userName ),
		);
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
	 * Clearing is not forgetting: the file keeps its stamp at zero, so the
	 * next rule sweep sees it as stale and the queue-drain job recomputes
	 * what a rule still asks for.
	 */
	public function deleteHashes( int $fileId ): int
	{

		$this->metadataService->clearMetadata( $fileId );

		return 1;
	}

}
