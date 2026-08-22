<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Public;

use InvalidArgumentException;
use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Service\DatabaseService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChecksumApiTest
	extends
	TestCase
{

// private properties
	private MockObject|HashIndexService $hashIndexService;

	private MockObject|MetadataService  $metadataService;

	private StatusService               $statusService;

	private MockObject|IRootFolder      $rootFolder;

	private MockObject|IUserSession     $userSession;

	private ChecksumApi                 $api;


	protected function setUp(): void
	{

		parent::setUp();

		$this->hashIndexService = $this->createMock( HashIndexService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );

		// StatusService is readonly — cannot be mocked by PHPUnit 10.5.
		// Construct a real instance with mocked collaborators.
		$this->statusService = new StatusService(
			$this->createMock( DatabaseService::class ),
			$this->createMock( TableNameService::class ),
			$this->createMock( IAppManager::class ),
			$this->createMock( MetadataService::class ),
		);

		$this->rootFolder  = $this->createMock( IRootFolder::class );
		$this->userSession = $this->createMock( IUserSession::class );

		$this->api = new ChecksumApi(
			$this->hashIndexService,
			$this->metadataService,
			$this->statusService,
			$this->rootFolder,
			$this->userSession,
		);
	}


	// ─── findByHash ─────────────────────────────────────────────────

	public function testFindByHashClampsLimitTo500(): void
	{

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findByHash' )
		                       ->with( 'abc', null, 500, null )
		                       ->willReturn( [] )
		;

		$this->api->findByHash( 'abc', null, 999 );
	}


	public function testFindByHashPassesAlgoFilter(): void
	{

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findByHash' )
		                       ->with( 'abc', 'md5', 100, null )
		                       ->willReturn( [] )
		;

		$result = $this->api->findByHash( 'abc', 'md5' );

		$this->assertEmpty( $result['results'] );
	}


	public function testFindByHashReturnsResults(): void
	{

		$rows = [
			[
				'fileid'     => '42',
				'algo'       => 'sha1',
				'hash_value' => 'abc123',
				'path'       => 'Docs',
				'name'       => 'report.pdf',
			],
		];

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findByHash' )
		                       ->with( 'abc123', null, 100, null )
		                       ->willReturn( $rows )
		;

		$result = $this->api->findByHash( 'abc123' );

		$this->assertArrayHasKey( 'results', $result );
		$this->assertCount( 1, $result['results'] );
		$this->assertSame( 42, $result['results'][0]['fileid'] );
		$this->assertSame( 'sha1', $result['results'][0]['algo'] );
		$this->assertSame( 'abc123', $result['results'][0]['hash'] );
	}


	public function testFindByHashThrowsOnEmptyHash(): void
	{

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Hash parameter is required.' );

		$this->api->findByHash( '' );
	}


	public function testFindByHashTrimsWhitespace(): void
	{

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findByHash' )
		                       ->with( 'abc123', null, 100, null )
		                       ->willReturn( [] )
		;

		$result = $this->api->findByHash( '  abc123  ' );

		$this->assertEmpty( $result['results'] );
	}


	public function testFindByHashPassesRequestingUserThrough(): void
	{

		// Regression test for FCIAS Review §6, Finding 1: /api/v1/lookup had
		// no per-file ownership check. $requestingUser must reach
		// HashIndexService so the search is restricted to that user's own
		// files unless the caller (a trusted/admin caller) omits it.
		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findByHash' )
		                       ->with( 'abc123', null, 100, 'alice' )
		                       ->willReturn( [] )
		;

		$this->api->findByHash( 'abc123', null, 100, 'alice' );
	}


	// ─── findDuplicates ─────────────────────────────────────────────

	public function testFindDuplicatesClampsLimit(): void
	{

		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( 'bob' )
		;
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;

		$this->hashIndexService->method( 'findAllDuplicates' )
		                       ->willReturn( [] )
		;

		// limit > 500 should be clamped
		$this->hashIndexService->method( 'batchLookupFilecachePaths' )
		                       ->willReturn( [] )
		;

		$result = $this->api->findDuplicates( null, 2, 999 );

		$this->assertSame( 500, $result['pagination']['limit'] );
	}


	public function testFindDuplicatesRespectsAlgoAndMinCount(): void
	{

		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( 'bob' )
		;
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findAllDuplicates' )
		                       ->with( 'sha256', 3, 10000, 0 )
		                       ->willReturn( [] )
		;

		$result = $this->api->findDuplicates( 'sha256', 3 );

		$this->assertEmpty( $result['duplicates'] );
	}


	public function testFindDuplicatesReturnsEmptyWhenNoUser(): void
	{

		$this->userSession->method( 'getUser' )
		                  ->willReturn( null )
		;

		$result = $this->api->findDuplicates();

		$this->assertEmpty( $result['duplicates'] );
		$this->assertSame( 0, $result['total_groups'] );
	}


	public function testFindDuplicatesReturnsGroupedResults(): void
	{

		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( 'bob' )
		;
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findAllDuplicates' )
		                       ->with( null, 2, 10000, 0 )
		                       ->willReturn( [
			                       [
				                       'algo'       => 'sha1',
				                       'hash_value' => 'abc',
				                       'file_count' => 2,
				                       'fileids'    => [
					                       42,
					                       108,
				                       ],
			                       ],
		                       ] )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'batchLookupFilecachePaths' )
		                       ->with(
			                       [
				                       42,
				                       108,
			                       ],
			                       'bob',
		                       )
		                       ->willReturn( [
			                       42  => [
				                       'path'       => 'files/a.pdf',
				                       'name'       => 'a.pdf',
				                       'storage_id' => 'home::bob',
				                       'user'       => 'bob',
			                       ],
			                       108 => [
				                       'path'       => 'files/b.pdf',
				                       'name'       => 'b.pdf',
				                       'storage_id' => 'home::bob',
				                       'user'       => 'bob',
			                       ],
		                       ] )
		;

		$result = $this->api->findDuplicates();

		$this->assertCount( 1, $result['duplicates'] );
		$this->assertSame( 1, $result['total_groups'] );
		$this->assertCount( 2, $result['duplicates'][0]['files'] );
	}


	// ─── getHashesByFile ────────────────────────────────────────────

	public function testGetHashesByFileDelegatesToMetadataService(): void
	{

		$file = $this->createMock( File::class );
		$file->expects( $this->once() )
		     ->method( 'getId' )
		     ->willReturn( 42 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [ 'sha1' => 'abc' ] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( null )
		;

		$data = $this->api->getHashesByFile( $file );

		$this->assertSame( 42, $data['fileid'] );
		$this->assertCount( 1, $data['hashes'] );
	}


	// ─── getHashesByFileId ──────────────────────────────────────────

	public function testGetHashesByFileIdReturnsEmptyForUnknownFile(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 99999 )
		                      ->willReturn( [] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'getUpdatedAt' )
		                      ->with( 99999 )
		                      ->willReturn( null )
		;

		$data = $this->api->getHashesByFileId( 99999 );

		$this->assertSame( 99999, $data['fileid'] );
		$this->assertEmpty( $data['hashes'] );
	}


	public function testGetHashesByFileIdReturnsHashes(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [
			                      'sha1'   => 'abc',
			                      'sha256' => 'def',
		                      ] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( 1234567890 )
		;

		$data = $this->api->getHashesByFileId( 42 );

		$this->assertSame( 42, $data['fileid'] );
		$this->assertCount( 2, $data['hashes'] );
		$this->assertSame( 'sha1', $data['hashes'][0]['algo'] );
		$this->assertSame( 'abc', $data['hashes'][0]['hash'] );
		$this->assertNotNull( $data['hashes'][0]['updated_at'] );
	}


	public function testGetHashesByFileIdThrowsWhenRequestingUserCannotAccessFile(): void
	{

		// Regression test for FCIAS Review §6, Finding 1.
		$userFolder = $this->createMock( Folder::class );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $userFolder )
		;
		$userFolder->method( 'getById' )
		           ->with( 42 )
		           ->willReturn( [] )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'getHashes' )
		;

		$this->expectException( NotFoundException::class );

		$this->api->getHashesByFileId( 42, 'alice' );
	}


	public function testGetHashesByFileIdAllowsRequestingUserWithAccess(): void
	{

		$userFolder = $this->createMock( Folder::class );
		$node       = $this->createMock( File::class );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $userFolder )
		;
		$userFolder->method( 'getById' )
		           ->with( 42 )
		           ->willReturn( [ $node ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [ 'sha1' => 'abc' ] )
		;
		$this->metadataService->method( 'getUpdatedAt' )
		                      ->willReturn( null )
		;

		$data = $this->api->getHashesByFileId( 42, 'alice' );

		$this->assertSame( 42, $data['fileid'] );
	}


	// ─── getHashesByPath ────────────────────────────────────────────

	public function testGetHashesByPathThrowsOnNonFile(): void
	{

		$folder = $this->createMock( Folder::class );

		$this->rootFolder->method( 'get' )
		                 ->willReturn( $folder )
		;

		$this->expectException( NotFoundException::class );
		$this->expectExceptionMessage( 'Path does not resolve to a file' );

		$this->api->getHashesByPath( '/some/folder' );
	}


	public function testGetHashesByPathWithUserResolvesRelativePath(): void
	{

		$file       = $this->createMock( File::class );
		$userFolder = $this->createMock( Folder::class );

		$this->rootFolder->expects( $this->once() )
		                 ->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $userFolder )
		;

		$userFolder->expects( $this->once() )
		           ->method( 'get' )
		           ->with( 'Documents/report.pdf' )
		           ->willReturn( $file )
		;

		$file->expects( $this->once() )
		     ->method( 'getId' )
		     ->willReturn( 42 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( null )
		;

		$data = $this->api->getHashesByPath( 'Documents/report.pdf', 'alice' );

		$this->assertSame( 42, $data['fileid'] );
		$this->assertSame( 'Documents/report.pdf', $data['path'] );
	}


	public function testGetHashesByPathWithoutUserResolvesAbsolutePath(): void
	{

		$file = $this->createMock( File::class );

		$this->rootFolder->expects( $this->once() )
		                 ->method( 'get' )
		                 ->with( '/alice/files/Docs/x.pdf' )
		                 ->willReturn( $file )
		;

		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getPath' )
		     ->willReturn( '/alice/files/Docs/x.pdf' )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( null )
		;

		$data = $this->api->getHashesByPath( '/alice/files/Docs/x.pdf' );

		$this->assertSame( 42, $data['fileid'] );
		$this->assertSame( '/alice/files/Docs/x.pdf', $data['path'] );
	}


	// ─── findSameHash ───────────────────────────────────────────────

	public function testFindSameHashReturnsEmptyWhenNoHashes(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [] )
		;

		$data = $this->api->findSameHash( 42 );

		$this->assertEmpty( $data['duplicates'] );
	}


	public function testFindSameHashReturnsEmptyWhenNoDuplicates(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [ 'sha1' => 'abc' ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'queryByHash' )
		                      ->with( 'abc', 'sha1', 100 )
		                      ->willReturn( [
			                      [
				                      MetadataService::FIELD_FILE_ID    => 42,
				                      MetadataService::FIELD_META_KEY   => 'file-checksum-sha1',
				                      MetadataService::FIELD_JSON_ALIAS => '{}',
			                      ],
		                      ] )
		;

		// Only the reference file itself was found (filtered out), so empty result
		$data = $this->api->findSameHash( 42 );

		$this->assertEmpty( $data['duplicates'] );
	}


	public function testFindSameHashReturnsGroupedDuplicates(): void
	{

		$user       = $this->createMock( IUser::class );
		$userFolder = $this->createMock( Folder::class );
		$dupNode    = $this->createMock( File::class );

		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;
		$user->method( 'getUID' )
		     ->willReturn( 'bob' )
		;

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'bob' )
		                 ->willReturn( $userFolder )
		;

		// Duplicate file found
		$userFolder->method( 'getById' )
		           ->with( 108 )
		           ->willReturn( [ $dupNode ] )
		;
		$dupNode->method( 'getPath' )
		        ->willReturn( '/bob/files/Backup/photo.jpg' )
		;
		$dupNode->method( 'getName' )
		        ->willReturn( 'photo.jpg' )
		;
		$userFolder->method( 'getRelativePath' )
		           ->with( '/bob/files/Backup/photo.jpg' )
		           ->willReturn( 'Backup/photo.jpg' )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [ 'sha1' => 'abc' ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'queryByHash' )
		                      ->with( 'abc', 'sha1', 100 )
		                      ->willReturn( [
			                      [
				                      MetadataService::FIELD_FILE_ID    => 42,
				                      MetadataService::FIELD_META_KEY   => 'file-checksum-sha1',
				                      MetadataService::FIELD_JSON_ALIAS => '{}',
			                      ],
			                      [
				                      MetadataService::FIELD_FILE_ID    => 108,
				                      MetadataService::FIELD_META_KEY   => 'file-checksum-sha1',
				                      MetadataService::FIELD_JSON_ALIAS => '{}',
			                      ],
		                      ] )
		;

		$data = $this->api->findSameHash( 42 );

		$this->assertCount( 1, $data['duplicates'] );
		$this->assertSame( 'sha1', $data['duplicates'][0]['algo'] );
		$this->assertSame( 'abc', $data['duplicates'][0]['hash_value'] );
		$this->assertCount( 1, $data['duplicates'][0]['files'] );
		$this->assertSame( 108, $data['duplicates'][0]['files'][0]['fileid'] );
		$this->assertSame( 'Backup/photo.jpg', $data['duplicates'][0]['files'][0]['path'] );
	}


	// ─── getStatus ──────────────────────────────────────────────────

	public function testGetStatusReturnsExpectedShape(): void
	{

		// StatusService is a real instance with mocked collaborators.
		// The collaborators return defaults (null/0), so we verify the
		// response shape rather than exact values.
		$status = $this->api->getStatus();

		$this->assertArrayHasKey( 'version', $status );
		$this->assertArrayHasKey( 'dbVersion', $status );
		$this->assertArrayHasKey( 'rowCount', $status );
		$this->assertArrayHasKey( 'pendingRows', $status );
		$this->assertIsInt( $status['rowCount'] );
		$this->assertIsInt( $status['pendingRows'] );
	}


	// ─── recalcHash ─────────────────────────────────────────────────

	public function testRecalcHashDelegatesToHashIndexService(): void
	{

		$expected = [
			'success' => true,
			'algo'    => 'sha256',
			'hash'    => 'def456',
			'fileid'  => 42,
		];

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcHash' )
		                       ->with( 42, 'sha256' )
		                       ->willReturn( $expected )
		;

		$result = $this->api->recalcHash( 42, 'sha256' );

		$this->assertSame( $expected, $result );
	}


	public function testRecalcHashReturnsFailureResult(): void
	{

		$expected = [
			'success' => false,
			'error'   => 'File not found.',
		];

		$this->hashIndexService->method( 'recalcHash' )
		                       ->willReturn( $expected )
		;

		$result = $this->api->recalcHash( 99999 );

		$this->assertFalse( $result['success'] );
	}


	public function testRecalcHashUsesDefaultAlgoWhenNull(): void
	{

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcHash' )
		                       ->with( 42, 'sha1' )
		                       ->willReturn( [ 'success' => true ] )
		;

		$this->api->recalcHash( 42 );
	}


	public function testRecalcHashReturnsFailureWhenRequestingUserCannotAccessFile(): void
	{

		// Regression test for FCIAS Review §6, Finding 1.
		$userFolder = $this->createMock( Folder::class );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $userFolder )
		;
		$userFolder->method( 'getById' )
		           ->with( 99999 )
		           ->willReturn( [] )
		;

		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'recalcHash' )
		;

		$result = $this->api->recalcHash( 99999, 'sha1', 'alice' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'File not found.', $result['error'] );
	}


	public function testRecalcHashProceedsWhenRequestingUserHasAccess(): void
	{

		$userFolder = $this->createMock( Folder::class );
		$node       = $this->createMock( File::class );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $userFolder )
		;
		$userFolder->method( 'getById' )
		           ->with( 42 )
		           ->willReturn( [ $node ] )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcHash' )
		                       ->with( 42, 'sha256' )
		                       ->willReturn( [ 'success' => true ] )
		;

		$result = $this->api->recalcHash( 42, 'sha256', 'alice' );

		$this->assertTrue( $result['success'] );
	}

}
