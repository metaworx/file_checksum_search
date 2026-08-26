<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\FileChecksumSearch\Controller\RulesController;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\RuleDefinitionValidator;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;

class RulesControllerTest
	extends
	FciasUnitTestCase
{

	private MockObject|RuleService       $ruleService;

	private MockObject|PermissionService $permissionService;

	private MockObject|IUserSession      $userSession;

	private MockObject|IGroupManager     $groupManager;

	private MockObject|IUserManager      $userManager;

	private MockObject|IRequest          $request;

	private MockObject|LoggerInterface   $logger;

	private RulesController              $controller;


	protected function setUp(): void
	{

		parent::setUp();

		$this->ruleService       = $this->createMock( RuleService::class );
		$this->permissionService = $this->createMock( PermissionService::class );
		$this->userSession       = $this->createMock( IUserSession::class );
		$this->groupManager      = $this->createMock( IGroupManager::class );
		$this->userManager       = $this->createMock( IUserManager::class );
		$this->request           = $this->createMock( IRequest::class );
		$this->logger            = $this->createMock( LoggerInterface::class );

		// Partial mock: only readRequestBody() is mocked so php://input
		// (read-only in CLI) can return test-provided JSON payloads.
		$this->controller = $this->getMockBuilder( RulesController::class )
		                         ->onlyMethods( [ 'readRequestBody' ] )
		                         ->setConstructorArgs( [
			                         'file_checksum_search',
			                         $this->request,
			                         $this->ruleService,
			                         $this->permissionService,
			                         $this->userSession,
			                         $this->groupManager,
			                         // Real validator over the same mocks: the
			                         // existing payload tests keep exercising
			                         // validation through the controller door.
			                         new RuleDefinitionValidator( $this->groupManager, $this->userManager ),
			                         $this->userManager,
			                         $this->logger,
		                         ] )
		                         ->getMock()
		;
	}


	private function signIn(
		?string $uid,
		bool    $isAdmin = false,
	): void {

		if ( $uid === null )
		{
			$this->userSession->method( 'getUser' )
			                  ->willReturn( null )
			;

			return;
		}

		$this->userSession->method( 'getUser' )
		                  ->willReturn( $this->createConfiguredMock( IUser::class, [ 'getUID' => $uid ] ) )
		;
		$this->groupManager->method( 'isAdmin' )
		                   ->with( $uid )
		                   ->willReturn( $isAdmin )
		;
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function body( array $payload ): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn( json_encode( $payload, JSON_THROW_ON_ERROR ) )
		;
	}


	private function scope( string $scope ): void
	{

		$this->request->method( 'getParam' )
		              ->willReturnCallback(
			              static fn(
				              string $name,
				              mixed  $default = null,
			              ): mixed => $name === 'scope'
				              ? $scope
				              : $default,
		              )
		;
	}


	// authentication

	public function testEveryEndpointRequiresLogin(): void
	{

		$this->signIn( null );

		foreach (
			[
				'index',
				'reorder',
				'create',
			] as $method
		)
		{
			$this->assertSame(
				Http::STATUS_UNAUTHORIZED,
				$this->controller->{$method}()
				                 ->getStatus(),
				$method . '() must require a login',
			);
		}

		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->controller->update( 'a' )
			                 ->getStatus(),
		);
		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->controller->destroy( 'a' )
			                 ->getStatus(),
		);
	}


	// index — the view selector

	public function testIndexDefaultsToTheCallersOwnView(): void
	{

		$this->signIn( 'alice' );
		$this->scope( 'own' );
		$this->permissionService->method( 'canUserEditRules' )
		                        ->willReturn( true )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'listRulesFor' )
		                  ->with( 'alice' )
		                  ->willReturn( [] )
		;

		$data = $this->controller->index()
		                         ->getData()
		;

		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['canCreate'] );
		// Pickers are for scopes only an administrator can assign.
		$this->assertArrayNotHasKey( 'availableUsers', $data );
		$this->assertArrayNotHasKey( 'availableGroups', $data );
	}


	public function testAnAdminAskingForTheirOwnViewGetsThePersonalOne(): void
	{

		// The point of the view selector: an administrator on the personal
		// page is still on the personal page. Capability exists but is not
		// exercised unless asked for.
		$this->signIn( 'theadmin', isAdmin: true );
		$this->scope( 'own' );

		$this->ruleService->expects( $this->once() )
		                  ->method( 'listRulesFor' )
		                  ->with( 'theadmin' )
		                  ->willReturn( [] )
		;

		$data = $this->controller->index()
		                         ->getData()
		;

		// No pickers either: in their own view an administrator can still
		// only create rules for themselves, so a user or group picker would
		// advertise a capability this view does not have.
		$this->assertArrayNotHasKey( 'availableUsers', $data );
		$this->assertArrayNotHasKey( 'availableGroups', $data );
	}


	public function testAnAdminCanAskForTheWholeInstance(): void
	{

		$this->signIn( 'theadmin', isAdmin: true );
		$this->scope( 'all' );
		$this->userManager->method( 'callForAllUsers' );
		$this->groupManager->method( 'search' )
		                   ->willReturn( [] )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'listRulesFor' )
		                  ->with( null )
		                  ->willReturn( [] )
		;

		$data = $this->controller->index()
		                         ->getData()
		;

		$this->assertArrayHasKey( 'availableUsers', $data );
		$this->assertArrayHasKey( 'availableGroups', $data );
	}


	public function testANonAdminCannotAskForTheWholeInstance(): void
	{

		$this->signIn( 'alice' );
		$this->scope( 'all' );

		$this->ruleService->expects( $this->never() )
		                  ->method( 'listRulesFor' )
		;

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->index()
			                 ->getStatus(),
		);
	}


	public function testIndexRejectsAnUnknownScope(): void
	{

		$this->signIn( 'alice' );
		$this->scope( 'everything' );

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller->index()
			                 ->getStatus(),
		);
	}


	// create


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testCreateForcesANonAdminsScopeAndEnforcedFlag(): void
	{

		$this->signIn( 'alice' );
		$this->permissionService->method( 'canUserEditRules' )
		                        ->willReturn( true )
		;
		$this->ruleService->method( 'isPathWritableByUser' )
		                  ->willReturn( true )
		;
		// A payload claiming an instance-wide, enforced rule.
		$this->body( [
			'path'           => '/Documents',
			'algos'          => [ 'sha1' ],
			'userScope'      => 'all',
			'admin_enforced' => true,
			'pinned'         => true,
		] );

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleAdd' )
		                  ->with(
			                  $this->callback(
				                  static function (
					                  array $definition,
				                  ): bool {

					                  return $definition['userScope'] === 'alice'
						                  && $definition['admin_enforced'] === false
						                  && ! isset( $definition['pinned'] );
				                  },
			                  ),
		                  )
		;

		$this->assertSame(
			Http::STATUS_OK,
			$this->controller->create()
			                 ->getStatus(),
		);
	}


	public function testCreateRefusesAUserWithoutTheRuleEditingPermission(): void
	{

		$this->signIn( 'alice' );
		$this->permissionService->method( 'canUserEditRules' )
		                        ->willReturn( false )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleAdd' )
		;

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->create()
			                 ->getStatus(),
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testCreateRefusesAPathTheUserCannotWriteTo(): void
	{

		$this->signIn( 'alice' );
		$this->permissionService->method( 'canUserEditRules' )
		                        ->willReturn( true )
		;
		$this->ruleService->method( 'isPathWritableByUser' )
		                  ->willReturn( false )
		;
		$this->body( [
			'path'  => '/SomeoneElse',
			'algos' => [ 'sha1' ],
		] );

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->create()
			                 ->getStatus(),
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testCreateLetsAnAdminSetScopeAndEnforcement(): void
	{

		$this->signIn( 'theadmin', isAdmin: true );
		$this->groupManager->method( 'groupExists' )
		                   ->with( 'staff' )
		                   ->willReturn( true )
		;
		$this->body( [
			'path'           => '/Shared',
			'algos'          => [ 'sha256' ],
			'userScope'      => 'group:staff',
			'admin_enforced' => true,
		] );

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleAdd' )
		                  ->with(
			                  $this->callback(
				                  static fn(
					                  array $definition,
				                  ): bool => $definition['userScope'] === 'group:staff'
					                  && $definition['admin_enforced'] === true,
			                  ),
		                  )
		;

		$this->assertSame(
			Http::STATUS_OK,
			$this->controller->create()
			                 ->getStatus(),
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testCreateRejectsAScopeNamingAGroupThatDoesNotExist(): void
	{

		// Otherwise the rule sits in the list matching nothing, with no
		// indication why.
		$this->signIn( 'theadmin', isAdmin: true );
		$this->groupManager->method( 'groupExists' )
		                   ->willReturn( false )
		;
		$this->body( [
			'path'      => '/Shared',
			'algos'     => [ 'sha1' ],
			'userScope' => 'group:ghosts',
		] );

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleAdd' )
		;

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller->create()
			                 ->getStatus(),
		);
	}


	/**
	 * @dataProvider invalidPayloadProvider
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testCreateRejectsAnInvalidPayload( array $payload ): void
	{

		$this->signIn( 'theadmin', isAdmin: true );
		$this->body( $payload );

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleAdd' )
		;

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller->create()
			                 ->getStatus(),
		);
	}


	/**
	 * @return array<string, array{array}>
	 */
	public static function invalidPayloadProvider(): array
	{

		return [
			'unknown type'   => [
				[
					'path' => '/x',
					'type' => 'maybe',
				],
			],
			'unknown mode'   => [
				[
					'path'  => '/x',
					'algos' => [ 'sha1' ],
					'mode'  => 'someday',
				],
			],
			'blank path'     => [
				[
					'path'  => '   ',
					'algos' => [ 'sha1' ],
				],
			],
			'no valid algos' => [
				[
					'path'  => '/x',
					'algos' => [ 'rot13' ],
				],
			],
		];
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testCreateStoresNoAlgorithmsForANonIncludeRule(): void
	{

		$this->signIn( 'theadmin', isAdmin: true );
		$this->body( [
			'path' => '/Archive',
			'type' => 'exclude',
		] );

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleAdd' )
		                  ->with(
			                  $this->callback(
				                  static fn(
					                  array $definition,
				                  ): bool => $definition['type'] === 'exclude'
					                  && ! isset( $definition['algos'] )
					                  && ! isset( $definition['mode'] ),
			                  ),
		                  )
		;

		$this->assertSame(
			Http::STATUS_OK,
			$this->controller->create()
			                 ->getStatus(),
		);
	}


	// update

	public function testUpdateRefusesARuleTheUserMayNotMutate(): void
	{

		$this->signIn( 'alice' );
		$this->permissionService->method( 'canUserEditRules' )
		                        ->willReturn( true )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn(
			                  [
				                  'id'        => 'r1',
				                  'userScope' => 'all',
			                  ],
		                  )
		;
		$this->ruleService->method( 'canUserMutateRule' )
		                  ->willReturn( false )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleUpdate' )
		;

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->update( 'r1' )
			                 ->getStatus(),
		);
	}


	public function testUpdateReturns404ForAnUnknownRule(): void
	{

		$this->signIn( 'theadmin', isAdmin: true );
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( null )
		;

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->update( 'nope' )
			                 ->getStatus(),
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testUpdateCarriesUnchangedFieldsFromTheStoredRule(): void
	{

		$this->signIn( 'theadmin', isAdmin: true );
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( [
			                  'id'        => 'r1',
			                  'userScope' => 'all',
			                  'path'      => '/Docs',
			                  'algos'     => [ 'sha256' ],
			                  'mode'      => 'force',
			                  'enabled'   => true,
		                  ] )
		;
		// Disabling a rule is an update of `enabled` — there is no separate
		// toggle endpoint, so a minimal payload must not erase the rest.
		$this->body( [ 'enabled' => false ] );

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleUpdate' )
		                  ->with(
			                  'r1',
			                  $this->callback(
				                  static fn(
					                  array $definition,
				                  ): bool => $definition['enabled'] === false
					                  && $definition['path'] === '/Docs'
					                  && $definition['algos'] === [ 'sha256' ]
					                  && $definition['mode'] === 'force',
			                  ),
		                  )
		;

		$this->assertSame(
			Http::STATUS_OK,
			$this->controller->update( 'r1' )
			                 ->getStatus(),
		);
	}


	// destroy

	public function testDestroyRefusesToDeleteThePinnedDefault(): void
	{

		$this->signIn( 'theadmin', isAdmin: true );
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( [
			                  'id'     => 'default',
			                  'pinned' => true,
		                  ] )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleDelete' )
		;

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller->destroy( 'default' )
			                 ->getStatus(),
		);
	}


	public function testDestroyRemovesARuleTheCallerMayMutate(): void
	{

		$this->signIn( 'theadmin', isAdmin: true );
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( [ 'id' => 'r1' ] )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleDelete' )
		                  ->with( 'r1' )
		;

		$this->assertSame(
			Http::STATUS_OK,
			$this->controller->destroy( 'r1' )
			                 ->getStatus(),
		);
	}


	// reorder


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testReorderPassesTheCallersIdForANonAdmin(): void
	{

		$this->signIn( 'alice' );
		$this->permissionService->method( 'canUserEditRules' )
		                        ->willReturn( true )
		;
		$this->body( [
			'band'       => 4,
			'orderedIds' => [
				'b',
				'a',
			],
		] );

		// The service confines a non-admin to their own band-4 segment; the
		// controller's job is only to hand it the caller's identity.
		$this->ruleService->expects( $this->once() )
		                  ->method( 'reorderBand' )
		                  ->with(
			                  4,
			                  null,
			                  [
				                  'b',
				                  'a',
			                  ],
			                  'alice',
		                  )
		;

		$this->assertSame(
			Http::STATUS_OK,
			$this->controller->reorder()
			                 ->getStatus(),
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testReorderPassesNoCallerForAnAdmin(): void
	{

		$this->signIn( 'theadmin', isAdmin: true );
		$this->body( [
			'band'       => 4,
			'ownerId'    => 'alice',
			'orderedIds' => [
				'b',
				'a',
			],
		] );

		$this->ruleService->expects( $this->once() )
		                  ->method( 'reorderBand' )
		                  ->with(
			                  4,
			                  'alice',
			                  [
				                  'b',
				                  'a',
			                  ],
			                  null,
		                  )
		;

		$this->controller->reorder();
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testReorderMapsAnInvalidPermutationToBadRequest(): void
	{

		$this->signIn( 'theadmin', isAdmin: true );
		$this->body( [
			'band'       => 6,
			'orderedIds' => [ 'a' ],
		] );

		$this->ruleService->method( 'reorderBand' )
		                  ->willThrowException( new InvalidArgumentException( 'not a permutation' ) )
		;

		$response = $this->controller->reorder();

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
		$this->assertSame( 'not a permutation', $response->getData()['error'] );
	}


	/**
	 * @dataProvider invalidReorderProvider
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testReorderRejectsAMalformedPayload( array $payload ): void
	{

		$this->signIn( 'theadmin', isAdmin: true );
		$this->body( $payload );

		$this->ruleService->expects( $this->never() )
		                  ->method( 'reorderBand' )
		;

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller->reorder()
			                 ->getStatus(),
		);
	}


	/**
	 * @return array<string, array{array}>
	 */
	public static function invalidReorderProvider(): array
	{

		return [
			'no band'           => [ [ 'orderedIds' => [ 'a' ] ] ],
			'band not an int'   => [
				[
					'band'       => 'four',
					'orderedIds' => [ 'a' ],
				],
			],
			'no orderedIds'     => [ [ 'band' => 4 ] ],
			'orderedIds scalar' => [
				[
					'band'       => 4,
					'orderedIds' => 'a',
				],
			],
		];
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFailureIsLoggedAndReportedAsAServerError(): void
	{

		$this->signIn( 'theadmin', isAdmin: true );
		$this->body( [
			'band'       => 6,
			'orderedIds' => [],
		] );

		$this->ruleService->method( 'reorderBand' )
		                  ->willThrowException( new RuntimeException( 'config write failed' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		$response = $this->controller->reorder();

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
		$this->assertSame( 'config write failed', $response->getData()['error'] );
	}

}
