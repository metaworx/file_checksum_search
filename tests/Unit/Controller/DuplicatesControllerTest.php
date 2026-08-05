<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\DuplicatesController;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DuplicatesControllerTest
	extends
	TestCase
{

	private MockObject|HashIndexService $hashIndexService;

	private MockObject|IDBConnection    $db;

	private MockObject|IUserSession     $userSession;

	private MockObject|IGroupManager    $groupManager;

	private MockObject|IUserManager     $userManager;

	private DuplicatesController        $controller;


	protected function setUp(): void
	{

		parent::setUp();

		$this->hashIndexService = $this->createMock( HashIndexService::class );
		$this->db               = $this->createMock( IDBConnection::class );
		$this->userSession      = $this->createMock( IUserSession::class );
		$this->groupManager     = $this->createMock( IGroupManager::class );
		$this->userManager      = $this->createMock( IUserManager::class );
		$request                = $this->createMock( IRequest::class );

		$this->controller = new DuplicatesController(
			'file_checksum_search',
			$request,
			$this->hashIndexService,
			$this->db,
			$this->userSession,
			$this->groupManager,
			$this->userManager,
		);
	}


	public function testFindAllReturnsEmptyWhenNoGroups(): void
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
		                       ->willReturn( [] )
		;

		$response = $this->controller->findAll();

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertEmpty( $data['duplicates'] );
	}


	public function testFindAllReturnsUserDuplicates(): void
	{

		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( 'bob' )
		;
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;

		$this->hashIndexService->method( 'findAllDuplicates' )
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

		// Mock QueryBuilder for batch lookup
		$qb     = $this->createMock( IQueryBuilder::class );
		$expr   = $this->createMock( IExpressionBuilder::class );
		$result = $this->createMock( IResult::class );

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
		$qb->method( 'andWhere' )
		   ->willReturn( $qb )
		;
		$qb->method( 'executeQuery' )
		   ->willReturn( $result )
		;

		$expr->method( 'eq' )
		     ->willReturn( '1=1' )
		;
		$expr->method( 'in' )
		     ->willReturn( '1=1' )
		;
		$qb->method( 'createNamedParameter' )
		   ->willReturn( 'x' )
		;

		$result->method( 'fetch' )
		       ->willReturnOnConsecutiveCalls(
			       [
				       'fileid' => 42,
				       'path'   => 'files/photo.jpg',
				       'name'   => 'photo.jpg',
			       ],
			       [
				       'fileid' => 108,
				       'path'   => 'files/backup/photo.jpg',
				       'name'   => 'photo.jpg',
			       ],
			       false,
		       )
		;

		$response = $this->controller->findAll();

		$data = $response->getData();
		$this->assertCount( 1, $data['duplicates'] );
		$this->assertCount( 2, $data['duplicates'][0]['files'] );
	}


	public function testFindAllAcceptsAlgoFilter(): void
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
		                       ->with( 'sha1', 2, 50, 0 )
		                       ->willReturn( [] )
		;

		$this->controller->findAll( algo: 'sha1' );
	}


	public function testFindAllRejectsNonAdminUserParam(): void
	{

		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( 'bob' )
		;
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;
		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'bob' )
		                   ->willReturn( false )
		;

		$response = $this->controller->findAll( user: 'alice' );

		$this->assertSame( Http::STATUS_FORBIDDEN, $response->getStatus() );
	}

}
