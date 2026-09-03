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
use OCA\FileChecksumSearch\Service\SudoConfirmation;
use OCA\FileChecksumSearch\Service\SudoScope;
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
	private MockObject|SudoScope $sudo;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private MockObject|SudoConfirmation $confirmation;

	private MockObject|\OCP\IAppConfig  $appConfig;

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
		$this->sudo             = $this->createMock( SudoScope::class );
		$this->sudo->method( 'resolve' )
		           ->willReturn( false )
		;
		// Confirmed unless a test says so; the rule has its own tests.
		$this->confirmation = $this->createMock( SudoConfirmation::class );
		$this->appConfig    = $this->createMock( \OCP\IAppConfig::class );
		$this->appConfig->method( 'getValueInt' )
		                ->willReturn( 21 )
		;
		$this->confirmation->method( 'isConfirmed' )
		                   ->willReturn( true )
		;

		$this->controller = new DuplicatesController(
			'file_checksum_search',
			$request,
			$this->hashIndexService,
			$this->userSession,
			$this->groupManager,
			$this->userManager,
			$this->logger,
			$this->sudo,
			$this->confirmation,
			$this->appConfig,
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


	/**
	 * Membership in the admin group grants nothing on this route any more:
	 * naming another user is refused for everyone until the confirmed
	 * cross-account route exists.
	 */
	public function testFindAllRefusesAnAdministratorNamingAnotherUser(): void
	{

		$admin = $this->createMock( IUser::class );
		$admin->method( 'getUID' )
		      ->willReturn( 'admin' )
		;
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $admin )
		;
		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'admin' )
		                   ->willReturn( true )
		;
		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'listDuplicatesForUser' )
		;

		$response = $this->controller->findAll( user: 'alice' );

		$this->assertSame( Http::STATUS_FORBIDDEN, $response->getStatus() );
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


	/**
	 * The picker's shape: a set of accounts and groups, authorised together
	 * by SudoScope, which is also what expands the groups. The listing is
	 * asked for the resolved accounts, merged into one answer.
	 */
	public function testFindAllSudoTakesASetAndListsTheResolvedAccounts(): void
	{

		$this->signedInAs( 'lead' );
		$this->confirmation->method( 'isConfirmed' )
		                   ->willReturn( true )
		;
		$this->sudo->expects( $this->once() )
		           ->method( 'resolveSet' )
		           ->with( 'lead', [ 'alice' ], [ 'team' ] )
		           ->willReturn( [ 'alice', 'bob' ] )
		;
		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'listDuplicatesForUser' )
		                       ->with( [ 'alice', 'bob' ] )
		                       ->willReturn( [ 'duplicates' => [] ] )
		;

		$response = $this->controller->findAllSudo( users: [ 'alice' ], groups: [ 'team' ] );

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
	}


	public function testFindAllSudoRefusesASetTheScopeDoesNotAllow(): void
	{

		$this->signedInAs( 'lead' );
		$this->sudo->method( 'resolveSet' )
		           ->willReturn( false )
		;
		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'listDuplicatesForUser' )
		;

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->findAllSudo( users: [ 'stranger' ] )->getStatus(),
		);
	}


	public function testSelectableAnswersWhatThePickerMayOfferAndWhetherAllIsOne(): void
	{

		$this->signedInAs( 'lead' );
		$this->sudo->method( 'selectableFor' )
		           ->with( 'lead', null, 21 )
		           ->willReturn( [
			           'prefill' => true,
			           'groups'  => [ [ 'id' => 'team', 'label' => 'Team' ] ],
			           'users'   => [ [ 'id' => 'member', 'label' => 'Member' ] ],
		           ] )
		;
		$this->sudo->method( 'isSudoer' )
		           ->willReturn( false )
		;

		$data = $this->controller->selectable()->getData();

		$this->assertTrue( $data['prefill'] );
		$this->assertFalse( $data['all'], 'a sub-admin is not offered every account' );
		$this->assertSame( [ 'team' ], array_column( $data['groups'], 'id' ) );
	}


	public function testSelectableRefusesAnAccountThatMayNameNobody(): void
	{

		$this->signedInAs( 'bob' );
		$this->sudo->method( 'selectableFor' )
		           ->willReturn( false )
		;

		$this->assertSame( Http::STATUS_FORBIDDEN, $this->controller->selectable()->getStatus() );
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
