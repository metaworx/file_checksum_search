<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\HashListingService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\ReachResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HashListingServiceTest
    extends
    TestCase
{

//  private properties

	private MetadataService&MockObject  $metadata;

	private FilecacheService&MockObject $filecache;

	private ReachResolver&MockObject    $reach;

	private HashListingService          $listing;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->metadata  = $this->createMock( MetadataService::class );
		$this->filecache = $this->createMock( FilecacheService::class );
		$this->reach     = $this->createMock( ReachResolver::class );

		$this->listing = new HashListingService( $this->metadata, $this->filecache, $this->reach );
	}


//  other non-static methods

	/**
	 * One entry per file: the path the reach gives it, whose it is and where
	 * it lives, the file's stamp, and its hashes keyed by algorithm — none
	 * for a file whose document holds nothing the index names.
	 */
	public function testAnEntryIsAFileWithItsHashesKeyedByAlgorithm(): void
	{
		$this->reach->method( 'filesViewsFor' )
		            ->with( [ 'alice' ] )
		            ->willReturn( [
			            [ 'uid' => 'alice', 'storage' => 1, 'root' => 'files', 'prefix' => '/' ],
			            [ 'uid' => 'alice', 'storage' => 9, 'root' => 'files/Fotos', 'prefix' => '/Shared/Fotos/' ],
			            [ 'uid' => 'alice', 'storage' => 9, 'root' => 'files/Fotos', 'prefix' => '/Again/' ],
		            ] )
		;
		$this->metadata->expects( $this->once() )
		               ->method( 'pageListedFiles' )
		               ->with(
			               [ [ 'storage' => 1, 'root' => 'files' ], [ 'storage' => 9, 'root' => 'files/Fotos' ] ],
			               null,
			               0,
			               null,
			               HashListingService::DEFAULT_LIMIT + 1,
		               )
		               ->willReturn( [
			               $this->row( 11, 1, 'home::alice', 'files/a.txt', 1756800000 ),
			               $this->row( 12, 9, 'home::bob', 'files/Fotos/GPZ 0001.jpg', null ),
		               ] )
		;
		$this->metadata->method( 'listedHashes' )
		               ->with( [ 11, 12 ], null )
		               ->willReturn( [ 11 => [ 'sha1' => 'aaa', 'sha256' => 'bbb' ] ] )
		;
		$this->metadata->method( 'countListedFiles' )
		               ->willReturn( 2 )
		;
		$this->filecache->expects( $this->never() )
		                ->method( 'localPaths' )
		;

		$page = $this->listing->page( [ 'alice' ] );

		$this->assertSame(
			[
				'files'           => [
					[
						'fileid'     => 11,
						'path'       => '/a.txt',
						'name'       => 'a.txt',
						'owner'      => 'alice',
						'location'   => '/alice/files/a.txt',
						'updated_at' => date( 'c', 1756800000 ),
						'hashes'     => [
							'sha1'   => [ 'algo' => 'sha1', 'hash' => 'aaa' ],
							'sha256' => [ 'algo' => 'sha256', 'hash' => 'bbb' ],
						],
					],
					[
						'fileid'     => 12,
						'path'       => '/Shared/Fotos/GPZ 0001.jpg',
						'name'       => 'GPZ 0001.jpg',
						'owner'      => 'bob',
						'location'   => '/bob/files/Fotos/GPZ 0001.jpg',
						'updated_at' => null,
						'hashes'     => [],
					],
				],
				'next'            => null,
				'estimated_total' => 2,
			],
			$page,
		);
	}

	/**
	 * One row past the page says another follows; `next` is the page's last
	 * file, which the caller passes back as `after`.
	 */
	public function testNextIsTheLastFileWhileFilesRemain(): void
	{
		$this->reach->method( 'filesViewsFor' )
		            ->willReturn( [ [ 'uid' => 'alice', 'storage' => 1, 'root' => 'files', 'prefix' => '/' ] ] )
		;
		$this->metadata->method( 'pageListedFiles' )
		               ->with( $this->anything(), null, 20, null, 3 )
		               ->willReturn( [
			               $this->row( 21, 1, 'home::alice', 'files/a', 1 ),
			               $this->row( 22, 1, 'home::alice', 'files/b', 1 ),
			               $this->row( 23, 1, 'home::alice', 'files/c', 1 ),
		               ] )
		;
		$this->metadata->expects( $this->never() )
		               ->method( 'countListedFiles' )
		;

		$page = $this->listing->page( [ 'alice' ], null, 2, 20 );

		$this->assertSame( [ 21, 22 ], array_column( $page['files'], 'fileid' ) );
		$this->assertSame( 22, $page['next'] );
		$this->assertArrayNotHasKey( 'estimated_total', $page, 'counted at the start only' );
	}

	/**
	 * `limit=0` is the count: no files, the total, and a `next` that does
	 * not read as the end.
	 */
	public function testAZeroLimitCountsAndListsNothing(): void
	{
		$this->metadata->expects( $this->never() )
		               ->method( 'pageListedFiles' )
		;
		$this->metadata->method( 'countListedFiles' )
		               ->with( null, 'sha256', 1700000000 )
		               ->willReturn( 57459 )
		;

		$this->assertSame(
			[ 'files' => [], 'next' => 0, 'estimated_total' => 57459 ],
			$this->listing->page( null, 'sha256', 0, 0, 1700000000 ),
		);
		$this->assertSame(
			[ 'files' => [], 'next' => 500 ],
			$this->listing->page( null, 'sha256', 0, 500 ),
		);
	}

	public function testTheFiltersReachTheQueriesAndTheLimitIsClamped(): void
	{
		$this->metadata->expects( $this->once() )
		               ->method( 'pageListedFiles' )
		               ->with( null, 'md5', 0, 1700000000, HashListingService::MAX_LIMIT + 1 )
		               ->willReturn( [] )
		;
		$this->metadata->expects( $this->once() )
		               ->method( 'countListedFiles' )
		               ->with( null, 'md5', 1700000000 )
		               ->willReturn( 0 )
		;

		$this->assertSame(
			[ 'files' => [], 'next' => null, 'estimated_total' => 0 ],
			$this->listing->page( null, 'md5', 5000, - 3, 1700000000 ),
		);
	}

	/**
	 * An algorithm is read in lower case, as the keys are: the selection
	 * lowercases through the key, and the values must be read under the
	 * same name, or `SHA256` lists the files with no hashes.
	 */
	public function testAnAlgorithmIsReadInLowerCase(): void
	{
		$this->metadata->expects( $this->once() )
		               ->method( 'pageListedFiles' )
		               ->with( null, 'sha256', 0, null, HashListingService::DEFAULT_LIMIT + 1 )
		               ->willReturn( [ $this->row( 51, 1, 'home::alice', 'files/a', 1 ) ] )
		;
		$this->metadata->expects( $this->once() )
		               ->method( 'listedHashes' )
		               ->with( [ 51 ], 'sha256' )
		               ->willReturn( [ 51 => [ 'sha256' => 'abc' ] ] )
		;
		$this->metadata->expects( $this->once() )
		               ->method( 'countListedFiles' )
		               ->with( null, 'sha256', null )
		;

		$this->assertSame(
			[ 'sha256' => [ 'algo' => 'sha256', 'hash' => 'abc' ] ],
			$this->listing->page( null, 'SHA256' )['files'][0]['hashes'],
		);
	}

	public function testAnEmptyAlgorithmIsNone(): void
	{
		$this->metadata->expects( $this->once() )
		               ->method( 'countListedFiles' )
		               ->with( null, null, null )
		;

		$this->listing->page( null, '', 0 );
	}

	/**
	 * With every account in reach, the owner's view of a home file, and of
	 * a file nobody owns its path in the area `location` names.
	 */
	public function testWithEveryAccountTheAreaNamesThePath(): void
	{
		$this->reach->expects( $this->never() )
		            ->method( 'filesViewsFor' )
		;
		$this->metadata->method( 'pageListedFiles' )
		               ->willReturn( [
			               $this->row( 31, 1, 'home::alice', 'files/Fotos/a.jpg', 1 ),
			               $this->row( 32, 4, 'local::/srv/data/__groupfolders/3/', 'files/Team/b.odt', 1 ),
			               $this->row( 33, 5, 'smb::archive@host/share/', 'Scans/c.tif', 1 ),
		               ] )
		;

		$page = $this->listing->page( null, null, 10, 30 );

		$this->assertSame(
			[ '/Fotos/a.jpg', '/Team/b.odt', '/Scans/c.tif' ],
			array_column( $page['files'], 'path' ),
		);
		$this->assertSame(
			[ '/alice/files/Fotos/a.jpg', 'groupfolder:3/Team/b.odt', 'storage:smb::archive@host/share//Scans/c.tif' ],
			array_column( $page['files'], 'location' ),
		);
		$this->assertSame( [ 'alice', null, null ], array_column( $page['files'], 'owner' ) );
	}

	/**
	 * The walk reads batch after batch, from where the last one ended, and
	 * stops at the first batch that comes back short. The reach is resolved
	 * once, no count is taken, and each file comes keyed by its id.
	 */
	public function testTheWalkReadsBatchAfterBatchUntilOneIsShort(): void
	{
		$batch = HashListingService::ITERATE_BATCH;

		$this->reach->expects( $this->once() )
		            ->method( 'filesViewsFor' )
		            ->with( [ 'alice' ] )
		            ->willReturn( [ [ 'uid' => 'alice', 'storage' => 1, 'root' => 'files', 'prefix' => '/' ] ] )
		;
		$this->metadata->expects( $this->exactly( 2 ) )
		               ->method( 'pageListedFiles' )
		               ->willReturnCallback( fn ( ?array $areas, ?string $algo, int $after, ?int $since, int $limit ): array => match ( $after )
		               {
			               0      => $this->rows( 1, $batch ),
			               $batch => $this->rows( $batch + 1, $batch + 2 ),
		               } )
		;
		$this->metadata->expects( $this->never() )
		               ->method( 'countListedFiles' )
		;

		$walk = iterator_to_array( $this->listing->iterate( [ 'alice' ] ) );

		$this->assertSame( range( 1, $batch + 2 ), array_keys( $walk ) );
		$this->assertSame( '/f' . ( $batch + 2 ), $walk[ $batch + 2 ]['path'] );
	}

	/**
	 * A full last batch is followed by one more query, which comes back
	 * empty and ends the walk.
	 */
	public function testAFullLastBatchEndsOnAnEmptyOne(): void
	{
		$batch = HashListingService::ITERATE_BATCH;

		$this->metadata->expects( $this->exactly( 2 ) )
		               ->method( 'pageListedFiles' )
		               ->willReturnOnConsecutiveCalls( $this->rows( 1, $batch ), [] )
		;

		$this->assertCount( $batch, iterator_to_array( $this->listing->iterate( null ) ) );
	}

	/**
	 * A walk cut short resumes after the last file it received; the
	 * algorithm is read in lower case, as on the pages.
	 */
	public function testAWalkResumesAfterAFileAndReadsTheAlgorithmInLowerCase(): void
	{
		$this->metadata->expects( $this->once() )
		               ->method( 'pageListedFiles' )
		               ->with( null, 'sha1', 42, 1700000000, HashListingService::ITERATE_BATCH )
		               ->willReturn( [] )
		;

		$this->assertSame( [], iterator_to_array( $this->listing->iterate( null, 'SHA1', 1700000000, false, 42 ) ) );
	}

	/**
	 * A stamp of zero is none — hashes written without a time, or cleared
	 * for the next sweep — and is not 1970.
	 */
	public function testAZeroStampIsNone(): void
	{
		$this->assertNull( HashListingService::stamp( 0 ) );
		$this->assertNull( HashListingService::stamp( null ) );
		$this->assertSame( date( 'c', 1756800000 ), HashListingService::stamp( 1756800000 ) );
	}

	public function testALocalPathIsGivenWhenAsked(): void
	{
		$this->metadata->method( 'pageListedFiles' )
		               ->willReturn( [ $this->row( 41, 1, 'home::alice', 'files/a.txt', 1 ) ] )
		;
		$this->filecache->expects( $this->once() )
		                ->method( 'localPaths' )
		                ->with( $this->callback( static fn ( array $locations ): bool => count( $locations ) === 1
		                                                                                 && $locations[0] instanceof FileLocation
		                                                                                 && $locations[0]->fileId === 41 ) )
		                ->willReturn( [ 41 => '/srv/data/alice/files/a.txt' ] )
		;

		$entry = $this->listing->page( null, null, 10, 40, null, true )['files'][0];

		$this->assertSame( '/srv/data/alice/files/a.txt', $entry['localPath'] );
		$this->assertSame(
			[ 'fileid', 'path', 'name', 'owner', 'location', 'localPath', 'updated_at', 'hashes' ],
			array_keys( $entry ),
		);
	}

	/**
	 * Rows for the file ids $from to $to, each a home file named after its id.
	 *
	 * @return list<array{fileid: int, storage: int, storage_id: string, path: string, updated_at: ?int}>
	 */
	private function rows(
		int $from,
		int $to,
	): array
	{
		return array_map(
			fn ( int $id ): array => $this->row( $id, 1, 'home::alice', 'files/f' . $id, 1 ),
			range( $from, $to ),
		);
	}

	/**
	 * @return array{fileid: int, storage: int, storage_id: string, path: string, updated_at: ?int}
	 */
	private function row(
		int    $fileId,
		int    $storage,
		string $storageId,
		string $path,
		?int   $updatedAt,
	): array
	{
		return [
			'fileid'     => $fileId,
			'storage'    => $storage,
			'storage_id' => $storageId,
			'path'       => $path,
			'updated_at' => $updatedAt,
		];
	}
}
