<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\ReachResolver;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\DB\IResult;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;

class ReachResolverTest
    extends
    FciasUnitTestCase
{

//  private properties

	private IUserMountCache&MockObject $mounts;

	private IUserManager&MockObject    $users;

	private IShareManager&MockObject   $shares;

	private ReachResolver              $reach;

	/** @var array<string, list<IShare>> */
	private array $receivedShares = [];


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->mounts = $this->createMock( IUserMountCache::class );
		$this->users  = $this->createMock( IUserManager::class );
		$this->shares = $this->createMock( IShareManager::class );
		$this->db     = $this->createMock( \OCP\IDBConnection::class );
		$this->setUpQueryBuilderMock();

		// As Nextcloud's database backend finds them: in any case, and
		// answering with the account's own uid.
		$this->users->method( 'get' )
		            ->willReturnCallback( fn ( string $uid ): ?IUser => in_array( strtolower( $uid ), [ 'alice', 'bob' ], true )
			            ? $this->createConfiguredMock( IUser::class, [ 'getUID' => strtolower( $uid ) ] )
			            : null );

		$this->reach = new ReachResolver( $this->mounts, $this->users, $this->db, $this->shares );
	}


//  other non-static methods

	public function testNullIsEverythingAndAnUnknownAccountIsNothing(): void
	{
		$this->assertNull( $this->reach->mountsFor( null ) );
		$this->assertNull( $this->reach->storageIdsFor( null ) );
		$this->assertSame( [], $this->reach->mountsFor( 'ghost' ) );
		$this->assertSame( [], $this->reach->mountsFor( [] ) );
	}

	/**
	 * A mount is a storage and a root. Two accounts sharing a storage
	 * through different roots are two mounts; the same mount twice is one.
	 * Where the root is comes from the filecache row of the mount's root
	 * id; a mount on a storage not yet scanned reaches nothing.
	 */
	public function testMountsAreStorageAndRootOnceEach(): void
	{
		$this->viewsByUid( [
			'alice' => [ [ 100, '/alice/' ], [ 900, '/alice/files/x/' ] ],
			'bob'   => [ [ 200, '/bob/' ], [ 900, '/bob/files/x/' ], [ 910, '/bob/files/Other/' ], [ 800, '/bob/files/Unscanned/' ] ],
		] );
		$this->filecacheRows( [
			[ 'fileid' => 100, 'storage' => 1, 'path' => '' ],
			[ 'fileid' => 900, 'storage' => 9, 'path' => 'files/Projects/x' ],
			[ 'fileid' => 200, 'storage' => 2, 'path' => '/' ],
			[ 'fileid' => 910, 'storage' => 9, 'path' => 'files/Other' ],
		] );

		$this->assertSame(
			[
				[ 'storage' => 1, 'root' => '' ],
				[ 'storage' => 9, 'root' => 'files/Projects/x' ],
				[ 'storage' => 2, 'root' => '' ],
				[ 'storage' => 9, 'root' => 'files/Other' ],
			],
			$this->reach->mountsFor( [ 'alice', 'bob' ] ),
		);

		// Narrowing callers get the storages, once each.
		$this->assertSame( [ 1, 9, 2 ], $this->reach->storageIdsFor( [ 'alice', 'bob' ] ) );
	}

	/**
	 * The subtree rule, per file: a home mount contains its whole storage;
	 * a share contains the shared folder and what is below it, and nothing
	 * beside it in the same storage — which is the case a storage-id filter
	 * got wrong.
	 */
	public function testContainsFollowsTheMountsRoot(): void
	{
		$mounts = [
			[ 'storage' => 1, 'root' => '' ],
			[ 'storage' => 9, 'root' => 'files/Projects/x' ],
		];

		$this->assertTrue( $this->reach->contains( null, 42 ), 'everything' );
		$this->assertFalse( $this->reach->contains( [], 42 ), 'nothing' );

		$this->assertTrue( $this->contains( $mounts, 1, 'files/anything/at/all.txt' ), 'a home holds its storage' );
		$this->assertTrue( $this->contains( $mounts, 9, 'files/Projects/x' ), 'the shared folder itself' );
		$this->assertTrue( $this->contains( $mounts, 9, 'files/Projects/x/deep/f.txt' ), 'below it' );
		$this->assertFalse( $this->contains( $mounts, 9, 'files/Projects/xy/f.txt' ), 'a sibling with the same prefix is not below it' );
		$this->assertFalse( $this->contains( $mounts, 9, 'files/Other/f.txt' ), 'beside it, same storage' );
		$this->assertFalse( $this->contains( $mounts, 7, 'files/Projects/x/f.txt' ), 'right path, wrong storage' );
	}

	/**
	 * A home is its files area, not the trash or the versions beside it; a
	 * mount in the files is whole, at the path it is mounted on; a mount
	 * elsewhere, or on a storage not yet scanned, is no view at all. In the
	 * order the accounts are named.
	 *
	 * Where a mount's root is comes from the filecache row of its root id,
	 * not from the mount: one recorded in this same request says `''` for a
	 * share, within the share's own storage, while naming the sharer's
	 * storage — the share 900 below.
	 */
	public function testAViewIsTheFilesAreaOfEachMount(): void
	{
		$this->viewsByUid( [
			'bob'   => [ [ 200, '/bob/' ] ],
			'alice' => [
				[ 100, '/alice/' ],
				[ 900, '/alice/files/Shared/x/', ReachResolver::SHARE_PROVIDER ],
				[ 700, '/alice/files/Archive/' ],
				[ 500, '/alice/files/report.pdf/', ReachResolver::SHARE_PROVIDER ],
				[ 300, '/alice/elsewhere/' ],
				[ 800, '/alice/files/Unscanned/' ],
			],
		] );
		$this->filecacheRows( [
			[ 'fileid' => 200, 'storage' => 2, 'path' => '' ],
			[ 'fileid' => 100, 'storage' => 1, 'path' => '' ],
			[ 'fileid' => 900, 'storage' => 9, 'path' => 'files/Projects/x' ],
			[ 'fileid' => 700, 'storage' => 7, 'path' => '' ],
			[ 'fileid' => 500, 'storage' => 5, 'path' => 'files/report.pdf' ],
			[ 'fileid' => 300, 'storage' => 3, 'path' => '' ],
		] );

		$this->assertSame(
			[
				[ 'uid' => 'bob', 'storage' => 2, 'root' => 'files', 'prefix' => '/', 'rootId' => 200, 'shared' => false ],
				[ 'uid' => 'alice', 'storage' => 1, 'root' => 'files', 'prefix' => '/', 'rootId' => 100, 'shared' => false ],
				[ 'uid' => 'alice', 'storage' => 9, 'root' => 'files/Projects/x', 'prefix' => '/Shared/x/', 'rootId' => 900, 'shared' => true ],
				[ 'uid' => 'alice', 'storage' => 7, 'root' => '', 'prefix' => '/Archive/', 'rootId' => 700, 'shared' => false ],
				[ 'uid' => 'alice', 'storage' => 5, 'root' => 'files/report.pdf', 'prefix' => '/report.pdf/', 'rootId' => 500, 'shared' => true ],
			],
			$this->reach->filesViewsFor( [ 'bob', 'ghost', 'alice' ] ),
		);
	}

	/**
	 * An account named in another case is still the account: its views
	 * are its own, keyed and matched by its own uid. Built from the name
	 * as asked, `/Alice/` matched no mount of alice's, and the listing for
	 * `users[]=Alice` was empty.
	 */
	public function testAnAccountNamedInAnotherCaseHasItsViews(): void
	{
		$this->viewsByUid( [ 'alice' => [ [ 100, '/alice/' ] ] ] );
		$this->filecacheRows( [ [ 'fileid' => 100, 'storage' => 1, 'path' => '' ] ] );

		$this->assertSame(
			[ [ 'uid' => 'alice', 'storage' => 1, 'root' => 'files', 'prefix' => '/', 'rootId' => 100, 'shared' => false ] ],
			$this->reach->filesViewsFor( [ 'ALICE' ] ),
		);
	}

	/**
	 * The path a view gives a file, the first account that holds it naming
	 * it; and where it holds it through a share, the share's address, from
	 * the share's root and nothing above it.
	 */
	public function testAFileIsSeenThroughTheFirstAccountThatHoldsIt(): void
	{
		$views = [
			$this->view( 'bob', 9, 'files/Projects/x', '/From alice/', 900, true ),
			$this->view( 'alice', 1, 'files', '/', 100, false ),
			$this->view( 'alice', 9, 'files', '/', 910, false ),
			$this->view( 'alice', 7, '', '/Archive/', 700, false ),
			$this->view( 'alice', 5, 'files/report.pdf', '/report.pdf/', 500, true ),
		];
		$this->sharesOf( 'bob', [ $this->share( 42, 900, 1000 ) ] );
		$this->sharesOf( 'alice', [ $this->share( 43, 500, 1000 ) ] );

		$this->assertSame( [ 'path' => '/Photos/a.jpg', 'share' => null ], $this->reach->seenThrough( $views, 1, 'files/Photos/a.jpg' ), 'a home file' );
		$this->assertSame(
			[ 'path' => '/From alice/deep/f.txt', 'share' => 'share:42//deep/f.txt' ],
			$this->reach->seenThrough( $views, 9, 'files/Projects/x/deep/f.txt' ),
			'the first holder names it, by its share',
		);
		$this->assertSame(
			[ 'path' => '/Projects/xy/f.txt', 'share' => null ],
			$this->reach->seenThrough( $views, 9, 'files/Projects/xy/f.txt' ),
			'a sibling with the same prefix is not the share\'s',
		);
		$this->assertSame( [ 'path' => '/Archive/2026/b.tif', 'share' => null ], $this->reach->seenThrough( $views, 7, '2026/b.tif' ), 'a storage mounted whole' );
		$this->assertSame(
			[ 'path' => '/report.pdf', 'share' => 'share:43//' ],
			$this->reach->seenThrough( $views, 5, 'files/report.pdf' ),
			'a shared file is mounted as itself, and is its share\'s top',
		);
		$this->assertNull( $this->reach->seenThrough( $views, 1, 'files_trashbin/files/a.jpg.d1' ), 'the trash is in no view' );
		$this->assertNull( $this->reach->seenThrough( $views, 4, 'files/a.jpg' ), 'a storage no view mounts' );
	}

	/**
	 * One account holding a file twice: its own mount before a share, and
	 * of two shares the one rooted higher, which shows it more.
	 */
	public function testAnAccountsOwnMountComesBeforeAShareAndTheHigherShareBeforeTheLower(): void
	{
		$own     = $this->view( 'alice', 9, 'files', '/', 100, false );
		$outer   = $this->view( 'alice', 9, 'files/Clients', '/Clients/', 910, true );
		$inner   = $this->view( 'alice', 9, 'files/Clients/Acme/x', '/x/', 920, true );
		$shareOf = [ $this->share( 51, 910, 1000 ), $this->share( 52, 920, 1000 ) ];
		$this->sharesOf( 'alice', $shareOf );

		$this->assertSame(
			'share:51//Acme/x/a.txt',
			$this->reach->seenThrough( [ $inner, $outer ], 9, 'files/Clients/Acme/x/a.txt' )['share'] ?? null,
		);
		$this->assertSame(
			[ 'path' => '/Clients/Acme/x/a.txt', 'share' => null ],
			$this->reach->seenThrough( [ $inner, $outer, $own ], 9, 'files/Clients/Acme/x/a.txt' ),
		);
	}

	/**
	 * The id a node is mounted by, as the sharing app picks it among an
	 * account's received shares of the node: the oldest, the lowest id
	 * between two of one time. A share without permissions, or one the
	 * account itself owns or made, is mounted by nothing.
	 */
	public function testANodeIsMountedByItsOldestShare(): void
	{
		$this->sharesOf( 'alice', [
			$this->share( 60, 900, 2000 ),
			$this->share( 61, 900, 1000 ),
			$this->share( 59, 900, 1000 ),
			$this->share( 10, 910, 500, permissions: 0 ),
			$this->share( 11, 920, 500, owner: 'alice' ),
		] );

		$this->assertSame( '59', $this->reach->shareIdOf( 'alice', 900 ) );
		$this->assertNull( $this->reach->shareIdOf( 'alice', 910 ) );
		$this->assertNull( $this->reach->shareIdOf( 'alice', 920 ) );
	}

	/**
	 * @return array{uid: string, storage: int, root: string, prefix: string, rootId: int, shared: bool}
	 */
	private function view(
		string $uid,
		int    $storage,
		string $root,
		string $prefix,
		int    $rootId,
		bool   $shared,
	): array
	{
		return [
			'uid'     => $uid,
			'storage' => $storage,
			'root'    => $root,
			'prefix'  => $prefix,
			'rootId'  => $rootId,
			'shared'  => $shared,
		];
	}

	private function share(
		int    $id,
		int    $nodeId,
		int    $time,
		int    $permissions = 1,
		string $owner = 'carol',
	): IShare
	{
		return $this->createConfiguredMock( IShare::class, [
			'getId'          => (string) $id,
			'getNodeId'      => $nodeId,
			'getShareTime'   => ( new \DateTime() )->setTimestamp( $time ),
			'getPermissions' => $permissions,
			'getShareOwner'  => $owner,
			'getSharedBy'    => $owner,
		] );
	}

	/**
	 * The user shares each account receives; every other kind none.
	 *
	 * @param  list<IShare>  $shares
	 */
	private function sharesOf(
		string $uid,
		array  $shares,
	): void
	{
		$this->receivedShares[ $uid ] = $shares;

		$this->shares->method( 'getSharedWith' )
		             ->willReturnCallback( fn ( string $who, int $type ): array => $type === IShare::TYPE_USER
			             ? $this->receivedShares[ $who ] ?? []
			             : [] )
		;
	}

	/**
	 * Mounts that know their root id and mount point, and nothing true
	 * about where their root is: the storage and path they report are the
	 * in-request ones, which the resolver must not believe.
	 *
	 * @param  array<string, list<array{0: int, 1: string, 2?: string}>>  $byUid  root id, mount point and
	 *                                                                         provider, per account
	 */
	private function viewsByUid( array $byUid ): void
	{
		$this->mounts->method( 'getMountsForUser' )
		             ->willReturnCallback( fn ( IUser $u ): array => array_map(
			             fn ( array $m ) => $this->createConfiguredMock( ICachedMountInfo::class, [
				             'getRootId'           => $m[0],
				             'getMountPoint'       => $m[1],
				             'getMountProvider'    => $m[2] ?? '',
				             'getStorageId'        => 666,
				             'getRootInternalPath' => '',
			             ] ),
			             $byUid[ $u->getUID() ] ?? [],
		             ) )
		;
	}

	/**
	 * @param  list<array{fileid: int, storage: int, path: string}>  $rows
	 */
	private function filecacheRows( array $rows ): void
	{
		// A fresh result per query: a caller may ask more than once.
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturnCallback( function() use ( $rows ): IResult
		                   {
			                   $result = $this->createMock( IResult::class );
			                   $result->method( 'fetchAssociative' )
			                          ->willReturnOnConsecutiveCalls( ...[ ...$rows, false ] )
			                   ;

			                   return $result;
		                   } )
		;
		$this->queryBuilder->method( 'expr' )
		                   ->willReturn( $this->expr )
		;
	}

	public function testAFileTheFilecacheDoesNotKnowLiesNowhere(): void
	{
		$result = $this->createMock( IResult::class );
		$result->method( 'fetchAssociative' )
		       ->willReturn( false )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$this->assertFalse( $this->reach->contains( [ [ 'storage' => 1, 'root' => '' ] ], 404 ) );
	}

	/**
	 * Runs contains() against a filecache row of the given storage and path.
	 */
	private function contains(
		array  $mounts,
		int    $storage,
		string $path,
	): bool
	{
		$result = $this->createMock( IResult::class );
		$result->method( 'fetchAssociative' )
		       ->willReturn( [ 'storage' => $storage, 'path' => $path ] )
		;

		$qb = $this->createMock( \OCP\DB\QueryBuilder\IQueryBuilder::class );
		$qb->method( 'select' )->willReturnSelf();
		$qb->method( 'from' )->willReturnSelf();
		$qb->method( 'where' )->willReturnSelf();
		$qb->method( 'expr' )->willReturn( $this->expr );
		$qb->method( 'createNamedParameter' )->willReturnArgument( 0 );
		$qb->method( 'executeQuery' )->willReturn( $result );

		$db = $this->createMock( \OCP\IDBConnection::class );
		$db->method( 'getQueryBuilder' )->willReturn( $qb );

		return ( new ReachResolver( $this->mounts, $this->users, $db, $this->shares ) )->contains( $mounts, 1 );
	}
}
