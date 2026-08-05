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
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChecksumApiTest
	extends
	TestCase
{

// private properties
	private MockObject|IDBConnection    $db;

	private MockObject|HashIndexService $hashIndexService;

	private StatusService               $statusService;

	private MockObject|IRootFolder      $rootFolder;

	private MockObject|IUserSession     $userSession;

	private ChecksumApi                 $api;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db               = $this->createMock( IDBConnection::class );
		$this->hashIndexService = $this->createMock( HashIndexService::class );

		// StatusService is readonly — cannot be mocked by PHPUnit 10.5.
		// Construct a real instance with mocked collaborators.
		$this->statusService = new StatusService(
			$this->createMock( DatabaseService::class ),
			$this->createMock( TableNameService::class ),
			$this->createMock( IAppManager::class ),
		);

		$this->rootFolder  = $this->createMock( IRootFolder::class );
		$this->userSession = $this->createMock( IUserSession::class );

		$this->api = new ChecksumApi(
			$this->db,
			$this->hashIndexService,
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
		                       ->with( 'abc', null, 500 )
		                       ->willReturn( [] )
		;

		$this->api->findByHash( 'abc', null, 999 );
	}


	public function testFindByHashPassesAlgoFilter(): void
	{

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findByHash' )
		                       ->with( 'abc', 'md5', 100 )
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
		                       ->with( 'abc123', null, 100 )
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
		                       ->with( 'abc123', null, 100 )
		                       ->willReturn( [] )
		;

		$result = $this->api->findByHash( '  abc123  ' );

		$this->assertEmpty( $result['results'] );
	}


	// ─── getHashesByFileId ──────────────────────────────────────────

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

		$result = $this->api->findDuplicates( null, 2, 999, 0 );

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


	// ─── getHashesByFile ────────────────────────────────────────────

	public function testFindDuplicatesReturnsEmptyWhenNoUser(): void
	{

		$this->userSession->method( 'getUser' )
		                  ->willReturn( null )
		;

		$result = $this->api->findDuplicates();

		$this->assertEmpty( $result['duplicates'] );
		$this->assertSame( 0, $result['total_groups'] );
	}


	// ─── getHashesByPath ────────────────────────────────────────────

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


	public function testFindSameHashReturnsEmptyWhenNoDuplicates(): void
	{

		$qb     = $this->createMock( IQueryBuilder::class );
		$expr   = $this->createMock( IExpressionBuilder::class );
		$result = $this->createMock( IResult::class );

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		// Chain all builder methods to return self
		$qb->method( 'select' )
		   ->willReturnSelf()
		;
		$qb->method( 'from' )
		   ->willReturnSelf()
		;
		$qb->method( 'innerJoin' )
		   ->willReturnSelf()
		;
		$qb->method( 'where' )
		   ->willReturnSelf()
		;
		$qb->method( 'orderBy' )
		   ->willReturnSelf()
		;
		$qb->method( 'addOrderBy' )
		   ->willReturnSelf()
		;
		$qb->method( 'setMaxResults' )
		   ->willReturnSelf()
		;
		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturn( ':param' )
		;
		$expr->method( 'eq' )
		     ->willReturn( 'h1.fileid = :param' )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $result )
		;
		$result->method( 'fetchAll' )
		       ->willReturn( [] )
		;

		$data = $this->api->findSameHash( 42 );

		$this->assertEmpty( $data['duplicates'] );
	}


	public function testFindSameHashReturnsGroupedDuplicates(): void
	{

		$user       = $this->createMock( IUser::class );
		$userFolder = $this->createMock( Folder::class );
		$dupNode    = $this->createMock( File::class );
		$qb         = $this->createMock( IQueryBuilder::class );
		$expr       = $this->createMock( IExpressionBuilder::class );
		$result     = $this->createMock( IResult::class );

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

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;
		$qb->method( 'select' )
		   ->willReturnSelf()
		;
		$qb->method( 'from' )
		   ->willReturnSelf()
		;
		$qb->method( 'innerJoin' )
		   ->willReturnSelf()
		;
		$qb->method( 'where' )
		   ->willReturnSelf()
		;
		$qb->method( 'orderBy' )
		   ->willReturnSelf()
		;
		$qb->method( 'addOrderBy' )
		   ->willReturnSelf()
		;
		$qb->method( 'setMaxResults' )
		   ->willReturnSelf()
		;
		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturn( ':param' )
		;
		$expr->method( 'eq' )
		     ->willReturn( 'h1.fileid = :param' )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $result )
		;
		$result->method( 'fetchAll' )
		       ->willReturn( [
			       [
				       'algo'       => 'sha1',
				       'hash_value' => 'abc',
				       'fileid'     => '108',
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


	// ─── findSameHash ───────────────────────────────────────────────

	public function testGetHashesByFileDelegatesToGetHashesByFileId(): void
	{

		$file = $this->createMock( File::class );
		$file->expects( $this->once() )
		     ->method( 'getId' )
		     ->willReturn( 42 )
		;

		$qb     = $this->createMock( IQueryBuilder::class );
		$expr   = $this->createMock( IExpressionBuilder::class );
		$result = $this->createMock( IResult::class );

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;
		$qb->method( 'select' )
		   ->willReturnSelf()
		;
		$qb->method( 'from' )
		   ->willReturnSelf()
		;
		$qb->method( 'where' )
		   ->willReturnSelf()
		;
		$qb->method( 'orderBy' )
		   ->willReturnSelf()
		;
		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturn( ':param' )
		;
		$expr->method( 'eq' )
		     ->willReturn( 'fileid = :param' )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $result )
		;
		$result->method( 'fetchAll' )
		       ->willReturn( [
			       [
				       'algo'       => 'sha1',
				       'hash_value' => 'abc',
			       ],
		       ] )
		;
		$result->method( 'closeCursor' );

		$data = $this->api->getHashesByFile( $file );

		$this->assertSame( 42, $data['fileid'] );
		$this->assertCount( 1, $data['hashes'] );
	}


	public function testGetHashesByFileIdReturnsEmptyForUnknownFile(): void
	{

		$qb     = $this->createMock( IQueryBuilder::class );
		$expr   = $this->createMock( IExpressionBuilder::class );
		$result = $this->createMock( IResult::class );

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;
		$qb->method( 'select' )
		   ->willReturnSelf()
		;
		$qb->method( 'from' )
		   ->willReturnSelf()
		;
		$qb->method( 'where' )
		   ->willReturnSelf()
		;
		$qb->method( 'orderBy' )
		   ->willReturnSelf()
		;
		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturn( ':param' )
		;
		$expr->method( 'eq' )
		     ->willReturn( 'fileid = :param' )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $result )
		;
		$result->method( 'fetchAll' )
		       ->willReturn( [] )
		;
		$result->method( 'closeCursor' );

		$data = $this->api->getHashesByFileId( 99999 );

		$this->assertSame( 99999, $data['fileid'] );
		$this->assertEmpty( $data['hashes'] );
	}


	// ─── findDuplicates ─────────────────────────────────────────────

	public function testGetHashesByFileIdReturnsHashes(): void
	{

		$qb          = $this->createMock( IQueryBuilder::class );
		$exprBuilder = $this->createMock( IExpressionBuilder::class );
		$result      = $this->createMock( IResult::class );

		$this->db->expects( $this->once() )
		         ->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$qb->expects( $this->once() )
		   ->method( 'select' )
		   ->with( 'fileid', 'algo', 'hash_value' )
		   ->willReturnSelf()
		;
		$qb->expects( $this->once() )
		   ->method( 'from' )
		   ->with( TableNameService::TABLE_FILE_CHECKSUM_SEARCH_HASHES )
		   ->willReturnSelf()
		;
		$qb->expects( $this->once() )
		   ->method( 'where' )
		   ->willReturnSelf()
		;
		$qb->expects( $this->once() )
		   ->method( 'orderBy' )
		   ->with( 'algo' )
		   ->willReturnSelf()
		;
		$qb->expects( $this->once() )
		   ->method( 'expr' )
		   ->willReturn( $exprBuilder )
		;
		$qb->expects( $this->once() )
		   ->method( 'createNamedParameter' )
		   ->with( 42, PDO::PARAM_INT )
		   ->willReturn( ':param' )
		;
		$exprBuilder->expects( $this->once() )
		            ->method( 'eq' )
		            ->with( 'fileid', ':param' )
		            ->willReturn( 'fileid = :param' )
		;
		$qb->expects( $this->once() )
		   ->method( 'executeQuery' )
		   ->willReturn( $result )
		;

		$result->expects( $this->once() )
		       ->method( 'fetchAll' )
		       ->willReturn( [
			       [
				       'algo'       => 'sha1',
				       'hash_value' => 'abc',
			       ],
			       [
				       'algo'       => 'sha256',
				       'hash_value' => 'def',
			       ],
		       ] )
		;
		$result->expects( $this->once() )
		       ->method( 'closeCursor' )
		;

		$data = $this->api->getHashesByFileId( 42 );

		$this->assertSame( 42, $data['fileid'] );
		$this->assertCount( 2, $data['hashes'] );
		$this->assertSame( 'sha1', $data['hashes'][0]['algo'] );
		$this->assertSame( 'abc', $data['hashes'][0]['hash'] );
	}


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
		$qb         = $this->createMock( IQueryBuilder::class );
		$expr       = $this->createMock( IExpressionBuilder::class );
		$result     = $this->createMock( IResult::class );

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

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;
		$qb->method( 'select' )
		   ->willReturnSelf()
		;
		$qb->method( 'from' )
		   ->willReturnSelf()
		;
		$qb->method( 'where' )
		   ->willReturnSelf()
		;
		$qb->method( 'orderBy' )
		   ->willReturnSelf()
		;
		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturn( ':param' )
		;
		$expr->method( 'eq' )
		     ->willReturn( 'fileid = :param' )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $result )
		;
		$result->method( 'fetchAll' )
		       ->willReturn( [] )
		;
		$result->method( 'closeCursor' );

		$data = $this->api->getHashesByPath( 'Documents/report.pdf', 'alice' );

		$this->assertSame( 42, $data['fileid'] );
		$this->assertSame( 'Documents/report.pdf', $data['path'] );
	}


	public function testGetHashesByPathWithoutUserResolvesAbsolutePath(): void
	{

		$file   = $this->createMock( File::class );
		$qb     = $this->createMock( IQueryBuilder::class );
		$expr   = $this->createMock( IExpressionBuilder::class );
		$result = $this->createMock( IResult::class );

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

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;
		$qb->method( 'select' )
		   ->willReturnSelf()
		;
		$qb->method( 'from' )
		   ->willReturnSelf()
		;
		$qb->method( 'where' )
		   ->willReturnSelf()
		;
		$qb->method( 'orderBy' )
		   ->willReturnSelf()
		;
		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturn( ':param' )
		;
		$expr->method( 'eq' )
		     ->willReturn( 'fileid = :param' )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $result )
		;
		$result->method( 'fetchAll' )
		       ->willReturn( [] )
		;
		$result->method( 'closeCursor' );

		$data = $this->api->getHashesByPath( '/alice/files/Docs/x.pdf' );

		$this->assertSame( 42, $data['fileid'] );
		$this->assertSame( '/alice/files/Docs/x.pdf', $data['path'] );
	}


	// ─── recalcHash ─────────────────────────────────────────────────

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


	// ─── getStatus ──────────────────────────────────────────────────

	public function testRecalcHashUsesDefaultAlgoWhenNull(): void
	{

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcHash' )
		                       ->with( 42, 'sha1' )
		                       ->willReturn( [ 'success' => true ] )
		;

		$this->api->recalcHash( 42 );
	}

}
