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

		// Accounts in, mounts out: null stays null (everyone), anything
		// named resolves to one home mount. What the tests assert is that
		// the *mounts* reach the path lookup, not the account name.
		$reach = $this->createMock( \OCA\FileChecksumSearch\Service\ReachResolver::class );
		$reach->method( 'mountsFor' )
		      ->willReturnCallback( static fn ( string|array|null $uids ): ?array => $uids === null
			      ? null
			      : [ [ 'storage' => 1, 'root' => '' ] ] )
		;

		$this->service = new DuplicateService(
			$this->metadataService,
			$this->filecacheService,
			$reach,
		);
	}


	/**
	 * @noinspection PhpRedundantOptionalArgumentInspection
	 */
	public function testFindAllDuplicatesDelegatesToMetadataService(): void
	{

		$rows = [
			[
				MetadataService::FIELD_META_KEY          => MetadataService::getHashKey( 'sha1' ),
				MetadataService::FIELD_META_VALUE_STRING => 'abc123def456',
				'file_count'                             => 3,
				'file_ids'                               => [
					10,
					20,
					30,
				],
			],
			[
				MetadataService::FIELD_META_KEY          => MetadataService::getHashKey( 'sha256' ),
				MetadataService::FIELD_META_VALUE_STRING => 'deadbeef',
				'file_count'                             => 2,
				'file_ids'                               => [
					5,
					15,
				],
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
		$this->assertSame(
			[
				10,
				20,
				30,
			],
			$result[0]['fileids'],
		);
		$this->assertSame( 'sha256', $result[1]['algo'] );
		$this->assertSame( 'deadbeef', $result[1]['hash_value'] );
		$this->assertSame( 2, $result[1]['file_count'] );
		$this->assertSame(
			[
				5,
				15,
			],
			$result[1]['fileids'],
		);
	}


	public function testFindByHashResolvesFilecachePaths(): void
	{

		$this->givenTheHashIsConfirmed();

		$hash = 'abc123';

		$rows = [
			[
				MetadataService::FIELD_FILE_ID  => 42,
				MetadataService::FIELD_META_KEY => MetadataService::getHashKey( 'sha1' ),
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
		                      ->willReturn(
			                      [
				                      'algo' => 'sha1',
				                      'hash' => $hash,
			                      ],
		                      )
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

		$this->givenTheHashIsConfirmed();

		$hash = 'deadbeef';

		$rows = [
			[
				MetadataService::FIELD_FILE_ID  => 10,
				MetadataService::FIELD_META_KEY => MetadataService::getHashKey( 'sha1' ),
			],
			[
				MetadataService::FIELD_FILE_ID  => 20,
				MetadataService::FIELD_META_KEY => MetadataService::getHashKey( 'sha1' ),
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
		                       ->with(
			                       [
				                       10,
				                       20,
			                       ],
			                       null,
		                       )
		                       ->willReturn( $fcPaths )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'extractAlgorithm' )
		                      ->with( 10, $rows[0] )
		                      ->willReturn(
			                      [
				                      'algo' => 'sha1',
				                      'hash' => $hash,
			                      ],
		                      )
		;

		$result = $this->service->findByHash( $hash );

		// Only fileId 10 should be present; fileId 20 skipped due to missing path
		$this->assertCount( 1, $result );
		$this->assertSame( 10, $result[0]['fileid'] );
	}


	public function testFindByHashRejectsTruncatedPrefixFalsePositive(): void
	{

		// Regression test for FCIAS Review §6, Finding 6: queryByHash()
		// matches on the truncated index value for long hashes, so a
		// candidate row may only share the truncated prefix. The full
		// authoritative hash from extractAlgorithm() must be checked
		// before trusting the match.
		$hash = str_repeat( 'a', 128 );

		$rows = [
			[
				MetadataService::FIELD_FILE_ID  => 42,
				MetadataService::FIELD_META_KEY => MetadataService::getHashKey( 'sha512' ),
			],
		];

		$this->metadataService->method( 'queryByHash' )
		                      ->willReturn( $rows )
		;
		$this->filecacheService->method( 'batchLookupFilecachePaths' )
		                       ->willReturn( [
			                       42 => [
				                       'path' => '/files/Docs',
				                       'name' => 'report.pdf',
			                       ],
		                       ] )
		;
		// The confirmation itself lives in MetadataService, where it is
		// written once for every caller; what this asserts is that the
		// duplicate finder honours it and does not report the row anyway.
		$this->metadataService->expects( $this->once() )
		                      ->method( 'confirmFullHash' )
		                      ->with( $rows, $hash )
		                      ->willReturn( [] )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'extractAlgorithm' )
		;

		$result = $this->service->findByHash( $hash );

		$this->assertCount( 0, $result );
	}


	public function testFindByHashScopesLookupToGivenUser(): void
	{

		$this->givenTheHashIsConfirmed();

		$hash = 'abc123';

		$rows = [
			[
				MetadataService::FIELD_FILE_ID  => 42,
				MetadataService::FIELD_META_KEY => MetadataService::getHashKey( 'sha1' ),
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

		// The requesting account's *mounts* must reach FilecacheService so
		// results are restricted to what that account can see (FCIAS Review
		// §6, Finding 1 was this filter never being threaded through at all;
		// the cross-account review was it being threaded as a bare uid, and
		// so resolving to the home storage alone).
		$this->filecacheService->expects( $this->once() )
		                       ->method( 'batchLookupFilecachePaths' )
		                       ->with( [ 42 ], [ [ 'storage' => 1, 'root' => '' ] ] )
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


	/**
	 * The confirmation lives in MetadataService; a test that is not about it
	 * says so by letting every candidate through.
	 */
	private function givenTheHashIsConfirmed(): void
	{

		$this->metadataService->method( 'confirmFullHash' )
		                      ->willReturnCallback(
			                      static fn(
				                      array $rows,
			                      ): array => $rows,
		                      )
		;
	}

}
