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
use PHPUnit\Framework\MockObject\MockObject;

class ReachResolverTest
	extends
	FciasUnitTestCase
{

	private IUserMountCache&MockObject $mounts;

	private IUserManager&MockObject    $users;

	private ReachResolver              $reach;


	protected function setUp(): void
	{

		parent::setUp();

		$this->mounts = $this->createMock( IUserMountCache::class );
		$this->users  = $this->createMock( IUserManager::class );
		$this->db     = $this->createMock( \OCP\IDBConnection::class );
		$this->setUpQueryBuilderMock();

		$this->users->method( 'get' )
		            ->willReturnCallback( fn ( string $uid ): ?IUser => in_array( $uid, [ 'alice', 'bob' ], true )
			            ? $this->createConfiguredMock( IUser::class, [ 'getUID' => $uid ] )
			            : null );

		$this->reach = new ReachResolver( $this->mounts, $this->users, $this->db );
	}


	/**
	 * @param  array<string, list<array{0: int, 1: string}>>  $byUid  storage id and root internal path, per account
	 */
	private function mountsByUid( array $byUid ): void
	{

		$this->mounts->method( 'getMountsForUser' )
		             ->willReturnCallback( fn ( IUser $u ): array => array_map(
			             fn ( array $m ) => $this->createConfiguredMock( ICachedMountInfo::class, [
				             'getStorageId'        => $m[0],
				             'getRootInternalPath' => $m[1],
			             ] ),
			             $byUid[ $u->getUID() ] ?? [],
		             ) )
		;
	}


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
	 */
	public function testMountsAreStorageAndRootOnceEach(): void
	{

		$this->mountsByUid( [
			'alice' => [ [ 1, '' ], [ 9, 'files/Projects/x' ] ],
			'bob'   => [ [ 2, '/' ], [ 9, 'files/Projects/x' ], [ 9, 'files/Other' ] ],
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


	public function testAFileTheFilecacheDoesNotKnowLiesNowhere(): void
	{

		$result = $this->createMock( IResult::class );
		$result->method( 'fetch' )
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
	): bool {

		$result = $this->createMock( IResult::class );
		$result->method( 'fetch' )
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

		return ( new ReachResolver( $this->mounts, $this->users, $db ) )->contains( $mounts, 1 );
	}

}
