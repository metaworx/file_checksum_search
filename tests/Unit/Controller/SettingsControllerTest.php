<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\SettingsController;
use OCA\FileChecksumSearch\Service\DatabaseService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

class SettingsControllerTest
	extends
	FciasUnitTestCase
{

// ── private properties ───────────────────────────────────────────────

	private StatusService              $statusService;

	private MockObject|IAppManager     $appManager;

	private MockObject|DatabaseService $databaseService;

	private MockObject|IUserManager    $userManager;

	private MockObject|RuleService     $ruleService;

	private MockObject|MetadataService $metadataService;

	private MockObject|IRequest        $request;

	private MockObject|LoggerInterface $logger;

	private SettingsController         $controller;


// ── setUp ────────────────────────────────────────────────────────────

	protected function setUp(): void
	{

		parent::setUp();

		// StatusService is readonly — cannot be mocked. Create the real
		// service with mocked dependencies and configure those mocks per-test.
		$this->appManager      = $this->createMock( IAppManager::class );
		$this->databaseService = $this->createMock( DatabaseService::class );
		$tableNameService      = $this->createMock( TableNameService::class );
		$this->metadataService = $this->createMock( MetadataService::class );

		$this->statusService = new StatusService(
			$this->databaseService,
			$tableNameService,
			$this->appManager,
			$this->metadataService,
		);

		$this->userManager = $this->createMock( IUserManager::class );
		$this->ruleService = $this->createMock( RuleService::class );
		$this->request     = $this->createMock( IRequest::class );
		$this->logger      = $this->createMock( LoggerInterface::class );

		// Partial mock: only readRequestBody() is mocked so php://input
		// (read-only in CLI) can return test-provided JSON payloads.
		$this->controller = $this->getMockBuilder( SettingsController::class )
		                         ->onlyMethods( [ 'readRequestBody' ] )
		                         ->setConstructorArgs( [
			                         'file_checksum_search',
			                         $this->request,
			                         $this->logger,
			                         $this->statusService,
			                         $this->userManager,
			                         $this->ruleService,
			                         $this->metadataService,
		                         ] )
		                         ->getMock()
		;
	}


// ── getStatus ────────────────────────────────────────────────────────

	public function testGetStatusReturnsAppVersionAndCounts(): void
	{

		// StatusService is readonly (real instance), mock its internal dependencies
		$this->appManager->expects( $this->once() )
		                 ->method( 'getAppVersion' )
		                 ->with( 'file_checksum_search' )
		                 ->willReturn( '1.2.3' )
		;

		$this->databaseService->expects( $this->once() )
		                      ->method( 'getDatabaseVersion' )
		                      ->willReturn( '10.11.6-MariaDB' )
		;

		// getHashRowCount() delegates to MetadataService::countHashEntries()
		$this->metadataService->expects( $this->once() )
		                      ->method( 'countHashEntries' )
		                      ->willReturn( 5000 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getPendingStats' )
		                      ->willReturn( [
			                      'pending:auto'    => 12,
			                      'pending:preview' => 3,
		                      ] )
		;

		$response = $this->controller->getStatus();

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertSame( '1.2.3', $data['version'] );
		$this->assertSame( '10.11.6-MariaDB', $data['dbVersion'] );
		$this->assertSame( 5000, $data['rowCount'] );
		$this->assertSame( [
			'pending:auto'    => 12,
			'pending:preview' => 3,
		], $data['pendingStats'] );
	}


// ── listRules ────────────────────────────────────────────────────────

	public function testListRulesReturnsDefinitionsAndUsers(): void
	{

		$definitions = [
			[
				'id'        => 'abc123',
				'enabled'   => true,
				'mode'      => 'auto',
				'algos'     => [
					'sha1',
					'sha256',
				],
				'path'      => '**',
				'userScope' => 'all',
			],
		];

		$this->ruleService->expects( $this->once() )
		                  ->method( 'loadRules' )
		                  ->willReturn( $definitions )
		;

		// Simulate callForAllUsers feeding two user UIDs
		$this->userManager->expects( $this->once() )
		                  ->method( 'callForAllUsers' )
		                  ->willReturnCallback(
			                  function (
				                  \Closure $callback,
			                  ): void {

				                  $alice = $this->createMock( IUser::class );
				                  $alice->method( 'getUID' )
				                        ->willReturn( 'alice' )
				                  ;
				                  $bob = $this->createMock( IUser::class );
				                  $bob->method( 'getUID' )
				                      ->willReturn( 'bob' )
				                  ;

				                  $callback( $alice );
				                  $callback( $bob );
			                  },
		                  )
		;

		$response = $this->controller->listRules();

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();

		$this->assertSame( $definitions, $data['definitions'] );
		$this->assertSame( HashCalculationService::SUPPORTED_ALGOS, $data['supportedAlgos'] );
		$this->assertSame(
			[
				'alice',
				'bob',
			],
			$data['users'],
		);
	}


// ── saveRule ─────────────────────────────────────────────────────────

	public function testSaveRuleCreatesNewRule(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'enabled'   => true,
				                 'mode'      => 'auto',
				                 'algos'     => [ 'sha1' ],
				                 'path'      => '/Documents',
				                 'userScope' => 'alice',
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleAdd' )
		                  ->with(
			                  $this->callback(
				                  function (
					                  array $definition,
				                  ): bool {

					                  return $definition['enabled'] === true
						                  && $definition['mode'] === 'auto'
						                  && $definition['algos'] === [ 'sha1' ]
						                  && $definition['path'] === '/Documents'
						                  && $definition['userScope'] === 'alice';
				                  },
			                  ),
		                  )
		;

		$response = $this->controller->saveRule();

		$this->assertInstanceOf( DataResponse::class, $response );
		$this->assertSame( Http::STATUS_OK, $response->getStatus() );

		$data = $response->getData();
		$this->assertTrue( $data['success'] );
	}


	public function testSaveRuleUpdatesExistingRule(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'id'        => 'existing-id-123',
				                 'enabled'   => false,
				                 'mode'      => 'onchange',
				                 'algos'     => [
					                 'sha256',
					                 'md5',
				                 ],
				                 'path'      => '/Photos',
				                 'userScope' => 'all',
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleUpdate' )
		                  ->with(
			                  'existing-id-123',
			                  $this->callback(
				                  function (
					                  array $definition,
				                  ): bool {

					                  return $definition['enabled'] === false
						                  && $definition['mode'] === 'onchange'
						                  && $definition['algos'] === [
							                  'sha256',
							                  'md5',
						                  ];
				                  },
			                  ),
		                  )
		;

		$response = $this->controller->saveRule();

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertTrue( $data['success'] );
	}


	public function testSaveRuleRejectsInvalidAlgos(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'algos' => [
					                 'invalid_algo',
					                 'also_bogus',
				                 ],
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleAdd' )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleUpdate' )
		;

		$response = $this->controller->saveRule();

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );

		$data = $response->getData();
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'At least one supported algorithm is required.', $data['error'] );
	}


	public function testSaveRuleReturnsServerErrorOnException(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'algos' => [ 'sha1' ],
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleAdd' )
		                  ->willThrowException( new RuntimeException( 'Storage failure' ) )
		;

		// Logger::error is called on exception
		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		$response = $this->controller->saveRule();

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );

		$data = $response->getData();
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'Storage failure', $data['error'] );
	}


// ── deleteRule ───────────────────────────────────────────────────────

	public function testDeleteRuleRemovesById(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'id' => 'rule-to-delete',
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleDelete' )
		                  ->with( 'rule-to-delete' )
		;

		$response = $this->controller->deleteRule();

		$this->assertInstanceOf( DataResponse::class, $response );
		$this->assertSame( Http::STATUS_OK, $response->getStatus() );

		$data = $response->getData();
		$this->assertTrue( $data['success'] );
	}


	public function testDeleteRuleRequiresId(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn( json_encode( [ 'notId' => 'whatever' ] ) )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleDelete' )
		;

		$response = $this->controller->deleteRule();

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );

		$data = $response->getData();
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'Definition ID is required.', $data['error'] );
	}


	public function testDeleteRuleReturnsServerErrorOnException(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn( json_encode( [ 'id' => 'doomed' ] ) )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleDelete' )
		                  ->with( 'doomed' )
		                  ->willThrowException( new RuntimeException( 'DB down' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		$response = $this->controller->deleteRule();

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );

		$data = $response->getData();
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'DB down', $data['error'] );
	}


// ── toggleRule ───────────────────────────────────────────────────────

	public function testToggleRuleEnablesAndDisables(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'id'      => 'toggle-me',
				                 'enabled' => true,
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleToggle' )
		                  ->with( 'toggle-me', true )
		;

		$response = $this->controller->toggleRule();

		$this->assertInstanceOf( DataResponse::class, $response );
		$this->assertSame( Http::STATUS_OK, $response->getStatus() );

		$data = $response->getData();
		$this->assertTrue( $data['success'] );
	}


	public function testToggleRuleRequiresId(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn( json_encode( [ 'enabled' => false ] ) )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleToggle' )
		;

		$response = $this->controller->toggleRule();

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );

		$data = $response->getData();
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'Definition ID is required.', $data['error'] );
	}


	public function testToggleRuleReturnsServerErrorOnException(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'id'      => 'broken',
				                 'enabled' => false,
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleToggle' )
		                  ->with( 'broken', false )
		                  ->willThrowException( new RuntimeException( 'DB write error' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		$response = $this->controller->toggleRule();

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );

		$data = $response->getData();
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'DB write error', $data['error'] );
	}


// ── getCrontabSnippet ────────────────────────────────────────────────

	public function testGetCrontabSnippetGeneratesCorrectLine(): void
	{

		$this->request->expects( $this->exactly( 5 ) )
		              ->method( 'getParam' )
		              ->willReturnMap( [
			              [
				              'userScope',
				              'all',
				              'all',
			              ],
			              [
				              'path',
				              '/',
				              '',
			              ],
			              [
				              'algo',
				              'sha1',
				              'sha256',
			              ],
			              [
				              'batchSize',
				              100,
				              200,
			              ],
			              [
				              'interval',
				              900,
				              3600,
			              ],
		              ] )
		;

		$response = $this->controller->getCrontabSnippet();

		$this->assertInstanceOf( DataResponse::class, $response );

		$data    = $response->getData();
		$snippet = $data['snippet'];

		$this->assertIsString( $snippet );
		// interval=3600 → 60 minutes → "0 */1 * * *"
		$this->assertStringStartsWith( '0 */1 * * * ', $snippet );
		$this->assertStringContainsString( 'file-checksum-search:generate', $snippet );
		$this->assertStringContainsString( '--user=all', $snippet );
		// path default '/' is suppressed; algo is escapeshellarg-quoted
		$this->assertStringContainsString( "--algo='sha256'", $snippet );
		$this->assertStringContainsString( '--batch-size=200', $snippet );
	}


	public function testGetCrontabSnippetUsesDefaults(): void
	{

		$this->request->expects( $this->exactly( 5 ) )
		              ->method( 'getParam' )
		              ->willReturnMap( [
			              [
				              'userScope',
				              'all',
				              'all',
			              ],
			              [
				              'path',
				              '/',
				              '/',
			              ],
			              [
				              'algo',
				              'sha1',
				              'sha1',
			              ],
			              [
				              'batchSize',
				              100,
				              100,
			              ],
			              [
				              'interval',
				              900,
				              900,
			              ],
		              ] )
		;

		$response = $this->controller->getCrontabSnippet();

		$data    = $response->getData();
		$snippet = $data['snippet'];

		// interval=900 → 15 minutes → "*/15 * * *"
		$this->assertStringStartsWith( '*/15 * * * ', $snippet );
		$this->assertStringContainsString( '--user=all', $snippet );
		$this->assertStringContainsString( "--algo='sha1'", $snippet );
		// batchSize=100 > 0 → included; path='/' → suppressed
		$this->assertStringContainsString( '--batch-size=100', $snippet );
		$this->assertStringNotContainsString( '--path=', $snippet );
	}


// ── getAdminOptions ──────────────────────────────────────────────────

	public function testGetAdminOptionsReturnsPermissionFields(): void
	{

		$this->userManager->expects( $this->once() )
		                  ->method( 'callForAllUsers' )
		                  ->willReturnCallback(
			                  function (
				                  \Closure $callback,
			                  ): void {

				                  $alice = $this->createConfiguredMock(
					                  IUser::class,
					                  [
						                  'getUID'         => 'alice',
						                  'getDisplayName' => 'Alice',
					                  ],
				                  );
				                  $callback( $alice );
			                  },
		                  )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'isAllUsersEnabled' )
		                  ->willReturn( true )
		;
		$this->ruleService->expects( $this->once() )
		                  ->method( 'getRuleEditorGroups' )
		                  ->willReturn( [ 'staff' ] )
		;
		$this->ruleService->expects( $this->once() )
		                  ->method( 'getRuleEditorUsers' )
		                  ->willReturn( [ 'alice' ] )
		;

		$response = $this->controller->getAdminOptions();

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['allowAllUsers'] );
		$this->assertSame( [ 'staff' ], $data['groups'] );
		$this->assertSame( [ 'alice' ], $data['users'] );
		$this->assertSame(
			[
				[
					'id'          => 'alice',
					'displayName' => 'Alice',
				],
			],
			$data['availableUsers'],
		);
	}


// ── saveAdminOptions ─────────────────────────────────────────────────

	public function testSaveAdminOptionsPersistsFields(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'allowAllUsers' => true,
				                 'groups'        => [ 'staff' ],
				                 'users'         => [ 'alice' ],
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'setAllUsersEnabled' )
		                  ->with( true )
		;
		$this->ruleService->expects( $this->once() )
		                  ->method( 'setRuleEditorGroups' )
		                  ->with( [ 'staff' ] )
		;
		$this->ruleService->expects( $this->once() )
		                  ->method( 'setRuleEditorUsers' )
		                  ->with( [ 'alice' ] )
		;

		$response = $this->controller->saveAdminOptions();

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
		$data = $response->getData();
		$this->assertTrue( $data['success'] );
	}


// ── admin-only enforcement ───────────────────────────────────────────

	/**
	 * Regression test for FCIAS Review §6, Finding 2: these endpoints
	 * back the *admin* settings page only. Nextcloud's SecurityMiddleware
	 * requires an administrator precisely when #[NoAdminRequired] is
	 * absent from the method — so calling the controller method directly
	 * (as every other test in this class does) can never exercise that
	 * gate. Assert the attribute's absence directly instead. Personal
	 * (non-admin) rule editing goes through PersonalSettingsController's
	 * separate /personal/rules* routes.
	 */
	public function testAdminOnlyMethodsDoNotCarryNoAdminRequired(): void
	{

		$adminOnlyMethods = [
			'listRules',
			'saveRule',
			'deleteRule',
			'toggleRule',
			'getCrontabSnippet',
			'getAdminOptions',
			'saveAdminOptions',
		];

		foreach ( $adminOnlyMethods as $method )
		{
			$reflection = new ReflectionMethod( SettingsController::class, $method );
			$attributes = $reflection->getAttributes( NoAdminRequired::class );

			$this->assertCount(
				0,
				$attributes,
				"$method must not carry #[NoAdminRequired] — it must remain admin-only.",
			);
		}
	}

}
