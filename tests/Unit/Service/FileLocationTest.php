<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\FileLocation;
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


	public function testAnExternalMountIsOther(): void
	{

		$location = FileLocation::fromRow(
			5,
			'smb::backup@fileserver//share/root',
			'files/report.xlsx',
			100,
		);

		$this->assertSame( FileLocation::NS_OTHER, $location->namespace );
		$this->assertNull( $location->owner );
		$this->assertNull( $location->groupFolderId );
		$this->assertSame( '/report.xlsx', $location->relativePath );
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


	public function testTheFilesRootItselfIsSlash(): void
	{

		$location = FileLocation::fromRow( 7, 'home::alice', 'files', 100 );

		$this->assertSame( '/', $location->relativePath );
	}


	public function testDescribePrintsHomePathsAsTheOwnersView(): void
	{

		$this->assertSame(
			'/alice/files/Photos/x.jpg',
			FileLocation::fromRow( 8, 'home::alice', 'files/Photos/x.jpg', 100 )
			            ->describe(),
		);
		$this->assertSame(
			'groupfolder:5/Team/notes.md',
			FileLocation::fromRow(
				9,
				'local::/data/__groupfolders/5/',
				'files/Team/notes.md',
				100,
			)
			            ->describe(),
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

}
