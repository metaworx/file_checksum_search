<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\SudoScope;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SudoScopeTest
	extends
	TestCase
{

	private IGroupManager&MockObject     $groups;

	private ISubAdmin&MockObject         $subAdmin;

	private IUserManager&MockObject      $users;

	private PermissionService&MockObject $permissions;

	private SudoScope                    $scope;


	protected function setUp(): void
	{

		parent::setUp();

		$this->groups      = $this->createMock( IGroupManager::class );
		$this->subAdmin    = $this->createMock( ISubAdmin::class );
		$this->users       = $this->createMock( IUserManager::class );
		$this->permissions = $this->createMock( PermissionService::class );

		$this->users->method( 'get' )
		            ->willReturnCallback( fn ( string $uid ): ?IUser => in_array( $uid, [ 'lead', 'member', 'stranger', 'root' ], true )
			            ? $this->createConfiguredMock( IUser::class, [ 'getUID' => $uid ] )
			            : null );

		$this->scope = new SudoScope( $this->groups, $this->subAdmin, $this->users, $this->permissions );
	}


	public function testAMemberOfAdminIsASudoerWhateverThePermissionSays(): void
	{

		$this->groups->method( 'isAdmin' )
		             ->with( 'root' )
		             ->willReturn( true )
		;
		$this->permissions->expects( $this->never() )
		                  ->method( 'isAllowed' )
		;

		$this->assertTrue( $this->scope->isSudoer( 'root' ) );
		$this->assertNull( $this->scope->resolve( 'root', null ), 'everyone' );
		$this->assertSame( 'member', $this->scope->resolve( 'root', 'member' ) );
	}


	public function testThePermissionMakesASudoerOfAnybodyItNames(): void
	{

		$this->groups->method( 'isAdmin' )
		             ->willReturn( false )
		;
		$this->permissions->method( 'isAllowed' )
		                  ->with( PermissionService::PERMISSION_INSTANCE_VIEW, 'lead' )
		                  ->willReturn( true )
		;

		$this->assertNull( $this->scope->resolve( 'lead', null ) );
	}


	/**
	 * Core's own delegation: a sub-admin sees the members of their groups,
	 * one at a time, and never everyone.
	 */
	public function testASubAdminReachesTheirMembersAndNobodyElse(): void
	{

		$this->groups->method( 'isAdmin' )
		             ->willReturn( false )
		;
		$this->permissions->method( 'isAllowed' )
		                  ->willReturn( false )
		;
		$this->subAdmin->method( 'isUserAccessible' )
		               ->willReturnCallback( static fn ( IUser $leader, IUser $member ): bool => $leader->getUID() === 'lead' && $member->getUID() === 'member' )
		;

		$this->assertSame( 'member', $this->scope->resolve( 'lead', 'member' ) );
		$this->assertFalse( $this->scope->resolve( 'lead', 'stranger' ), 'outside their groups' );
		$this->assertFalse( $this->scope->resolve( 'lead', null ), 'never everyone' );
		$this->assertFalse( $this->scope->resolve( 'lead', 'nobody' ), 'a target that does not exist' );
	}


	public function testEveryoneElseIsRefused(): void
	{

		$this->groups->method( 'isAdmin' )
		             ->willReturn( false )
		;
		$this->permissions->method( 'isAllowed' )
		                  ->willReturn( false )
		;
		$this->subAdmin->method( 'isUserAccessible' )
		               ->willReturn( false )
		;

		$this->assertFalse( $this->scope->isSudoer( 'stranger' ) );
		$this->assertFalse( $this->scope->resolve( 'stranger', null ) );
		$this->assertFalse( $this->scope->resolve( 'stranger', 'member' ) );
	}

}
