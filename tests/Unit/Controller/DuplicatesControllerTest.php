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

	protected IDBConnection&MockObject  $db;

	private MockObject|IUserSession     $userSession;

	private MockObject|IGroupManager    $groupManager;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private MockObject|IUserManager $userManager;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private MockObject|LoggerInterface $logger;

	private DuplicatesController       $controller;


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


	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testFindAllReturnsEmptyWhenNoGroups(): void
	{

		$this->signedInAs( 'bob' );

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'listDuplicatesForUser' )
		                       ->willReturn( [
			                       'duplicates'   => [],
			                       'total_groups' => 0,
			                       'pagination'   => [
				                       'offset' => 0,
				                       'limit'  => 50,
			                       ],
		                       ] )
		;

		$response = $this->controller->findAll();

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertEmpty( $data['duplicates'] );
	}


	/**
	 * The grouping and per-user filtering itself lives in HashIndexService and
	 * is tested there; what matters here is that the controller asks for the
	 * signed-in user's view and returns the answer untouched.
	 */
	public function testFindAllReturnsTheListingForTheSignedInUser(): void
	{

		$this->signedInAs( 'bob' );

		$listing = [
			'duplicates'   => [
				[
					'algo'       => 'sha1',
					'hash_value' => 'abc',
					'file_count' => 2,
					'files'      => [
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
					],
				],
			],
			'total_groups' => 1,
			'pagination'   => [
				'offset' => 0,
				'limit'  => 50,
			],
		];

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'listDuplicatesForUser' )
		                       ->with( 'bob' )
		                       ->willReturn( $listing )
		;

		$this->assertSame(
			$listing,
			$this->controller->findAll()
			                 ->getData(),
		);
	}


	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testFindAllPassesTheQueryParametersThrough(): void
	{

		$this->signedInAs( 'bob' );

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'listDuplicatesForUser' )
		                       ->with( 'bob', 'sha1', 3, 10, 20 )
		                       ->willReturn( [
			                       'duplicates'   => [],
			                       'total_groups' => 0,
			                       'pagination'   => [
				                       'offset' => 20,
				                       'limit'  => 10,
			                       ],
		                       ] )
		;

		$response = $this->controller->findAll( algo: 'sha1', minCount: 3, limit: 10, offset: 20 );

		$this->assertInstanceOf( DataResponse::class, $response );
	}


	public function testFindAllQueriesTheTargetUserWhenAnAdminNamesOne(): void
	{

		$this->signedInAs( 'admin' );
		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'admin' )
		                   ->willReturn( true )
		;

		$alice = $this->createMock( IUser::class );
		$alice->method( 'getUID' )
		      ->willReturn( 'alice' )
		;
		$this->userManager->method( 'get' )
		                  ->with( 'alice' )
		                  ->willReturn( $alice )
		;

		// The whole point of the admin parameter: the listing is built for
		// alice, not for the admin making the request.
		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'listDuplicatesForUser' )
		                       ->with( 'alice' )
		                       ->willReturn( [
			                       'duplicates'   => [],
			                       'total_groups' => 0,
			                       'pagination'   => [
				                       'offset' => 0,
				                       'limit'  => 50,
			                       ],
		                       ] )
		;

		$this->controller->findAll( user: 'alice' );
	}


	public function testFindAllRejectsNonAdminUserParam(): void
	{

		$this->signedInAs( 'bob' );
		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'bob' )
		                   ->willReturn( false )
		;

		$response = $this->controller->findAll( user: 'alice' );

		$this->assertSame( Http::STATUS_FORBIDDEN, $response->getStatus() );
	}


	private function signedInAs( string $uid ): void
	{

		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( $uid )
		;
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;
	}

}
