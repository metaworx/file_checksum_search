<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Config\IUserMountCache;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * What a set of accounts can reach, as mounts.
 *
 * The one place reach is resolved. A listing, a lookup and a per-file check
 * used to each answer it their own way — the listing by `home::<uid>`
 * storages, the lookup by mount storage ids, the per-file check by whatever
 * folder the session happened to have — and so disagreed about what one
 * account could see. They all come here now.
 *
 * **A mount is a storage and a root**, never the storage alone. A share of
 * a subfolder mounts the owner's *whole* storage, so filtering by storage id
 * would list every file the owner has for whoever received one folder of
 * them. `MetadataService::queryByHash()` says the same and, being only a
 * narrowing step, may take bare storage ids ({@see storageIdsFor()}); the
 * listing decides, and takes the pair ({@see mountsFor()}).
 *
 * `oc_filecache` carries an index on `(storage, path)` for exactly this
 * kind of prefix query, so "within the mount" costs what a storage filter
 * costs.
 */
class ReachResolver
{

//  constructor

	public function __construct(
		private readonly IUserMountCache $mountCache,
		private readonly IUserManager    $userManager,
		private readonly IDBConnection   $db,
	) {
	}


//  static methods

	/**
	 * The path the first of $views that holds a file gives it, with a
	 * leading slash; null when none holds it.
	 *
	 * @param  list<array{uid: string, storage: int, root: string, prefix: string}>  $views  From {@see filesViewsFor()}.
	 * @param  string                                                                  $path   The file's internal path in its storage.
	 */
	public static function pathInViews(
		array  $views,
		int    $storage,
		string $path,
	): ?string
	{
		foreach ( $views as $view )
		{
			if ( $view['storage'] !== $storage )
			{
				continue;
			}

			if ( $view['root'] === '' )
			{
				return $view['prefix'] . $path;
			}

			if ( $path === $view['root'] )
			{
				// A shared file is mounted as itself.
				return rtrim( $view['prefix'], '/' );
			}

			if ( str_starts_with( $path, $view['root'] . '/' ) )
			{
				return $view['prefix'] . substr( $path, strlen( $view['root'] ) + 1 );
			}
		}

		return null;
	}


//  other non-static methods

	/**
	 * Every mount of every named account, once each.
	 *
	 * @param  string|list<string>|null  $uids  One account, several, or null
	 *                                          for every account.
	 *
	 * @return list<array{storage: int, root: string}>|null  `root` is the
	 *         mount's internal path within its storage — `''` for a home,
	 *         the shared folder for a share. Null means everything; an empty
	 *         list means nothing, which is what an unknown account, or a
	 *         group with no members, reaches.
	 */
	public function mountsFor( string|array|null $uids ): ?array
	{
		if ( $uids === null )
		{
			return null;
		}

		$userMounts = [];

		foreach ( is_array( $uids ) ? $uids : [ $uids ] as $uid )
		{
			$user = $this->userManager->get( (string) $uid );

			if ( $user !== null )
			{
				array_push( $userMounts, ...array_values( $this->mountCache->getMountsForUser( $user ) ) );
			}
		}

		// Where each root is, from the filecache: not from the mount, which
		// says the sharer's whole storage for a share recorded in this same
		// request ({@see rootsOf()}).
		$roots  = $this->rootsOf( $userMounts );
		$mounts = [];

		foreach ( $userMounts as $mount )
		{
			$root = $roots[ $mount->getRootId() ] ?? null;

			if ( $root === null )
			{
				// A storage not yet scanned: nothing in it to reach.
				continue;
			}

			$mounts[ $root['storage'] . "\0" . $root['path'] ] = [
				'storage' => $root['storage'],
				'root'    => $root['path'],
			];
		}

		return array_values( $mounts );
	}

	/**
	 * Where each named account sees its files: per mount, the part of the
	 * storage that lies in the account's files area, and the path it has
	 * there.
	 *
	 * Narrower than {@see mountsFor()}, which hands a home over whole, the
	 * trash and the versions included: here a home is its `files/` subtree.
	 * What a view holds has a path the account would know it by, so a
	 * listing can name a file without asking the filesystem for its node —
	 * file by file, the cost of a large page.
	 *
	 * In the order the accounts are named, so "the first account that holds
	 * it" means the same on every page.
	 *
	 * @param  list<string>  $uids
	 *
	 * @return list<array{uid: string, storage: int, root: string, prefix: string}>
	 *         `root` is the subtree's internal path in the storage, `''` for
	 *         the whole storage; a file at `root/x/y` is `prefix . 'x/y'` to
	 *         the account, `prefix` beginning and ending with a slash. An
	 *         unknown account sees nothing.
	 */
	public function filesViewsFor( array $uids ): array
	{
		$mounts = [];

		foreach ( $uids as $uid )
		{
			$uid  = (string) $uid;
			$user = $this->userManager->get( $uid );

			if ( $user !== null )
			{
				$mounts[ $uid ] = $this->mountCache->getMountsForUser( $user );
			}
		}

		$roots = $this->rootsOf( array_merge( ...array_values( $mounts ) ) );
		$views = [];

		foreach ( $mounts as $uid => $userMounts )
		{
			$home  = '/' . $uid . '/';
			$files = $home . 'files/';

			foreach ( $userMounts as $mount )
			{
				if ( ! isset( $roots[ $mount->getRootId() ] ) )
				{
					// A storage not yet scanned: nothing in it to list.
					continue;
				}

				$point   = $mount->getMountPoint();
				$storage = $roots[ $mount->getRootId() ]['storage'];
				$root    = $roots[ $mount->getRootId() ]['path'];

				if ( $point === $home )
				{
					// The home: its files area, not the trash or the versions
					// beside it.
					$root   = $root === '' ? 'files' : $root . '/files';
					$prefix = '/';
				}
				elseif ( str_starts_with( $point, $files ) )
				{
					// A share, a team folder, an external storage: mounted
					// whole, somewhere in the files.
					$prefix = '/' . substr( $point, strlen( $files ) );
				}
				else
				{
					continue;
				}

				$views[] = [
					'uid'     => (string) $uid,
					'storage' => $storage,
					'root'    => $root,
					'prefix'  => $prefix,
				];
			}
		}

		return $views;
	}

	/**
	 * Where each mount's root really is: its storage and internal path, from
	 * the filecache row its root id names.
	 *
	 * Not from the mount itself. A mount recorded in this same request —
	 * the first time an account's files are set up after a share reached
	 * them — answers `getRootInternalPath()` within its own storage, `''`
	 * for a share, while its storage id is the sharer's: the sharer's whole
	 * storage. The root id is the shared node's either way.
	 *
	 * @param  list<\OCP\Files\Config\ICachedMountInfo>  $mounts
	 *
	 * @return array<int, array{storage: int, path: string}>  keyed by root id
	 */
	private function rootsOf( array $mounts ): array
	{
		$rootIds = array_values( array_unique( array_map(
			static fn ( \OCP\Files\Config\ICachedMountInfo $mount ): int => $mount->getRootId(),
			$mounts,
		) ) );
		$roots   = [];

		// 1000 per IN(): Oracle's placeholder ceiling.
		foreach ( array_chunk( $rootIds, 1000 ) as $chunk )
		{
			$qb = $this->db->getQueryBuilder();
			$qb->select( 'fileid', 'storage', 'path' )
			   ->from( 'filecache' )
			   ->where( $qb->expr()->in( 'fileid', $qb->createNamedParameter( $chunk, IQueryBuilder::PARAM_INT_ARRAY ) ) )
			;

			$result = $qb->executeQuery();

			while ( ( $row = $result->fetchAssociative() ) !== false )
			{
				$roots[ (int) $row['fileid'] ] = [
					'storage' => (int) $row['storage'],
					'path'    => trim( (string) $row['path'], '/' ),
				];
			}

			$result->closeCursor();
		}

		return $roots;
	}

	/**
	 * The storage ids behind {@see mountsFor()}, for a caller that only
	 * narrows a query and keeps its own per-file authority.
	 *
	 * @param  string|list<string>|null  $uids
	 *
	 * @return list<int>|null
	 */
	public function storageIdsFor( string|array|null $uids ): ?array
	{
		$mounts = $this->mountsFor( $uids );

		if ( $mounts === null )
		{
			return null;
		}

		return array_values( array_unique( array_column( $mounts, 'storage' ) ) );
	}

	/**
	 * Whether $fileId lies within one of $mounts.
	 *
	 * The same test the listing's query makes, made for one file: same
	 * storage, and a path that is the mount's root or below it. Null mounts
	 * contain everything; a file the filecache does not know lies nowhere.
	 *
	 * @param  list<array{storage: int, root: string}>|null  $mounts
	 */
	public function contains(
		?array $mounts,
		int    $fileId,
	): bool
	{
		if ( $mounts === null )
		{
			return true;
		}

		if ( $mounts === [] )
		{
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'storage', 'path' )
		   ->from( 'filecache' )
		   ->where( $qb->expr()->eq( 'fileid', $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ) )
		;

		$result = $qb->executeQuery();
		$row    = $result->fetchAssociative();
		$result->closeCursor();

		if ( $row === false )
		{
			return false;
		}

		$storage = (int) $row['storage'];
		$path    = (string) $row['path'];

		foreach ( $mounts as $mount )
		{
			if ( $mount['storage'] !== $storage )
			{
				continue;
			}

			if ( $mount['root'] === '' || $path === $mount['root'] || str_starts_with( $path, $mount['root'] . '/' ) )
			{
				return true;
			}
		}

		return false;
	}
}
