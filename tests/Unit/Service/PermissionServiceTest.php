<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PermissionService.
 *
 * RuleServiceTest already covers the rule-editing permission end to end
 * through RuleService's façade.  These tests cover the generic mechanism
 * itself: the config-key mapping, the three ways a user can be allowed,
 * and the behaviour on inputs the façade never produces.
 */
class PermissionServiceTest
	extends
	TestCase
{

// private properties
	private MockObject|IAppConfig    $appConfig;

	private MockObject|IGroupManager $groupManager;

	private PermissionService        $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->appConfig    = $this->createMock( IAppConfig::class );
		$this->groupManager = $this->createMock( IGroupManager::class );

		$this->service = new PermissionService( $this->appConfig, $this->groupManager );
	}


	/**
	 * Deny by default, then allow via each of the three routes in turn.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function configure(
		bool  $allUsers = false,
		array $users = [],
		array $groups = [],
	): void {

		$this->appConfig->method( 'getValueBool' )
		                ->with( Application::APP_ID, 'rule_editors_all_users', false )
		                ->willReturn( $allUsers )
		;

		$this->appConfig->method( 'getValueString' )
		                ->willReturnMap( [
			                [
				                Application::APP_ID,
				                'rule_editors_users',
				                '[]',
				                json_encode( $users, JSON_THROW_ON_ERROR ),
			                ],
			                [
				                Application::APP_ID,
				                'rule_editors_groups',
				                '[]',
				                json_encode( $groups, JSON_THROW_ON_ERROR ),
			                ],
		                ] )
		;
	}


	// isAllowed

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAllUsersFlagAllowsAnyone(): void
	{

		$this->configure( allUsers: true );

		$this->assertTrue(
			$this->service->isAllowed( PermissionService::PERMISSION_RULE_EDITING, 'nobody-in-particular' ),
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testListedUserIsAllowed(): void
	{

		$this->configure( users: [ 'alice' ] );

		$this->assertTrue(
			$this->service->isAllowed( PermissionService::PERMISSION_RULE_EDITING, 'alice' ),
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testMemberOfListedGroupIsAllowed(): void
	{

		$this->configure( groups: [ 'staff' ] );

		$this->groupManager->method( 'isInGroup' )
		                   ->with( 'bob', 'staff' )
		                   ->willReturn( true )
		;

		$this->assertTrue(
			$this->service->isAllowed( PermissionService::PERMISSION_RULE_EDITING, 'bob' ),
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testUnlistedUserIsDenied(): void
	{

		$this->configure( users: [ 'alice' ], groups: [ 'staff' ] );

		$this->groupManager->method( 'isInGroup' )
		                   ->willReturn( false )
		;

		$this->assertFalse(
			$this->service->isAllowed( PermissionService::PERMISSION_RULE_EDITING, 'carol' ),
		);
	}


	/**
	 * IGroupManager is optional, so group membership must not be consulted
	 * when it is absent — and its absence must not deny a user who is
	 * allowed by name.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGroupsAreSkippedWithoutAGroupManager(): void
	{

		$service = new PermissionService( $this->appConfig );
		$this->configure( users: [ 'alice' ], groups: [ 'staff' ] );

		$this->assertTrue(
			$service->isAllowed( PermissionService::PERMISSION_RULE_EDITING, 'alice' ),
		);
		$this->assertFalse(
			$service->isAllowed( PermissionService::PERMISSION_RULE_EDITING, 'bob' ),
		);
	}


	/**
	 * The allow-all flag short-circuits, so a denied lookup never costs a
	 * group-membership check per configured group.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAllUsersFlagSkipsTheGroupLookup(): void
	{

		$this->configure( allUsers: true, groups: [ 'staff' ] );

		$this->groupManager->expects( $this->never() )
		                   ->method( 'isInGroup' )
		;

		$this->service->isAllowed( PermissionService::PERMISSION_RULE_EDITING, 'alice' );
	}


	// config key mapping

	public function testWritesTheHistoricalConfigKeys(): void
	{

		// The keys on disk predate this service; renaming them would strand
		// every existing installation's configuration.
		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueBool' )
		                ->with( Application::APP_ID, 'rule_editors_all_users', true )
		;

		$this->service->setAllUsersEnabled( PermissionService::PERMISSION_RULE_EDITING, true );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testStringListsAreFilteredBeforePersisting(): void
	{

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_editors_groups',
			                $this->callback(
				                static function (
					                string $json,
				                ): bool {

					                return json_decode( $json, true, 512, JSON_THROW_ON_ERROR )
						                === [
							                'staff',
							                'admins',
						                ];
				                },
			                ),
		                )
		;

		$this->service->setGroups(
			PermissionService::PERMISSION_RULE_EDITING,
			[
				'staff',
				'',
				'admins',
			],
		);
	}


	public function testMalformedStoredListReadsAsEmpty(): void
	{

		$this->appConfig->method( 'getValueString' )
		                ->willReturn( '{invalid' )
		;

		$this->assertSame(
			[],
			$this->service->getUsers( PermissionService::PERMISSION_RULE_EDITING ),
		);
	}


	public function testNonListStoredValueReadsAsEmpty(): void
	{

		$this->appConfig->method( 'getValueString' )
		                ->willReturn( '"a string, not a list"' )
		;

		$this->assertSame(
			[],
			$this->service->getGroups( PermissionService::PERMISSION_RULE_EDITING ),
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testNonStringEntriesAreDroppedOnRead(): void
	{

		$this->appConfig->method( 'getValueString' )
		                ->willReturn(
			                json_encode(
				                [
					                'alice',
					                42,
					                '',
					                null,
					                'bob',
				                ],
				                JSON_THROW_ON_ERROR,
			                ),
		                )
		;

		$this->assertSame(
			[
				'alice',
				'bob',
			],
			$this->service->getUsers( PermissionService::PERMISSION_RULE_EDITING ),
		);
	}


	/**
	 * The read side of the same mapping. RuleService used to own these keys;
	 * pinning both directions is what makes the move a refactor rather than a
	 * rename that silently orphans every configured allow-list.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testReadsTheHistoricalConfigKeys(): void
	{

		$this->appConfig->expects( $this->once() )
		                ->method( 'getValueBool' )
		                ->with( Application::APP_ID, 'rule_editors_all_users', false )
		                ->willReturn( true )
		;
		$this->appConfig->method( 'getValueString' )
		                ->willReturnMap( [
			                [
				                Application::APP_ID,
				                'rule_editors_groups',
				                '[]',
				                json_encode( [ 'staff' ], JSON_THROW_ON_ERROR ),
			                ],
			                [
				                Application::APP_ID,
				                'rule_editors_users',
				                '[]',
				                json_encode( [ 'alice' ], JSON_THROW_ON_ERROR ),
			                ],
		                ] )
		;

		$this->assertTrue(
			$this->service->isAllUsersEnabled( PermissionService::PERMISSION_RULE_EDITING ),
		);
		$this->assertSame(
			[ 'staff' ],
			$this->service->getGroups( PermissionService::PERMISSION_RULE_EDITING ),
		);
		$this->assertSame(
			[ 'alice' ],
			$this->service->getUsers( PermissionService::PERMISSION_RULE_EDITING ),
		);
	}


	// canUserEditRules shortcut


	/**
	 * The named shortcut must stay a pure alias — if it ever drifted from
	 * isAllowed(), the four guard sites calling it would enforce something
	 * different from what the settings form configures.
	 *
	 * @dataProvider ruleEditingConfigurationProvider
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testCanUserEditRulesMatchesIsAllowed(
		bool   $allUsers,
		array  $users,
		array  $groups,
		bool   $inGroup,
		string $userId,
		bool   $expected,
	): void {

		$this->configure( allUsers: $allUsers, users: $users, groups: $groups );

		$this->groupManager->method( 'isInGroup' )
		                   ->willReturn( $inGroup )
		;

		$this->assertSame( $expected, $this->service->canUserEditRules( $userId ) );
		$this->assertSame(
			$this->service->isAllowed( PermissionService::PERMISSION_RULE_EDITING, $userId ),
			$this->service->canUserEditRules( $userId ),
		);
	}


	/**
	 * @return array<string, array{bool, string[], string[], bool, string, bool}>
	 */
	public static function ruleEditingConfigurationProvider(): array
	{

		return [
			'all users'    => [
				true,
				[],
				[],
				false,
				'anyone',
				true,
			],
			'listed user'  => [
				false,
				[ 'alice' ],
				[],
				false,
				'alice',
				true,
			],
			'group member' => [
				false,
				[],
				[ 'staff' ],
				true,
				'bob',
				true,
			],
			'unlisted'     => [
				false,
				[ 'alice' ],
				[ 'staff' ],
				false,
				'carol',
				false,
			],
		];
	}


	// unknown permissions


	/**
	 * An unknown permission must fail loudly. Returning false instead would
	 * read as a legitimate denial, and a typo in a permission key would then
	 * lock everyone out of a feature with nothing in the logs to say why.
	 *
	 * @dataProvider unknownPermissionCallProvider
	 */
	public function testUnknownPermissionThrows( callable $call ): void
	{

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown permission "no_such_permission".' );

		$call( $this->service );
	}


	/**
	 * @return array<string, array{callable}>
	 */
	public static function unknownPermissionCallProvider(): array
	{

		return [
			'isAllowed'          => [
				static fn(
					PermissionService $s,
				) => $s->isAllowed( 'no_such_permission', 'alice' ),
			],
			'isAllUsersEnabled'  => [
				static fn(
					PermissionService $s,
				) => $s->isAllUsersEnabled( 'no_such_permission' ),
			],
			'setAllUsersEnabled' => [
				static fn(
					PermissionService $s,
				) => $s->setAllUsersEnabled( 'no_such_permission', true ),
			],
			'getGroups'          => [
				static fn(
					PermissionService $s,
				) => $s->getGroups( 'no_such_permission' ),
			],
			'setGroups'          => [
				static fn(
					PermissionService $s,
				) => $s->setGroups( 'no_such_permission', [] ),
			],
			'getUsers'           => [
				static fn(
					PermissionService $s,
				) => $s->getUsers( 'no_such_permission' ),
			],
			'setUsers'           => [
				static fn(
					PermissionService $s,
				) => $s->setUsers( 'no_such_permission', [] ),
			],
		];
	}


	/**
	 * A rejected write must not reach the config at all.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testUnknownPermissionWriteTouchesNothing(): void
	{

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;
		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueBool' )
		;

		$this->expectException( InvalidArgumentException::class );

		$this->service->setUsers( 'no_such_permission', [ 'alice' ] );
	}

}
