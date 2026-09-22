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

	private \OCA\FileChecksumSearch\Service\ReachResolver&MockObject $reach;

	private SudoScope                    $scope;


	protected function setUp(): void
	{

		parent::setUp();

		$this->groups      = $this->createMock( IGroupManager::class );
		$this->subAdmin    = $this->createMock( ISubAdmin::class );
		$this->users       = $this->createMock( IUserManager::class );
		$this->permissions = $this->createMock( PermissionService::class );
		$this->reach       = $this->createMock( \OCA\FileChecksumSearch\Service\ReachResolver::class );

		$this->users->method( 'get' )
		            ->willReturnCallback( fn ( string $uid ): ?IUser => in_array( $uid, [ 'lead', 'member', 'stranger', 'root' ], true )
			            ? $this->createConfiguredMock( IUser::class, [ 'getUID' => $uid ] )
			            : null );

		$this->scope = new SudoScope(
			$this->groups,
			$this->subAdmin,
			$this->users,
			$this->permissions,
			$this->reach,
		);
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
		$this->assertNull( $this->scope->resolve( 'root' ), 'everyone' );
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

		$this->assertNull( $this->scope->resolve( 'lead' ) );
	}


	/**
	 * Core's own delegation: a sub-admin's ceiling is the members of the
	 * groups they lead, once each, and never everyone. Naming one of them is
	 * {@see SudoScope::resolveSet()}'s business now — a set of one.
	 */
	public function testASubAdminsCeilingIsTheirMembersAndNobodyElse(): void
	{

		$this->groups->method( 'isAdmin' )
		             ->willReturn( false )
		;
		$this->permissions->method( 'isAllowed' )
		                  ->willReturn( false )
		;
		$this->subAdmin->method( 'getSubAdminsGroups' )
		               ->willReturnCallback( fn ( IUser $u ): array => $u->getUID() === 'lead'
			               ? [ $this->group( 'team', [ 'member' ] ), $this->group( 'crew', [ 'member', 'mate' ] ) ]
			               : [] )
		;

		$this->assertSame( [ 'member', 'mate' ], $this->scope->resolve( 'lead' ), 'their ceiling' );
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
		$this->assertFalse( $this->scope->resolve( 'stranger' ) );
	}


	// ─── mayCross: may they cross at all ────────────────────────────

	/**
	 * The entry question is wider than the ceiling question. `isSudoer()`
	 * asks "may they see everyone", which a group leader may not; `mayCross()`
	 * asks "may they see anyone but themselves", which a group leader may.
	 * Offering the tab on the first is how it stayed hidden from them.
	 */
	public function testASubAdminMayCrossThoughTheyAreNoSudoer(): void
	{

		$this->groups->method( 'isAdmin' )
		             ->willReturn( false )
		;
		$this->permissions->method( 'isAllowed' )
		                  ->willReturn( false )
		;
		$this->subAdmin->method( 'isSubAdmin' )
		               ->willReturnCallback( static fn ( IUser $u ): bool => $u->getUID() === 'lead' )
		;

		$this->assertFalse( $this->scope->isSudoer( 'lead' ) );
		$this->assertTrue( $this->scope->mayCross( 'lead' ) );
		$this->assertFalse( $this->scope->mayCross( 'member' ) );
		$this->assertFalse( $this->scope->mayCross( 'ghost' ), 'an account that does not exist' );
	}


	public function testASudoerMayCrossWithoutCoreBeingAsked(): void
	{

		$this->asSudoer();

		$this->subAdmin->expects( $this->never() )
		               ->method( 'isSubAdmin' )
		;

		$this->assertTrue( $this->scope->mayCross( 'root' ) );
	}


	// ─── resolveSet: naming several at once ─────────────────────────

	/**
	 * @return \OCP\IGroup&MockObject
	 */
	private function group(
		string $gid,
		array  $memberUids = [],
	) {

		// A display name too: IUser::getDisplayName() carries no return type
		// in OCP, so an unconfigured mock answers null where a real account
		// always answers a string, and a search that reads it would trip on
		// the mock rather than on anything the code does.
		$members = array_map(
			fn ( string $uid ) => $this->createConfiguredMock( IUser::class, [ 'getUID' => $uid, 'getDisplayName' => $uid ] ),
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

	/**
	 * Typing a member's name finds the member whether or not the name of
	 * the group they are in matches too. It did not: the groups were
	 * filtered by the term first, and members were collected from the
	 * groups that survived — so a search for "member" in a group called
	 * "team" found nobody. Found by the HTTP case, not by this suite.
	 */
	public function testALeadersSearchFindsAMemberByNameAloneAndGroupsByTheirs(): void
	{

		$this->asSubAdminOf( 'team' );
		$this->subAdmin->method( 'getSubAdminsGroups' )
		               ->willReturn( [ $this->group( 'team', [ 'member', 'mate' ] ) ] )
		;

		$byMember = $this->scope->selectableFor( 'lead', 'memb', 21 );

		$this->assertSame( [ 'member' ], array_column( $byMember['users'], 'id' ) );
		$this->assertSame( [], $byMember['groups'], 'the group is not called that' );

		$byGroup = $this->scope->selectableFor( 'lead', 'tea', 21 );

		$this->assertSame( [ 'team' ], array_column( $byGroup['groups'], 'id' ) );
		$this->assertSame( [], $byGroup['users'], 'no member is called that' );
	}


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


	// ─── mayReachFile: asking about a file, not an account ───────────

	/**
	 * A sudoer reaches anything, and the resolver is never consulted.
	 */
	public function testASudoerReachesAnyFile(): void
	{

		$this->asSudoer();

		$this->reach->expects( $this->never() )
		            ->method( 'mountsFor' )
		;

		$this->assertTrue( $this->scope->mayReachFile( 'root', 42 ) );
	}


	/**
	 * A group leader reaches a file within their ceiling's mounts and not
	 * one beside it — the same predicate the listing filters by, asked of
	 * the same resolver, so the two cannot disagree. The earlier branch this
	 * replaces accepted any file whose *storage* a member had mounted, which
	 * a single shared folder made the sharer's whole storage.
	 */
	public function testASubAdminReachesWhatLiesWithinTheirMembersMounts(): void
	{

		$this->asSubAdminOf( 'team' );
		$this->subAdmin->method( 'getSubAdminsGroups' )
		               ->willReturn( [ $this->group( 'team', [ 'member' ] ) ] )
		;

		$mounts = [ [ 'storage' => 9, 'root' => 'files/Projects/x' ] ];

		$this->reach->method( 'mountsFor' )
		            ->with( [ 'member' ] )
		            ->willReturn( $mounts )
		;
		$this->reach->method( 'contains' )
		            ->willReturnCallback( static fn ( ?array $m, int $fileId ): bool => $m === $mounts && $fileId === 42 )
		;

		$this->assertTrue( $this->scope->mayReachFile( 'lead', 42 ), 'within the shared subtree' );
		$this->assertFalse( $this->scope->mayReachFile( 'lead', 43 ), 'beside it' );
	}


	/**
	 * No ceiling, no reach: a plain account and an unknown one are refused
	 * before the resolver is asked anything.
	 */
	public function testAnAccountWithNoCeilingReachesNoFile(): void
	{

		$this->asSubAdminOf( 'team' );

		$this->reach->expects( $this->never() )
		            ->method( 'mountsFor' )
		;

		$this->assertFalse( $this->scope->mayReachFile( 'member', 42 ) );
		$this->assertFalse( $this->scope->mayReachFile( 'ghost', 42 ) );
	}

}
