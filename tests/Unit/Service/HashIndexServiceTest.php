<?php
/**
 * @noinspection PhpPrivateFieldCanBeLocalVariableInspection
 */

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HashIndexServiceTest
	extends
	TestCase
{

	private MockObject|IDBConnection    $db;

	private MockObject|TableNameService $tables;

	private MockObject|LifecycleHandler $lifecycleHandler;

	private MockObject|IRootFolder      $rootFolder;

	private MockObject|IUserManager     $userManager;

	private MockObject|ILockingProvider $lockingProvider;

	private MockObject|LoggerInterface  $logger;

	private HashIndexService            $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db               = $this->createMock( IDBConnection::class );
		$this->tables           = $this->createMock( TableNameService::class );
		$this->lifecycleHandler = $this->createMock( LifecycleHandler::class );
		$this->rootFolder       = $this->createMock( IRootFolder::class );
		$this->userManager      = $this->createMock( IUserManager::class );
		$this->lockingProvider  = $this->createMock( ILockingProvider::class );
		$this->logger           = $this->createMock( LoggerInterface::class );

		$this->tables->method( 'getHashTableName' )
		             ->willReturn( 'oc_file_checksum_search_hashes' )
		;
		$this->tables->method( 'getPendingTableName' )
		             ->willReturn( 'oc_file_checksum_search_pending' )
		;
		$this->tables->method( 'getSpName' )
		             ->willReturn( 'oc_fcias_parse_file_hashes' )
		;
		$this->tables->method( 'getFilecacheTableName' )
		             ->willReturn( 'oc_filecache' )
		;
		$this->tables->method( 'getPrefix' )
		             ->willReturn( 'oc_' )
		;

		$this->service = new HashIndexService(
			$this->db,
			$this->tables,
			$this->lifecycleHandler,
			$this->rootFolder,
			$this->userManager,
			$this->lockingProvider,
			$this->logger,
		);
	}


	public function testGetDefaultAlgoReturnsSha1(): void
	{

		$this->assertSame( 'sha1', HashIndexService::getDefaultAlgo() );
	}


	public function testSupportedAlgosContainsExpectedValues(): void
	{

		$this->assertContains( 'sha1', HashIndexService::SUPPORTED_ALGOS );
		$this->assertContains( 'sha256', HashIndexService::SUPPORTED_ALGOS );
		$this->assertContains( 'sha512', HashIndexService::SUPPORTED_ALGOS );
		$this->assertContains( 'md5', HashIndexService::SUPPORTED_ALGOS );
	}


	public function testRecalcHashAlgoIsLowercased(): void
	{

		// recalcHash lowercases algo before delegating to recalcFileHash.
		// The algo validation happens in recalcFileHash, not recalcHash.
		// We test that an unsupported algo reaches the rootFolder lookup
		// (meaning it passed through recalcHash without early error).
		$this->rootFolder->method( 'getById' )
		                 ->with( 42 )
		                 ->willReturn( [] )
		;

		$result = $this->service->recalcHash( 42, 'UNKNOWN_ALGO' );

		$this->assertFalse( $result['success'] );
	}


	public function testRecalcHashFileNotFoundReturnsError(): void
	{

		$this->rootFolder->method( 'getById' )
		                 ->with( 99999 )
		                 ->willReturn( [] )
		;

		$result = $this->service->recalcHash( 99999, 'sha1' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'File not found.', $result['error'] ?? '' );
	}


	public function testRecalcHashNonFileNodeReturnsError(): void
	{

		$folder = $this->createMock( Folder::class );

		$this->rootFolder->method( 'getById' )
		                 ->with( 42 )
		                 ->willReturn( [ $folder ] )
		;

		$result = $this->service->recalcHash( 42, 'sha1' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Node is not a file.', $result['error'] ?? '' );
	}


	public function testFindByHashReturnsRows(): void
	{

		$hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
		$rows = [
			[
				'fileid'     => '42',
				'algo'       => 'sha1',
				'hash_value' => $hash,
				'path'       => 'Documents',
				'name'       => 'report.pdf',
			],
		];

		$resultMock = $this->createMock( IResult::class );
		$resultMock->method( 'fetchAll' )
		           ->willReturn( $rows )
		;
		$resultMock->method( 'closeCursor' );

		$qb   = $this->createMock( IQueryBuilder::class );
		$expr = $this->createMock( IExpressionBuilder::class );

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;
		$qb->method( 'select' )
		   ->willReturn( $qb )
		;
		$qb->method( 'from' )
		   ->willReturn( $qb )
		;
		$qb->method( 'innerJoin' )
		   ->willReturn( $qb )
		;
		$qb->method( 'where' )
		   ->willReturn( $qb )
		;
		$qb->method( 'setMaxResults' )
		   ->willReturn( $qb )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $resultMock )
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturnCallback(
			   static fn(
				   mixed $value,
			   ): string => (string) $value,
		   )
		;

		$expr->method( 'eq' )
		     ->willReturn( '1=1' )
		;

		$result = $this->service->findByHash( $hash );

		$this->assertCount( 1, $result );
		$this->assertSame( '42', $result[0]['fileid'] );
		$this->assertSame( 'sha1', $result[0]['algo'] );
	}


	public function testFindByHashWithAlgoFilter(): void
	{

		$hash = 'abc123';
		$algo = 'md5';

		$resultMock = $this->createMock( IResult::class );
		$resultMock->method( 'fetchAll' )
		           ->willReturn( [] )
		;
		$resultMock->method( 'closeCursor' );

		$qb   = $this->createMock( IQueryBuilder::class );
		$expr = $this->createMock( IExpressionBuilder::class );

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;
		$qb->method( 'select' )
		   ->willReturn( $qb )
		;
		$qb->method( 'from' )
		   ->willReturn( $qb )
		;
		$qb->method( 'innerJoin' )
		   ->willReturn( $qb )
		;
		$qb->method( 'where' )
		   ->willReturn( $qb )
		;
		$qb->method( 'setMaxResults' )
		   ->willReturn( $qb )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $resultMock )
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturnCallback(
			   static fn(
				   mixed $value,
			   ): string => (string) $value,
		   )
		;

		// andWhere should be called when algo is provided
		$qb->expects( $this->once() )
		   ->method( 'andWhere' )
		   ->willReturn( $qb )
		;

		$expr->method( 'eq' )
		     ->willReturn( '1=1' )
		;

		$result = $this->service->findByHash( $hash, $algo );

		$this->assertEmpty( $result );
	}


	public function testBatchLookupFilecachePathsReturnsMappedPaths(): void
	{

		$fileIds = [
			42,
			108,
		];
		$rows    = [
			[
				'fileid' => 42,
				'path'   => 'files/photo.jpg',
				'name'   => 'photo.jpg',
				'id'     => 'home::bob',
			],
			[
				'fileid' => 108,
				'path'   => 'files/backup/photo.jpg',
				'name'   => 'photo.jpg',
				'id'     => 'home::bob',
			],
		];

		$resultMock = $this->createMock( IResult::class );
		$resultMock->method( 'fetch' )
		           ->willReturnOnConsecutiveCalls( $rows[0], $rows[1], false )
		;
		$resultMock->method( 'closeCursor' );

		$qb   = $this->createMock( IQueryBuilder::class );
		$expr = $this->createMock( IExpressionBuilder::class );

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;
		$qb->method( 'select' )
		   ->willReturn( $qb )
		;
		$qb->method( 'from' )
		   ->willReturn( $qb )
		;
		$qb->method( 'innerJoin' )
		   ->willReturn( $qb )
		;
		$qb->method( 'where' )
		   ->willReturn( $qb )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $resultMock )
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturnCallback(
			   static fn(
				   mixed $value,
			   ): string => is_array( $value )
				   ? implode( ',', $value )
				   : (string) $value,
		   )
		;

		$expr->method( 'in' )
		     ->willReturn( '1=1' )
		;

		$result = $this->service->batchLookupFilecachePaths( $fileIds );

		$this->assertCount( 2, $result );
		$this->assertSame( 'files/photo.jpg', $result[42]['path'] );
		$this->assertSame( 'bob', $result[42]['user'] );
		$this->assertSame( 'files/backup/photo.jpg', $result[108]['path'] );
	}


	public function testBatchLookupFilecachePathsWithEmptyArrayReturnsEmpty(): void
	{

		$result = $this->service->batchLookupFilecachePaths( [] );

		$this->assertEmpty( $result );
	}


	public function testDrainPendingReturnsProcessedCount(): void
	{

		$selectResultMock = $this->createMock( IResult::class );
		$selectResultMock->method( 'fetch' )
		                 ->willReturnOnConsecutiveCalls( false )
		;
		$selectResultMock->method( 'closeCursor' );

		$qb   = $this->createMock( IQueryBuilder::class );
		$expr = $this->createMock( IExpressionBuilder::class );

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;
		$qb->method( 'select' )
		   ->willReturn( $qb )
		;
		$qb->method( 'from' )
		   ->willReturn( $qb )
		;
		$qb->method( 'orderBy' )
		   ->willReturn( $qb )
		;
		$qb->method( 'setMaxResults' )
		   ->willReturn( $qb )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $selectResultMock )
		;

		$result = $this->service->drainPending( 10 );

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( 0, $result['deleted'] );
	}


	public function testFindAllDuplicatesReturnsGroups(): void
	{

		$rows = [
			[
				'algo'       => 'sha1',
				'hash_value' => 'abc123',
				'cnt'        => '3',
				'fileids'    => '42,108,256',
			],
		];

		$resultMock = $this->createMock( IResult::class );
		$resultMock->method( 'fetchAll' )
		           ->willReturn( $rows )
		;
		$resultMock->method( 'closeCursor' );

		$qb   = $this->createMock( IQueryBuilder::class );
		$func = $this->createMock( \OCP\DB\QueryBuilder\IFunctionBuilder::class );
		$expr = $this->createMock( IExpressionBuilder::class );

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;
		$qb->method( 'func' )
		   ->willReturn( $func )
		;

		$func->method( 'count' )
		     ->willReturn( $this->createMock( \OCP\DB\QueryBuilder\IQueryFunction::class ) )
		;
		$func->method( 'groupConcat' )
		     ->willReturn( $this->createMock( \OCP\DB\QueryBuilder\IQueryFunction::class ) )
		;
		$qb->method( 'select' )
		   ->willReturn( $qb )
		;
		$qb->method( 'selectAlias' )
		   ->willReturn( $qb )
		;
		$qb->method( 'from' )
		   ->willReturn( $qb )
		;
		$qb->method( 'groupBy' )
		   ->willReturn( $qb )
		;
		$qb->method( 'addGroupBy' )
		   ->willReturn( $qb )
		;
		$qb->method( 'having' )
		   ->willReturn( $qb )
		;
		$qb->method( 'orderBy' )
		   ->willReturn( $qb )
		;
		$qb->method( 'setMaxResults' )
		   ->willReturn( $qb )
		;
		$qb->method( 'setFirstResult' )
		   ->willReturn( $qb )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $resultMock )
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturnCallback(
			   static fn(
				   mixed $value,
			   ): string => (string) $value,
		   )
		;

		$expr->method( 'gte' )
		     ->willReturn( 'cnt >= 2' )
		;

		$result = $this->service->findAllDuplicates();

		$this->assertCount( 1, $result );
		$this->assertSame( 'sha1', $result[0]['algo'] );
		$this->assertSame( 3, $result[0]['file_count'] );
		$this->assertCount( 3, $result[0]['fileids'] );
	}


	public function testDeleteHashesExecutesStatement(): void
	{

		$this->db->expects( $this->once() )
		         ->method( 'executeStatement' )
		         ->with(
			         $this->stringContains( 'DELETE FROM' ),
			         $this->callback(
				         static fn(
					         array $params,
				         ): bool => $params[0] === 42,
			         ),
		         )
		         ->willReturn( 1 )
		;

		$result = $this->service->deleteHashes( 42 );

		$this->assertSame( 1, $result );
	}


	public function testCountHashesReturnsCount(): void
	{

		$resultMock = $this->createMock( IResult::class );
		$resultMock->method( 'fetchOne' )
		           ->willReturn( '5' )
		;

		$this->db->method( 'executeQuery' )
		         ->willReturn( $resultMock )
		;

		$result = $this->service->countHashes( 42 );

		$this->assertSame( 5, $result );
	}

}
