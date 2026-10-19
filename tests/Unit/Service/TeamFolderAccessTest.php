<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\GroupFolderService;
use OCA\FileChecksumSearch\Service\TeamFolderAccess;
use OCP\Files\Config\ICachedMountFileInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Only files in a team folder with advanced permissions are opened, and a
 * file there is out of reach where no account in reach can open it through
 * its own folder. Which folder opens what is the groupfolders app's, mocked
 * here; the integration tests ask a real one.
 */
class TeamFolderAccessTest
    extends
    TestCase
{

//  constants

	/** A team folder with advanced permissions on, as `aclRoots()` gives it. */
	private const ROOTS = [ [ 'storage' => 3, 'root' => '__groupfolders/5' ] ];


//  private properties

	private GroupFolderService&MockObject $groupFolders;

	private IRootFolder&MockObject        $rootFolder;

	private IUserMountCache&MockObject    $mountCache;

	private TeamFolderAccess              $access;

	/**
	 * Per account, the file ids its folder opens.
	 *
	 * @var array<string, list<int>>
	 */
	private array $opens = [];

	/** @var list<string> */
	private array $foldersAsked = [];


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->groupFolders = $this->createMock( GroupFolderService::class );
		$this->rootFolder   = $this->createMock( IRootFolder::class );
		$this->mountCache   = $this->createMock( IUserMountCache::class );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->willReturnCallback( function( string $uid ): Folder
		                 {
			                 $this->foldersAsked[] = $uid;

			                 if ( ! isset( $this->opens[ $uid ] ) )
			                 {
				                 throw new \RuntimeException( "no folder for $uid" );
			                 }

			                 $folder = $this->createMock( Folder::class );
			                 $folder->method( 'getFirstNodeById' )
			                        ->willReturnCallback( fn ( int $id ): ?File => in_array( $id, $this->opens[ $uid ], true )
				                        ? $this->createMock( File::class )
				                        : null )
			                 ;

			                 return $folder;
		                 } )
		;

		$this->access = new TeamFolderAccess( $this->groupFolders, $this->rootFolder, $this->mountCache );
	}


//  other non-static methods

	/**
	 * No folder has advanced permissions on: nothing is opened, whatever
	 * the rows.
	 */
	public function testWithNoSuchFolderNothingIsOpened(): void
	{
		$this->groupFolders->method( 'aclRoots' )
		                   ->willReturn( [] )
		;
		$this->rootFolder->expects( $this->never() )
		                 ->method( 'getUserFolder' )
		;

		$this->assertSame( [], $this->access->deniedAmong( [ 'alice' ], [ 42 => [ 'storage' => 3, 'path' => '__groupfolders/5/x' ] ] ) );
	}

	/**
	 * A file outside every such folder — another folder in the same storage,
	 * a sibling with the same prefix, another storage — is not opened.
	 */
	public function testAFileOutsideTheFoldersIsNotOpened(): void
	{
		$this->groupFolders->method( 'aclRoots' )
		                   ->willReturn( self::ROOTS )
		;
		$this->rootFolder->expects( $this->never() )
		                 ->method( 'getUserFolder' )
		;

		$this->assertSame(
			[],
			$this->access->deniedAmong( [ 'alice' ], [
				41 => [ 'storage' => 3, 'path' => '__groupfolders/6/x' ],
				42 => [ 'storage' => 3, 'path' => '__groupfolders/55/x' ],
				43 => [ 'storage' => 2, 'path' => '__groupfolders/5/x' ],
			] ),
		);
	}

	/**
	 * Inside one, a file is out of reach where the account's folder does
	 * not open it, and in reach where it does; the folder's root counts as
	 * inside.
	 */
	public function testAFileInsideIsDeniedWhereTheAccountsFolderDoesNotOpenIt(): void
	{
		$this->groupFolders->method( 'aclRoots' )
		                   ->willReturn( self::ROOTS )
		;
		$this->opens = [ 'alice' => [ 43 ] ];

		$this->assertSame(
			[ 42, 44 ],
			$this->access->deniedAmong( [ 'alice' ], [
				42 => [ 'storage' => 3, 'path' => '__groupfolders/5/Secret/s.txt' ],
				43 => [ 'storage' => 3, 'path' => '__groupfolders/5/Open/a.txt' ],
				44 => [ 'storage' => 3, 'path' => '__groupfolders/5' ],
			] ),
		);
	}

	/**
	 * An account whose folder will not open at all opens nothing: the file
	 * is out of reach, not in it.
	 */
	public function testAFolderThatFailsToOpenOpensNothing(): void
	{
		$this->groupFolders->method( 'aclRoots' )
		                   ->willReturn( self::ROOTS )
		;

		$this->assertSame( [ 42 ], $this->access->deniedAmong( [ 'ghost' ], [ 42 => [ 'storage' => 3, 'path' => '__groupfolders/5/x' ] ] ) );
	}

	/**
	 * Of several accounts, one that may read the file is enough; only those
	 * the mount cache says hold it are asked, in the order given, matched in
	 * any case.
	 */
	public function testOfSeveralAccountsOneThatMayReadIsEnough(): void
	{
		$this->groupFolders->method( 'aclRoots' )
		                   ->willReturn( self::ROOTS )
		;
		$this->holders( [ 42 => [ 'Bob', 'alice', 'dave' ], 43 => [ 'alice' ] ] );
		$this->opens = [ 'carol' => [ 42, 43 ], 'alice' => [], 'bob' => [ 42 ] ];

		$denied = $this->access->deniedAmong( [ 'carol', 'alice', 'bob' ], [
			42 => [ 'storage' => 3, 'path' => '__groupfolders/5/x' ],
			43 => [ 'storage' => 3, 'path' => '__groupfolders/5/y' ],
		] );

		$this->assertSame( [ 43 ], $denied, 'bob opens 42; carol, who would open both, holds neither' );
		$this->assertSame( [ 'alice', 'bob', 'alice' ], $this->foldersAsked );
	}

	/**
	 * Where the folders cannot be told, every file is opened — a home file
	 * too: slower, and never wider.
	 */
	public function testWhereTheFoldersAreUnknownEveryFileIsOpened(): void
	{
		$this->groupFolders->method( 'aclRoots' )
		                   ->willReturn( null )
		;
		$this->opens = [ 'alice' => [ 41 ] ];

		$this->assertSame(
			[ 42 ],
			$this->access->deniedAmong( [ 'alice' ], [
				41 => [ 'storage' => 1, 'path' => 'files/a.txt' ],
				42 => [ 'storage' => 2, 'path' => 'files/b.txt' ],
			] ),
		);
	}

	public function testTheFoldersAreReadOncePerRequest(): void
	{
		$this->groupFolders->expects( $this->once() )
		                   ->method( 'aclRoots' )
		                   ->willReturn( [] )
		;

		$row = [ 42 => [ 'storage' => 3, 'path' => '__groupfolders/5/x' ] ];

		$this->access->deniedAmong( [ 'alice' ], $row );
		$this->access->deniedAmong( [ 'alice' ], $row );
		$this->assertSame( [], $this->access->deniedAmong( [ 'alice' ], [] ), 'no rows, nothing read' );
	}

	/**
	 * The accounts whose mounts hold each file, as the mount cache gives
	 * them.
	 *
	 * @param  array<int, list<string>>  $byFile
	 */
	private function holders( array $byFile ): void
	{
		$this->mountCache->method( 'getMountsForFileId' )
		                 ->willReturnCallback( fn ( int $id ): array => array_map(
			                 fn ( string $uid ): ICachedMountFileInfo => $this->createConfiguredMock( ICachedMountFileInfo::class, [
				                 'getUser' => $this->createConfiguredMock( IUser::class, [ 'getUID' => $uid ] ),
			                 ] ),
			                 $byFile[ $id ] ?? [],
		                 ) )
		;
	}
}
