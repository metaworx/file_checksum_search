<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\FilecacheService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Cache\ICache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FilecacheServiceTest
	extends
	TestCase
{

	private IRootFolder&MockObject   $rootFolder;

	private IDBConnection&MockObject $db;

	private FilecacheService         $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->rootFolder = $this->createMock( IRootFolder::class );
		$this->db         = $this->createMock( IDBConnection::class );
		$this->service    = new FilecacheService( $this->rootFolder, $this->db );
	}


	public function testGetFileReturnsFileWhenFilePassed(): void
	{

		$file = $this->createMock( File::class );

		$result = $this->service->getFile( $file );

		$this->assertSame( $file, $result );
	}


	public function testGetFileResolvesById(): void
	{

		$file   = $this->createMock( File::class );
		$fileId = 42;

		$this->rootFolder->expects( $this->once() )
		                 ->method( 'getFirstNodeById' )
		                 ->with( $fileId )
		                 ->willReturn( $file )
		;

		$result = $this->service->getFile( $fileId );

		$this->assertSame( $file, $result );
	}


	public function testGetFileThrowsWhenNotFound(): void
	{

		$fileId = 42;

		$this->rootFolder->expects( $this->once() )
		                 ->method( 'getFirstNodeById' )
		                 ->with( $fileId )
		                 ->willReturn( null )
		;

		$this->expectException( NotFoundException::class );

		$this->service->getFile( $fileId );
	}


	public function testGetFileThrowsWhenNodeIsNotFile(): void
	{

		$fileId = 42;
		$folder = $this->createMock( Folder::class );

		$this->rootFolder->expects( $this->once() )
		                 ->method( 'getFirstNodeById' )
		                 ->with( $fileId )
		                 ->willReturn( $folder )
		;

		$this->expectException( NotFoundException::class );

		$this->service->getFile( $fileId );
	}


	public function testGetHashesParsesChecksumField(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getChecksum' )
		     ->willReturn( 'SHA1:abc123 MD5:def456' )
		;

		$hashes = $this->service->getHashes( $file );

		$this->assertSame(
			[
				'sha1' => 'abc123',
				'md5'  => 'def456',
			],
			$hashes,
		);
	}


	public function testGetHashesFiltersByAlgo(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getChecksum' )
		     ->willReturn( 'SHA1:abc123 MD5:def456 SHA256:ghi789' )
		;

		$hashes = $this->service->getHashes(
			$file,
			[
				'sha1',
				'sha256',
			],
		);

		$this->assertSame(
			[
				'sha1'   => 'abc123',
				'sha256' => 'ghi789',
			],
			$hashes,
		);
	}


	public function testGetHashesReturnsEmptyForNullChecksum(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getChecksum' )
		     ->willReturn( null )
		;

		$hashes = $this->service->getHashes( $file );

		$this->assertSame( [], $hashes );
	}


	public function testGetHashesReturnsEmptyForEmptyChecksum(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getChecksum' )
		     ->willReturn( '' )
		;

		$hashes = $this->service->getHashes( $file );

		$this->assertSame( [], $hashes );
	}


	public function testSetHashesWritesToCache(): void
	{

		$file    = $this->createMock( File::class );
		$storage = $this->createMock( IStorage::class );
		$cache   = $this->createMock( ICache::class );

		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getStorage' )
		     ->willReturn( $storage )
		;
		$storage->method( 'getCache' )
		        ->willReturn( $cache )
		;

		$cache->expects( $this->once() )
		      ->method( 'update' )
		      ->with( 42, [ 'checksum' => 'SHA1:abc123 MD5:def456' ] )
		;

		$this->service->setHashes(
			$file,
			[
				'sha1' => 'abc123',
				'md5'  => 'def456',
			],
		);
	}


	public function testSetHashesKeepsAdditionalWhenFlagSet(): void
	{

		$file    = $this->createMock( File::class );
		$storage = $this->createMock( IStorage::class );
		$cache   = $this->createMock( ICache::class );

		$file->method( 'getChecksum' )
		     ->willReturn( 'SHA1:old123 SHA256:keep456' )
		;
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getStorage' )
		     ->willReturn( $storage )
		;
		$storage->method( 'getCache' )
		        ->willReturn( $cache )
		;

		$cache->expects( $this->once() )
		      ->method( 'update' )
		      ->with(
			      42,
			      $this->callback( function (
				      $data,
			      ) {

				      $parts = explode( ' ', $data['checksum'] );

				      return count( $parts ) === 3
					      && in_array( 'SHA1:new456', $parts )
					      && in_array( 'SHA256:keep456', $parts )
					      && in_array( 'MD5:added789', $parts );
			      } ),
		      )
		;

		$this->service->setHashes(
			$file,
			[
				'sha1' => 'new456',
				'md5'  => 'added789',
			],
			keepAdditional: true,
		);
	}


	public function testGetFileIdReturnsIdFromFile(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$id = FilecacheService::getFileId( $file );

		$this->assertSame( 42, $id );
	}


	public function testGetFileIdReturnsInt(): void
	{

		$id = FilecacheService::getFileId( 42 );

		$this->assertSame( 42, $id );
	}


	public function testGetNodeByIdReturnsNode(): void
	{

		$node = $this->createMock( Node::class );

		$this->rootFolder->expects( $this->once() )
		                 ->method( 'getById' )
		                 ->with( 42 )
		                 ->willReturn( [ $node ] )
		;

		$result = $this->service->getNodeById( 42 );

		$this->assertSame( $node, $result );
	}


	public function testGetNodeByIdThrowsWhenEmpty(): void
	{

		$this->rootFolder->expects( $this->once() )
		                 ->method( 'getById' )
		                 ->with( 42 )
		                 ->willReturn( [] )
		;

		$this->expectException( NotFoundException::class );

		$this->service->getNodeById( 42 );
	}


	public function testGetUserFolderDelegates(): void
	{

		$folder = $this->createMock( Folder::class );

		$this->rootFolder->expects( $this->once() )
		                 ->method( 'getUserFolder' )
		                 ->with( 'admin' )
		                 ->willReturn( $folder )
		;

		$result = $this->service->getUserFolder( 'admin' );

		$this->assertSame( $folder, $result );
	}


	public function testGetUserFolderPathReturnsPath(): void
	{

		$folder = $this->createMock( Folder::class );
		$folder->method( 'getPath' )
		       ->willReturn( '/admin/files' )
		;

		$this->rootFolder->expects( $this->once() )
		                 ->method( 'getUserFolder' )
		                 ->with( 'admin' )
		                 ->willReturn( $folder )
		;

		$path = $this->service->getUserFolderPath( 'admin' );

		$this->assertSame( '/admin/files', $path );
	}


	public function testCopyFilecacheChecksumDoesNotThrow(): void
	{

		$source = $this->createMock( File::class );
		$target = $this->createMock( File::class );

		$source->method( 'getChecksum' )
		       ->willReturn( null )
		;

		$this->service->copyFilecacheChecksum( $source, $target );

		$this->addToAssertionCount( 1 );
	}


	public function testCopyFilecacheChecksumCopiesToTarget(): void
	{

		$source = $this->createMock( File::class );
		$target = $this->createMock( File::class );

		$source->method( 'getChecksum' )
		       ->willReturn( 'SHA1:abc123' )
		;
		$target->method( 'getId' )
		       ->willReturn( 99 )
		;

		$targetCache = $this->createMock( ICache::class );
		$targetCache->expects( $this->once() )
		            ->method( 'update' )
		            ->with( 99, [ 'checksum' => 'SHA1:abc123' ] )
		;

		$targetStorage = $this->createMock( IStorage::class );
		$targetStorage->method( 'getCache' )
		              ->willReturn( $targetCache )
		;

		$target->method( 'getStorage' )
		       ->willReturn( $targetStorage )
		;

		$this->service->copyFilecacheChecksum( $source, $target );
	}


	public function testBatchLookupFilecachePathsReturnsEmptyForEmptyInput(): void
	{

		$result = $this->service->batchLookupFilecachePaths( [] );

		$this->assertSame( [], $result );
	}


	public function testBatchLookupFilecachePath(): void
	{

		$fileIds  = [ 42 ];
		$mockRows = [
			[
				'fileid' => 42,
				'path'   => 'files/Documents',
				'name'   => 'report.pdf',
				'id'     => 'home::admin',
			],
		];

		$exprBuilder = $this->createMock( IExpressionBuilder::class );
		$exprBuilder->method( 'in' )
		            ->willReturn( 'fc.fileid IN (:dcValue1)' )
		;

		$qb = $this->createMock( IQueryBuilder::class );
		$qb->method( 'expr' )
		   ->willReturn( $exprBuilder )
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
		$qb->method( 'createNamedParameter' )
		   ->willReturnArgument( 0 )
		;

		$resultStmt = $this->createMock( IResult::class );
		$resultStmt->method( 'fetch' )
		           ->willReturnOnConsecutiveCalls( $mockRows[0], false )
		;

		$qb->method( 'executeQuery' )
		   ->willReturn( $resultStmt )
		;

		$this->db->expects( $this->once() )
		         ->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$result = $this->service->batchLookupFilecachePaths( $fileIds );

		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 42, $result );
	}


	public function testBatchLookupFilecachePathsBuildsQueryAndReturnsRows(): void
	{

		$fileIds  = [
			42,
			108,
		];
		$mockRows = [
			[
				'fileid' => 42,
				'path'   => 'files/Documents',
				'name'   => 'report.pdf',
				'id'     => 'home::admin',
			],
			[
				'fileid' => 108,
				'path'   => 'files/Photos',
				'name'   => 'vacation.jpg',
				'id'     => 'home::admin',
			],
		];

		// Mock the query builder chain
		$exprBuilder = $this->createMock( IExpressionBuilder::class );
		$exprBuilder->method( 'in' )
		            ->willReturn( 'fc.fileid IN (:dcValue1, :dcValue2)' )
		;

		$qb = $this->createMock( IQueryBuilder::class );
		$qb->method( 'expr' )
		   ->willReturn( $exprBuilder )
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
		$qb->method( 'andWhere' )
		   ->willReturnSelf()
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturnArgument( 0 )
		;

		$resultStmt = $this->createMock( IResult::class );
		$resultStmt->method( 'fetch' )
		           ->willReturnOnConsecutiveCalls( $mockRows[0], $mockRows[1], false )
		;

		$qb->method( 'executeQuery' )
		   ->willReturn( $resultStmt )
		;

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$result = $this->service->batchLookupFilecachePaths( $fileIds );

		$this->assertCount( 2, $result );
		$this->assertArrayHasKey( 42, $result );
		$this->assertArrayHasKey( 108, $result );
		$this->assertSame( 'files/Documents', $result[42]['path'] );
		$this->assertSame( 'report.pdf', $result[42]['name'] );
		$this->assertSame( 'home::admin', $result[42]['storage_id'] );
		$this->assertSame( 'admin', $result[42]['user'] );
		$this->assertSame( 'files/Photos', $result[108]['path'] );
		$this->assertSame( 'vacation.jpg', $result[108]['name'] );
	}


	public function testBatchLookupFilecachePathsFiltersByUserName(): void
	{

		$fileIds  = [ 42 ];
		$mockRows = [
			[
				'fileid' => 42,
				'path'   => 'files/Documents',
				'name'   => 'report.pdf',
				'id'     => 'home::admin',
			],
		];

		$exprBuilder = $this->createMock( IExpressionBuilder::class );
		$exprBuilder->method( 'eq' )
		            ->willReturn( 's.id = :dcValue1' )
		;
		$exprBuilder->method( 'in' )
		            ->willReturn( 'fc.fileid IN (:dcValue2)' )
		;

		$qb = $this->createMock( IQueryBuilder::class );
		$qb->method( 'expr' )
		   ->willReturn( $exprBuilder )
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
		$qb->method( 'andWhere' )
		   ->willReturnSelf()
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturnArgument( 0 )
		;

		$resultStmt = $this->createMock( IResult::class );
		$resultStmt->method( 'fetch' )
		           ->willReturnOnConsecutiveCalls( $mockRows[0], false )
		;

		$qb->method( 'executeQuery' )
		   ->willReturn( $resultStmt )
		;

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$result = $this->service->batchLookupFilecachePaths( $fileIds, 'admin' );

		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 42, $result );
		$this->assertSame( 'admin', $result[42]['user'] );
	}


	public function testBatchLookupFilecachePathsExtractsUserFromLocalStorage(): void
	{

		$fileIds  = [ 42 ];
		$mockRows = [
			[
				'fileid' => 42,
				'path'   => 'files/Documents',
				'name'   => 'report.pdf',
				'id'     => 'local::/mnt/data/user1',
			],
		];

		$exprBuilder = $this->createMock( IExpressionBuilder::class );
		$exprBuilder->method( 'in' )
		            ->willReturn( 'fc.fileid IN (:dcValue1)' )
		;

		$qb = $this->createMock( IQueryBuilder::class );
		$qb->method( 'expr' )
		   ->willReturn( $exprBuilder )
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
		$qb->method( 'createNamedParameter' )
		   ->willReturnArgument( 0 )
		;

		$resultStmt = $this->createMock( IResult::class );
		$resultStmt->method( 'fetch' )
		           ->willReturnOnConsecutiveCalls( $mockRows[0], false )
		;

		$qb->method( 'executeQuery' )
		   ->willReturn( $resultStmt )
		;

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$result = $this->service->batchLookupFilecachePaths( $fileIds );

		$this->assertCount( 1, $result );
		$this->assertSame( 'user1', $result[42]['user'] );
	}

}
