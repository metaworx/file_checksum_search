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


	// ─── resolveSet: naming several at once ─────────────────────────

	/**
	 * @return \OCP\IGroup&MockObject
	 */
	private function group(
		string $gid,
		array  $memberUids = [],
	) {

		$members = array_map(
			fn ( string $uid ) => $this->createConfiguredMock( IUser::class, [ 'getUID' => $uid ] ),
			$memberUids,
		);

		return $this->createConfiguredMock( \OCP\IGroup::class, [
			'getGID'         => $gid,
			'getDisplayName' => $gid,
			'getUsers'       => $members,
		] );
	}


	private function asSudoer(): void
	{

		$this->groups->method( 'isAdmin' )
		             ->willReturn( true )
		;
	}


	private function asSubAdminOf( string $gid ): void
	{

		$this->groups->method( 'isAdmin' )
		             ->willReturn( false )
		;
		$this->permissions->method( 'isAllowed' )
		                  ->willReturn( false )
		;
		$this->subAdmin->method( 'isSubAdminOfGroup' )
		               ->willReturnCallback( static fn ( IUser $u, $g ): bool => $u->getUID() === 'lead' && $g->getGID() === $gid )
		;
		$this->subAdmin->method( 'isUserAccessible' )
		               ->willReturnCallback( static fn ( IUser $leader, IUser $member ): bool => $leader->getUID() === 'lead' && $member->getUID() === 'member' )
		;
	}


	public function testASudoerMayNameAnyoneAndGroupsAreExpandedServerSide(): void
	{

		$this->asSudoer();
		$this->groups->method( 'get' )
		             ->with( 'team' )
		             ->willReturn( $this->group( 'team', [ 'member', 'stranger' ] ) )
		;

		$this->assertSame(
			[ 'member', 'stranger', 'root' ],
			$this->scope->resolveSet( 'root', [ 'root' ], [ 'team' ] ),
		);
	}


	public function testASubAdminMayNameTheirOwnGroupAndItsMembers(): void
	{

		$this->asSubAdminOf( 'team' );
		$this->groups->method( 'get' )
		             ->with( 'team' )
		             ->willReturn( $this->group( 'team', [ 'member' ] ) )
		;

		$this->assertSame( [ 'member' ], $this->scope->resolveSet( 'lead', [], [ 'team' ] ) );
		$this->assertSame( [ 'member' ], $this->scope->resolveSet( 'lead', [ 'member' ], [] ) );
	}


	/**
	 * The whole request is refused rather than quietly narrowed: a listing
	 * that answers for fewer accounts than were asked for hides the refusal.
	 */
	public function testOneUnreachableTargetRefusesTheWholeSet(): void
	{

		$this->asSubAdminOf( 'team' );
		$this->groups->method( 'get' )
		             ->willReturnCallback( fn ( string $gid ) => $this->group( $gid, [ 'member' ] ) )
		;

		$this->assertFalse( $this->scope->resolveSet( 'lead', [ 'member', 'stranger' ], [] ), 'a member they cannot reach' );
		$this->assertFalse( $this->scope->resolveSet( 'lead', [], [ 'others' ] ), 'a group they do not administer' );
		$this->assertFalse( $this->scope->resolveSet( 'lead', [ 'nobody' ], [] ), 'an account that does not exist' );
	}


	public function testNamingNobodyIsAnEmptySetNotARefusal(): void
	{

		$this->asSudoer();

		$this->assertSame( [], $this->scope->resolveSet( 'root', [], [] ) );
	}


	// ─── selectableFor: what the picker may offer ───────────────────

	public function testASubAdminIsOfferedOnlyTheirOwnGroupsAndMembers(): void
	{

		$this->asSubAdminOf( 'team' );
		$this->subAdmin->method( 'getSubAdminsGroups' )
		               ->willReturn( [ $this->group( 'team', [ 'member' ] ) ] )
		;

		$offer = $this->scope->selectableFor( 'lead', null, 21 );

		$this->assertTrue( $offer['prefill'] );
		$this->assertSame( [ 'team' ], array_column( $offer['groups'], 'id' ) );
		$this->assertSame( [ 'member' ], array_column( $offer['users'], 'id' ) );
	}


	public function testAnAccountThatAdministersNothingIsOfferedNothing(): void
	{

		$this->groups->method( 'isAdmin' )
		             ->willReturn( false )
		;
		$this->permissions->method( 'isAllowed' )
		                  ->willReturn( false )
		;
		$this->subAdmin->method( 'getSubAdminsGroups' )
		               ->willReturn( [] )
		;

		$this->assertFalse( $this->scope->selectableFor( 'stranger', null, 21 ) );
	}


	/**
	 * One past the threshold is fetched: its presence is what says the list
	 * is too long to hold, so the client must search instead. No count query.
	 */
	public function testMoreThanTheThresholdTurnsPrefillOff(): void
	{

		$this->asSudoer();
		$this->groups->method( 'search' )
		             ->with( '', 3 )
		             ->willReturn( [ $this->group( 'a' ), $this->group( 'b' ) ] )
		;
		$this->users->method( 'searchDisplayName' )
		            ->with( '', 3 )
		            ->willReturn( [
			            $this->createConfiguredMock( IUser::class, [ 'getUID' => 'u1', 'getDisplayName' => 'u1' ] ),
			            $this->createConfiguredMock( IUser::class, [ 'getUID' => 'u2', 'getDisplayName' => 'u2' ] ),
			            $this->createConfiguredMock( IUser::class, [ 'getUID' => 'u3', 'getDisplayName' => 'u3' ] ),
		            ] )
		;

		$offer = $this->scope->selectableFor( 'root', null, 2 );

		$this->assertFalse( $offer['prefill'], 'three users past a threshold of two' );
		$this->assertCount( 2, $offer['users'], 'and the overflow row is not handed out' );
	}

}
