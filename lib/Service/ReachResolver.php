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

	public function __construct(
		private readonly IUserMountCache $mountCache,
		private readonly IUserManager    $userManager,
		private readonly IDBConnection   $db,
	) {
	}


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

		$mounts = [];

		foreach ( is_array( $uids ) ? $uids : [ $uids ] as $uid )
		{
			$user = $this->userManager->get( (string) $uid );

			if ( $user === null )
			{
				continue;
			}

			foreach ( $this->mountCache->getMountsForUser( $user ) as $mount )
			{
				$storage = $mount->getStorageId();
				$root    = trim( $mount->getRootInternalPath(), '/' );

				$mounts[ $storage . "\0" . $root ] = [
					'storage' => $storage,
					'root'    => $root,
				];
			}
		}

		return array_values( $mounts );
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
	): bool {

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
		$row    = $result->fetch();
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
