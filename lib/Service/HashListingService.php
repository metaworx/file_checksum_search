<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use Generator;
use OCP\DB\Exception;

/**
 * Every hash in reach, page by page.
 *
 * For a caller that keeps a copy — an archive syncing its own database
 * with the checksums here — and would otherwise ask hash by hash. One entry
 * per file, paged by file id: `after` names the last file received, `next`
 * the one to pass on, and null when the listing is done.
 *
 * Which files: those the index holds a hash row for, within the reach's
 * mounts, not disowned ({@see MetadataService::pageListedFiles()}). The
 * same rows a lookup selects, with one difference: a lookup then opens each
 * file through the account's folder, which applies a team folder's access
 * rules, and this listing does not yet.
 * Their values: from the metadata documents, since the index holds a
 * truncated SHA-256 ({@see MetadataService::listedHashes()}). Their paths:
 * from the reach's own mounts, in one pass per page, never from a node per
 * file ({@see ReachResolver::filesViewsFor()}).
 */
class HashListingService
{

//  constants

	public const DEFAULT_LIMIT = 500;
	public const MAX_LIMIT     = 1000;

	/** Files {@see iterate()} reads at once, and holds at once. */
	public const ITERATE_BATCH = 500;


//  constructor

	public function __construct(
		private readonly MetadataService  $metadataService,
		private readonly FilecacheService $filecacheService,
		private readonly ReachResolver    $reach,
	) {
	}


//  static methods

	/**
	 * The views' areas, once each, as {@see FilecacheService::andWhereWithin()} takes them.
	 *
	 * @param  list<array{uid: string, storage: int, root: string, prefix: string}>  $views
	 *
	 * @return list<array{storage: int, root: string}>
	 */
	private static function areasOf( array $views ): array
	{
		$areas = [];

		foreach ( $views as $view )
		{
			$areas[ $view['storage'] . "\0" . $view['root'] ] = [
				'storage' => $view['storage'],
				'root'    => $view['root'],
			];
		}

		return array_values( $areas );
	}

	/**
	 * An algorithm as the hash keys spell it, null for none: the selection
	 * lowercases through the key, and the values have to be read under the
	 * same name.
	 */
	private static function algoOf( ?string $algo ): ?string
	{
		return $algo === null || $algo === '' ? null : strtolower( $algo );
	}

	/**
	 * A file's stamp as the API gives it: ISO 8601, null for none. Zero is
	 * none: it is what a file's hashes carry when they were written without
	 * a time, or cleared, so that the next sweep recomputes them.
	 *
	 * The stamp is not when the hashes were written. It says until when the
	 * app holds them current, and is what freshness compares with a file's
	 * mtime: the time of the computation for hashes this app computed, the
	 * file's mtime for checksums taken over from Nextcloud's filecache, an
	 * import's own value for an import. A recalculation by hand leaves it.
	 */
	public static function stamp( ?int $updatedAt ): ?string
	{
		return $updatedAt !== null && $updatedAt > 0
			? date( 'c', $updatedAt )
			: null;
	}


//  other non-static methods

	/**
	 * One page of the listing.
	 *
	 * @param  list<string>|null  $reachUids      Whose files: their own, the
	 *                                            shares they received (to the
	 *                                            shared subtree) and their team
	 *                                            folders. Null is every file.
	 * @param  string|null        $algo           Only files with a hash in this
	 *                                            algorithm, and only that hash.
	 * @param  int                $limit          Files on the page, 0 to 1000;
	 *                                            0 lists none, and from the
	 *                                            start is the count alone.
	 * @param  int                $after          The last file id received; 0
	 *                                            starts the listing.
	 * @param  int|null           $since          Only files whose stamp is at
	 *                                            or after it, unix seconds; a
	 *                                            file without one never
	 *                                            ({@see stamp()}).
	 * @param  bool               $withLocalPath  Each entry gains `localPath`,
	 *                                            as {@see FilecacheService::localPaths()}.
	 *
	 * @return array{files: list<array{fileid: int, path: string, name: string, owner: ?string, location: string, localPath?: ?string, updated_at: ?string, hashes: array<string, array{algo: string, hash: string}>}>, next: ?int, estimated_total?: int}
	 *         `next` is the file id to pass as `after` while files remain,
	 *         null when none do, and `after` itself for a count. With no
	 *         `after`, `estimated_total` counts the whole listing: an
	 *         estimate, as files come and go while it is read.
	 * @throws Exception
	 */
	public function page(
		?array  $reachUids,
		?string $algo = null,
		int     $limit = self::DEFAULT_LIMIT,
		int     $after = 0,
		?int    $since = null,
		bool    $withLocalPath = false,
	): array
	{
		$limit = max( 0, min( $limit, self::MAX_LIMIT ) );
		$after = max( 0, $after );
		$algo  = self::algoOf( $algo );
		$views = $this->viewsOf( $reachUids );
		$areas = $views === null ? null : self::areasOf( $views );

		$answer = [
			'files' => [],
			'next'  => $after,
		];

		if ( $limit > 0 )
		{
			// One past the page, to know whether another follows.
			$rows = $this->metadataService->pageListedFiles( $areas, $algo, $after, $since, $limit + 1 );
			$more = count( $rows ) > $limit;
			$rows = array_slice( $rows, 0, $limit );

			$answer['files'] = $this->entries( $rows, $views, $algo, $withLocalPath );
			$answer['next']  = $more ? $rows[ $limit - 1 ]['fileid'] : null;
		}

		if ( $after === 0 )
		{
			$answer['estimated_total'] = $this->metadataService->countListedFiles( $areas, $algo, $since );
		}

		return $answer;
	}

	/**
	 * The whole listing, file by file, for a caller in this process.
	 *
	 * The pages of {@see page()}, read {@see ITERATE_BATCH} files at a time
	 * and handed on one by one; a batch is let go when the next is read, so
	 * one is held at a time however large the listing. Each batch is a query
	 * of its own, not one result read row by row: on MySQL and PostgreSQL the
	 * whole result would reach PHP when the query ran, and a result left
	 * open would forbid the caller's own queries between two files. No
	 * count: {@see page()} with a limit of 0 gives one.
	 *
	 * @param  list<string>|null  $reachUids      As {@see page()}.
	 * @param  string|null        $algo           As {@see page()}.
	 * @param  int|null           $since          As {@see page()}.
	 * @param  bool               $withLocalPath  As {@see page()}.
	 * @param  int                $after          The last file id a walk that
	 *                                            was cut short received; 0
	 *                                            starts at the beginning.
	 *
	 * @return Generator<int, array{fileid: int, path: string, name: string, owner: ?string, location: string, localPath?: ?string, updated_at: ?string, hashes: array<string, array{algo: string, hash: string}>}>
	 *         keyed by file id, in file id order
	 * @throws Exception
	 */
	public function iterate(
		?array  $reachUids,
		?string $algo = null,
		?int    $since = null,
		bool    $withLocalPath = false,
		int     $after = 0,
	): Generator
	{
		$after = max( 0, $after );
		$algo  = self::algoOf( $algo );
		$views = $this->viewsOf( $reachUids );
		$areas = $views === null ? null : self::areasOf( $views );

		do
		{
			$rows = $this->metadataService->pageListedFiles( $areas, $algo, $after, $since, self::ITERATE_BATCH );

			foreach ( $this->entries( $rows, $views, $algo, $withLocalPath ) as $entry )
			{
				yield $entry['fileid'] => $entry;
			}

			$after = $rows === [] ? $after : $rows[ array_key_last( $rows ) ]['fileid'];
		}
		while ( count( $rows ) === self::ITERATE_BATCH );
	}

	/**
	 * The reach's views, resolved once per call: null for every file.
	 *
	 * @param  list<string>|null  $reachUids
	 *
	 * @return list<array{uid: string, storage: int, root: string, prefix: string}>|null
	 */
	private function viewsOf( ?array $reachUids ): ?array
	{
		return $reachUids === null
			? null
			: $this->reach->filesViewsFor( array_values( $reachUids ) );
	}

	/**
	 * A page's rows, as the listing gives them.
	 *
	 * @param  list<array{fileid: int, storage: int, storage_id: string, path: string, updated_at: ?int}>  $rows
	 * @param  list<array{uid: string, storage: int, root: string, prefix: string}>|null                     $views
	 *
	 * @return list<array{fileid: int, path: string, name: string, owner: ?string, location: string, localPath?: ?string, updated_at: ?string, hashes: array<string, array{algo: string, hash: string}>}>
	 * @throws Exception
	 */
	private function entries(
		array   $rows,
		?array  $views,
		?string $algo,
		bool    $withLocalPath,
	): array
	{
		if ( $rows === [] )
		{
			return [];
		}

		$hashes    = $this->metadataService->listedHashes( array_column( $rows, 'fileid' ), $algo );
		$locations = [];

		foreach ( $rows as $row )
		{
			$locations[ $row['fileid'] ] = FileLocation::fromRow( $row['fileid'], $row['storage_id'], $row['path'], 0 );
		}

		$localPaths = $withLocalPath
			? $this->filecacheService->localPaths( array_values( $locations ) )
			: [];

		$entries = [];

		foreach ( $rows as $row )
		{
			$fileId   = $row['fileid'];
			$location = $locations[ $fileId ];

			// The first account in reach that holds the file names it. With
			// no account named, the owner's view; a file nobody owns, its
			// path in the area `location` names.
			$path = $views === null
				? null
				: ReachResolver::pathInViews( $views, $row['storage'], $row['path'] );
			$path ??= $location->relativePath ?? '/' . $row['path'];

			$byAlgo = [];

			foreach ( $hashes[ $fileId ] ?? [] as $name => $hash )
			{
				$byAlgo[ $name ] = [
					'algo' => $name,
					'hash' => $hash,
				];
			}

			$entries[] = [
				'fileid'   => $fileId,
				'path'     => $path,
				'name'     => substr( $path, (int) strrpos( $path, '/' ) + 1 ),
				'owner'    => $location->owner,
				'location' => $location->describe(),
			] + ( $withLocalPath ? [ 'localPath' => $localPaths[ $fileId ] ?? null ] : [] ) + [
				'updated_at' => self::stamp( $row['updated_at'] ),
				'hashes'     => $byAlgo,
			];
		}

		return $entries;
	}
}
