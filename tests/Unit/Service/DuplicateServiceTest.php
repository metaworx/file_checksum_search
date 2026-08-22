<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\DuplicateService;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\MetadataService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DuplicateService.
 *
 * Covers findAllDuplicates() and findByHash() with mocked
 * MetadataService and FilecacheService dependencies.
 */
class DuplicateServiceTest
	extends
	TestCase
{

	private MetadataService&MockObject  $metadataService;

	private FilecacheService&MockObject $filecacheService;

	private DuplicateService            $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->filecacheService = $this->createMock( FilecacheService::class );

		$this->service = new DuplicateService(
			$this->metadataService,
			$this->filecacheService,
		);
	}


	public function testFindAllDuplicatesDelegatesToMetadataService(): void
	{

		$rows = [
			[
				MetadataService::FIELD_META_KEY          => MetadataService::KEY_FILE_CHECKSUM_PREFIX . 'sha1',
				MetadataService::FIELD_META_VALUE_STRING => 'abc123def456',
				'file_count'                            => 3,
				'file_ids'                              => [ 10, 20, 30 ],
			],
			[
				MetadataService::FIELD_META_KEY          => MetadataService::KEY_FILE_CHECKSUM_PREFIX . 'sha256',
				MetadataService::FIELD_META_VALUE_STRING => 'deadbeef',
				'file_count'                            => 2,
				'file_ids'                              => [ 5, 15 ],
			],
		];

		$this->metadataService->expects( $this->once() )
		                      ->method( 'queryDuplicates' )
		                      ->with( 'sha1', 2, 50, 0 )
		                      ->willReturn( $rows )
		;

		$result = $this->service->findAllDuplicates( 'sha1', 2, 50, 0 );

		$this->assertCount( 2, $result );
		$this->assertSame( 'sha1', $result[0]['algo'] );
		$this->assertSame( 'abc123def456', $result[0]['hash_value'] );
		$this->assertSame( 3, $result[0]['file_count'] );
		$this->assertSame( [ 10, 20, 30 ], $result[0]['fileids'] );
		$this->assertSame( 'sha256', $result[1]['algo'] );
		$this->assertSame( 'deadbeef', $result[1]['hash_value'] );
		$this->assertSame( 2, $result[1]['file_count'] );
		$this->assertSame( [ 5, 15 ], $result[1]['fileids'] );
	}


	public function testFindByHashResolvesFilecachePaths(): void
	{

		$hash = 'abc123';

		$rows = [
			[
				MetadataService::FIELD_FILE_ID => 42,
				MetadataService::FIELD_META_KEY => MetadataService::KEY_FILE_CHECKSUM_PREFIX . 'sha1',
			],
		];

		$fcPaths = [
			42 => [
				'path' => '/files/Documents',
				'name' => 'report.pdf',
			],
		];

		$this->metadataService->expects( $this->once() )
		                      ->method( 'queryByHash' )
		                      ->with( $hash, null, 100 )
		                      ->willReturn( $rows )
		;

		$this->filecacheService->expects( $this->once() )
		                       ->method( 'batchLookupFilecachePaths' )
		                       ->with( [ 42 ], null )
		                       ->willReturn( $fcPaths )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'extractAlgorithm' )
		                      ->with( 42, $rows[0] )
		                      ->willReturn( [ 'algo' => 'sha1', 'hash' => $hash ] )
		;

		$result = $this->service->findByHash( $hash );

		$this->assertCount( 1, $result );
		$this->assertSame( 42, $result[0]['fileid'] );
		$this->assertSame( 'sha1', $result[0]['algo'] );
		$this->assertSame( $hash, $result[0]['hash_value'] );
		$this->assertSame( '/files/Documents', $result[0]['path'] );
		$this->assertSame( 'report.pdf', $result[0]['name'] );
	}


	public function testFindByHashSkipsUnresolvablePaths(): void
	{

		$hash = 'deadbeef';

		$rows = [
			[
				MetadataService::FIELD_FILE_ID => 10,
				MetadataService::FIELD_META_KEY => MetadataService::KEY_FILE_CHECKSUM_PREFIX . 'sha1',
			],
			[
				MetadataService::FIELD_FILE_ID => 20,
				MetadataService::FIELD_META_KEY => MetadataService::KEY_FILE_CHECKSUM_PREFIX . 'sha1',
			],
		];

		// Only fileId 10 has a resolved path; 20 is missing
		$fcPaths = [
			10 => [
				'path' => '/files/Docs',
				'name' => 'notes.txt',
			],
		];

		$this->metadataService->expects( $this->once() )
		                      ->method( 'queryByHash' )
		                      ->with( $hash, null, 100 )
		                      ->willReturn( $rows )
		;

		$this->filecacheService->expects( $this->once() )
		                       ->method( 'batchLookupFilecachePaths' )
		                       ->with( [ 10, 20 ], null )
		                       ->willReturn( $fcPaths )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'extractAlgorithm' )
		                      ->with( 10, $rows[0] )
		                      ->willReturn( [ 'algo' => 'sha1', 'hash' => $hash ] )
		;

		$result = $this->service->findByHash( $hash );

		// Only fileId 10 should be present; fileId 20 skipped due to missing path
		$this->assertCount( 1, $result );
		$this->assertSame( 10, $result[0]['fileid'] );
	}


	public function testFindByHashScopesLookupToGivenUser(): void
	{

		$hash = 'abc123';

		$rows = [
			[
				MetadataService::FIELD_FILE_ID  => 42,
				MetadataService::FIELD_META_KEY => MetadataService::KEY_FILE_CHECKSUM_PREFIX . 'sha1',
			],
		];

		$fcPaths = [
			42 => [
				'path' => '/files/Documents',
				'name' => 'report.pdf',
			],
		];

		$this->metadataService->method( 'queryByHash' )
		                      ->willReturn( $rows )
		;

		// The requesting user's UID must reach FilecacheService so results
		// are restricted to that user's own home storage (see FCIAS Review
		// §6, Finding 1 — public API lookups previously leaked cross-user
		// file paths because this filter was never threaded through).
		$this->filecacheService->expects( $this->once() )
		                       ->method( 'batchLookupFilecachePaths' )
		                       ->with( [ 42 ], 'alice' )
		                       ->willReturn( $fcPaths )
		;

		$this->metadataService->method( 'extractAlgorithm' )
		                      ->willReturn( [
			                      'algo' => 'sha1',
			                      'hash' => $hash,
		                      ] )
		;

		$result = $this->service->findByHash( $hash, null, 100, 'alice' );

		$this->assertCount( 1, $result );
	}

}
