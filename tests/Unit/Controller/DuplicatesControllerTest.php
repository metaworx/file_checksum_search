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
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use Psr\Log\LoggerInterface;

class DuplicatesControllerTest
	extends
	FciasUnitTestCase
{

	private MockObject|HashIndexService $hashIndexService;

	protected IDBConnection&MockObject $db;

	private MockObject|IUserSession     $userSession;

	private MockObject|IGroupManager    $groupManager;

	private MockObject|IUserManager     $userManager;

	private MockObject|LoggerInterface  $logger;

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
		$this->logger           = $this->createMock( LoggerInterface::class );

		$this->controller = new DuplicatesController(
			'file_checksum_search',
			$request,
			$this->hashIndexService,
			$this->userSession,
			$this->groupManager,
			$this->userManager,
			$this->logger,
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

		// Mock batchLookupFilecachePaths on HashIndexService
		$this->hashIndexService->method( 'batchLookupFilecachePaths' )
		                       ->willReturn( [
			                       42  => [
				                       'path'       => 'files/photo.jpg',
				                       'name'       => 'photo.jpg',
				                       'storage_id' => 'home::bob',
				                       'user'       => 'bob',
			                       ],
			                       108 => [
				                       'path'       => 'files/backup/photo.jpg',
				                       'name'       => 'photo.jpg',
				                       'storage_id' => 'home::bob',
				                       'user'       => 'bob',
			                       ],
		                       ] )
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
		                       ->with( 'sha1', 2, 10000, 0 )
		                       ->willReturn( [] )
		;

		$response = $this->controller->findAll( algo: 'sha1' );

		$this->assertInstanceOf( DataResponse::class, $response );
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
