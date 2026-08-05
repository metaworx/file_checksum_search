<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\LookupController;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LookupControllerTest
	extends
	TestCase
{

	private MockObject|IDBConnection    $db;

	private MockObject|IRequest         $request;

	private MockObject|HashIndexService $hashIndexService;

	private MockObject|IRootFolder      $rootFolder;

	private MockObject|IUserSession     $userSession;

	private MockObject|LoggerInterface  $logger;

	private LookupController            $controller;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db               = $this->createMock( IDBConnection::class );
		$this->request          = $this->createMock( IRequest::class );
		$this->hashIndexService = $this->createMock( HashIndexService::class );
		$this->rootFolder       = $this->createMock( IRootFolder::class );
		$this->userSession      = $this->createMock( IUserSession::class );
		$this->logger           = $this->createMock( LoggerInterface::class );
		$this->controller       = new LookupController(
			'file_checksum_search',
			$this->request,
			$this->db,
			$this->hashIndexService,
			$this->rootFolder,
			$this->userSession,
			$this->logger,
		);
	}


	public function testByHashWithEmptyHashReturnsBadRequest(): void
	{

		$response = $this->controller->byHash( '' );

		$this->assertInstanceOf( DataResponse::class, $response );
		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );

		$data = $response->getData();
		$this->assertArrayHasKey( 'error', $data );
	}


	public function testByHashWithWhitespaceHashReturnsBadRequest(): void
	{

		$response = $this->controller->byHash( '   ' );

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
	}


	public function testByHashWithValidHashReturnsResults(): void
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
		$resultMock->expects( $this->once() )
		           ->method( 'fetchAll' )
		           ->willReturn( $rows )
		;
		$resultMock->expects( $this->once() )
		           ->method( 'closeCursor' )
		;

		$qb = $this->createMock( IQueryBuilder::class );
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
		$qb->method( 'setMaxResults' )
		   ->willReturnSelf()
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturn( $hash )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $resultMock )
		;
		$expr = $this->createMock( IExpressionBuilder::class );
		$expr->method( 'eq' )
		     ->willReturn( 'hash_value = :hash' )
		;

		$qb->expects( $this->once() )
		   ->method( 'expr' )
		   ->willReturn( $expr )
		;

		$this->db->expects( $this->once() )
		         ->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$response = $this->controller->byHash( $hash );

		$this->assertInstanceOf( DataResponse::class, $response );
		$this->assertSame( Http::STATUS_OK, $response->getStatus() );

		$data = $response->getData();
		$this->assertArrayHasKey( 'results', $data );
		$this->assertCount( 1, $data['results'] );
		$this->assertSame( 42, $data['results'][0]['fileid'] );
		$this->assertSame( 'sha1', $data['results'][0]['algo'] );
	}


	public function testByHashWithAlgoAddsWhereClause(): void
	{

		$hash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
		$algo = 'sha256';

		$resultMock = $this->createMock( IResult::class );
		$resultMock->method( 'fetchAll' )
		           ->willReturn( [] )
		;
		$resultMock->method( 'closeCursor' );

		$qb = $this->createMock( IQueryBuilder::class );
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
		$qb->method( 'setMaxResults' )
		   ->willReturnSelf()
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $resultMock )
		;

		// Verify andWhere is called for algo
		$qb->expects( $this->once() )
		   ->method( 'andWhere' )
		   ->willReturnSelf()
		;

		$qb->method( 'createNamedParameter' )
		   ->willReturnMap( [
			   [
				   $hash,
				   PDO::PARAM_STR,
				   null,
				   $hash,
			   ],
			   [
				   $algo,
				   PDO::PARAM_STR,
				   null,
				   $algo,
			   ],
		   ] )
		;

		$expr = $this->createMock( IExpressionBuilder::class );
		$expr->method( 'eq' )
		     ->willReturn( 'hash_value = :hash' )
		;

		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$response = $this->controller->byHash( $hash, $algo );

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
	}


	public function testGetHashesByFileIdReturnsHashArray(): void
	{

		$fileId = 42;
		$rows   = [
			[
				'algo'       => 'sha1',
				'hash_value' => 'abc123',
			],
			[
				'algo'       => 'sha256',
				'hash_value' => 'def456',
			],
		];

		$resultMock = $this->createMock( IResult::class );
		$resultMock->method( 'fetchAll' )
		           ->willReturn( $rows )
		;
		$resultMock->method( 'closeCursor' );

		$qb = $this->createMock( IQueryBuilder::class );
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
		$qb->method( 'createNamedParameter' )
		   ->willReturn( $fileId )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $resultMock )
		;
		$expr = $this->createMock( IExpressionBuilder::class );
		$expr->method( 'eq' )
		     ->willReturn( 'fileid = :fileid' )
		;

		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$response = $this->controller->getHashesByFileId( $fileId );

		$this->assertInstanceOf( DataResponse::class, $response );
		$this->assertSame( Http::STATUS_OK, $response->getStatus() );

		$data = $response->getData();
		$this->assertArrayHasKey( 'hashes', $data );
		$this->assertCount( 2, $data['hashes'] );
		$this->assertSame( 'sha1', $data['hashes'][0]['algo'] );
		$this->assertSame( 'abc123', $data['hashes'][0]['hash'] );
	}


	public function testGetHashesByFileIdReturnsEmptyForUnknownFile(): void
	{

		$resultMock = $this->createMock( IResult::class );
		$resultMock->method( 'fetchAll' )
		           ->willReturn( [] )
		;
		$resultMock->method( 'closeCursor' );

		$qb = $this->createMock( IQueryBuilder::class );
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
		$qb->method( 'createNamedParameter' )
		   ->willReturn( 99999 )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $resultMock )
		;
		$expr = $this->createMock( IExpressionBuilder::class );
		$expr->method( 'eq' )
		     ->willReturn( 'fileid = :fileid' )
		;

		$qb->method( 'expr' )
		   ->willReturn( $expr )
		;

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;

		$response = $this->controller->getHashesByFileId( 99999 );

		$data = $response->getData();
		$this->assertEmpty( $data['hashes'] );
	}

}
