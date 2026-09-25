<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\FileChecksumSearch\Controller\SudoTokensController;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\SudoTokens;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SudoTokensControllerTest
    extends
    TestCase
{

//  private properties

	private IRequest&MockObject          $request;

	private IGroupManager&MockObject     $groups;

	private PermissionService&MockObject $permissions;

	private SudoTokens&MockObject        $sudoTokens;

	private SudoTokensController         $controller;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( 'alice' )
		;
		$session = $this->createMock( IUserSession::class );
		$session->method( 'getUser' )
		        ->willReturn( $user )
		;

		$this->request     = $this->createMock( IRequest::class );
		$this->groups      = $this->createMock( IGroupManager::class );
		$this->permissions = $this->createMock( PermissionService::class );
		$this->sudoTokens  = $this->createMock( SudoTokens::class );

		$this->controller = new SudoTokensController(
			'file_checksum_search',
			$this->request,
			$session,
			$this->groups,
			$this->permissions,
			$this->sudoTokens,
		);
	}


//  other non-static methods

	private function apiAllowed( bool $allowed ): void
	{
		$this->groups->method( 'isAdmin' )
		             ->willReturn( false )
		;
		$this->permissions->method( 'isAllowed' )
		                  ->with( PermissionService::PERMISSION_API_ACCESS, 'alice' )
		                  ->willReturn( $allowed )
		;
	}

	/**
	 * A grant buys an account denied the API nothing, so the page has
	 * nothing to offer — and is told so rather than refused, so it can say
	 * why instead of erroring.
	 */
	public function testAnAccountDeniedTheApiIsOfferedNothing(): void
	{
		$this->apiAllowed( false );
		$this->sudoTokens->expects( $this->never() )
		                 ->method( 'listForUser' )
		;

		$data = $this->controller->mine()->getData();

		$this->assertFalse( $data['canUseApi'] );
		$this->assertSame( [], $data['tokens'] );
	}

	public function testTheListingIsTheCallersOwn(): void
	{
		$this->apiAllowed( true );
		$this->sudoTokens->method( 'listForUser' )
		                 ->with( 'alice' )
		                 ->willReturn( [ [ 'id' => 7, 'granted' => false ] ] )
		;

		$data = $this->controller->mine()->getData();

		$this->assertTrue( $data['canUseApi'] );
		$this->assertSame( 7, $data['tokens'][0]['id'] );
	}

	public function testGrantingRecordsWhoGrantedAndAnswersTheListing(): void
	{
		$this->apiAllowed( true );
		$this->request->method( 'getParam' )
		              ->with( 'granted' )
		              ->willReturn( true )
		;
		$this->sudoTokens->expects( $this->once() )
		                 ->method( 'grant' )
		                 ->with( 'alice', 7, 'alice' )
		;
		$this->sudoTokens->method( 'listForUser' )
		                 ->willReturn( [] )
		;

		$response = $this->controller->setMine( 7 );

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
		$this->assertTrue( $response->getData()['success'] );
	}

	public function testRevokingOnesOwnGrant(): void
	{
		$this->apiAllowed( true );
		$this->request->method( 'getParam' )
		              ->willReturn( false )
		;
		$this->sudoTokens->expects( $this->once() )
		                 ->method( 'revoke' )
		                 ->with( 'alice', 7 )
		;
		$this->sudoTokens->method( 'listForUser' )
		                 ->willReturn( [] )
		;

		$this->assertSame( Http::STATUS_OK, $this->controller->setMine( 7 )->getStatus() );
	}

	public function testAGrantTheServiceRefusesIsA400WithItsReason(): void
	{
		$this->apiAllowed( true );
		$this->request->method( 'getParam' )
		              ->willReturn( true )
		;
		$this->sudoTokens->method( 'grant' )
		                 ->willThrowException( new InvalidArgumentException( 'Only an app password can be granted.' ) )
		;

		$response = $this->controller->setMine( 5 );

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
		$this->assertSame( 'Only an app password can be granted.', $response->getData()['error'] );
	}

	public function testAnAccountDeniedTheApiCannotGrant(): void
	{
		$this->apiAllowed( false );
		$this->sudoTokens->expects( $this->never() )
		                 ->method( 'grant' )
		;

		$this->assertSame( Http::STATUS_FORBIDDEN, $this->controller->setMine( 7 )->getStatus() );
	}

	public function testTheAdministratorRevokesAnybodysGrant(): void
	{
		$this->sudoTokens->expects( $this->once() )
		                 ->method( 'revoke' )
		                 ->with( 'bob', 42 )
		;
		$this->sudoTokens->method( 'allGrants' )
		                 ->willReturn( [] )
		;
		$this->sudoTokens->method( 'listingAvailable' )
		                 ->willReturn( true )
		;

		$this->assertSame( [ 'grants' => [], 'available' => true ], $this->controller->revoke( 'bob', 42 )->getData() );
	}

	/**
	 * An empty list from a table that could not be read is not "no grants",
	 * and the page must be able to tell the two apart.
	 */
	public function testTheListingSaysWhenItIsNoAnswer(): void
	{
		$this->sudoTokens->method( 'allGrants' )
		                 ->willReturn( [] )
		;
		$this->sudoTokens->method( 'listingAvailable' )
		                 ->willReturn( false )
		;

		$data = $this->controller->all()->getData();

		$this->assertSame( [], $data['grants'] );
		$this->assertFalse( $data['available'] );
	}

	public function testTheCallersListingSaysWhenItIsNoAnswer(): void
	{
		$this->apiAllowed( true );
		$this->sudoTokens->method( 'listForUser' )
		                 ->willReturn( [] )
		;
		$this->sudoTokens->method( 'listingAvailable' )
		                 ->willReturn( false )
		;

		$this->assertFalse( $this->controller->mine()->getData()['available'] );
	}
}
