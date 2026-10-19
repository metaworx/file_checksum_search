<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\Files\Config\IUserMountCache;
use OCP\Files\IRootFolder;
use Throwable;

/**
 * What a team folder's advanced permissions take out of reach.
 *
 * Reach is a mount, a storage and a root ({@see ReachResolver}), and is
 * decided in SQL. A team folder with advanced permissions can deny an
 * account a subfolder of its mount, which no query of the filecache can
 * see: the groupfolders app applies it in the account's own view of the
 * folder, so that the account's folder never opens what it may not read.
 *
 * So a file in such a folder is opened here through the folders of the
 * accounts in reach — {@see \OCP\Files\Folder::getFirstNodeById()} — and is
 * out of reach where none of them can. Only those files: the folders are
 * read from the app's table ({@see GroupFolderService::aclRoots()}), and a
 * file anywhere else costs nothing. Where that table cannot be read, every
 * file is opened, which is slower and never wider.
 *
 * One account that may read a file is enough, as with a share. A caller
 * with no accounts to ask — a sudoer, whose reach is the whole instance —
 * does not come here.
 *
 * Opened in a web request for an account other than the session's,
 * groupfolders asks for read *and share* permission (its `$inShare`): a
 * group admin is shown a member's file in such a folder only where the
 * member may read and share it. That errs on the closed side.
 *
 * Not readonly: the resolver's tests double this class, and PHPUnit cannot
 * mock readonly classes (TESTING.md §6.1).
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class TeamFolderAccess
{
	/**
	 * {@see GroupFolderService::aclRoots()}, read once per request; false
	 * until it is.
	 *
	 * @var list<array{storage: int, root: string}>|null|false
	 */
	private array|null|false $roots = false;


//  constructor

	public function __construct(
		private readonly GroupFolderService $groupFolders,
		private readonly IRootFolder        $rootFolder,
		private readonly IUserMountCache    $mountCache,
	) {
	}


//  other non-static methods

	/**
	 * The files among $rows that lie in a team folder with advanced
	 * permissions on and that no account in $uids may open through its own
	 * folder.
	 *
	 * @param  list<string>                                   $uids  The accounts in reach.
	 * @param  array<int, array{storage: int, path: string}>  $rows  Keyed by file id;
	 *                                                               `path` is the
	 *                                                               filecache's.
	 *
	 * @return list<int>  Their file ids, in the order of $rows.
	 */
	public function deniedAmong(
		array $uids,
		array $rows,
	): array
	{
		if ( $rows === [] )
		{
			return [];
		}

		$this->roots = $this->roots === false
			? $this->groupFolders->aclRoots()
			: $this->roots;

		if ( $this->roots === [] )
		{
			return [];
		}

		$denied = [];

		foreach ( $rows as $fileId => $row )
		{
			// A folder's root is a mount's: below it is in the folder.
			if ( $this->roots !== null && ! ReachResolver::within( $this->roots, $row['storage'], $row['path'] ) )
			{
				continue;
			}

			if ( ! $this->anyMayOpen( $uids, (int) $fileId ) )
			{
				$denied[] = (int) $fileId;
			}
		}

		return $denied;
	}

	/**
	 * Whether one of $uids can open $fileId through its own folder.
	 *
	 * With several accounts, only those the mount cache says hold the file
	 * are asked, in the order given; the others' folders would not open it
	 * anyway, and each costs a filesystem set up for that account.
	 *
	 * @param  list<string>  $uids
	 */
	private function anyMayOpen(
		array $uids,
		int   $fileId,
	): bool
	{
		foreach ( count( $uids ) > 1 ? $this->holdersAmong( $uids, $fileId ) : $uids as $uid )
		{
			try
			{
				if ( $this->rootFolder->getUserFolder( $uid )->getFirstNodeById( $fileId ) !== null )
				{
					return true;
				}
			}
			catch ( Throwable )
			{
				// A folder that will not open opens nothing.
			}
		}

		return false;
	}

	/**
	 * The accounts of $uids that hold $fileId by a mount of theirs, in the
	 * order of $uids. Matched in any case, as the user backend finds them.
	 *
	 * @param  list<string>  $uids
	 *
	 * @return list<string>
	 */
	private function holdersAmong(
		array $uids,
		int   $fileId,
	): array
	{
		$holders = [];

		foreach ( $this->mountCache->getMountsForFileId( $fileId ) as $mount )
		{
			$holders[ strtolower( $mount->getUser()->getUID() ) ] = true;
		}

		return array_values( array_filter(
			$uids,
			static fn ( string $uid ): bool => isset( $holders[ strtolower( $uid ) ] ),
		) );
	}
}
