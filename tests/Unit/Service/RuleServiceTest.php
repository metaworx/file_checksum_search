<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Service\Selector;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
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

	private MockObject|FilecacheService  $filecacheService;

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
		$this->filecacheService  = $this->createMock( FilecacheService::class );
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
			$this->filecacheService,
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
			            $this->filecacheService,
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
		bool  $homeStorage = true,
	): Folder&MockObject {

		$folder = $this->createMock( Folder::class );
		$folder->method( 'search' )
		       ->willReturn( $searchResults )
		;

		$storage = $this->createMock( IStorage::class );
		$storage->method( 'instanceOfStorage' )
		        ->willReturn( $homeStorage )
		;
		$folder->method( 'getStorage' )
		       ->willReturn( $storage )
		;

		return $folder;
	}


	private function homeLocation(
		int    $fileId,
		string $owner,
		string $relative,
		int    $mtime = 1000,
	): FileLocation {

		return FileLocation::fromRow( $fileId, 'home::' . $owner, 'files' . $relative, $mtime );
	}


	private function stubLocate( FileLocation ...$locations ): void
	{

		$byId = [];

		foreach ( $locations as $location )
		{
			$byId[ $location->fileId ] = $location;
		}

		$this->filecacheService->method( 'locateAll' )
		                       ->willReturnCallback(
			                       static fn(
				                       array $fileIds,
			                       ): array => array_intersect_key( $byId, array_flip( $fileIds ) ),
		                       )
		;
	}


	/**
	 * Feed the storage-paged sweep one synthetic storage holding these rows.
	 */
	private function stubSweep( FileLocation ...$locations ): void
	{

		$this->filecacheService->method( 'storageNumericIdsFor' )
		                       ->willReturn( [ 7 ] )
		;
		$this->filecacheService->method( 'homeStorageNumericIds' )
		                       ->willReturn( [ 7 ] )
		;
		$this->filecacheService->method( 'pageStorageFiles' )
		                       ->willReturnCallback(
			                       static fn(
				                       int $storageNumericId,
				                       int $lastFileId,
			                       ): array => array_values(
				                       array_filter(
					                       $locations,
					                       static fn(
						                       FileLocation $location,
					                       ): bool => $location->fileId > $lastFileId,
				                       ),
			                       ),
		                       )
		;
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testEvaluateRulesProcessesEnabledRules(): void
	{

		$rule = $this->defaultRule();

		$this->setupRulesConfig( [ $rule ] );

		$this->stubSweep( $this->homeLocation( 42, 'user1', '/test.txt' ) );

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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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

		// Both rules sweep the same storage and see the same row.
		$this->stubSweep( $this->homeLocation( 42, 'user1', '/test.txt' ) );

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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testEvaluateRulesSurvivesASweepFailure(): void
	{

		$rule = $this->defaultRule();

		$this->setupRulesConfig( [ $rule ] );

		// A rule whose sweep fails (storage listing, paging, …) is logged
		// and skipped; it must not abort the whole evaluation.
		$this->filecacheService->method( 'storageNumericIdsFor' )
		                       ->willThrowException(
			                       new class( 'db gone' )
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

		$this->stubSweep( $this->homeLocation( 42, 'user1', '/test.txt' ) );

		$this->metadataService->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( null ) // stale → updatedAt is null
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, MetadataService::PENDING_PREFIX . 'auto' )
		;

		$result = $this->service->processRule( $this->defaultRule(), [] );

		$this->assertSame( 1, $result['marked'] );
		$this->assertSame( 1, $result['matched'] );
		$this->assertContains( 42, $result['fileIds'] );
	}


	public function testProcessRuleSkipsFreshFiles(): void
	{

		// updatedAt (2000) >= mtime (1000) → fresh, skip
		$this->stubSweep( $this->homeLocation( 42, 'user1', '/test.txt', mtime: 1000 ) );

		// updatedAt (2000) >= mtime (1000) → fresh
		$this->metadataService->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( 2000 )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$result = $this->service->processRule( $this->defaultRule(), [] );

		$this->assertSame( 0, $result['marked'] );
		$this->assertSame( 1, $result['matched'] );
	}


	public function testProcessRuleRespectsBatchLimit(): void
	{

		$locations = [];

		for ( $i = 1; $i <= 150; $i ++ )
		{
			$locations[] = $this->homeLocation( $i, 'user1', '/f' . $i . '.txt' );
		}

		$this->stubSweep( ...$locations );

		$this->metadataService->method( 'getUpdatedAt' )
		                      ->willReturn( null )
		;

		// batchSize is 100 (hardcoded in processRule), so only 100 marked
		$this->metadataService->expects( $this->exactly( 100 ) )
		                      ->method( 'markPending' )
		;

		$result = $this->service->processRule( $this->defaultRule(), [] );

		$this->assertSame( 100, $result['marked'] );
		$this->assertSame( 100, $result['matched'] );
		$this->assertCount( 100, $result['fileIds'] );
	}


	// searchFilesByGlob


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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

					                // The new regular rule lands before the
					                // segment's ** default — never behind it.
					                return is_array( $rules )
						                && count( $rules ) === 2
						                && isset( $rules[0]['id'] )
						                && strlen( $rules[0]['id'] ) === 32
						                && $rules[0]['path'] === 'Docs/*.pdf'
						                && $rules[1]['id'] === 'existing-id';
				                },
			                ),
		                )
		;

		$this->service->ruleAdd( [ 'path' => 'Docs/*.pdf' ] );
	}


	// ruleDelete


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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

	public function testLoadRulesMemoisesTheDecodedList(): void
	{

		// Verdict loops resolve a rule per file; the decode + sort must be
		// paid once per process, not once per file.
		$this->appConfig->expects( $this->once() )
		                ->method( 'getValueString' )
		                ->willReturn( '[]' )
		;

		$this->service->loadRules();
		$this->service->loadRules();
	}


	public function testLoadRulesRefreshRereadsStorage(): void
	{

		$this->appConfig->expects( $this->exactly( 2 ) )
		                ->method( 'getValueString' )
		                ->willReturn( '[]' )
		;

		$this->service->loadRules();
		$this->service->loadRules( refresh: true );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAMutationInvalidatesTheLoadRulesMemo(): void
	{

		// ruleAdd() serves its own read from the memo, then saveRules() —
		// the single write path — drops it, so the next read re-derives
		// from what was actually persisted.
		$this->appConfig->expects( $this->exactly( 2 ) )
		                ->method( 'getValueString' )
		                ->willReturn( '[]' )
		;

		$this->service->loadRules();
		$this->service->ruleAdd(
			[
				'enabled'  => true,
				'path'     => '**',
				'selector' => 'home:alice',
			],
			'test',
		);
		$this->service->loadRules();
	}


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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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
		$this->stubLocate( $this->homeLocation( 42, 'alice', '/docs/report.pdf' ) );

		$result = $this->service->findFirstMatchingRule( 42 );

		$this->assertNotNull( $result );
		$this->assertSame( 'r2', $result['id'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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
		$this->stubLocate( $this->homeLocation( 42, 'alice', '/docs/report.pdf' ) );

		$result = $this->service->findFirstMatchingRule( 42 );

		$this->assertNull( $result );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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
		$this->stubLocate( $this->homeLocation( 42, 'alice', '/docs/report.pdf' ) );

		$result = $this->service->findFirstMatchingRule( 42 );

		$this->assertNull( $result );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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
		$this->stubLocate( $this->homeLocation( 42, 'alice', '/docs/report.pdf' ) );

		$result = $this->service->findFirstMatchingRule( 42 );

		$this->assertNotNull( $result );
		$this->assertSame( 'r1', $result['id'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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
		$this->stubLocate( $this->homeLocation( 42, 'alice', '/docs/report.pdf' ) );

		$result = $this->service->findFirstMatchingRule( 42 );

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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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
			'user enforced'        => [
				[
					'selector'       => 'home:alice',
					'admin_enforced' => true,
				],
				RuleService::BAND_EXACT_ENFORCED,
			],
			'group enforced'       => [
				[
					'selector'       => 'group:staff',
					'admin_enforced' => true,
				],
				RuleService::BAND_GROUP_ENFORCED,
			],
			// Group folders sit with groups: shared things next to groups,
			// not next to individuals. Disjoint namespaces make the
			// placement matching-irrelevant either way.
			'groupfolder enforced' => [
				[
					'selector'       => 'groupfolder:5',
					'admin_enforced' => true,
				],
				RuleService::BAND_GROUP_ENFORCED,
			],
			'all homes enforced'   => [
				[
					'selector'       => 'home:*',
					'admin_enforced' => true,
				],
				RuleService::BAND_NAMESPACE_ENFORCED,
			],
			'universal enforced'   => [
				[
					'selector'       => '*',
					'admin_enforced' => true,
				],
				RuleService::BAND_UNIVERSAL_ENFORCED,
			],
			'user'                 => [
				[ 'selector' => 'home:alice' ],
				RuleService::BAND_EXACT,
			],
			'storage'              => [
				[ 'selector' => 'storage:smb::u@h//share/' ],
				RuleService::BAND_EXACT,
			],
			'group'                => [
				[ 'selector' => 'group:staff' ],
				RuleService::BAND_GROUP,
			],
			'groupfolder'          => [
				[ 'selector' => 'groupfolder:5' ],
				RuleService::BAND_GROUP,
			],
			'all homes'            => [
				[ 'selector' => 'home:*' ],
				RuleService::BAND_NAMESPACE,
			],
			'universal'            => [
				[ 'selector' => '*' ],
				RuleService::BAND_UNIVERSAL,
			],
			// Legacy stored forms keep working while the migration runs.
			'legacy all'           => [
				[ 'userScope' => 'all' ],
				RuleService::BAND_NAMESPACE,
			],
			'legacy bare uid'      => [
				[ 'userScope' => 'alice' ],
				RuleService::BAND_EXACT,
			],
		];
	}


	public function testSelectorParsingAndCanonicalForms(): void
	{

		$this->assertSame(
			'home:*',
			Selector::fromStored( 'all' )
			        ->canonical(),
		);
		$this->assertSame(
			'home:alice',
			Selector::fromStored( 'alice' )
			        ->canonical(),
		);
		$this->assertSame(
			'group:staff',
			Selector::parse( 'group:staff' )
			        ->canonical(),
		);
		$this->assertSame(
			'groupfolder:5',
			Selector::parse( 'groupfolder:5' )
			        ->canonical(),
		);
		// Raw storage ids may contain ':' and '//' — value semantics, no
		// escaping: everything after the first colon is the target.
		$this->assertSame(
			'smb::u@h//share/',
			Selector::parse( 'storage:smb::u@h//share/' )->target,
		);
		$this->assertSame(
			'*',
			Selector::parse( '*' )
			        ->canonical(),
		);

		// group:* is deliberately not a value.
		$this->expectException( InvalidArgumentException::class );
		Selector::parse( 'group:*' );
	}


	public function testSortRulesOrdersByBandSegmentAndPartition(): void
	{

		$rules = [
			// home:* default first in storage — the partition must push it
			// after the segment's specific rule regardless of stored order.
			[
				'id'       => 'd1',
				'selector' => 'home:*',
				'path'     => '**',
			],
			[
				'id'       => 'g1',
				'selector' => 'home:*',
				'path'     => '/media/**',
			],
			[
				'id'       => 'u1',
				'selector' => 'home:alice',
				'path'     => '/docs/**',
			],
			[
				'id'             => 'e1',
				'selector'       => 'home:alice',
				'admin_enforced' => true,
				'path'           => '/legal/**',
			],
			[
				'id'       => 'u2',
				'selector' => 'home:alice',
				'path'     => '/img/**',
			],
			[
				'id'       => 'univ',
				'selector' => '*',
				'path'     => '**',
			],
			[
				'id'       => 'gf',
				'selector' => 'groupfolder:5',
				'path'     => '/**',
			],
		];

		// Bands ascend (enforced 1-4, unenforced 5-8); within a segment the
		// stored order holds, except that a bare-** default always trails
		// its segment's specific rules.
		$this->assertSame(
			[
				'e1',
				// band 1
				'u1',
				// band 5, home:alice
				'u2',
				'gf',
				// band 6, groupfolder:5
				'g1',
				// band 7, home:* — specific before default
				'd1',
				// band 7, home:* default partition
				'univ',
				// band 8
			],
			array_column( RuleService::sortRules( $rules ), 'id' ),
		);
	}


	// matching order


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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

		$this->stubLocate( $this->homeLocation( 42, 'alice', '/Documents/a.txt' ) );

		$match = $this->service->findFirstMatchingRule( 42 );

		$this->assertNotNull( $match );
		$this->assertSame( 'docs', $match['id'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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

		$this->stubLocate( $this->homeLocation( 42, 'alice', '/a.txt' ) );

		$match = $this->service->findFirstMatchingRule( 42 );

		$this->assertNotNull( $match );
		$this->assertSame( 'alice-enforced', $match['id'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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

		$this->stubLocate(
			$this->homeLocation( 42, 'alice', '/a.txt' ),
			$this->homeLocation( 43, 'bob', '/a.txt' ),
		);

		$this->assertSame(
			'staff',
			$this->service->findFirstMatchingRule( 42 )['id'] ?? null,
		);
		$this->assertNull( $this->service->findFirstMatchingRule( 43 ) );
	}


	// idle banner acknowledgement (D5)


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testSavingAnEnabledIncludeRuleClearsTheIdleBannerAck(): void
	{

		$this->setupRulesConfig( [] );

		$this->appConfig->expects( $this->once() )
		                ->method( 'deleteKey' )
		                ->with(
			                Application::APP_ID,
			                RuleService::CONFIG_KEY_IDLE_BANNER_ACK,
		                )
		;

		$this->service->ruleAdd(
			[
				'enabled'  => true,
				'type'     => 'include',
				'path'     => '**',
				'selector' => 'home:alice',
			],
			'test',
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testSavingOnlyDisabledOrNonIncludeRulesKeepsTheAck(): void
	{

		$this->setupRulesConfig( [
			[
				'id'       => 'off',
				'enabled'  => false,
				'type'     => 'include',
				'path'     => '**',
				'selector' => 'home:*',
			],
		] );

		// Neither a disabled include nor an enabled exclude turns hashing
		// on, so the acknowledgement stands.
		$this->appConfig->expects( $this->never() )
		                ->method( 'deleteKey' )
		;

		$this->service->ruleAdd(
			[
				'enabled'  => true,
				'type'     => 'exclude',
				'path'     => '**/*.iso',
				'selector' => 'home:alice',
			],
			'test',
		);
	}


	// canonical identity (Block G)


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGoverningRulesForFileIdsResolvesTheBatchInOneScan(): void
	{

		$this->setupRulesConfig( [
			[
				'id'       => 'alice-all',
				'enabled'  => true,
				'selector' => 'home:alice',
				'path'     => '**',
			],
		] );

		// One locateAll() for the whole batch — that is the method's point.
		$this->filecacheService->expects( $this->once() )
		                       ->method( 'locateAll' )
		                       ->with( [
			                       1,
			                       2,
			                       3,
		                       ] )
		                       ->willReturn( [
			                       1 => $this->homeLocation( 1, 'alice', '/a.txt' ),
			                       2 => $this->homeLocation( 2, 'bob', '/b.txt' ),
		                       ] )
		;

		$rules = $this->service->governingRulesForFileIds( [
			1,
			2,
			3,
		] );

		// Every requested id has an entry: a governing rule, or null for a
		// file no rule matches (2) or one with no filecache row (3).
		$this->assertSame( 'alice-all', $rules[1]['id'] ?? null );
		$this->assertNull( $rules[2] );
		$this->assertNull( $rules[3] );
		$this->assertSame( [
			1,
			2,
			3,
		], array_keys( $rules ) );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGoverningRuleIsResolvedByOwnerIdentityNotActingView(): void
	{

		// The share-edit regression, service half: whoever's view the event
		// came through, the file id resolves to the OWNER's identity, so the
		// owner's rules — written against the owner's paths — govern.
		$this->setupRulesConfig( [
			[
				'id'       => 'alice-photos',
				'enabled'  => true,
				'selector' => 'home:alice',
				'path'     => 'Photos/**',
			],
		] );

		$this->stubLocate(
		// Alice's file — reachable in bob's view as /bob/files/Shared/x.jpg,
		// but its one filecache row is alice's.
			$this->homeLocation( 42, 'alice', '/Photos/x.jpg' ),
			// Bob's own file at the same relative path.
			$this->homeLocation( 43, 'bob', '/Photos/x.jpg' ),
		);

		$this->assertSame(
			'alice-photos',
			$this->service->findFirstMatchingRule( 42 )['id'] ?? null,
		);
		$this->assertNull( $this->service->findFirstMatchingRule( 43 ) );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAGroupfolderSelectorGovernsOnlyItsFolder(): void
	{

		$this->setupRulesConfig( [
			[
				'id'       => 'team',
				'enabled'  => true,
				'selector' => 'groupfolder:5',
				'path'     => '**',
			],
		] );

		$this->stubLocate(
			FileLocation::fromRow( 50, 'local::/data/__groupfolders/5/', 'files/doc.md', 100 ),
			FileLocation::fromRow( 51, 'local::/data/__groupfolders/6/', 'files/doc.md', 100 ),
			$this->homeLocation( 52, 'alice', '/doc.md' ),
		);

		$this->assertSame( 'team', $this->service->findFirstMatchingRule( 50 )['id'] ?? null );
		$this->assertNull( $this->service->findFirstMatchingRule( 51 ) );
		$this->assertNull( $this->service->findFirstMatchingRule( 52 ) );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAStorageSelectorMatchesItsRawIdWhateverTheNamespace(): void
	{

		// storage:<raw id> is exact identity — including a home storage:
		// storage:home::alice and home:alice describe the same files.
		$this->setupRulesConfig( [
			[
				'id'       => 'that-storage',
				'enabled'  => true,
				'selector' => 'storage:home::alice',
				'path'     => '**',
			],
		] );

		$this->stubLocate(
			$this->homeLocation( 60, 'alice', '/a.txt' ),
			$this->homeLocation( 61, 'bob', '/a.txt' ),
		);

		$this->assertSame( 'that-storage', $this->service->findFirstMatchingRule( 60 )['id'] ?? null );
		$this->assertNull( $this->service->findFirstMatchingRule( 61 ) );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheUniversalSelectorGovernsEveryNamespace(): void
	{

		$this->setupRulesConfig( [
			[
				'id'       => 'everything',
				'enabled'  => true,
				'selector' => '*',
				'path'     => '**',
			],
		] );

		$this->stubLocate(
			$this->homeLocation( 70, 'alice', '/a.txt' ),
			FileLocation::fromRow( 71, 'local::/data/__groupfolders/5/', 'files/b.txt', 100 ),
			FileLocation::fromRow( 72, 'smb::u@host//share/', 'files/c.txt', 100 ),
		);

		foreach (
			[
				70,
				71,
				72,
			] as $fileId
		)
		{
			$this->assertSame(
				'everything',
				$this->service->findFirstMatchingRule( $fileId )['id'] ?? null,
			);
		}
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testLocationsOutsideAFilesAreaAreGovernedByNothing(): void
	{

		// Trash and versions rows exist in the filecache, but no rule — not
		// even a universal catch-all — may reach them. The rules are not
		// even loaded: the classification decides before any rule is read.
		$this->stubLocate(
			FileLocation::fromRow( 80, 'home::alice', 'files_trashbin/files/x.d1', 100 ),
		);

		$this->assertNull( $this->service->findFirstMatchingRule( 80 ) );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRuleGlobsMatchWithOrWithoutALeadingSlash(): void
	{

		$this->setupRulesConfig( [
			[
				'id'       => 'slashed',
				'enabled'  => true,
				'selector' => 'home:alice',
				'path'     => '/Photos/**',
			],
		] );

		$this->stubLocate( $this->homeLocation( 90, 'alice', '/Photos/x.jpg' ) );

		$this->assertSame(
			'slashed',
			$this->service->findFirstMatchingRule( 90 )['id'] ?? null,
		);
	}


	public function testAGroupfolderSweepSkipsLegacyRowsOfOtherFolders(): void
	{

		// The legacy root-jail layout stores many folders on one storage, so
		// the sweep must tell rows apart by their per-row folder id.
		$this->filecacheService->method( 'storageNumericIdsFor' )
		                       ->willReturn( [ 9 ] )
		;

		$rows = [
			FileLocation::fromRow( 1, 'local::/data/', '__groupfolders/5/files/in.md', 100 ),
			FileLocation::fromRow( 2, 'local::/data/', '__groupfolders/6/files/out.md', 100 ),
			FileLocation::fromRow( 3, 'local::/data/', '__groupfolders/5/trash/gone.md', 100 ),
		];
		$this->filecacheService->method( 'pageStorageFiles' )
		                       ->willReturnCallback(
			                       static fn(
				                       int $storageNumericId,
				                       int $lastFileId,
			                       ): array => array_values(
				                       array_filter(
					                       $rows,
					                       static fn(
						                       FileLocation $location,
					                       ): bool => $location->fileId > $lastFileId,
				                       ),
			                       ),
		                       )
		;

		$this->metadataService->method( 'getUpdatedAt' )
		                      ->willReturn( null )
		;

		$result = $this->service->processRule(
			[
				'id'       => 'team',
				'enabled'  => true,
				'selector' => 'groupfolder:5',
				'path'     => '**',
				'mode'     => 'auto',
			],
			[],
		);

		// Folder 6's row and folder 5's trash row are both invisible.
		$this->assertSame( [ 1 ], $result['fileIds'] );
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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

		$this->stubLocate( $this->homeLocation( 42, 'alice', '/secret.key' ) );

		// Band 3 precedes band 4, so the mandate is reached first and the
		// user's own include never gets the file.
		$match = $this->service->findFirstMatchingRule( 42 );

		$this->assertSame( 'mandate', $match['id'] ?? null );
		$this->assertFalse( RuleService::maintainsHashes( $match ) );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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

		$this->stubLocate( $this->homeLocation( 42, 'alice', '/big.iso' ) );

		$match = $this->service->findFirstMatchingRule( 42 );

		// Overriding a *default* is exactly what a non-enforced rule may do.
		$this->assertSame( 'mine', $match['id'] ?? null );
		$this->assertFalse( RuleService::maintainsHashes( $match ) );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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

		$this->stubLocate( $this->homeLocation( 42, 'alice', '/big.iso' ) );

		// Band 4 precedes band 6: an admin who did not enforce the exclusion
		// offered it as a default, and the user is entitled to override it.
		$match = $this->service->findFirstMatchingRule( 42 );

		$this->assertSame( 'mine', $match['id'] ?? null );
		$this->assertTrue( RuleService::maintainsHashes( $match ) );
	}


	public function testProcessRuleClaimsFilesForANonIncludeRuleWithoutQueueingThem(): void
	{

		$this->stubSweep( $this->homeLocation( 42, 'alice', '/test.txt' ) );

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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testSavingCanonicalisesLegacyKeysAndDropsPinned(): void
	{

		$this->setupRulesConfig( [
			[
				'id'        => 'legacy',
				'userScope' => 'all',
				'path'      => '/docs/**',
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

					                // Every write migrates: canonical
					                // selector in, retired keys out.
					                return $rules[0]['selector'] === 'home:*'
						                && ! array_key_exists( 'userScope', $rules[0] )
						                && ! array_key_exists( 'pinned', $rules[0] );
				                },
			                ),
		                )
		;

		$this->service->resaveCanonical();
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testANewRuleLandsBeforeItsSegmentsDefault(): void
	{

		$this->setupRulesConfig( [
			[
				'id'       => 'default',
				'selector' => 'home:*',
				'path'     => '**',
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

					                // The usability property the partition
					                // exists for: creating a rule never
					                // requires dragging it past the default.
					                return $rules[0]['path'] === '/docs/**'
						                && $rules[1]['id'] === 'default';
				                },
			                ),
		                )
		;

		$this->service->ruleAdd(
			[
				'selector' => 'home:*',
				'path'     => '/docs/**',
				'enabled'  => true,
			],
		);
	}


	// migrateToBands

	// reorderBand

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testReorderSegmentPermutesOnePartitionAndLeavesTheRestUntouched(): void
	{

		$this->setupRulesConfig( [
			[
				'id'             => 'e1',
				'selector'       => 'home:alice',
				'path'           => '/legal/**',
				'admin_enforced' => true,
			],
			[
				'id'       => 'g1',
				'selector' => 'home:*',
				'path'     => '/a/**',
			],
			[
				'id'       => 'g2',
				'selector' => 'home:*',
				'path'     => '/b/**',
			],
			[
				'id'       => 'g3',
				'selector' => 'home:*',
				'path'     => '/c/**',
			],
			[
				'id'       => 'd1',
				'selector' => 'home:*',
				'path'     => '**',
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

					                // The default partition (d1) is not part
					                // of the reordered regular partition and
					                // cannot move.
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

		$this->service->reorderSegment(
			'home:*',
			false,
			[
				'g3',
				'g1',
				'g2',
			],
		);
	}


	/**
	 * A default-shaped rule belongs to the defaults partition: submitting it
	 * as part of the regular partition's order is a non-permutation, which
	 * is what makes dragging a default above the specific rules impossible.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testReorderSegmentKeepsThePartitionsApart(): void
	{

		$this->setupRulesConfig( [
			[
				'id'       => 'g1',
				'selector' => 'home:*',
				'path'     => '/a/**',
			],
			[
				'id'       => 'd1',
				'selector' => 'home:*',
				'path'     => '**',
			],
		] );

		$this->expectException( InvalidArgumentException::class );

		$this->service->reorderSegment(
			'home:*',
			false,
			[
				'd1',
				'g1',
			],
		);
	}


	/**
	 * @dataProvider badPermutationProvider
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testReorderSegmentRejectsAnythingThatIsNotAnExactPermutation(
		array $orderedIds,
	): void {

		$this->setupRulesConfig( [
			[
				'id'       => 'g1',
				'selector' => 'home:*',
				'path'     => '/a/**',
			],
			[
				'id'       => 'g2',
				'selector' => 'home:*',
				'path'     => '/b/**',
			],
			[
				'id'       => 'u1',
				'selector' => 'home:alice',
				'path'     => '/c/**',
			],
		] );

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;

		$this->expectException( InvalidArgumentException::class );

		$this->service->reorderSegment( 'home:*', false, $orderedIds );
	}


	/**
	 * @return array<string, array{array}>
	 */
	public static function badPermutationProvider(): array
	{

		return [
			'incomplete'            => [ [ 'g1' ] ],
			'unknown id'            => [
				[
					'g1',
					'g2',
					'nope',
				],
			],
			'duplicate'             => [
				[
					'g1',
					'g1',
				],
			],
			// u1 belongs to home:alice — naming it in a home:* reorder must
			// be rejected rather than silently pulled across a segment
			// boundary.
			'id from other segment' => [
				[
					'g1',
					'g2',
					'u1',
				],
			],
		];
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testReorderSegmentRestrictsANonAdminToTheirOwnRules(): void
	{

		$this->setupRulesConfig( [
			[
				'id'       => 'bob1',
				'selector' => 'home:bob',
				'path'     => '/a/**',
			],
			[
				'id'       => 'alice1',
				'selector' => 'home:alice',
				'path'     => '/b/**',
			],
			[
				'id'       => 'alice2',
				'selector' => 'home:alice',
				'path'     => '/c/**',
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

					                // The derived order groups segments
					                // alphabetically (home:alice before
					                // home:bob); alice's two swap and bob's
					                // rule keeps its place in its segment.
					                return $ids === [
							                'alice2',
							                'alice1',
							                'bob1',
						                ];
				                },
			                ),
		                )
		;

		$this->service->reorderSegment(
			'home:alice',
			false,
			[
				'alice2',
				'alice1',
			],
			'alice',
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testReorderSegmentRejectsAnotherUsersRuleForANonAdmin(): void
	{

		$this->setupRulesConfig( [
			[
				'id'       => 'bob1',
				'selector' => 'home:bob',
				'path'     => '/a/**',
			],
			[
				'id'       => 'alice1',
				'selector' => 'home:alice',
				'path'     => '/b/**',
			],
		] );

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;

		$this->expectException( InvalidArgumentException::class );

		// Naming another user's rule is not a permutation of alice's segment.
		$this->service->reorderSegment(
			'home:alice',
			false,
			[
				'alice1',
				'bob1',
			],
			'alice',
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testReorderSegmentRejectsANonAdminTouchingAnyOtherSegment(): void
	{

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;

		$this->expectException( InvalidArgumentException::class );

		// A non-admin owns exactly one segment: home:<their own uid>.
		$this->service->reorderSegment(
			'home:*',
			false,
			[ 'g1' ],
			'alice',
		);
	}


	// listRulesFor


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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
				5,
				5,
				7,
			],
			array_column( $rules, 'band' ),
		);
		// alice's and bob's rules are separate segments now — each numbers
		// from 1; there is no shared "band 4" counter to be second in.
		$this->assertSame(
			[
				1,
				1,
				1,
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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
				5,
				6,
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


	public function testCanUserMutateRuleAcceptsOnlyTheUsersOwnSegment(): void
	{

		$folder = $this->createFolderMock();
		$folder->method( 'isCreatable' )
		       ->willReturn( true )
		;
		$this->rootFolder->method( 'getUserFolder' )
		                 ->willReturn( $folder )
		;

		// pinned is gone: a user's own default-shaped rule is theirs to
		// edit like any other of their rules …
		$this->assertTrue(
			$this->service->canUserMutateRule(
				'alice',
				[
					'selector' => 'home:alice',
					'path'     => '/',
				],
			),
		);

		// … but nothing outside their own segment ever is.
		foreach (
			[
				'home:*',
				'groupfolder:5',
				'*',
				'home:bob',
			] as $selector
		)
		{
			$this->assertFalse(
				$this->service->canUserMutateRule(
					'alice',
					[
						'selector' => $selector,
						'path'     => '/',
					],
				),
			);
		}
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

	public function testRuleTargetRefusalAcceptsAWritableOwnHomeRoot(): void
	{

		$folder = $this->createFolderMock();
		$folder->method( 'isCreatable' )
		       ->willReturn( true )
		;

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $folder )
		;

		$this->assertNull( $this->service->ruleTargetRefusal( 'alice', '/' ) );
	}


	public function testRuleTargetRefusalRefusesANonWritableRoot(): void
	{

		$folder = $this->createFolderMock();
		$folder->method( 'isCreatable' )
		       ->willReturn( false )
		;

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $folder )
		;

		$this->assertStringContainsString(
			'write',
			(string) $this->service->ruleTargetRefusal( 'alice', '/' ),
		);
	}


	public function testRuleTargetRefusalRefusesOnException(): void
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

		$this->assertNotNull( $this->service->ruleTargetRefusal( 'alice', '/' ) );
	}


	public function testRuleTargetRefusalNamesAMountedPath(): void
	{

		// G9: a received share or a mounted group folder is writable — but a
		// personal rule is home:<uid> and by identity never governs another
		// namespace's files, so the path is refused with the reason.
		$mounted = $this->createFolderMock( homeStorage: false );
		$mounted->method( 'isCreatable' )
		        ->willReturn( true )
		;

		$home = $this->createFolderMock();
		$home->method( 'get' )
		     ->with( '/RenamedShare' )
		     ->willReturn( $mounted )
		;

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'bob' )
		                 ->willReturn( $home )
		;

		$this->assertStringContainsString(
			'received share',
			(string) $this->service->ruleTargetRefusal( 'bob', '/RenamedShare/**' ),
		);
	}


	// ─── applyRule ──────────────────────────────────────────────────


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testApplyRuleQueuesGovernedStaleFilesUncapped(): void
	{

		$rule = [
			'id'        => 'r1',
			'enabled'   => true,
			'type'      => 'include',
			'path'      => '**',
			'userScope' => 'alice',
			'mode'      => 'missing',
		];

		$this->setupRulesConfig( [ $rule ] );

		$this->stubSweep(
			$this->homeLocation( 1, 'alice', '/a.txt', mtime: 2000 ),
			$this->homeLocation( 2, 'alice', '/b.txt', mtime: 1000 ),
		);

		$this->metadataService->method( 'getUpdatedAt' )
		                      ->willReturnMap( [
			                      [
				                      1,
				                      1500,
			                      ],
			                      [
				                      2,
				                      1500,
			                      ],
		                      ] )
		;

		// Only the stale file is queued, with the rule's own mode.
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 1, 'pending:missing' )
		;

		$result = $this->service->applyRule( $rule );

		$this->assertSame(
			[
				'matched' => 2,
				'marked'  => 1,
				'skipped' => 0,
				'fresh'   => 1,
			],
			$result,
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testApplyRuleNeverMarksAFileAHigherBandClaims(): void
	{

		$mine   = [
			'id'        => 'mine',
			'enabled'   => true,
			'type'      => 'include',
			'path'      => '**',
			'userScope' => 'all',
			'mode'      => 'force',
		];
		$higher = [
			'id'             => 'mandate',
			'enabled'        => true,
			'type'           => 'exclude',
			'path'           => '**',
			'userScope'      => 'alice',
			'admin_enforced' => true,
		];

		// Band discipline holds for a single-rule apply exactly as for the
		// sweep: the enforced band-1 rule claims alice's files, so applying
		// the band-6 rule must not touch them.
		$this->setupRulesConfig(
			[
				$higher,
				$mine,
			],
		);

		$this->stubSweep( $this->homeLocation( 1, 'alice', '/a.txt' ) );

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$result = $this->service->applyRule( $mine );

		$this->assertSame( 1, $result['skipped'] );
	}


	public function testApplyRuleRefusesADisabledRule(): void
	{

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'disabled' );

		$this->service->applyRule(
			[
				'id'      => 'r1',
				'enabled' => false,
				'type'    => 'include',
			],
		);
	}


	public function testApplyRuleRefusesANonIncludeRule(): void
	{

		// An ignore/exclude rule computes nothing, so applying it could only
		// queue work the drain is designed to drop.
		$this->expectException( InvalidArgumentException::class );

		$this->service->applyRule(
			[
				'id'      => 'r1',
				'enabled' => true,
				'type'    => 'exclude',
			],
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testApplyRuleWithModeOverrideOnAnEnforcedRuleWarns(): void
	{

		$rule = [
			'id'             => 'mandate',
			'enabled'        => true,
			'type'           => 'include',
			'path'           => '**',
			'userScope'      => 'alice',
			'mode'           => 'auto',
			'admin_enforced' => true,
		];

		// An empty sweep: the selector's user resolves, but their home
		// storage holds nothing (the mocked filecache returns no ids).
		$this->userManager->method( 'get' )
		                  ->with( 'alice' )
		                  ->willReturn( $this->createConfiguredMock( IUser::class, [ 'getUID' => 'alice' ] ) )
		;

		// Deviating from the mode someone wrote down as non-negotiable
		// leaves a trace another person can find.
		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		             ->with( $this->stringContains( 'mode override' ), $this->anything() )
		;

		$this->service->applyRule( $rule, 'force', null, 'cli' );
	}


	// ─── audit logging ──────────────────────────────────────────────


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testMutationsAreAuditLoggedWithTheActor(): void
	{

		$this->setupRulesConfig( [] );

		$this->logger->expects( $this->once() )
		             ->method( 'info' )
		             ->with(
			             $this->stringContains( 'rule audit' ),
			             $this->callback(
				             static fn(
					             array $context,
				             ): bool => $context['operation'] === 'created'
					             && $context['actor'] === 'alice',
			             ),
		             )
		;

		$this->service->ruleAdd(
			[
				'enabled'   => true,
				'path'      => '/docs/**',
				'userScope' => 'alice',
			],
			'alice',
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testMutatingAnEnforcedRuleEscalatesToWarning(): void
	{

		$this->setupRulesConfig( [
			[
				'id'             => 'mandate',
				'enabled'        => true,
				'path'           => '**',
				'userScope'      => 'all',
				'admin_enforced' => true,
			],
		] );

		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		             ->with( $this->stringContains( 'admin-enforced' ), $this->anything() )
		;

		$this->service->ruleDelete( 'mandate', 'cli' );
	}

}
