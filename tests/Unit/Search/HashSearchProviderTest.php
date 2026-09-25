<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Search;

use OCA\FileChecksumSearch\Search\HashSearchProvider;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HashSearchProviderTest
    extends
    TestCase
{

//  private properties

	private HashSearchProvider $provider;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->provider = new HashSearchProvider(
			$this->createMock( MetadataService::class ),
			$this->createMock( IRootFolder::class ),
			$this->createMock( \OCA\FileChecksumSearch\Service\ReachResolver::class ),
			$this->createMock( IURLGenerator::class ),
			$this->createMock( LoggerInterface::class ),
		);
	}


//  other non-static methods

	public function testGetIdReturnsProviderId(): void
	{
		$this->assertSame(
			'file_checksum_search_provider',
			$this->provider->getId(),
		);
	}

	public function testGetNameReturnsProviderName(): void
	{
		$this->assertSame(
			'File Checksums',
			$this->provider->getName(),
		);
	}

	public function testGetOrderReturnsInt(): void
	{
		$this->assertSame(
			20,
			$this->provider->getOrder( '', [] ),
		);
	}

	/**
	 * Each surviving row costs a getById(), so the search caps the limit
	 * whatever core hands it: a search for a common hash with a huge limit
	 * would otherwise spend thousands of queries.
	 */
	public function testSearchCapsTheLimitCoreAsksFor(): void
	{
		$metadata   = $this->createMock( MetadataService::class );
		$mountCache = $this->createMock( IUserMountCache::class );

		$mount = $this->createMock( ICachedMountInfo::class );
		$mount->method( 'getStorageId' )->willReturn( 1 );
		$mount->method( 'getRootInternalPath' )->willReturn( '' );
		$mountCache->method( 'getMountsForUser' )->willReturn( [ $mount ] );

		// The searching account, known to the user manager the resolver
		// looks it up through.
		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )->willReturn( 'u1' );
		$users = $this->createMock( \OCP\IUserManager::class );
		$users->method( 'get' )->with( 'u1' )->willReturn( $user );

		$metadata->expects( $this->once() )
		         ->method( 'queryByHash' )
		         ->with( $this->anything(), $this->anything(), 100, [ 1 ] )
		         ->willReturn( [] )
		;
		$metadata->method( 'confirmFullHash' )->willReturn( [] );

		$provider = new HashSearchProvider(
			$metadata,
			$this->createMock( IRootFolder::class ),
			new \OCA\FileChecksumSearch\Service\ReachResolver( $mountCache, $users, $this->createMock( \OCP\IDBConnection::class ) ),
			$this->createMock( IURLGenerator::class ),
			$this->createMock( LoggerInterface::class ),
		);

		$query = $this->createMock( ISearchQuery::class );
		$query->method( 'getTerm' )->willReturn( 'abc123abc123abc123abc123abc123abc123abc1' );
		$query->method( 'getLimit' )->willReturn( 100000 );

		$provider->search( $user, $query );
	}
}
