<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCA\FileChecksumSearch\Controller\SettingsController;
use OCA\FileChecksumSearch\Service\DatabaseService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class SettingsControllerTest
	extends
	FciasUnitTestCase
{

// ── private properties ───────────────────────────────────────────────

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private StatusService                $statusService;

	private MockObject|IAppManager       $appManager;

	private MockObject|DatabaseService   $databaseService;

	private MockObject|IUserManager      $userManager;

	private MockObject|PermissionService $permissionService;

	private MockObject|IAppConfig        $appConfig;

	private MockObject|JobStatsService   $jobStats;

	private MockObject|MetadataService   $metadataService;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private MockObject|IRequest $request;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
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

		$this->userManager       = $this->createMock( IUserManager::class );
		$this->permissionService = $this->createMock( PermissionService::class );
		$this->appConfig         = $this->createMock( IAppConfig::class );
		$this->jobStats          = $this->createMock( JobStatsService::class );
		$this->request           = $this->createMock( IRequest::class );
		$this->logger            = $this->createMock( LoggerInterface::class );

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
			                         $this->metadataService,
			                         $this->permissionService,
			                         $this->appConfig,
			                         $this->jobStats,
				new AlgorithmCatalogue( $this->createMock( IAppConfig::class ) ),
			] )
		                         ->getMock()
		;
	}


// ── getStatus ────────────────────────────────────────────────────────


	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
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


// ── status: untrusted hashes + job heartbeats (D17) ─────────────────

	public function testGetStatusReportsUntrustedHashesAndJobHeartbeats(): void
	{

		// Broken down by reason, not one total: erosion has already thrown
		// the hashes away and heals itself, a reset has not and is waiting —
		// different things for an operator to do about them.
		$this->metadataService->method( 'getStaleStats' )
		                      ->willReturn(
			                      [
				                      MetadataService::STATE_ERODED => 4,
				                      MetadataService::STATE_RESET  => 9,
			                      ],
		                      )
		;
		$this->jobStats->method( 'lastRuns' )
		               ->willReturn( [
			               'rule_sweep' => [
				               'lastRun' => 1700000000,
				               'counts'  => [
					               'matched' => 12,
					               'marked'  => 3,
				               ],
			               ],
		               ] )
		;

		$data = $this->controller->getStatus()
		                         ->getData()
		;

		$this->assertSame(
			[
				MetadataService::STATE_ERODED => 4,
				MetadataService::STATE_RESET  => 9,
			],
			$data['staleStats'],
		);
		$this->assertSame( 1700000000, $data['jobs']['rule_sweep']['lastRun'] );
	}


// ── idle banner ──────────────────────────────────────────────────────

	public function testGetStatusReportsTheIdleBannerAcknowledgement(): void
	{

		$this->appConfig->method( 'getValueBool' )
		                ->with(
			                'file_checksum_search',
			                RuleService::CONFIG_KEY_IDLE_BANNER_ACK,
		                )
		                ->willReturn( true )
		;

		$data = $this->controller->getStatus()
		                         ->getData()
		;

		$this->assertTrue( $data['idleBannerAcknowledged'] );
	}


	public function testAcknowledgeIdleBannerPersistsTheFlag(): void
	{

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueBool' )
		                ->with(
			                'file_checksum_search',
			                RuleService::CONFIG_KEY_IDLE_BANNER_ACK,
			                true,
		                )
		;

		$data = $this->controller->acknowledgeIdleBanner()
		                         ->getData()
		;

		$this->assertTrue( $data['success'] );
	}


// ── getAdminOptions ──────────────────────────────────────────────────


	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
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

		$this->permissionService->expects( $this->once() )
		                        ->method( 'isAllUsersEnabled' )
		                        ->with( PermissionService::PERMISSION_RULE_EDITING )
		                        ->willReturn( true )
		;
		$this->permissionService->expects( $this->once() )
		                        ->method( 'getGroups' )
		                        ->with( PermissionService::PERMISSION_RULE_EDITING )
		                        ->willReturn( [ 'staff' ] )
		;
		$this->permissionService->expects( $this->once() )
		                        ->method( 'getUsers' )
		                        ->with( PermissionService::PERMISSION_RULE_EDITING )
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

		$this->permissionService->expects( $this->once() )
		                        ->method( 'setAllUsersEnabled' )
		                        ->with( PermissionService::PERMISSION_RULE_EDITING, true )
		;
		$this->permissionService->expects( $this->once() )
		                        ->method( 'setGroups' )
		                        ->with( PermissionService::PERMISSION_RULE_EDITING, [ 'staff' ] )
		;
		$this->permissionService->expects( $this->once() )
		                        ->method( 'setUsers' )
		                        ->with( PermissionService::PERMISSION_RULE_EDITING, [ 'alice' ] )
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
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAdminOnlyMethodsDoNotCarryNoAdminRequired(): void
	{

		$adminOnlyMethods = [
			'getAdminOptions',
			'saveAdminOptions',
			'acknowledgeIdleBanner',
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


	/**
	 * Two sections of the admin page save to this one endpoint, each sending
	 * only its own fields. A field that is not sent must be left exactly as
	 * it was — not reset to a default the sending section never showed.
	 */
	public function testAPartialBodyLeavesTheFieldsItDoesNotSendAlone(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn( json_encode( [ 'allowedAlgorithms' => [ 'sha256', 'sha1' ] ] ) )
		;

		$this->permissionService->expects( $this->never() )
		                        ->method( 'setAllUsersEnabled' )
		;
		$this->permissionService->expects( $this->never() )
		                        ->method( 'setGroups' )
		;
		$this->permissionService->expects( $this->never() )
		                        ->method( 'setUsers' )
		;

		$response = $this->controller->saveAdminOptions();

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
		$data = $response->getData();
		$this->assertTrue( $data['success'] );
		$this->assertIsArray( $data['allowedAlgorithms'] );
		$this->assertNotEmpty( $data['allowedAlgorithms'] );
	}


	/**
	 * A list that keeps nothing is refused, and the previous list stays.
	 */
	public function testAnAllowlistNothingOnThisServerCanHonourIsRefused(): void
	{

		$this->controller->method( 'readRequestBody' )
		                 ->willReturn( json_encode( [ 'allowedAlgorithms' => [ 'nonsense', 'sha512/256' ] ] ) )
		;

		$response = $this->controller->saveAdminOptions();

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
		$this->assertFalse( $response->getData()['success'] );
	}

}
