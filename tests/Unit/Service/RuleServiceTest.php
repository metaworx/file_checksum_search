<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Unit tests for RuleService.
 *
 * Covers all 11 methods: evaluateRules, processRule, searchFiles,
 * searchFilesByGlob, globToLike, loadRules, findFirstMatchingRule,
 * ruleAdd, ruleDelete, ruleToggle, ruleUpdate.
 */
class RuleServiceTest
	extends
	FciasUnitTestCase
{

	private MockObject|IAppConfig        $appConfig;

	private MockObject|IRootFolder       $rootFolder;

	private MockObject|IUserManager      $userManager;

	private MockObject|MetadataService   $metadataService;

	private MockObject|PermissionService $permissionService;

	private MockObject|IGroupManager     $groupManager;

	private MockObject|LoggerInterface   $logger;

	private RuleService                  $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->appConfig         = $this->createMock( IAppConfig::class );
		$this->rootFolder        = $this->createMock( IRootFolder::class );
		$this->userManager       = $this->createMock( IUserManager::class );
		$this->metadataService   = $this->createMock( MetadataService::class );
		$this->permissionService = $this->createMock( PermissionService::class );
		$this->groupManager      = $this->createMock( IGroupManager::class );
		$this->logger            = $this->createMock( LoggerInterface::class );

		$this->service = new RuleService(
			$this->appConfig,
			$this->rootFolder,
			$this->userManager,
			$this->metadataService,
			$this->logger,
			$this->permissionService,
			$this->groupManager,
		);
	}


	private function mockResolveAllUsers( array $uids ): void
	{

		$users = array_map(
			fn(
				string $uid,
			) => $this->createConfiguredMock( IUser::class, [ 'getUID' => $uid ] ),
			$uids,
		);

		$this->userManager->method( 'callForAllUsers' )
		                  ->willReturnCallback(
			                  function (
				                  callable $callback,
			                  ) use
			                  (
				                  $users,
			                  ): void
			                  {

				                  foreach ( $users as $user )
				                  {
					                  $callback( $user );
				                  }
			                  },
		                  )
		;
	}


	private function createRuleServicePartial(
		array $methods,
	): RuleService&MockObject {

		return $this->getMockBuilder( RuleService::class )
		            ->setConstructorArgs( [
			            $this->appConfig,
			            $this->rootFolder,
			            $this->userManager,
			            $this->metadataService,
			            $this->logger,
			            $this->permissionService,
			            $this->groupManager,
		            ] )
		            ->onlyMethods( $methods )
		            ->getMock()
		;
	}


	private function createFileMock(
		int    $id,
		int    $mtime = 1000,
		string $path = '/files/test.txt',
	): File&MockObject {

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( $id )
		;
		$file->method( 'getMTime' )
		     ->willReturn( $mtime )
		;

		$file->method( 'getPath' )
		     ->willReturn( $path )
		;

		return $file;
	}


	private function createFolderMock(
		array $searchResults = [],
	): Folder&MockObject {

		$folder = $this->createMock( Folder::class );
		$folder->method( 'search' )
		       ->willReturn( $searchResults )
		;

		return $folder;
	}


	private function setupRulesConfig( array $rules ): void
	{

		$this->appConfig->expects( $this->atLeastOnce() )
		                ->method( 'getValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                '[]',
		                )
		                ->willReturn( json_encode( $rules, JSON_THROW_ON_ERROR ) )
		;
	}


	private function defaultRule( array $overrides = [] ): array
	{

		return array_merge(
			[
				'id'        => 'test-rule-1',
				'enabled'   => true,
				'mode'      => 'auto',
				'path'      => '**',
				'userScope' => 'all',
			],
			$overrides,
		);
	}


	// evaluateRules

	public function testEvaluateRulesProcessesEnabledRules(): void
	{

		$rule = $this->defaultRule();

		$this->setupRulesConfig( [ $rule ] );

		$this->mockResolveAllUsers( [ 'user1' ] );

		$file = $this->createFileMock( 42 );

		$folder = $this->createFolderMock( [ $file ] );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'user1' )
		                 ->willReturn( $folder )
		;

		$this->metadataService->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( null ) // stale
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, MetadataService::PENDING_PREFIX . 'auto' )
		;

		$result = $this->service->evaluateRules();

		$this->assertSame( 1, $result['marked'] );
		$this->assertSame( 1, $result['matched'] );
	}


	public function testEvaluateRulesSkipsDisabledRules(): void
	{

		$rule = $this->defaultRule( [ 'enabled' => false ] );

		$this->setupRulesConfig( [ $rule ] );

		// resolveUsers should NOT be called because the rule is disabled
		$this->userManager->expects( $this->never() )
		                  ->method( 'callForAllUsers' )
		;

		$result = $this->service->evaluateRules();

		$this->assertSame( 0, $result['marked'] );
		$this->assertSame( 0, $result['matched'] );
	}


	public function testEvaluateRulesBuildsExclusionList(): void
	{

		$rule1 = $this->defaultRule( [ 'id' => 'r1' ] );
		$rule2 = $this->defaultRule( [ 'id' => 'r2' ] );

		$this->setupRulesConfig(
			[
				$rule1,
				$rule2,
			],
		);

		// Rule 1: resolve to user1
		// Rule 2: resolve to user1 (same user, file excluded by rule1)
		$this->mockResolveAllUsers( [ 'user1' ] );

		$file1 = $this->createFileMock( 42 );

		$folder = $this->createFolderMock( [ $file1 ] );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'user1' )
		                 ->willReturn( $folder )
		;

		// Rule 1: file 42 is stale → marked
		// Rule 2: file 42 is in exclusion list → skipped
		$this->metadataService->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( null )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, MetadataService::PENDING_PREFIX . 'auto' )
		;

		$result = $this->service->evaluateRules();

		// Only rule1 matched and marked; rule2's file was excluded
		$this->assertSame( 1, $result['marked'] );
		$this->assertSame( 1, $result['matched'] );
	}


	public function testEvaluateRulesHandlesUserResolutionFailure(): void
	{

		$rule = $this->defaultRule();

		$this->setupRulesConfig( [ $rule ] );

		$this->mockResolveAllUsers( [ 'baduser' ] );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'baduser' )
		                 ->willThrowException(
			                 new class( 'User folder not found' )
				                 extends
				                 \Exception
				                 implements
				                 Throwable {

			                 },
		                 )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		;

		$result = $this->service->evaluateRules();

		$this->assertSame( 0, $result['marked'] );
		$this->assertSame( 0, $result['matched'] );
	}


	// processRule

	public function testProcessRuleMarksStaleFiles(): void
	{

		$file = $this->createFileMock( 42 );

		$partial = $this->createRuleServicePartial( [ 'searchFiles' ] );

		$partial->method( 'searchFiles' )
		        ->willReturn( [ $file ] )
		;

		$this->mockResolveAllUsers( [ 'user1' ] );

		$folder = $this->createFolderMock();

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'user1' )
		                 ->willReturn( $folder )
		;

		$this->metadataService->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( null ) // stale → updatedAt is null
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, MetadataService::PENDING_PREFIX . 'auto' )
		;

		$result = $partial->processRule( $this->defaultRule(), [] );

		$this->assertSame( 1, $result['marked'] );
		$this->assertSame( 1, $result['matched'] );
		$this->assertContains( 42, $result['fileIds'] );
	}


	public function testProcessRuleSkipsFreshFiles(): void
	{

		$file = $this->createFileMock( 42, 2000 );
		// updatedAt >= mtime → fresh, skip
		$file->method( 'getMTime' )
		     ->willReturn( 1000 )
		;

		$partial = $this->createRuleServicePartial( [ 'searchFiles' ] );

		$partial->method( 'searchFiles' )
		        ->willReturn( [ $file ] )
		;

		$this->mockResolveAllUsers( [ 'user1' ] );

		$folder = $this->createFolderMock();

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'user1' )
		                 ->willReturn( $folder )
		;

		// updatedAt (2000) >= mtime (1000) → fresh
		$this->metadataService->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( 2000 )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$result = $partial->processRule( $this->defaultRule(), [] );

		$this->assertSame( 0, $result['marked'] );
		$this->assertSame( 1, $result['matched'] );
	}


	public function testProcessRuleRespectsBatchLimit(): void
	{

		$files = [];

		for ( $i = 1; $i <= 150; $i ++ )
		{
			$files[] = $this->createFileMock( $i );
		}

		$partial = $this->createRuleServicePartial( [ 'searchFiles' ] );

		$partial->method( 'searchFiles' )
		        ->willReturn( $files )
		;

		$this->mockResolveAllUsers( [ 'user1' ] );

		$folder = $this->createFolderMock();

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'user1' )
		                 ->willReturn( $folder )
		;

		$this->metadataService->method( 'getUpdatedAt' )
		                      ->willReturn( null )
		;

		// batchSize is 100 (hardcoded in processRule), so only 100 marked
		$this->metadataService->expects( $this->exactly( 100 ) )
		                      ->method( 'markPending' )
		;

		$result = $partial->processRule( $this->defaultRule(), [] );

		$this->assertSame( 100, $result['marked'] );
		$this->assertSame( 100, $result['matched'] );
		$this->assertCount( 100, $result['fileIds'] );
	}


	// searchFilesByGlob

	public function testSearchFilesByGlobReturnsMatchingFiles(): void
	{

		$file1 = $this->createFileMock( 1, 1000, '/files/photos/img1.jpg' );
		$file2 = $this->createFileMock( 2, 1000, '/files/docs/report.pdf' );

		$folder = $this->createFolderMock(
			[
				$file1,
				$file2,
			],
		);

		$results = $this->service->searchFilesByGlob( $folder, '**/*.jpg', 10 );

		// Both files returned by search; fnmatch('**/*.jpg', '/files/docs/report.pdf') is false,
		// so only file1 passes the PathUtil::matchesGlob refinement
		$this->assertCount( 1, $results );
		$this->assertSame( 1, $results[0]->getId() );
	}


	public function testSearchFilesByGlobHandlesOffsetPagination(): void
	{

		// First page: 3 results, second page: 2 results, limit 4
		$folder = $this->createMock( Folder::class );

		$page1 = [
			$this->createFileMock( 1, 1000, '/files/a.txt' ),
			$this->createFileMock( 2, 1000, '/files/b.txt' ),
			$this->createFileMock( 3, 1000, '/files/c.txt' ),
		];

		$page2 = [
			$this->createFileMock( 4, 1000, '/files/d.txt' ),
			$this->createFileMock( 5, 1000, '/files/e.txt' ),
		];

		$folder->expects( $this->exactly( 2 ) )
		       ->method( 'search' )
		       ->willReturnOnConsecutiveCalls( $page1, $page2 )
		;

		$results = $this->service->searchFilesByGlob( $folder, '**/*.txt', 4, 3 );

		$this->assertCount( 4, $results );
		$this->assertSame( 1, $results[0]->getId() );
		$this->assertSame( 2, $results[1]->getId() );
		$this->assertSame( 3, $results[2]->getId() );
		$this->assertSame( 4, $results[3]->getId() );
	}


	public function testSearchFilesByGlobStopsAtMaxScan(): void
	{

		// maxScan = max(limit * 5, pageSize) = max(2 * 5, 3) = 10
		// offset starts at 0, increments by pageSize (3): 0, 3, 6, 9 → 4 iterations total
		// Each call always returns 3 files, but none match (return empty files to minimize fnmatch overhead)
		$folder = $this->createMock( Folder::class );

		$results = $this->service->searchFilesByGlob( $folder, 'nonexistent/**', 2, 3 );

		// With pageSize=3, limit=2, maxScan=10
		// offset sequence: 0, 3, 6, 9 → 4 calls before offset(9) < maxScan(10), then offset(12) >= maxScan → stop
		// So exactly 4 calls
		$this->assertCount( 0, $results );
	}


	public function testSearchFilesByGlobUnlimitedWhenLimitIsZero(): void
	{

		$file1 = $this->createFileMock( 1, 1000, '/files/a.txt' );
		$file2 = $this->createFileMock( 2, 1000, '/files/b.txt' );

		$folder = $this->createMock( Folder::class );
		$folder->expects( $this->exactly( 2 ) )
		       ->method( 'search' )
		       ->willReturnOnConsecutiveCalls(
			       [
				       $file1,
				       $file2,
			       ],
			       [],
		       )
		;

		$results = $this->service->searchFilesByGlob( $folder, '**/*.txt', 0 );

		$this->assertCount( 2, $results );
	}


	// globToLike

	public function testGlobToLikeConvertsGlobToSqlLike(): void
	{

		$this->assertSame( '%\\_test%.jpg', RuleService::globToLike( '*_test*.jpg' ) );
		$this->assertSame( '\\%literal\\_', RuleService::globToLike( '%literal_' ) );
		$this->assertSame( 'prefix%', RuleService::globToLike( 'prefix*' ) );
		$this->assertSame( 'file_', RuleService::globToLike( 'file?' ) );
	}


	// ruleAdd

	public function testRuleAddGeneratesIdAndPersists(): void
	{

		$existing = [
			[
				'id'      => 'existing-id',
				'enabled' => true,
				'path'    => '**',
			],
		];

		$this->setupRulesConfig( $existing );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                $this->callback(
				                function (
					                string $json,
				                ): bool {

					                $rules = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

					                return is_array( $rules )
						                && count( $rules ) === 2
						                && isset( $rules[1]['id'] )
						                && strlen( $rules[1]['id'] ) === 32
						                && $rules[1]['path'] === 'Docs/*.pdf';
				                },
			                ),
		                )
		;

		$this->service->ruleAdd( [ 'path' => 'Docs/*.pdf' ] );
	}


	// ruleDelete

	public function testRuleDeleteRemovesCorrectRule(): void
	{

		$rules = [
			[
				'id'      => 'r1',
				'enabled' => true,
			],
			[
				'id'      => 'r2',
				'enabled' => false,
			],
			[
				'id'      => 'r3',
				'enabled' => true,
			],
		];

		$this->setupRulesConfig( $rules );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                $this->callback(
				                function (
					                string $json,
				                ): bool {

					                $rules = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

					                return count( $rules ) === 2
						                && $rules[0]['id'] === 'r1'
						                && $rules[1]['id'] === 'r3';
				                },
			                ),
		                )
		;

		$this->service->ruleDelete( 'r2' );
	}


	public function testRuleDeleteHandlesNonexistentId(): void
	{

		$rules = [
			[
				'id'      => 'r1',
				'enabled' => true,
			],
		];

		$this->setupRulesConfig( $rules );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                $this->callback(
				                function (
					                string $json,
				                ): bool {

					                $rules = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

					                return count( $rules ) === 1
						                && $rules[0]['id'] === 'r1';
				                },
			                ),
		                )
		;

		$this->service->ruleDelete( 'nonexistent' );
	}


	// ruleToggle

	public function testRuleToggleFlipsEnabled(): void
	{

		$rules = [
			[
				'id'      => 'r1',
				'enabled' => true,
			],
			[
				'id'      => 'r2',
				'enabled' => false,
			],
		];

		$this->setupRulesConfig( $rules );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                $this->callback(
				                function (
					                string $json,
				                ): bool {

					                $rules = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

					                return $rules[1]['enabled'] === true;
				                },
			                ),
		                )
		;

		$this->service->ruleToggle( 'r2', true );
	}


	// ruleUpdate

	public function testRuleUpdateReplacesFields(): void
	{

		$rules = [
			[
				'id'      => 'r1',
				'enabled' => true,
				'path'    => '**',
			],
		];

		$this->setupRulesConfig( $rules );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                $this->callback(
				                function (
					                string $json,
				                ): bool {

					                $rules = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

					                return $rules[0]['path'] === 'Docs/**'
						                && $rules[0]['mode'] === 'force'
						                && $rules[0]['id'] === 'r1';
				                },
			                ),
		                )
		;

		$this->service->ruleUpdate( 'r1', [
			'path' => 'Docs/**',
			'mode' => 'force',
		] );
	}


	// loadRules

	public function testLoadRulesHandlesInvalidJson(): void
	{

		$this->appConfig->expects( $this->once() )
		                ->method( 'getValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                '[]',
		                )
		                ->willReturn( '{invalid' )
		;

		$rules = $this->service->loadRules();

		$this->assertSame( [], $rules );
	}


	public function testLoadRulesHandlesCorruptedData(): void
	{

		$this->appConfig->expects( $this->once() )
		                ->method( 'getValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                '[]',
		                )
		                ->willReturn( '"just a string"' )
		;

		$rules = $this->service->loadRules();

		$this->assertSame( [], $rules );
	}


	// findFirstMatchingRule

	public function testFindFirstMatchingRuleReturnsFirstEnabledMatch(): void
	{

		$rules = [
			[
				'id'      => 'r1',
				'enabled' => false,
				'path'    => '**/*.jpg',
			],
			[
				'id'      => 'r2',
				'enabled' => true,
				'path'    => '**/*.pdf',
			],
			[
				'id'      => 'r3',
				'enabled' => true,
				'path'    => '**/*.pdf',
			],
		];

		$this->setupRulesConfig( $rules );

		$result = $this->service->findFirstMatchingRule( '/files/docs/report.pdf' );

		$this->assertNotNull( $result );
		$this->assertSame( 'r2', $result['id'] );
	}


	public function testFindFirstMatchingRuleReturnsNullWhenNoMatch(): void
	{

		$rules = [
			[
				'id'      => 'r1',
				'enabled' => true,
				'path'    => '**/*.jpg',
			],
		];

		$this->setupRulesConfig( $rules );

		$result = $this->service->findFirstMatchingRule( '/files/docs/report.pdf' );

		$this->assertNull( $result );
	}


	public function testFindFirstMatchingRuleSkipsRuleScopedToAnotherUser(): void
	{

		// Regression test for FCIAS Review §6, Finding 4: a rule scoped
		// to a specific user must not match a different user's file.
		$rules = [
			[
				'id'        => 'r1',
				'enabled'   => true,
				'path'      => '**/*.pdf',
				'userScope' => 'bob',
			],
		];

		$this->setupRulesConfig( $rules );

		$result = $this->service->findFirstMatchingRule( '/files/docs/report.pdf', 'alice' );

		$this->assertNull( $result );
	}


	public function testFindFirstMatchingRuleMatchesRuleScopedToRequestingUser(): void
	{

		$rules = [
			[
				'id'        => 'r1',
				'enabled'   => true,
				'path'      => '**/*.pdf',
				'userScope' => 'alice',
			],
		];

		$this->setupRulesConfig( $rules );

		$result = $this->service->findFirstMatchingRule( '/files/docs/report.pdf', 'alice' );

		$this->assertNotNull( $result );
		$this->assertSame( 'r1', $result['id'] );
	}


	public function testFindFirstMatchingRuleMatchesAllScopedRuleForAnyOwner(): void
	{

		$rules = [
			[
				'id'        => 'r1',
				'enabled'   => true,
				'path'      => '**/*.pdf',
				'userScope' => 'all',
			],
		];

		$this->setupRulesConfig( $rules );

		$result = $this->service->findFirstMatchingRule( '/files/docs/report.pdf', 'alice' );

		$this->assertNotNull( $result );
		$this->assertSame( 'r1', $result['id'] );
	}


	// resolveUsers

	public function testResolveUsersReturnsAllUsers(): void
	{

		$mockUsers = [
			$this->createMock( IUser::class ),
			$this->createMock( IUser::class ),
			$this->createMock( IUser::class ),
		];

		$mockUsers[0]->method( 'getUID' )
		             ->willReturn( 'alice' )
		;
		$mockUsers[1]->method( 'getUID' )
		             ->willReturn( 'bob' )
		;
		$mockUsers[2]->method( 'getUID' )
		             ->willReturn( 'carol' )
		;

		$this->userManager->expects( $this->once() )
		                  ->method( 'callForAllUsers' )
		                  ->willReturnCallback(
			                  function (
				                  callable $callback,
			                  ) use
			                  (
				                  $mockUsers,
			                  ): void
			                  {

				                  foreach ( $mockUsers as $user )
				                  {
					                  $callback( $user );
				                  }
			                  },
		                  )
		;

		$result = $this->service->resolveUsers( 'all' );

		$this->assertSame( [
			'alice',
			'bob',
			'carol',
		], $result );
	}


	public function testResolveUsersReturnsSpecificUser(): void
	{

		$user = $this->createMock( IUser::class );

		$user->method( 'getUID' )
		     ->willReturn( 'alice' )
		;

		$this->userManager->expects( $this->once() )
		                  ->method( 'get' )
		                  ->with( 'alice' )
		                  ->willReturn( $user )
		;

		$result = $this->service->resolveUsers( 'alice' );

		$this->assertSame( [ 'alice' ], $result );
	}


	public function testResolveUsersReturnsEmptyForUnknownUser(): void
	{

		$this->userManager->expects( $this->once() )
		                  ->method( 'get' )
		                  ->with( 'nonexistent' )
		                  ->willReturn( null )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		;

		$result = $this->service->resolveUsers( 'nonexistent' );

		$this->assertSame( [], $result );
	}


	// findRuleById

	public function testFindRuleByIdReturnsMatchingRule(): void
	{

		$this->setupRulesConfig( [
			[
				'id'   => 'r1',
				'path' => '**',
			],
			[
				'id'   => 'r2',
				'path' => '/docs',
			],
		] );

		$this->assertSame(
			[
				'id'   => 'r2',
				'path' => '/docs',
			],
			$this->service->findRuleById( 'r2' ),
		);
	}


	public function testFindRuleByIdReturnsNullWhenNotFound(): void
	{

		$this->setupRulesConfig( [
			[
				'id'   => 'r1',
				'path' => '**',
			],
		] );

		$this->assertNull( $this->service->findRuleById( 'nope' ) );
	}


	// bandOf / scope helpers


	/**
	 * @dataProvider bandProvider
	 */
	public function testBandOfDerivesTheBandFromScopeAndFlags(
		array $rule,
		int   $expected,
	): void {

		$this->assertSame( $expected, RuleService::bandOf( $rule ) );
	}


	/**
	 * @return array<string, array{array, int}>
	 */
	public static function bandProvider(): array
	{

		return [
			'user enforced'   => [
				[
					'userScope'      => 'alice',
					'admin_enforced' => true,
				],
				RuleService::BAND_USER_ENFORCED,
			],
			'group enforced'  => [
				[
					'userScope'      => 'group:staff',
					'admin_enforced' => true,
				],
				RuleService::BAND_GROUP_ENFORCED,
			],
			'global enforced' => [
				[
					'userScope'      => 'all',
					'admin_enforced' => true,
				],
				RuleService::BAND_GLOBAL_ENFORCED,
			],
			'user'            => [
				[ 'userScope' => 'alice' ],
				RuleService::BAND_USER,
			],
			'group'           => [
				[ 'userScope' => 'group:staff' ],
				RuleService::BAND_GROUP,
			],
			'global'          => [
				[ 'userScope' => 'all' ],
				RuleService::BAND_GLOBAL,
			],
			'scope omitted'   => [
				[],
				RuleService::BAND_GLOBAL,
			],
			// pinned wins over everything else, including the enforced flag.
			'pinned'          => [
				[
					'userScope' => 'all',
					'pinned'    => true,
				],
				RuleService::BAND_DEFAULT,
			],
			'pinned enforced' => [
				[
					'userScope'      => 'all',
					'admin_enforced' => true,
					'pinned'         => true,
				],
				RuleService::BAND_DEFAULT,
			],
		];
	}


	public function testScopeHelpersParseGroupScopes(): void
	{

		$this->assertSame( 'global', RuleService::scopeKind( 'all' ) );
		$this->assertSame( 'group', RuleService::scopeKind( 'group:staff' ) );
		$this->assertSame( 'user', RuleService::scopeKind( 'alice' ) );

		$this->assertSame( 'staff', RuleService::scopeGroupId( 'group:staff' ) );
		$this->assertNull( RuleService::scopeGroupId( 'alice' ) );
		$this->assertNull( RuleService::scopeGroupId( 'all' ) );
	}


	public function testSortIntoBandsIsStableWithinABand(): void
	{

		$rules = [
			[
				'id'        => 'g1',
				'userScope' => 'all',
			],
			[
				'id'        => 'u1',
				'userScope' => 'alice',
			],
			[
				'id'        => 'g2',
				'userScope' => 'all',
			],
			[
				'id'             => 'e1',
				'userScope'      => 'alice',
				'admin_enforced' => true,
			],
			[
				'id'        => 'd1',
				'userScope' => 'all',
				'pinned'    => true,
			],
			[
				'id'        => 'u2',
				'userScope' => 'alice',
			],
		];

		// Bands ascend; within each band the original relative order holds,
		// which is what makes within-band position the real priority.
		$this->assertSame(
			[
				'e1',
				'u1',
				'u2',
				'g1',
				'g2',
				'd1',
			],
			array_column( RuleService::sortIntoBands( $rules ), 'id' ),
		);
	}


	// matching order

	public function testFindFirstMatchingRuleFollowsBandOrderNotArrayOrder(): void
	{

		// Regression test for the priority inversion: the `**` catch-all used
		// to sit at slot 0 and shadow every rule below it, so no additional
		// rule could ever match. It is now the pinned last band.
		$this->setupRulesConfig( [
			[
				'id'        => 'default',
				'enabled'   => true,
				'userScope' => 'all',
				'path'      => '**',
				'pinned'    => true,
			],
			[
				'id'        => 'docs',
				'enabled'   => true,
				'userScope' => 'all',
				'path'      => '**/*.txt',
			],
		] );

		$match = $this->service->findFirstMatchingRule( '/files/Documents/a.txt', 'alice' );

		$this->assertNotNull( $match );
		$this->assertSame( 'docs', $match['id'] );
	}


	public function testFindFirstMatchingRulePrefersTheSpecificEnforcedRule(): void
	{

		// Within the enforced half, specific beats general — so an admin can
		// enforce something instance-wide and still carve out one user.
		$this->setupRulesConfig( [
			[
				'id'             => 'global-enforced',
				'enabled'        => true,
				'userScope'      => 'all',
				'path'           => '**',
				'admin_enforced' => true,
			],
			[
				'id'             => 'alice-enforced',
				'enabled'        => true,
				'userScope'      => 'alice',
				'path'           => '**',
				'admin_enforced' => true,
			],
		] );

		$match = $this->service->findFirstMatchingRule( '/files/a.txt', 'alice' );

		$this->assertNotNull( $match );
		$this->assertSame( 'alice-enforced', $match['id'] );
	}


	public function testFindFirstMatchingRuleMatchesGroupScopeByMembership(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'staff',
				'enabled'   => true,
				'userScope' => 'group:staff',
				'path'      => '**',
			],
		] );

		$this->groupManager->method( 'isInGroup' )
		                   ->willReturnCallback(
			                   static fn(
				                   string $uid,
				                   string $gid,
			                   ): bool => $uid === 'alice' && $gid === 'staff',
		                   )
		;

		$this->assertSame(
			'staff',
			$this->service->findFirstMatchingRule( '/files/a.txt', 'alice' )['id'] ?? null,
		);
		$this->assertNull( $this->service->findFirstMatchingRule( '/files/a.txt', 'bob' ) );
	}


	// verdicts


	/**
	 * @dataProvider verdictProvider
	 */
	public function testVerdictOfDefaultsToIncludeForAnythingUnrecognised(
		array  $rule,
		string $expected,
	): void {

		$this->assertSame( $expected, RuleService::verdictOf( $rule ) );
	}


	/**
	 * @return array<string, array{array, string}>
	 */
	public static function verdictProvider(): array
	{

		return [
			'include'      => [
				[ 'type' => 'include' ],
				RuleService::TYPE_INCLUDE,
			],
			'ignore'       => [
				[ 'type' => 'ignore' ],
				RuleService::TYPE_IGNORE,
			],
			'exclude'      => [
				[ 'type' => 'exclude' ],
				RuleService::TYPE_EXCLUDE,
			],
			// Rules written before verdicts existed carry no type and must
			// keep behaving exactly as they did.
			'absent'       => [
				[],
				RuleService::TYPE_INCLUDE,
			],
			'unrecognised' => [
				[ 'type' => 'nonsense' ],
				RuleService::TYPE_INCLUDE,
			],
		];
	}


	public function testMaintainsHashesOnlyForIncludeRules(): void
	{

		$this->assertTrue( RuleService::maintainsHashes( [ 'type' => 'include' ] ) );
		$this->assertTrue( RuleService::maintainsHashes( [] ) );
		$this->assertFalse( RuleService::maintainsHashes( [ 'type' => 'ignore' ] ) );
		$this->assertFalse( RuleService::maintainsHashes( [ 'type' => 'exclude' ] ) );
		// No rule at all is not a licence to hash automatically either.
		$this->assertFalse( RuleService::maintainsHashes( null ) );
	}


	public function testAnEnforcedExcludeBeatsAUserIncludeBelowIt(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'user-include',
				'enabled'   => true,
				'userScope' => 'alice',
				'path'      => '**',
			],
			[
				'id'             => 'mandate',
				'enabled'        => true,
				'userScope'      => 'all',
				'path'           => '**/*.key',
				'admin_enforced' => true,
				'type'           => 'exclude',
			],
		] );

		// Band 3 precedes band 4, so the mandate is reached first and the
		// user's own include never gets the file.
		$match = $this->service->findFirstMatchingRule( '/files/secret.key', 'alice' );

		$this->assertSame( 'mandate', $match['id'] ?? null );
		$this->assertFalse( RuleService::maintainsHashes( $match ) );
	}


	public function testAUserExcludeSuppressesTheDefaultsBelowIt(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'default',
				'enabled'   => true,
				'userScope' => 'all',
				'path'      => '**',
				'pinned'    => true,
			],
			[
				'id'        => 'mine',
				'enabled'   => true,
				'userScope' => 'alice',
				'path'      => '**/*.iso',
				'type'      => 'exclude',
			],
		] );

		$match = $this->service->findFirstMatchingRule( '/files/big.iso', 'alice' );

		// Overriding a *default* is exactly what a non-enforced rule may do.
		$this->assertSame( 'mine', $match['id'] ?? null );
		$this->assertFalse( RuleService::maintainsHashes( $match ) );
	}


	public function testADefaultExcludeIsBeatenByAUserInclude(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'suggestion',
				'enabled'   => true,
				'userScope' => 'all',
				'path'      => '**/*.iso',
				'type'      => 'exclude',
			],
			[
				'id'        => 'mine',
				'enabled'   => true,
				'userScope' => 'alice',
				'path'      => '**/*.iso',
			],
		] );

		// Band 4 precedes band 6: an admin who did not enforce the exclusion
		// offered it as a default, and the user is entitled to override it.
		$match = $this->service->findFirstMatchingRule( '/files/big.iso', 'alice' );

		$this->assertSame( 'mine', $match['id'] ?? null );
		$this->assertTrue( RuleService::maintainsHashes( $match ) );
	}


	public function testProcessRuleClaimsFilesForANonIncludeRuleWithoutQueueingThem(): void
	{

		$file = $this->createFileMock( 42 );

		$this->mockResolveAllUsers( [ 'alice' ] );
		$this->rootFolder->method( 'getUserFolder' )
		                 ->willReturn( $this->createFolderMock( [ $file ] ) )
		;

		// Nothing is queued — but the file is still reported as matched, which
		// is what keeps a lower-priority rule from picking it up afterwards.
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$result = $this->service->processRule(
			[
				'userScope' => 'all',
				'path'      => '**',
				'type'      => 'exclude',
			],
			[],
		);

		$this->assertSame( 0, $result['marked'] );
		$this->assertSame( 1, $result['matched'] );
		$this->assertSame( [ 42 ], $result['fileIds'] );
	}


	// resolveUsers — group scope

	public function testResolveUsersExpandsGroupMembership(): void
	{

		$group = $this->createMock( IGroup::class );
		$group->method( 'getUsers' )
		      ->willReturn( [
			      $this->createConfiguredMock( IUser::class, [ 'getUID' => 'alice' ] ),
			      $this->createConfiguredMock( IUser::class, [ 'getUID' => 'bob' ] ),
		      ] )
		;

		$this->groupManager->method( 'get' )
		                   ->with( 'staff' )
		                   ->willReturn( $group )
		;

		$this->assertSame(
			[
				'alice',
				'bob',
			],
			$this->service->resolveUsers( 'group:staff' ),
		);
	}


	public function testResolveUsersReturnsEmptyForUnknownGroup(): void
	{

		$this->groupManager->method( 'get' )
		                   ->willReturn( null )
		;

		$this->assertSame( [], $this->service->resolveUsers( 'group:nope' ) );
	}


	// band placement on write

	public function testRuleAddLandsAtTheEndOfItsOwnBand(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'u1',
				'userScope' => 'alice',
			],
			[
				'id'        => 'g1',
				'userScope' => 'all',
			],
		] );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                $this->callback(
				                static function (
					                string $json,
				                ): bool {

					                $ids = array_column(
						                json_decode( $json, true, 512, JSON_THROW_ON_ERROR ),
						                'id',
					                );

					                // The new band-4 rule follows u1 but still
					                // precedes the band-6 rule.
					                return $ids[0] === 'u1'
						                && $ids[2] === 'g1'
						                && count( $ids ) === 3;
				                },
			                ),
		                )
		;

		$this->service->ruleAdd(
			[
				'userScope' => 'bob-is-a-user',
				'path'      => '/x',
			],
		);
	}


	public function testRuleUpdateMovesToTheEndOfItsNewBandWhenEnforcedChanges(): void
	{

		$this->setupRulesConfig( [
			[
				'id'             => 'e1',
				'userScope'      => 'alice',
				'admin_enforced' => true,
			],
			[
				'id'        => 'target',
				'userScope' => 'alice',
			],
		] );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                $this->callback(
				                static function (
					                string $json,
				                ): bool {

					                $rules = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

					                // Promoted into band 1, and placed after the
					                // rule already there rather than ahead of it.
					                return array_column( $rules, 'id' ) === [
							                'e1',
							                'target',
						                ];
				                },
			                ),
		                )
		;

		$this->service->ruleUpdate(
			'target',
			[
				'userScope'      => 'alice',
				'admin_enforced' => true,
			],
		);
	}


	public function testRuleUpdateKeepsThePinnedFlag(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'default',
				'userScope' => 'all',
				'path'      => '**',
				'pinned'    => true,
			],
		] );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                $this->callback(
				                static function (
					                string $json,
				                ): bool {

					                $rules = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

					                // An edit that does not mention `pinned`
					                // must not silently unpin the default.
					                return $rules[0]['pinned'] === true;
				                },
			                ),
		                )
		;

		$this->service->ruleUpdate(
			'default',
			[
				'userScope' => 'all',
				'path'      => '**',
				'mode'      => 'force',
			],
		);
	}


	public function testOnlyOnePinnedRuleSurvivesAWrite(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'first',
				'userScope' => 'all',
				'pinned'    => true,
			],
			[
				'id'        => 'second',
				'userScope' => 'all',
				'pinned'    => true,
			],
		] );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                $this->callback(
				                static function (
					                string $json,
				                ): bool {

					                $rules  = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
					                $pinned = array_filter(
						                $rules,
						                static fn(
							                array $r,
						                ): bool => ! empty( $r['pinned'] ),
					                );

					                return count( $pinned ) === 1;
				                },
			                ),
		                )
		;

		$this->service->ruleAdd(
			[
				'userScope' => 'all',
				'path'      => '/x',
			],
		);
	}


	// migrateToBands

	public function testMigrateToBandsPinsSlotZeroAndSorts(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'default',
				'userScope' => 'all',
				'path'      => '**',
			],
			[
				'id'        => 'u1',
				'userScope' => 'alice',
			],
			[
				'id'             => 'e1',
				'userScope'      => 'alice',
				'admin_enforced' => true,
			],
		] );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                $this->callback(
				                static function (
					                string $json,
				                ): bool {

					                $rules = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

					                return array_column( $rules, 'id' ) === [
							                'e1',
							                'u1',
							                'default',
						                ]
						                && $rules[2]['pinned'] === true;
				                },
			                ),
		                )
		;

		$result = $this->service->migrateToBands();

		$this->assertSame( 'default', $result['pinnedId'] );
		$this->assertSame( 3, $result['rules'] );
	}


	public function testMigrateToBandsPinsTheFirstGlobalRuleWhenSlotZeroIsNot(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'u1',
				'userScope' => 'alice',
			],
			[
				'id'        => 'default',
				'userScope' => 'all',
				'path'      => '**',
			],
		] );

		$result = $this->service->migrateToBands();

		$this->assertSame( 'default', $result['pinnedId'] );
	}


	public function testMigrateToBandsIsIdempotent(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'u1',
				'userScope' => 'alice',
			],
			[
				'id'        => 'default',
				'userScope' => 'all',
				'pinned'    => true,
			],
		] );

		$result = $this->service->migrateToBands();

		// Already pinned — the existing flag is kept, nothing is re-pinned.
		$this->assertSame( 'default', $result['pinnedId'] );
	}


	public function testMigrateToBandsHandlesAnEmptyRuleList(): void
	{

		$this->setupRulesConfig( [] );

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;

		$this->assertSame(
			[
				'pinnedId' => null,
				'rules'    => 0,
			],
			$this->service->migrateToBands(),
		);
	}


	// reorderBand

	public function testReorderBandPermutesOneBandAndLeavesOthersUntouched(): void
	{

		$this->setupRulesConfig( [
			[
				'id'             => 'e1',
				'userScope'      => 'alice',
				'admin_enforced' => true,
			],
			[
				'id'        => 'g1',
				'userScope' => 'all',
			],
			[
				'id'        => 'g2',
				'userScope' => 'all',
			],
			[
				'id'        => 'g3',
				'userScope' => 'all',
			],
			[
				'id'        => 'd1',
				'userScope' => 'all',
				'pinned'    => true,
			],
		] );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                $this->callback(
				                static function (
					                string $json,
				                ): bool {

					                $ids = array_column(
						                json_decode( $json, true, 512, JSON_THROW_ON_ERROR ),
						                'id',
					                );

					                return $ids === [
							                'e1',
							                'g3',
							                'g1',
							                'g2',
							                'd1',
						                ];
				                },
			                ),
		                )
		;

		$this->service->reorderBand(
			RuleService::BAND_GLOBAL,
			null,
			[
				'g3',
				'g1',
				'g2',
			],
		);
	}


	public function testReorderBandRejectsThePinnedBand(): void
	{

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;

		$this->expectException( InvalidArgumentException::class );

		$this->service->reorderBand( RuleService::BAND_DEFAULT, null, [ 'd1' ] );
	}


	/**
	 * @dataProvider badPermutationProvider
	 */
	public function testReorderBandRejectsAnythingThatIsNotAnExactPermutation(
		array $orderedIds,
	): void {

		$this->setupRulesConfig( [
			[
				'id'        => 'g1',
				'userScope' => 'all',
			],
			[
				'id'        => 'g2',
				'userScope' => 'all',
			],
			[
				'id'        => 'u1',
				'userScope' => 'alice',
			],
		] );

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;

		$this->expectException( InvalidArgumentException::class );

		$this->service->reorderBand( RuleService::BAND_GLOBAL, null, $orderedIds );
	}


	/**
	 * @return array<string, array{array}>
	 */
	public static function badPermutationProvider(): array
	{

		return [
			'incomplete'         => [ [ 'g1' ] ],
			'unknown id'         => [
				[
					'g1',
					'g2',
					'nope',
				],
			],
			'duplicate'          => [
				[
					'g1',
					'g1',
				],
			],
			// u1 is band 4 — naming it in a band-6 reorder must be rejected
			// rather than silently pulling it across a band boundary.
			'id from other band' => [
				[
					'g1',
					'g2',
					'u1',
				],
			],
		];
	}


	public function testReorderBandRestrictsANonAdminToTheirOwnRules(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'bob1',
				'userScope' => 'bob',
			],
			[
				'id'        => 'alice1',
				'userScope' => 'alice',
			],
			[
				'id'        => 'alice2',
				'userScope' => 'alice',
			],
		] );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                Application::APP_ID,
			                'rule_definitions',
			                $this->callback(
				                static function (
					                string $json,
				                ): bool {

					                $ids = array_column(
						                json_decode( $json, true, 512, JSON_THROW_ON_ERROR ),
						                'id',
					                );

					                // bob's rule never moves, and alice's two swap
					                // within the slots they already occupied.
					                return $ids === [
							                'bob1',
							                'alice2',
							                'alice1',
						                ];
				                },
			                ),
		                )
		;

		$this->service->reorderBand(
			RuleService::BAND_USER,
			null,
			[
				'alice2',
				'alice1',
			],
			'alice',
		);
	}


	public function testReorderBandRejectsAnotherUsersRuleForANonAdmin(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'bob1',
				'userScope' => 'bob',
			],
			[
				'id'        => 'alice1',
				'userScope' => 'alice',
			],
		] );

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;

		$this->expectException( InvalidArgumentException::class );

		// Naming another user's rule is not a permutation of alice's segment.
		$this->service->reorderBand(
			RuleService::BAND_USER,
			null,
			[
				'alice1',
				'bob1',
			],
			'alice',
		);
	}


	public function testReorderBandRejectsANonAdminTouchingAnyOtherBand(): void
	{

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;

		$this->expectException( InvalidArgumentException::class );

		$this->service->reorderBand(
			RuleService::BAND_GLOBAL,
			null,
			[ 'g1' ],
			'alice',
		);
	}


	public function testReorderBandRequiresAnOwnerForTheUserBand(): void
	{

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;

		$this->expectException( InvalidArgumentException::class );

		$this->service->reorderBand( RuleService::BAND_USER, null, [] );
	}


	// listRulesFor

	public function testListRulesForAdminReturnsEverythingBandedAndNumbered(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'default',
				'userScope' => 'all',
				'path'      => '**',
				'pinned'    => true,
			],
			[
				'id'        => 'alice1',
				'userScope' => 'alice',
			],
			[
				'id'        => 'bob1',
				'userScope' => 'bob',
			],
			[
				'id'             => 'e1',
				'userScope'      => 'alice',
				'admin_enforced' => true,
			],
		] );

		$rules = $this->service->listRulesFor( null );

		$this->assertSame(
			[
				'e1',
				'alice1',
				'bob1',
				'default',
			],
			array_column( $rules, 'id' ),
		);
		$this->assertSame(
			[
				1,
				4,
				4,
				7,
			],
			array_column( $rules, 'band' ),
		);
		$this->assertSame(
			[
				1,
				1,
				2,
				1,
			],
			array_column( $rules, 'position' ),
		);
		// An admin may edit every rule.
		$this->assertSame(
			[
				true,
				true,
				true,
				true,
			],
			array_column( $rules, 'canEdit' ),
		);
	}


	public function testListRulesForUserHidesRulesTargetingFoldersTheyCannotSee(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'mine',
				'userScope' => 'all',
				'path'      => '**',
			],
			[
				'id'        => 'finance',
				'userScope' => 'all',
				'path'      => '/Finance/**',
			],
		] );

		$this->permissionService->method( 'canUserEditRules' )
		                        ->willReturn( false )
		;

		$folder = $this->createFolderMock();
		$folder->method( 'nodeExists' )
		       ->willReturnCallback(
			       static fn(
				       string $path,
			       ): bool => $path !== '/Finance',
		       )
		;
		$this->rootFolder->method( 'getUserFolder' )
		                 ->willReturn( $folder )
		;

		// The scope covers alice either way, but a rule confined to a folder
		// that does not exist in her tree cannot touch anything she can see,
		// so it is noise on a page about her own files.
		$this->assertSame(
			[ 'mine' ],
			array_column( $this->service->listRulesFor( 'alice' ), 'id' ),
		);
	}


	public function testListRulesForAnAdminOnTheirOwnPersonalPageCannotEditGlobalRules(): void
	{

		// Surface, not permission: an administrator asking as themselves —
		// which is what the personal settings page does — gets the same
		// read-only view of instance-wide rules as anyone else. Editing those
		// is available from the admin page, where the scope of the change is
		// evident. The REST layer still allows it; the UI simply never offers
		// it from the wrong place.
		$this->setupRulesConfig( [
			[
				'id'        => 'global',
				'userScope' => 'all',
				'path'      => '**',
			],
			[
				'id'        => 'own',
				'userScope' => 'theadmin',
				'path'      => '/',
			],
		] );

		$this->permissionService->method( 'canUserEditRules' )
		                        ->willReturn( true )
		;

		$folder = $this->createFolderMock();
		$folder->method( 'isCreatable' )
		       ->willReturn( true )
		;
		$folder->method( 'nodeExists' )
		       ->willReturn( true )
		;
		$this->rootFolder->method( 'getUserFolder' )
		                 ->willReturn( $folder )
		;

		$rules = $this->service->listRulesFor( 'theadmin' );
		$byId  = array_column( $rules, 'canEdit', 'id' );

		$this->assertFalse( $byId['global'] );
		$this->assertTrue( $byId['own'] );

		// Asking as the admin surface instead, the same rule is editable.
		$this->assertSame(
			[
				true,
				true,
			],
			array_column( $this->service->listRulesFor( null ), 'canEdit' ),
		);
	}


	public function testListRulesForUserHidesOtherUsersRulesAndNumbersWhatRemains(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'default',
				'userScope' => 'all',
				'path'      => '**',
				'pinned'    => true,
			],
			[
				'id'        => 'bob1',
				'userScope' => 'bob',
			],
			[
				'id'        => 'alice1',
				'userScope' => 'alice',
				'path'      => '/',
			],
			[
				'id'        => 'staff1',
				'userScope' => 'group:staff',
			],
		] );

		$this->permissionService->method( 'canUserEditRules' )
		                        ->willReturn( true )
		;
		$this->groupManager->method( 'isInGroup' )
		                   ->willReturn( true )
		;

		$folder = $this->createFolderMock();
		$folder->method( 'isCreatable' )
		       ->willReturn( true )
		;
		$this->rootFolder->method( 'getUserFolder' )
		                 ->willReturn( $folder )
		;

		$rules = $this->service->listRulesFor( 'alice' );

		// bob's rule is filtered out; alice's own is numbered 4.1 rather than
		// 4.2, because a rule she cannot see cannot compete with hers.
		$this->assertSame(
			[
				'alice1',
				'staff1',
				'default',
			],
			array_column( $rules, 'id' ),
		);
		$this->assertSame(
			[
				4,
				5,
				7,
			],
			array_column( $rules, 'band' ),
		);
		$this->assertSame(
			[
				1,
				1,
				1,
			],
			array_column( $rules, 'position' ),
		);

		// Only her own band-4 rule is editable.
		$this->assertSame(
			[
				true,
				false,
				false,
			],
			array_column( $rules, 'canEdit' ),
		);
	}


	// canUserMutateRule

	public function testCanUserMutateRuleRejectsAnInstanceWideRule(): void
	{

		// Regression test: a non-admin used to be able to mutate a rule
		// scoped to 'all' — changing its path, mode or algorithms for every
		// user on the instance. A user's writable surface is now their own
		// rules only.
		$folder = $this->createFolderMock();
		$folder->method( 'isCreatable' )
		       ->willReturn( true )
		;
		$this->rootFolder->method( 'getUserFolder' )
		                 ->willReturn( $folder )
		;

		$this->assertFalse(
			$this->service->canUserMutateRule(
				'alice',
				[
					'userScope' => 'all',
					'path'      => '/',
				],
			),
		);
	}


	public function testCanUserMutateRuleRejectsThePinnedDefault(): void
	{

		$folder = $this->createFolderMock();
		$folder->method( 'isCreatable' )
		       ->willReturn( true )
		;
		$this->rootFolder->method( 'getUserFolder' )
		                 ->willReturn( $folder )
		;

		$this->assertFalse(
			$this->service->canUserMutateRule(
				'alice',
				[
					'userScope' => 'alice',
					'path'      => '/',
					'pinned'    => true,
				],
			),
		);
	}


	public function testCanUserMutateRuleRejectsAGroupRule(): void
	{

		$folder = $this->createFolderMock();
		$folder->method( 'isCreatable' )
		       ->willReturn( true )
		;
		$this->rootFolder->method( 'getUserFolder' )
		                 ->willReturn( $folder )
		;

		// Group rules are admin-authored; being a member does not make them
		// yours to edit.
		$this->assertFalse(
			$this->service->canUserMutateRule(
				'alice',
				[
					'userScope' => 'group:staff',
					'path'      => '/',
				],
			),
		);
	}


	public function testCanUserMutateRuleRejectsAdminEnforcedRule(): void
	{

		$rule = [
			'userScope'      => 'alice',
			'path'           => '/',
			'admin_enforced' => true,
		];

		$this->assertFalse( $this->service->canUserMutateRule( 'alice', $rule ) );
	}


	public function testCanUserMutateRuleRejectsDifferentUsersRule(): void
	{

		// Regression test for FCIAS Review §6, Finding 3: a user must
		// not be able to mutate another specific user's rule.
		$rule = [
			'userScope' => 'bob',
			'path'      => '/',
		];

		$this->assertFalse( $this->service->canUserMutateRule( 'alice', $rule ) );
	}


	public function testCanUserMutateRuleAllowsOwnRuleWhenPathWritable(): void
	{

		$folder = $this->createFolderMock();
		$folder->method( 'isCreatable' )
		       ->willReturn( true )
		;
		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $folder )
		;

		$rule = [
			'userScope' => 'alice',
			'path'      => '/',
		];

		$this->assertTrue( $this->service->canUserMutateRule( 'alice', $rule ) );
	}


	public function testCanUserMutateRuleRejectsUnwritablePath(): void
	{

		$folder = $this->createFolderMock();
		$folder->method( 'isCreatable' )
		       ->willReturn( false )
		;
		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $folder )
		;

		$rule = [
			'userScope' => 'alice',
			'path'      => '/',
		];

		$this->assertFalse( $this->service->canUserMutateRule( 'alice', $rule ) );
	}


	// isPathWritableByUser

	public function testIsPathWritableByUserReturnsTrueForWritableRoot(): void
	{

		$folder = $this->createFolderMock();
		$folder->method( 'isCreatable' )
		       ->willReturn( true )
		;

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $folder )
		;

		$this->assertTrue( $this->service->isPathWritableByUser( 'alice', '/' ) );
	}


	public function testIsPathWritableByUserReturnsFalseForNonWritableRoot(): void
	{

		$folder = $this->createFolderMock();
		$folder->method( 'isCreatable' )
		       ->willReturn( false )
		;

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $folder )
		;

		$this->assertFalse( $this->service->isPathWritableByUser( 'alice', '/' ) );
	}


	public function testIsPathWritableByUserReturnsFalseOnException(): void
	{

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willThrowException(
			                 new class( 'nope' )
				                 extends
				                 \Exception
				                 implements
				                 Throwable {

			                 },
		                 )
		;

		$this->assertFalse( $this->service->isPathWritableByUser( 'alice', '/' ) );
	}

}
