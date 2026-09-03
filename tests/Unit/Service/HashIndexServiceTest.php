<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\DuplicateService;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class HashIndexServiceTest
	extends
	FciasUnitTestCase
{

	private MockObject|HashCalculationService $hashCalc;

	private MockObject|DuplicateService       $duplicates;

	private MockObject|MetadataService        $metadataService;

	private MockObject|FilecacheService       $filecacheService;

	private HashIndexService                  $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->hashCalc         = $this->createMock( HashCalculationService::class );
		$this->duplicates       = $this->createMock( DuplicateService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->filecacheService = $this->createMock( FilecacheService::class );

		$this->service = new HashIndexService(
			$this->hashCalc,
			$this->duplicates,
			$this->metadataService,
			$this->filecacheService,
		);
	}


	// The default algorithm and the list it heads moved to AlgorithmCatalogue,
	// which has its own tests; nothing about them is HashIndexService's.


	public function testRecalcHashDelegatesToHashCalc(): void
	{

		$this->hashCalc->expects( $this->once() )
		               ->method( 'recalcHash' )
		               ->with( 42, 'sha256', true )
		               ->willReturn(
			               [
				               'success' => true,
				               'algo'    => 'sha256',
				               'hash'    => 'abc',
				               'existed' => false,
			               ],
		               )
		;

		$result = $this->service->recalcHash( 42, 'sha256' );

		$this->assertTrue( $result['success'] );
	}


	public function testFindByHashDelegatesToDuplicates(): void
	{

		$this->duplicates->expects( $this->once() )
		                 ->method( 'findByHash' )
		                 ->with( 'abc123', null, 100, null )
		                 ->willReturn( [] )
		;

		$result = $this->service->findByHash( 'abc123' );

		$this->assertIsArray( $result );
	}


	public function testFindByHashWithAlgoPassesFilter(): void
	{

		$this->duplicates->expects( $this->once() )
		                 ->method( 'findByHash' )
		                 ->with( 'abc123', 'sha256', 50, null )
		                 ->willReturn( [] )
		;

		$result = $this->service->findByHash( 'abc123', 'sha256', 50 );

		$this->assertIsArray( $result );
	}


	public function testFindByHashPassesUserNameThrough(): void
	{

		$this->duplicates->expects( $this->once() )
		                 ->method( 'findByHash' )
		                 ->with( 'abc123', null, 100, 'alice' )
		                 ->willReturn( [] )
		;

		$result = $this->service->findByHash( 'abc123', null, 100, 'alice' );

		$this->assertIsArray( $result );
	}


	public function testFindAllDuplicatesDelegates(): void
	{

		$groups = [
			[
				'algo'       => 'sha1',
				'hash_value' => 'abc',
				'file_count' => 2,
				'fileids'    => [
					42,
					108,
				],
			],
		];

		$this->duplicates->expects( $this->once() )
		                 ->method( 'findAllDuplicates' )
		                 ->with( 'sha1', 2, 50, 0 )
		                 ->willReturn( $groups )
		;

		$result = $this->service->findAllDuplicates( 'sha1' );

		$this->assertCount( 1, $result );
	}


	public function testCountHashesDelegatesToMetadata(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'countByFileId' )
		                      ->with( 42 )
		                      ->willReturn( 5 )
		;

		$result = $this->service->countHashes( 42 );

		$this->assertSame( 5, $result );
	}


	public function testDeleteHashesClearsMetadata(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearMetadata' )
		                      ->with( 42 )
		;

		$result = $this->service->deleteHashes( 42 );

		$this->assertSame( 1, $result );
	}


	public function testGenerateMissingHashesWithPathPattern(): void
	{

		$this->hashCalc->expects( $this->once() )
		               ->method( 'generateMissingHashes' )
		               ->with( 'alice', 'sha256', '**/*.jpg', 200, null )
		               ->willReturn(
			               [
				               'processed' => 5,
				               'skipped'   => 2,
				               'errors'    => 0,
			               ],
		               )
		;

		$result = $this->service->generateMissingHashes(
			'alice',
			'sha256',
			'**/*.jpg',
			200,
		);

		$this->assertSame(
			[
				'processed' => 5,
				'skipped'   => 2,
				'errors'    => 0,
			],
			$result,
		);
	}


	// ─── backfillFromFilecache ──────────────────────────────────────

	public function testBackfillPagesUntilExhaustedAndSumsTheCounts(): void
	{

		$this->filecacheService->method( 'pageFileidChecksums' )
		                       ->willReturnCallback(
			                       static fn(
				                       int $lastFileId,
			                       ): array => match ( $lastFileId )
			                       {
				                       0 => [
					                       5 => [
						                       'checksum' => 'SHA1:dead MD5:cafe',
						                       'mtime'    => 100,
					                       ],
					                       9 => [
						                       'checksum' => 'SHA1:beef',
						                       'mtime'    => 200,
					                       ],
				                       ],
				                       9 => [
					                       12 => [
						                       'checksum' => 'garbage-without-colon',
						                       'mtime'    => 300,
					                       ],
				                       ],
				                       default => [],
			                       },
		                       )
		;

		$calls = [];
		$this->metadataService->method( 'backfillHashes' )
		                      ->willReturnCallback(
			                      static function (
				                      int   $fileId,
				                      array $algoToHash,
				                      int   $mtime,
			                      ) use
			                      (
				                      &
				                      $calls,
			                      ): int
			                      {

				                      $calls[ $fileId ] = [
					                      $algoToHash,
					                      $mtime,
				                      ];

				                      return count( $algoToHash );
			                      },
		                      )
		;

		$result = $this->service->backfillFromFilecache();

		// File 12's checksum parses to nothing, so it is never offered.
		$this->assertSame(
			[
				5,
				9,
			],
			array_keys( $calls ),
		);
		$this->assertSame(
			[
				[
					'sha1' => 'dead',
					'md5'  => 'cafe',
				],
				100,
			],
			$calls[5],
		);
		$this->assertSame(
			[
				'files'  => 2,
				'hashes' => 3,
			],
			$result,
		);
	}


	public function testBackfillCountsOnlyFilesThatGainedSomething(): void
	{

		$this->filecacheService->method( 'pageFileidChecksums' )
		                       ->willReturnCallback(
			                       static fn(
				                       int $lastFileId,
			                       ): array => $lastFileId === 0
				                       ? [
					                       5 => [
						                       'checksum' => 'SHA1:dead',
						                       'mtime'    => 100,
					                       ],
				                       ]
				                       : [],
		                       )
		;
		// Everything already present — nothing added, nothing counted.
		$this->metadataService->method( 'backfillHashes' )
		                      ->willReturn( 0 )
		;

		$this->assertSame(
			[
				'files'  => 0,
				'hashes' => 0,
			],
			$this->service->backfillFromFilecache(),
		);
	}


	// ─── listDuplicatesForUser ──────────────────────────────────────

	public function testListDuplicatesForUserReturnsAnEmptyListingWhenNoGroupsExist(): void
	{

		$this->duplicates->method( 'findAllDuplicates' )
		                 ->willReturn( [] )
		;
		$this->filecacheService->expects( $this->never() )
		                       ->method( 'batchLookupFilecachePaths' )
		;

		$result = $this->service->listDuplicatesForUser( 'bob' );

		$this->assertSame(
			[
				'duplicates'   => [],
				'total_groups' => 0,
				'pagination'   => [
					'offset' => 0,
					'limit'  => DuplicateService::DEFAULT_DUPLICATE_LIMIT,
				],
			],
			$result,
		);
	}


	public function testListDuplicatesForUserResolvesEveryGroupsPathsInOneLookup(): void
	{

		$this->duplicates->method( 'findAllDuplicates' )
		                 ->willReturn( [
			                 $this->group(
				                 'abc',
				                 [
					                 42,
					                 108,
				                 ],
			                 ),
			                 $this->group(
				                 'def',
				                 [
					                 7,
					                 9,
				                 ],
			                 ),
		                 ] )
		;

		// One batched lookup for both groups, not one per group.
		$this->filecacheService->expects( $this->once() )
		                       ->method( 'batchLookupFilecachePaths' )
		                       ->with(
			                       [
				                       42,
				                       108,
				                       7,
				                       9,
			                       ],
			                       'bob',
		                       )
		                       ->willReturn(
			                       $this->paths(
				                       [
					                       42,
					                       108,
					                       7,
					                       9,
				                       ],
			                       ),
		                       )
		;

		$result = $this->service->listDuplicatesForUser( 'bob' );

		$this->assertCount( 2, $result['duplicates'] );
		$this->assertSame( 2, $result['total_groups'] );
	}


	public function testListDuplicatesForUserKeepsOnlyTheFilesThatUserCanSee(): void
	{

		$this->duplicates->method( 'findAllDuplicates' )
		                 ->willReturn(
			                 [
				                 $this->group(
					                 'abc',
					                 [
						                 42,
						                 108,
						                 200,
					                 ],
				                 ),
			                 ],
		                 )
		;

		// 200 belongs to somebody else, so the lookup does not return it.
		$this->filecacheService->method( 'batchLookupFilecachePaths' )
		                       ->willReturn(
			                       $this->paths(
				                       [
					                       42,
					                       108,
				                       ],
			                       ),
		                       )
		;

		$result = $this->service->listDuplicatesForUser( 'bob' );

		$this->assertCount( 1, $result['duplicates'] );
		$this->assertCount( 2, $result['duplicates'][0]['files'] );
		// file_count is recomputed from what survived, not carried over.
		$this->assertSame( 2, $result['duplicates'][0]['file_count'] );
		$this->assertSame(
			[
				42,
				108,
			],
			array_column( $result['duplicates'][0]['files'], 'fileid' ),
		);
	}


	public function testListDuplicatesForUserDropsAGroupThatFallsBelowMinCount(): void
	{

		$this->duplicates->method( 'findAllDuplicates' )
		                 ->willReturn(
			                 [
				                 $this->group(
					                 'abc',
					                 [
						                 42,
						                 108,
					                 ],
				                 ),
			                 ],
		                 )
		;

		// Only one of the two files is this user's, so for them it is not a
		// duplicate at all — reporting it would mean claiming a file
		// duplicates itself.
		$this->filecacheService->method( 'batchLookupFilecachePaths' )
		                       ->willReturn( $this->paths( [ 42 ] ) )
		;

		$result = $this->service->listDuplicatesForUser( 'bob' );

		$this->assertSame( [], $result['duplicates'] );
		$this->assertSame( 0, $result['total_groups'] );
	}


	/**
	 * A group is two files or more; a minCount under 2 makes every hashed
	 * file its own group and turns the listing into a whole-index scan. The
	 * controllers pass the client's value straight through, so the clamp is
	 * here, and the query never sees a number below 2.
	 */
	public function testListDuplicatesForUserClampsMinCountToTwo(): void
	{

		$this->duplicates->expects( $this->once() )
		                 ->method( 'findAllDuplicates' )
		                 ->with( 'sha1', 2, 10000, 0 )
		                 ->willReturn( [] )
		;

		$this->service->listDuplicatesForUser( 'bob', 'sha1', 0, 50, 0 );
	}


	public function testListDuplicatesForUserOverFetchesThenAppliesTheCallersLimit(): void
	{

		// The limit cannot be pushed into the query: how many groups survive
		// per-user filtering is unknown until after it. So the query asks for
		// far more than the caller wants, and the caller's limit trims what is
		// left.
		$this->duplicates->expects( $this->once() )
		                 ->method( 'findAllDuplicates' )
		                 ->with( 'sha1', 2, 10000, 5 )
		                 ->willReturn( [
			                 $this->group(
				                 'a',
				                 [
					                 1,
					                 2,
				                 ],
			                 ),
			                 $this->group(
				                 'b',
				                 [
					                 3,
					                 4,
				                 ],
			                 ),
			                 $this->group(
				                 'c',
				                 [
					                 5,
					                 6,
				                 ],
			                 ),
		                 ] )
		;
		$this->filecacheService->method( 'batchLookupFilecachePaths' )
		                       ->willReturn(
			                       $this->paths(
				                       [
					                       1,
					                       2,
					                       3,
					                       4,
					                       5,
					                       6,
				                       ],
			                       ),
		                       )
		;

		$result = $this->service->listDuplicatesForUser( 'bob', 'sha1', 2, 2, 5 );

		$this->assertCount( 2, $result['duplicates'] );
		$this->assertSame( 2, $result['total_groups'] );
		$this->assertSame(
			[
				'offset' => 5,
				'limit'  => 2,
			],
			$result['pagination'],
		);
	}


	/**
	 * @param  int[]  $fileIds
	 *
	 * @return array{algo: string, hash_value: string, file_count: int, fileids: int[]}
	 */
	private function group(
		string $hash,
		array  $fileIds,
	): array {

		return [
			'algo'       => 'sha1',
			'hash_value' => $hash,
			'file_count' => count( $fileIds ),
			'fileids'    => $fileIds,
		];
	}


	/**
	 * @param  int[]  $fileIds
	 *
	 * @return array<int, array{path: string, name: string, storage_id: string, user: string}>
	 */
	private function paths( array $fileIds ): array
	{

		$paths = [];

		foreach ( $fileIds as $fileId )
		{
			$paths[ $fileId ] = [
				'path'       => "files/f$fileId.pdf",
				'name'       => "f$fileId.pdf",
				'storage_id' => 'home::bob',
				'user'       => 'bob',
			];
		}

		return $paths;
	}

}
