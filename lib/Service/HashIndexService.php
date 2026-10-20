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

//  constants

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


//  constructor

	public function __construct(
		private readonly HashCalculationService $hashCalc,
		private readonly DuplicateService       $duplicates,
		private readonly MetadataService        $metadataService,
		private readonly FilecacheService       $filecacheService,
		private readonly ReachResolver          $reach,
	) {
	}


//  static methods

	/**
	 * Whether a group's members are copies of more than one file outside
	 * the trash: one file and its own versions, or only versions of one
	 * file, are its history, not a duplicate — saved unchanged, a file makes
	 * a version identical to itself — and a file whose other copies are all
	 * in the trash has none left to remove. A copy in the trash is shown in
	 * a group that is one, marked.
	 *
	 * @param  list<array{fileid: int, version?: array{fileid: int, ...}, trash?: array{deletedAt: string}, ...}>  $files
	 */
	public static function isDuplicate( array $files ): bool
	{
		$of = [];

		foreach ( $files as $file )
		{
			if ( ! isset( $file['trash'] ) )
			{
				$of[ $file['version']['fileid'] ?? $file['fileid'] ] = true;
			}
		}

		return count( $of ) > 1;
	}


//  other non-static methods

	/**
	 * {@see HashCalculationService::recalcFileHash()}, which is where the
	 * behaviour and the return shape are described.
	 */
	public function recalcFileHash(
		File   $file,
		string $algo,
		bool   $skipExisting = true,
	): array
	{
		return $this->hashCalc->recalcFileHash( $file, $algo, $skipExisting );
	}

	/**
	 * {@see FilecacheService::fileSizes()}.
	 *
	 * @param  int[]  $fileIds
	 *
	 * @return array<int, int>
	 */
	public function fileSizes( array $fileIds ): array
	{
		return $this->filecacheService->fileSizes( $fileIds );
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
	): array
	{
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
	): array
	{
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
	 * {@see HashCalculationService::markMissingHashes()}.
	 *
	 * @return array{marked: int, skipped: int}
	 */
	public function markMissingHashes(
		string           $userId,
		?string          $pathPattern,
		int              $batchSize,
		RuleOverrides    $overrides,
		string           $mode,
		?OutputInterface $output = null,
	): array
	{
		return $this->hashCalc->markMissingHashes( $userId, $pathPattern, $batchSize, $overrides, $mode, $output );
	}

	/**
	 * {@see HashCalculationService::generateVersionHashes()}: the pass over
	 * an account's versions, after its files.
	 */
	public function generateVersionHashes(
		string           $userId,
		string|array     $algo,
		?string          $pathPattern = null,
		int              $batchSize = 100,
		?OutputInterface $output = null,
		?RuleOverrides   $overrides = null,
		string           $mode = MetadataService::PENDING_MODE_MISSING,
		bool             $all = false,
	): array
	{
		return $this->hashCalc->generateVersionHashes(
			$userId,
			$algo,
			$pathPattern,
			$batchSize,
			$output,
			$overrides,
			$mode,
			$all,
		);
	}

	/**
	 * {@see HashCalculationService::markVersions()}.
	 */
	public function markVersions(
		string           $userId,
		?string          $pathPattern,
		int              $batchSize,
		RuleOverrides    $overrides,
		string           $mode,
		?OutputInterface $output = null,
	): int
	{
		return $this->hashCalc->markVersions( $userId, $pathPattern, $batchSize, $overrides, $mode, $output );
	}

	/**
	 * @return array{algo: string, hash_value: string, file_count: int, fileids: int[], empty: bool}[]
	 */
	public function findAllDuplicates(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?string $hash = null,
		bool    $anywhere = false,
		bool    $includeEmpty = false,
	): array
	{
		return $this->duplicates->findAllDuplicates( $algo, $minCount, $limit, $offset, $hash, $anywhere, $includeEmpty );
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
	 * instance size, and resumable: $after starts past a file id a previous
	 * pass reached, and $keepGoing, asked after every page, ends the pass
	 * early — which is how {@see \OCA\FileChecksumSearch\BackgroundJob\FilecacheBackfill}
	 * spreads an instance's worth of rows over several cron runs instead of
	 * one request.
	 *
	 * @param  int                    $after      Start past this file id.
	 * @param  (\Closure(): bool)|null  $keepGoing  Asked after each page; false stops.
	 *
	 * @return array{files: int, hashes: int, last: int, done: bool}  Files
	 *         touched, hash keys added, the last file id read, and whether
	 *         the filecache ran out (true) or $keepGoing stopped it.
	 */
	public function backfillFromFilecache(
		?OutputInterface $output = null,
		int              $pageSize = 1000,
		int              $after = 0,
		?\Closure        $keepGoing = null,
	): array
	{
		$lastFileId = $after;
		$files      = 0;
		$hashes     = 0;
		$done       = false;

		while ( true )
		{
			$page = $this->filecacheService->pageFileidChecksums( $lastFileId, $pageSize );

			if ( $page === [] )
			{
				$done = true;

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

				$added = $this->metadataService->backfillHashes( $fileId, $parsed, $row['mtime'], $row['etag'], $row['storageMtime'] );

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

			if ( $keepGoing !== null && ! $keepGoing() )
			{
				break;
			}
		}

		return [
			'files'  => $files,
			'hashes' => $hashes,
			'last'   => $lastFileId,
			'done'   => $done,
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
	 * @param  bool                      $includeEmpty  List the groups of
	 *                                                  empty files too.
	 * @param  bool                      $canonical     Every `location` the
	 *                                                  file's own address, as
	 *                                                  a sudoer reads it;
	 *                                                  otherwise a file the
	 *                                                  accounts hold through a
	 *                                                  share is located by the
	 *                                                  share.
	 *
	 * @return array{duplicates: array<int, array{algo: string, hash_value: string, file_count: int, empty: bool,
	 *                            files: array<int, array{fileid: int, path: string, name: string, owner: ?string,
	 *                            location: string}>}>, total_groups: int, pagination: array{offset: int, limit: int}}
	 *         `path` is the first named account's path for the file, with a
	 *         leading slash; for the whole instance, its path below its area.
	 */
	public function listDuplicatesForUser(
		string|array|null $userId,
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?string $hash = null,
		bool    $anywhere = false,
		bool    $includeEmpty = false,
		bool    $canonical = false,
	): array
	{
		// Who names the rows: the accounts' own views, read once.
		$views = $userId === null
			? null
			: $this->reach->filesViewsFor( array_values( (array) $userId ) );

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
			$groups = $this->findAllDuplicates( $algo, $minCount, $pageSize, $rawOffset, $hash, $anywhere, $includeEmpty );

			if ( $groups === [] )
			{
				break;
			}

			$rawOffset += count( $groups );
			$scanned += count( $groups );

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
						$seen = $this->reach->asSeenIn( $views, $fcPaths[ $fileId ], $canonical );

						$files[] = [
							'fileid'   => $fileId,
							'path'     => $seen['path'],
							'name'     => $seen['name'],
							'owner'    => $seen['owner'] ?? null,
							'location' => $seen['location'],
						]
							+ ( isset( $seen['version'] ) ? [ 'version' => $seen['version'] ] : [] )
							+ ( isset( $seen['trash'] ) ? [ 'trash' => $seen['trash'] ] : [] );
					}
				}

				if ( count( $files ) < $minCount || ! self::isDuplicate( $files ) )
				{
					continue;
				}

				$result[] = [
					'algo'       => $group['algo'],
					'hash_value' => $group['hash_value'],
					'file_count' => count( $files ),
					'empty'      => $group['empty'],
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
	 * @param  string|list<string>|null  $userName       One account, several, or
	 *                                                   null for every file.
	 * @param  bool                      $withLocalPath  As {@see FilecacheService::batchLookupFilecachePaths()}.
	 *
	 * @return array<int, array{path: string, name: string, storage: int, storage_id: string, internal_path: string, owner: ?string, location: string, local_path?: ?string, file?: array{fileid: int, internal_path: string, path: string, location: string, name: string}, version?: array{fileid: int, timestamp: string}, trash?: array{deletedAt: string}}>
	 */
	public function batchLookupFilecachePaths(
		array             $fileIds,
		string|array|null $userName = null,
		bool              $withLocalPath = false,
	): array
	{
		// Accounts in, mounts down: the one place the listing's reach is
		// resolved, so the listing, the set listing and the occ command all
		// mean the same thing by "whose files". Then what no mount says: a
		// file a team folder's advanced permissions deny every one of them.
		$rows = $this->filecacheService->batchLookupFilecachePaths(
			$fileIds,
			$this->reach->mountsFor( $userName ),
			$withLocalPath,
		);

		return $userName === null
			? $rows
			: $this->reach->withoutDenied( array_values( (array) $userName ), $rows, 'internal_path' );
	}

	/**
	 * @param  string|null  $userName       When provided, results are restricted to
	 *                                      files in that user's home storage.
	 * @param  bool         $withLocalPath  Each row gains `local_path`, as
	 *                                      {@see FilecacheService::batchLookupFilecachePaths()}.
	 *
	 * @return array<int, array{fileid: int, algo: string, hash_value: string, path: string, name: string, owner: ?string, location: string, local_path?: ?string, version?: array{fileid: int, timestamp: string}, trash?: array{deletedAt: string}}>
	 */
	public function findByHash(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
		?string $userName = null,
		bool    $withLocalPath = false,
	): array
	{
		return $this->duplicates->findByHash( $hash, $algo, $limit, $userName, $withLocalPath );
	}

	/**
	 * The trashed files among $fileIds that one of $uids holds, each with
	 * the path the first of them sees its place before the deletion at,
	 * after {@see TrashOf::SCHEME}, and `trash`: for a caller that opens a
	 * result through a folder, which reaches no trashed file. Held as the
	 * listing holds one ({@see FilecacheService::withPlaces()}): by its
	 * place's reach and a team folder's permissions there. Null accounts, a
	 * sudoer's, hold every one, at its owner's path.
	 *
	 * @param  list<int>          $fileIds
	 * @param  list<string>|null  $uids
	 *
	 * @return array<int, array{path: string, name: string, trash: array{deletedAt: string}}>
	 *         keyed by file id
	 * @throws \OCP\DB\Exception
	 */
	public function trashAmong(
		array  $fileIds,
		?array $uids,
	): array
	{
		$trashed = [];

		foreach ( $this->filecacheService->locateAll( $fileIds ) as $fileId => $location )
		{
			if ( $location->trash !== null )
			{
				$trashed[] = $fileId;
			}
		}

		if ( $trashed === [] )
		{
			return [];
		}

		$views = $uids === null
			? null
			: $this->reach->filesViewsFor( $uids );
		$among = [];

		foreach ( $this->batchLookupFilecachePaths( $trashed, $uids ) as $fileId => $row )
		{
			if ( ! isset( $row['trash'] ) )
			{
				continue;
			}

			$seen = $this->reach->asSeenIn( $views, $row, false );

			$among[ $fileId ] = [
				'path'  => $seen['path'],
				'name'  => $seen['name'],
				'trash' => $row['trash'],
			];
		}

		return $among;
	}

	/**
	 * The versions among $fileIds, each with its file: for a caller that
	 * opens a result through a folder, which reaches a version only as its
	 * file. A version whose file is gone is not among them.
	 *
	 * @param  list<int>  $fileIds
	 *
	 * @return array<int, array{fileid: int, timestamp: string}>  keyed by the
	 *         version's id: its file's id, and when the version was the file
	 * @throws \OCP\DB\Exception
	 */
	public function versionsAmong( array $fileIds ): array
	{
		$versions = array_filter(
			$this->filecacheService->locateAll( $fileIds ),
			static fn ( FileLocation $location ): bool => $location->version !== null,
		);

		if ( $versions === [] )
		{
			return [];
		}

		$among = [];

		foreach ( $this->filecacheService->filesOfVersions( array_values( $versions ) ) as $versionId => $file )
		{
			if ( $file->storageId === $versions[ $versionId ]->storageId )
			{
				$among[ $versionId ] = [
					'fileid'    => $file->fileId,
					'timestamp' => date( 'c', (int) $versions[ $versionId ]->version?->timestamp ),
				];
			}
		}

		return $among;
	}

	/**
	 * The versions Nextcloud keeps of a file, newest first, each with the
	 * checksums it holds that are current, as the file's own are given: by
	 * algorithm, none where they no longer describe it.
	 *
	 * @return list<array{fileid: int, timestamp: string, size: int, hashes: array<string, array{algo: string, hash: string}>}>
	 * @throws \OCP\DB\Exception
	 */
	public function versionsOf( int $fileId ): array
	{
		$file     = $this->filecacheService->locate( $fileId );
		$versions = $file === null
			? []
			: $this->filecacheService->versionsOf( $file );

		if ( $versions === [] )
		{
			return [];
		}

		$ids     = array_column( $versions, 'fileid' );
		$states  = $this->metadataService->fileStatesOf( $ids );
		$current = array_values( array_filter( $ids, static fn ( int $id ): bool => $states[ $id ]['vouched'] ?? false ) );
		$hashes  = $current === []
			? []
			: $this->metadataService->listedHashes( $current, null );

		return array_map(
			static function( array $version ) use ( $hashes ): array
			{
				$byAlgo = [];

				foreach ( $hashes[ $version['fileid'] ] ?? [] as $algo => $hash )
				{
					$byAlgo[ $algo ] = [
						'algo' => $algo,
						'hash' => $hash,
					];
				}

				return [
					'fileid'    => $version['fileid'],
					'timestamp' => date( 'c', $version['timestamp'] ),
					'size'      => $version['size'],
					'hashes'    => $byAlgo,
				];
			},
			$versions,
		);
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
