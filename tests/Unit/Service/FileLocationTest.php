<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\FileLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The classification matrix: every storage-id shape Nextcloud produces maps
 * to exactly one namespace, and only rows below a files area get a relative
 * path — everything else (trash, versions, appdata) is invisible to rules.
 */
class FileLocationTest
    extends
    TestCase
{

//  other non-static methods

	public function testAHomeStorageRowBelongsToItsOwner(): void
	{
		$location = FileLocation::fromRow( 1, 'home::alice', 'files/Photos/x.jpg', 100 );

		$this->assertSame( FileLocation::NS_HOME, $location->namespace );
		$this->assertSame( 'alice', $location->owner );
		$this->assertSame( '/Photos/x.jpg', $location->relativePath );
		$this->assertNull( $location->groupFolderId );
	}

	public function testAnObjectStoreHomeRowBelongsToItsOwner(): void
	{
		$location = FileLocation::fromRow( 2, 'object::user:bob', 'files/doc.txt', 100 );

		$this->assertSame( FileLocation::NS_HOME, $location->namespace );
		$this->assertSame( 'bob', $location->owner );
		$this->assertSame( '/doc.txt', $location->relativePath );
	}

	public function testAGroupfolderJailStorageIsItsFolder(): void
	{
		// The current groupfolders layout: one Local storage per folder,
		// prefix-indistinguishable from a local external mount — the jail
		// root in the id is the tell.
		$location = FileLocation::fromRow(
			3,
			'local::/var/www/data/__groupfolders/5/',
			'files/Team/notes.md',
			100,
		);

		$this->assertSame( FileLocation::NS_GROUPFOLDER, $location->namespace );
		$this->assertSame( 5, $location->groupFolderId );
		$this->assertNull( $location->owner );
		$this->assertSame( '/Team/notes.md', $location->relativePath );
	}

	public function testALegacyRootJailRowIsItsFolder(): void
	{
		// The legacy layout jails group folders inside the root storage; the
		// folder id and the files area both live in the internal path.
		$location = FileLocation::fromRow(
			4,
			'local::/var/www/data/',
			'__groupfolders/7/files/plan.md',
			100,
		);

		$this->assertSame( FileLocation::NS_GROUPFOLDER, $location->namespace );
		$this->assertSame( 7, $location->groupFolderId );
		$this->assertSame( '/plan.md', $location->relativePath );
	}

	/**
	 * An external storage holds its files from its root, as Nextcloud's
	 * filecache has them: `2024/report.xlsx`, never below `files/`. This used
	 * to be built as `files/report.xlsx`, which no external storage holds, and
	 * hid that every real row of one had no relative path, so no rule
	 * governed it.
	 */
	public function testAnExternalMountIsOtherAndRelativeFromItsRoot(): void
	{
		$location = FileLocation::fromRow(
			5,
			'smb::backup@fileserver//share//',
			'2024/report.xlsx',
			100,
		);

		$this->assertSame( FileLocation::NS_OTHER, $location->namespace );
		$this->assertNull( $location->owner );
		$this->assertNull( $location->groupFolderId );
		$this->assertSame( '/2024/report.xlsx', $location->relativePath );

		// A folder named `files` on it is a folder like any other.
		$this->assertSame(
			'/files/report.xlsx',
			FileLocation::fromRow( 5, 'local::/mnt/archive/', 'files/report.xlsx', 100 )->relativePath,
		);
		$this->assertSame( '/', FileLocation::fromRow( 5, 'local::/mnt/archive/', '', 100 )->relativePath );
	}

	/**
	 * The instance's root storage reads as `other` too, and holds nothing a
	 * rule may govern: appdata, and the directories of the team folders'
	 * jails.
	 */
	public function testTheRootStoragesAppdataAndJailsHaveNoRelativePath(): void
	{
		foreach (
			[
				'appdata_oc123/preview/1.png',
				'__groupfolders',
				'__groupfolders/7',
				'files_trashbin/files/x.txt.d1234',
				'files_versions/x.txt.v1',
			] as $internalPath
		)
		{
			$this->assertNull(
				FileLocation::fromRow( 6, 'local::/var/www/data/', $internalPath, 100 )->relativePath,
				$internalPath . ' must not be reachable by any rule',
			);
		}
	}

	public function testRowsOutsideAFilesAreaHaveNoRelativePath(): void
	{
		foreach (
			[
				'files_trashbin/files/x.txt.d1234',
				'files_versions/x.txt.v1',
				'appdata_abc/preview/1.png',
				'cache/upload.part',
				'filesx/sneaky.txt',
			] as $internalPath
		)
		{
			$location = FileLocation::fromRow( 6, 'home::alice', $internalPath, 100 );

			$this->assertNull(
				$location->relativePath,
				$internalPath . ' must not be reachable by any rule',
			);
		}
	}

	/**
	 * A home's version is `files_versions/<path>.v<mtime>`: the file it is a
	 * version of is `files/<path>` in the same storage, and it stays outside
	 * the files area itself.
	 */
	public function testAHomeVersionNamesItsFileByPath(): void
	{
		$location = FileLocation::fromRow( 8, 'home::alice', 'files_versions/Docs/a.txt.v1700000000', 1700000000 );

		$this->assertSame( FileLocation::NS_HOME, $location->namespace );
		$this->assertSame( 'alice', $location->owner );
		$this->assertNull( $location->relativePath );
		$this->assertNotNull( $location->version );
		$this->assertSame( 1700000000, $location->version->timestamp );
		$this->assertSame( 'files/Docs/a.txt', $location->version->fileInternalPath );
		$this->assertNull( $location->version->fileId );
	}

	/**
	 * A name may hold `.v` itself: only the last one, before digits alone,
	 * is the version's.
	 */
	public function testOnlyTheLastVersionSuffixIsTheVersions(): void
	{
		$this->assertSame(
			'files/a.v5.txt',
			FileLocation::fromRow( 8, 'home::alice', 'files_versions/a.v5.txt.v1700000000', 1 )->version?->fileInternalPath,
		);
		$this->assertSame(
			'files/notes.v2',
			FileLocation::fromRow( 8, 'object::user:bob', 'files_versions/notes.v2.v1700000000', 1 )->version?->fileInternalPath,
		);
	}

	/**
	 * A team folder's version is `versions/<fileId>/<mtime>` in the folder's
	 * own storage, and names its file by id.
	 */
	public function testATeamFolderVersionNamesItsFileById(): void
	{
		$location = FileLocation::fromRow( 9, 'local::/var/www/data/__groupfolders/5/', 'versions/42/1700000000', 1700000000 );

		$this->assertSame( FileLocation::NS_GROUPFOLDER, $location->namespace );
		$this->assertSame( 5, $location->groupFolderId );
		$this->assertNull( $location->relativePath );
		$this->assertSame( 42, $location->version?->fileId );
		$this->assertSame( 1700000000, $location->version?->timestamp );
		$this->assertNull( $location->version?->fileInternalPath );
	}

	/**
	 * The legacy layout keeps a team folder's versions in the root storage,
	 * `__groupfolders/versions/<id>/<fileId>/<mtime>`: the folder is the
	 * version's area, as it is its file's.
	 */
	public function testALegacyTeamFolderVersionIsItsFoldersAndNamesItsFile(): void
	{
		$location = FileLocation::fromRow( 10, 'local::/var/www/data/', '__groupfolders/versions/7/42/1700000000', 1700000000 );

		$this->assertSame( FileLocation::NS_GROUPFOLDER, $location->namespace );
		$this->assertSame( 7, $location->groupFolderId );
		$this->assertNull( $location->relativePath );
		$this->assertSame( 42, $location->version?->fileId );
		$this->assertSame( 1700000000, $location->version?->timestamp );
	}

	/**
	 * A home's version in the trash names its trashed file, and the trash
	 * item it is inside: `<name>.v<mtime>.d<time>` for a deleted file's,
	 * `<folder>.d<time>/<path>.v<mtime>` for a deleted folder's file's.
	 */
	public function testAHomeVersionInTheTrashNamesItsTrashedFile(): void
	{
		$file   = FileLocation::fromRow( 8, 'home::alice', 'files_trashbin/versions/a.txt.v1700000000.d1700000100', 1 );
		$inside = FileLocation::fromRow( 9, 'home::alice', 'files_trashbin/versions/Docs.d1700000100/sub/b.txt.v1700000000', 1 );

		$this->assertSame( 1700000000, $file->version?->timestamp );
		$this->assertSame( 'files_trashbin/files/a.txt.d1700000100', $file->version?->fileInternalPath );
		$this->assertSame( 'a.txt', $file->trash?->name );
		$this->assertSame( 1700000100, $file->trash?->deletedAt );

		$this->assertSame( 1700000000, $inside->version?->timestamp );
		$this->assertSame( 'files_trashbin/files/Docs.d1700000100/sub/b.txt', $inside->version?->fileInternalPath );
		$this->assertSame( 'Docs', $inside->trash?->name );
		$this->assertSame( 'sub/b.txt', $inside->trash?->subPath );
	}

	/**
	 * What only looks like a version is none: a folder of versions, a name
	 * without the suffix, a user's file named like one, and the root
	 * storage's own directories.
	 */
	public function testRowsThatAreNoVersionNameNoFile(): void
	{
		foreach (
			[
				[ 'home::alice', 'files_versions/Docs' ],
				[ 'home::alice', 'files_versions/a.txt' ],
				[ 'home::alice', 'files_versions/a.txt.vx1' ],
				[ 'home::alice', 'files_trashbin/versions/a.txt.d1700000100' ],
				[ 'home::alice', 'files/a.txt.v1700000000' ],
				[ 'local::/var/www/data/', 'files_versions/x.txt.v1' ],
				[ 'local::/var/www/data/__groupfolders/5/', 'versions/42' ],
				[ 'local::/var/www/data/__groupfolders/5/', 'files/versions/42/1700000000' ],
				[ 'local::/var/www/data/__groupfolders/5/', 'trash/42/1700000000' ],
				[ 'local::/var/www/data/', '__groupfolders/versions/7/42' ],
			] as [ $storageId, $internalPath ]
		)
		{
			$this->assertNull(
				FileLocation::fromRow( 11, $storageId, $internalPath, 1 )->version,
				$storageId . ' ' . $internalPath . ' is no version',
			);
		}
	}

	/**
	 * A home's trash item is `files_trashbin/files/<name>.d<time>`: its name
	 * as deleted and the deletion time, outside the files area.
	 */
	public function testAHomeTrashItemNamesItselfAndItsDeletion(): void
	{
		$location = FileLocation::fromRow( 8, 'home::alice', 'files_trashbin/files/a.txt.d1700000000', 1 );

		$this->assertSame( FileLocation::NS_HOME, $location->namespace );
		$this->assertSame( 'alice', $location->owner );
		$this->assertNull( $location->relativePath );
		$this->assertSame( 'a.txt', $location->trash?->name );
		$this->assertSame( 1700000000, $location->trash?->deletedAt );
		$this->assertSame( '', $location->trash?->subPath );
		$this->assertNull( $location->version );
	}

	/**
	 * A row inside a trashed folder names the folder's item, and its path
	 * below it.
	 */
	public function testARowInsideATrashedFolderNamesTheFoldersItem(): void
	{
		$location = FileLocation::fromRow( 8, 'object::user:bob', 'files_trashbin/files/Docs.d1700000000/sub/b.txt', 1 );

		$this->assertSame( 'Docs', $location->trash?->name );
		$this->assertSame( 1700000000, $location->trash?->deletedAt );
		$this->assertSame( 'sub/b.txt', $location->trash?->subPath );
	}

	/**
	 * A name may hold `.d` itself: only the last one, before digits alone
	 * and the end of the name, is the deletion's.
	 */
	public function testOnlyTheLastDeletionSuffixIsTheDeletions(): void
	{
		$this->assertSame(
			'x.d5.txt',
			FileLocation::fromRow( 8, 'home::alice', 'files_trashbin/files/x.d5.txt.d1700000000', 1 )->trash?->name,
		);
	}

	/**
	 * A team folder's trash item is `trash/<name>.d<time>` in the folder's
	 * own storage, and in the legacy layout
	 * `__groupfolders/trash/<id>/<name>.d<time>` in the root storage: the
	 * folder is its area, as it is its files'.
	 */
	public function testATeamFolderTrashItemIsItsFolders(): void
	{
		$current = FileLocation::fromRow( 9, 'local::/var/www/data/__groupfolders/5/', 'trash/plan.md.d1700000000', 1 );
		$legacy  = FileLocation::fromRow( 10, 'local::/var/www/data/', '__groupfolders/trash/7/Docs.d1700000000/plan.md', 1 );

		$this->assertSame( FileLocation::NS_GROUPFOLDER, $current->namespace );
		$this->assertSame( 5, $current->groupFolderId );
		$this->assertNull( $current->relativePath );
		$this->assertSame( 'plan.md', $current->trash?->name );

		$this->assertSame( FileLocation::NS_GROUPFOLDER, $legacy->namespace );
		$this->assertSame( 7, $legacy->groupFolderId );
		$this->assertNull( $legacy->relativePath );
		$this->assertSame( 'Docs', $legacy->trash?->name );
		$this->assertSame( 'plan.md', $legacy->trash?->subPath );
	}

	/**
	 * What only looks like a trash item is none: the trash's own folders, a
	 * name without the suffix, a user's file named like one.
	 */
	public function testRowsThatAreNoTrashItemNameNone(): void
	{
		foreach (
			[
				[ 'home::alice', 'files_trashbin/files' ],
				[ 'home::alice', 'files_trashbin/files/a.txt' ],
				[ 'home::alice', 'files_trashbin/versions' ],
				[ 'home::alice', 'files/a.txt.d1700000000' ],
				[ 'local::/var/www/data/__groupfolders/5/', 'trash' ],
				[ 'local::/var/www/data/__groupfolders/5/', 'trash/42/1700000000' ],
				[ 'local::/var/www/data/__groupfolders/5/', 'files/trash/a.txt.d1700000000' ],
				[ 'local::/var/www/data/', '__groupfolders/trash/7' ],
			] as [ $storageId, $internalPath ]
		)
		{
			$this->assertNull(
				FileLocation::fromRow( 11, $storageId, $internalPath, 1 )->trash,
				$storageId . ' ' . $internalPath . ' is no trash item',
			);
		}
	}

	/**
	 * Where Nextcloud keeps the version it makes of a file: the inverse of
	 * reading one.
	 */
	public function testTheVersionPathIsWhereNextcloudKeepsTheVersion(): void
	{
		$this->assertSame(
			'files_versions/Docs/a.txt.v1700000000',
			FileLocation::fromRow( 1, 'home::alice', 'files/Docs/a.txt', 1 )->versionPathAt( 1700000000 ),
		);
		$this->assertSame(
			'versions/42/1700000000',
			FileLocation::fromRow( 42, 'local::/var/www/data/__groupfolders/5/', 'files/plan.md', 1 )->versionPathAt( 1700000000 ),
		);
		$this->assertSame(
			'__groupfolders/versions/7/42/1700000000',
			FileLocation::fromRow( 42, 'local::/var/www/data/', '__groupfolders/7/files/plan.md', 1 )->versionPathAt( 1700000000 ),
		);

		$path = (string) FileLocation::fromRow( 1, 'home::alice', 'files/a.v2.txt', 1 )->versionPathAt( 1700000000 );

		$this->assertSame(
			'files/a.v2.txt',
			FileLocation::fromRow( 2, 'home::alice', $path, 1 )->version?->fileInternalPath,
			'reading the path back names the file',
		);
	}

	public function testRowsWithoutVersionsBesideThemHaveNoVersionPath(): void
	{
		foreach (
			[
				[ 'smb::u@host//share//', '2024/x.pdf' ],
				[ 'home::alice', 'files_versions/Docs/a.txt.v1700000000' ],
				[ 'home::alice', 'files_trashbin/files/x.txt.d1' ],
				[ 'home::alice', 'files' ],
			] as [ $storageId, $internalPath ]
		)
		{
			$this->assertNull(
				FileLocation::fromRow( 1, $storageId, $internalPath, 1 )->versionPathAt( 1700000000 ),
				$storageId . ' ' . $internalPath,
			);
		}
	}

	/**
	 * Where a reach that holds a root holds the versions of its files: a
	 * home's files area and a share in one beside them, a team folder in
	 * its own storage, the legacy layout by its folder; a storage held
	 * whole, nothing more.
	 */
	public function testTheVersionRootsOfARoot(): void
	{
		$this->assertSame( [ 'files_versions', 'versions' ], FileLocation::versionRootsOf( 'files' ) );
		$this->assertSame( [ 'files_versions/Docs/x' ], FileLocation::versionRootsOf( 'files/Docs/x' ) );
		$this->assertSame( [ '__groupfolders/versions/7' ], FileLocation::versionRootsOf( '__groupfolders/7' ) );
		$this->assertSame( [], FileLocation::versionRootsOf( '' ) );
		$this->assertSame( [], FileLocation::versionRootsOf( 'filesx/a' ) );
	}

	/**
	 * Where a reach that holds a root holds its files' trash: only beside a
	 * whole files area — a home's, a team folder's, the legacy layout's by
	 * its folder; a share of a folder in one, nothing.
	 */
	public function testTheTrashRootsOfARoot(): void
	{
		$this->assertSame( [ 'files_trashbin/files', 'files_trashbin/versions', 'trash' ], FileLocation::trashRootsOf( 'files' ) );
		$this->assertSame( [], FileLocation::trashRootsOf( 'files/Docs' ) );
		$this->assertSame( [ '__groupfolders/trash/7' ], FileLocation::trashRootsOf( '__groupfolders/7' ) );
		$this->assertSame( [], FileLocation::trashRootsOf( '' ) );
	}

	public function testTheFreshnessStampKeepsTheVersion(): void
	{
		$location = FileLocation::fromRow( 8, 'home::alice', 'files_versions/a.txt.v1700000000', 1 )
		                        ->withUpdatedAt( 5 );

		$this->assertSame( 'files/a.txt', $location->version?->fileInternalPath );
	}

	public function testTheFilesRootItselfIsSlash(): void
	{
		$location = FileLocation::fromRow( 7, 'home::alice', 'files', 100 );

		$this->assertSame( '/', $location->relativePath );
	}

	/**
	 * The address: the area as a rule's selector names it, two slashes, and
	 * the path below the area's top.
	 */
	#[DataProvider( 'addressProvider' )]
	public function testDescribeGivesTheAddress(
		string $storageId,
		string $internalPath,
		string $address,
	): void
	{
		$this->assertSame( $address, FileLocation::fromRow( 8, $storageId, $internalPath, 100 )->describe() );

		// The last `//` separates, whatever the area's id holds.
		$at = strrpos( $address, '//' );

		$this->assertSame(
			FileLocation::fromRow( 8, $storageId, $internalPath, 100 )->area(),
			substr( $address, 0, (int) $at ),
		);
	}

	// ─── localPath ──────────────────────────────────────────────────
	public function testAHomeFileLivesUnderTheAccountsHome(): void
	{
		$location = FileLocation::fromRow( 1, 'home::alice', 'files/Photos/x.jpg', 100 );

		$this->assertSame( '/srv/data/alice/files/Photos/x.jpg', $location->localPath( '/srv/data/alice' ) );
		$this->assertSame( '/srv/data/alice/files/Photos/x.jpg', $location->localPath( '/srv/data/alice/' ) );
	}

	public function testAHomeFileWithoutAKnownHomeHasNoLocalPath(): void
	{
		$this->assertNull( FileLocation::fromRow( 1, 'home::gone', 'files/x.txt', 100 )->localPath( null ) );
	}

	public function testAnObjectStoreHomeHasNoLocalPath(): void
	{
		$this->assertNull(
			FileLocation::fromRow( 2, 'object::user:bob', 'files/doc.txt', 100 )->localPath( '/srv/data/bob' ),
		);
	}

	public function testALocalStorageLivesAtItsRoot(): void
	{
		// The id carries the root with a trailing slash; a test fixture
		// elsewhere writes it without, and the path is the same.
		$this->assertSame(
			'/mnt/archive/2024/scan.pdf',
			FileLocation::fromRow( 3, 'local::/mnt/archive/', '2024/scan.pdf', 100 )->localPath( null ),
		);
		$this->assertSame(
			'/mnt/archive/2024/scan.pdf',
			FileLocation::fromRow( 3, 'local::/mnt/archive', '2024/scan.pdf', 100 )->localPath( null ),
		);
	}

	public function testAGroupFolderLivesUnderTheDataDirectoryInEitherLayout(): void
	{
		$this->assertSame(
			'/srv/data/__groupfolders/5/files/Team/notes.md',
			FileLocation::fromRow( 4, 'local::/srv/data/__groupfolders/5/', 'files/Team/notes.md', 100 )
			            ->localPath( null ),
		);
		$this->assertSame(
			'/srv/data/__groupfolders/5/files/Team/notes.md',
			FileLocation::fromRow( 5, 'local::/srv/data/', '__groupfolders/5/files/Team/notes.md', 100 )
			            ->localPath( null ),
		);
	}

	public function testAShareOrARemoteMountHasNoLocalPath(): void
	{
		$this->assertNull( FileLocation::fromRow( 6, 'shared::/Docs', 'x.txt', 100 )->localPath( null ) );
		$this->assertNull( FileLocation::fromRow( 7, 'smb::user@host//share/', 'x.txt', 100 )->localPath( null ) );
	}


//  static methods

	/**
	 * @return array<string, array{string, string, string}>  storage id, internal path, address
	 */
	public static function addressProvider(): array
	{
		return [
			'a home'                   => [ 'home::alice', 'files/Photos/x.jpg', 'home:alice//Photos/x.jpg' ],
			'an object-store home'     => [ 'object::user:alice', 'files/Photos/x.jpg', 'home:alice//Photos/x.jpg' ],
			'a home\'s top'            => [ 'home::alice', 'files', 'home:alice//' ],
			'a team folder'            => [ 'local::/data/__groupfolders/5/', 'files/Team/notes.md', 'groupfolder:5//Team/notes.md' ],
			'a legacy team folder'     => [ 'local::/data/', '__groupfolders/7/files/plan.md', 'groupfolder:7//plan.md' ],
			'a local storage'          => [ 'local::/mnt/data/', 'docs/x.pdf', 'storage:local::/mnt/data//docs/x.pdf' ],
			'an SMB share'             => [ 'smb::alice@fs//projects//', 'docs/x.pdf', 'storage:smb::alice@fs//projects//docs/x.pdf' ],
			'an SMB share with a root' => [ 'smb::alice@fs//projects//sub/', 'docs/x.pdf', 'storage:smb::alice@fs//projects//sub//docs/x.pdf' ],
			'a long id, stored as MD5' => [ md5( 'x' ), 'docs/x.pdf', 'storage:' . md5( 'x' ) . '//docs/x.pdf' ],
			'outside the files area'   => [ 'home::alice', 'files_trashbin/files/x.d1', 'home:alice//../files_trashbin/files/x.d1' ],
		];
	}
}
