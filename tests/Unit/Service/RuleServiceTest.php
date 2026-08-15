<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
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

	private MockObject|IAppConfig      $appConfig;

	private MockObject|IRootFolder     $rootFolder;

	private MockObject|IUserManager    $userManager;

	private MockObject|MetadataService $metadataService;

	private MockObject|LoggerInterface $logger;

	private RuleService                $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->appConfig       = $this->createMock( IAppConfig::class );
		$this->rootFolder      = $this->createMock( IRootFolder::class );
		$this->userManager     = $this->createMock( IUserManager::class );
		$this->metadataService = $this->createMock( MetadataService::class );
		$this->logger          = $this->createMock( LoggerInterface::class );

		$this->service = new RuleService(
			$this->appConfig,
			$this->rootFolder,
			$this->userManager,
			$this->metadataService,
			$this->logger,
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
			[ 'id'      => 'r1',
			  'enabled' => true,
			],
			[ 'id'      => 'r2',
			  'enabled' => false,
			],
			[ 'id'      => 'r3',
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
			[ 'id'      => 'r1',
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
			[ 'id'      => 'r1',
			  'enabled' => true,
			],
			[ 'id'      => 'r2',
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
			[ 'id'      => 'r1',
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
			[ 'id'      => 'r1',
			  'enabled' => false,
			  'path'    => '**/*.jpg',
			],
			[ 'id'      => 'r2',
			  'enabled' => true,
			  'path'    => '**/*.pdf',
			],
			[ 'id'      => 'r3',
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
			[ 'id'      => 'r1',
			  'enabled' => true,
			  'path'    => '**/*.jpg',
			],
		];

		$this->setupRulesConfig( $rules );

		$result = $this->service->findFirstMatchingRule( '/files/docs/report.pdf' );

		$this->assertNull( $result );
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

}
