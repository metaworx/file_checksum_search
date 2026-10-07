<?php
/** @noinspection SqlNoDataSourceInspection */

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\ProcessPendingUpdates;
use OCA\FileChecksumSearch\Command\Queue\Drain;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Server;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

/**
 * End-to-end verification that ProcessPendingUpdates actually drains
 * pending metadata index entries (pending:auto / pending:missing) and
 * computes hashes, that Application::boot() no longer re-registers
 * background jobs on every request (which reset last_run), and that a file
 * the drain cannot hash holds up neither the job nor `queue:drain --all`.
 */
class ProcessPendingUpdatesIntegrationTest
    extends
    DatabaseTestCase
{

//  constants

	private const RULE_CONFIG_KEY = 'rule_definitions';

	private const BATCH_LIMIT_KEY = 'pending_batch_limit';

	/**
	 * How many fetches `queue:drain` gets answered before the queue reads as
	 * empty: a drain that would fetch for ever then ends, and the test fails
	 * on the count rather than hanging.
	 */
	private const FETCH_GUARD = 5;


//  private properties

	private MetadataService $metadataService;

	private RuleService     $ruleService;

	private IAppConfig      $appConfig;

	private IJobList        $jobList;

	private string          $originalRulesJson = '';

	/** @var File[] */
	private array $cleanupFiles = [];

	/** @var list<int> */
	private array $cleanupFileIds = [];

	/** @var list<string> The app's hashing locks a test holds, released in tearDown(). */
	private array $heldLocks = [];

	/** The batch limit a test replaced, false while none did; null when it was unset. */
	private int|null|false $batchLimitBefore = false;


//  getters / setters / is* / has*

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->metadataService = Server::get( MetadataService::class );
		$this->ruleService     = Server::get( RuleService::class );
		$this->appConfig       = Server::get( IAppConfig::class );
		$this->jobList         = Server::get( IJobList::class );

		$this->originalRulesJson = $this->appConfig->getValueString(
			Application::APP_ID,
			self::RULE_CONFIG_KEY,
			'[]',
		);
	}


//  other non-static methods

	protected function tearDown(): void
	{
		// Before the rollback: the provider keeps its own account of the
		// locks this process holds, which a rollback of their rows leaves
		// behind.
		foreach ( $this->heldLocks as $path )
		{
			try
			{
				Server::get( ILockingProvider::class )
				      ->releaseLock( $path, ILockingProvider::LOCK_EXCLUSIVE )
				;
			}
			catch ( Throwable )
			{
			}
		}

		// Roll back any open transaction before touching committed state.
		parent::tearDown();

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::RULE_CONFIG_KEY,
			$this->originalRulesJson,
		);

		// Through the config itself, which keeps the value in memory as well:
		// the rollback restores only the row.
		if ( $this->batchLimitBefore === null )
		{
			$this->appConfig->deleteKey( Application::APP_ID, self::BATCH_LIMIT_KEY );
		}
		elseif ( $this->batchLimitBefore !== false )
		{
			$this->appConfig->setValueInt( Application::APP_ID, self::BATCH_LIMIT_KEY, $this->batchLimitBefore );
		}

		// Written past saveRules() like the test's own rule, so the memoised
		// list is dropped here too: the files deleted below, and whatever
		// runs next in this process, see the instance's rules again.
		$this->ruleService->loadRules( refresh: true );

		foreach ( $this->cleanupFiles as $file )
		{
			try
			{
				$file->delete();
			}
			catch ( Throwable )
			{
			}
		}

		if ( ! empty( $this->cleanupFileIds ) )
		{
			$placeholders = implode( ',', array_fill( 0, count( $this->cleanupFileIds ), '?' ) );

			try
			{
				$this->getRawConnection()
				     ->executeStatement(
					     "DELETE FROM `*PREFIX*filecache` WHERE `fileid` IN ($placeholders)",
					     $this->cleanupFileIds,
				     )
				;
			}
			catch ( Throwable )
			{
			}

			try
			{
				$this->getRawConnection()
				     ->executeStatement(
					     "DELETE FROM `*PREFIX*files_metadata_index` WHERE `file_id` IN ($placeholders)",
					     $this->cleanupFileIds,
				     )
				;
			}
			catch ( Throwable )
			{
			}

			try
			{
				$this->getRawConnection()
				     ->executeStatement(
					     "DELETE FROM `*PREFIX*files_metadata` WHERE `file_id` IN ($placeholders)",
					     $this->cleanupFileIds,
				     )
				;
			}
			catch ( Throwable )
			{
			}
		}
	}

	/**
	 * Cron drains pending entries and computes hashes for both the
	 * pending:auto and pending:missing markers; the algorithms come from
	 * the governing rule, resolved at drain time.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testCronDrainsPendingEntriesAndComputesHashes(): void
	{
		// Create files first so NodeCreatedEvent sees no rules.
		$newFile     = $this->createTestFile( 'fcias_cron_new_' . time() . '.dat' );
		$missingFile = $this->createTestFile( 'fcias_cron_missing_' . time() . '.dat' );

		// Catch-all force rule: the drain resolves it and its algorithms.
		$this->addCatchAllForceRule();

		$newFileId     = $newFile->getId();
		$missingFileId = $missingFile->getId();
		$testFileIds   = [
			$newFileId,
			$missingFileId,
		];

		$this->beginTransaction();

		$this->insertPendingMarker( $newFileId, MetadataService::PENDING_FORCE );
		$this->insertPendingMarker(
			$missingFileId,
			MetadataService::PENDING_PREFIX . 'missing',
		);

		// Isolate: keep only our two pending rows so the cron batch is
		// deterministic regardless of unrelated pending data in the dev DB.
		$this->deleteOtherPendingRows( $testFileIds );

		$this->assertSame(
			2,
			$this->countPendingRows( $testFileIds ),
			'Both test files should be pending before the cron run.',
		);

		$job = $this->buildJob();

		$reflection = new ReflectionMethod( ProcessPendingUpdates::class, 'run' );
		$reflection->invoke( $job, null );

		$this->assertSame(
			0,
			$this->countPendingRows( $testFileIds ),
			'Pending markers should be drained after the cron run.',
		);

		foreach ( $testFileIds as $fileId )
		{
			$hashes = $this->metadataService->getHashes( $fileId );

			$this->assertNotEmpty( $hashes, "Hash entries should exist in oc_files_metadata for fileId $fileId." );
			$this->assertArrayHasKey( 'sha1', $hashes, "sha1 hash should be computed for fileId $fileId." );
			$this->assertNotEmpty( $hashes['sha1'] );
			$this->assertArrayHasKey( 'sha256', $hashes, "sha256 hash should be computed for fileId $fileId." );
			$this->assertNotEmpty( $hashes['sha256'] );
		}
	}

	/**
	 * Running Application::boot() twice must not reset the job's last_run.
	 */
	public function testBootTwiceDoesNotResetJobLastRun(): void
	{
		$jobClass = ProcessPendingUpdates::class;

		$originalRow = $this->fetchJobRow( $jobClass );
		$created     = false;

		if ( $originalRow === null )
		{
			$this->jobList->add( $jobClass );
			$originalRow = $this->fetchJobRow( $jobClass );
			$created     = true;
		}

		$sentinel = 2000000000;

		try
		{
			$this->setJobLastRun( $jobClass, $sentinel );

			$app     = new Application();
			$context = $this->createMock( IBootContext::class );

			$app->boot( $context );
			$app->boot( $context );

			$row = $this->fetchJobRow( $jobClass );
			$this->assertNotNull( $row, 'The ProcessPendingUpdates job row should exist.' );
			$this->assertSame(
				$sentinel,
				(int) $row['last_run'],
				'Application::boot() must not reset last_run.',
			);
		}
		finally
		{
			if ( $created )
			{
				$this->jobList->remove( $jobClass );
			}
			else
			{
				$this->setJobLastRun( $jobClass, (int) ( $originalRow['last_run'] ?? 0 ) );
			}
		}
	}

	/**
	 * A file the drain cannot read just now — here one this app's own lock
	 * is held on, as another hashing of it holds it — stays queued with its
	 * failed attempt counted, and processFile() says so by its return value:
	 * no exception tells its caller.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileThatCannotBeHashedStaysQueuedWithItsAttemptCounted(): void
	{
		$fileId = $this->createTestFile( 'fcias_locked_' . time() . '.dat' )
		               ->getId()
		;
		$this->addCatchAllForceRule();

		$this->beginTransaction();
		$this->insertPendingMarker( $fileId, MetadataService::PENDING_FORCE );
		$this->holdHashingLock( $fileId );

		$hashCalc = Server::get( HashCalculationService::class );

		$this->assertFalse( $hashCalc->processFile( $fileId, MetadataService::PENDING_MODE_FORCE ) );
		$this->assertFalse( $hashCalc->processFile( $fileId, MetadataService::PENDING_MODE_FORCE ) );

		$this->assertSame( 1, $this->countPendingRows( [ $fileId ] ), 'The file left the queue unhashed.' );
		$this->assertSame( 2, $this->failedAttemptsOf( $fileId ) );
		$this->assertArrayNotHasKey( 'sha1', $this->metadataService->getHashes( $fileId ) );
	}

	/**
	 * A lazy mark asks for the file's hashes to be dropped and nothing
	 * computed. Once the drain has done that, the file leaves the queue.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testALazyMarkLeavesTheQueue(): void
	{
		$fileId = $this->createTestFile( 'fcias_lazy_' . time() . '.dat' )
		               ->getId()
		;
		$this->addCatchAllForceRule();

		$this->beginTransaction();
		$this->insertPendingMarker( $fileId, MetadataService::PENDING_LAZY );

		$left = Server::get( HashCalculationService::class )
		              ->processFile( $fileId, MetadataService::PENDING_MODE_LAZY )
		;

		$this->assertSame( 0, $this->countPendingRows( [ $fileId ] ), 'The lazy mark stayed on the queue.' );
		$this->assertTrue( $left, 'processFile() reported the lazy file as still queued.' );
	}

	/**
	 * `queue:drain --all` with nothing left but a file it cannot hash: the
	 * walk tries it once and reaches the end of the queue, and the file is
	 * not counted as hashed.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testDrainAllEndsWhenTheOnlyFileLeftCannotBeHashed(): void
	{
		$fileId = $this->createTestFile( 'fcias_drain_locked_' . time() . '.dat' )
		               ->getId()
		;
		$this->addCatchAllForceRule();

		$this->beginTransaction();
		$this->insertPendingMarker( $fileId, MetadataService::PENDING_FORCE );
		$this->deleteOtherPendingRows( [ $fileId ] );
		$this->holdHashingLock( $fileId );

		[ $tester, $fetches ] = $this->drainCountingFetches();
		$tester->execute( [ '--all' => true ] );

		// The file, and the end of the queue behind it.
		$this->assertSame( 2, $fetches->count, 'queue:drain --all fetched the file it could not hash again.' );
		$this->assertSame( 1, $this->countPendingRows( [ $fileId ] ) );
		$this->assertSame( 1, $this->failedAttemptsOf( $fileId ) );
		$this->assertStringContainsString( 'Hashed 0 files, 1 failed.', $tester->getDisplay() );
	}

	/**
	 * Two files at the head of the queue that cannot be hashed fill the
	 * first batch: `--all` walks on past them and hashes the file behind.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testDrainAllTriesTheFilesBehindABatchThatFailedWhole(): void
	{
		// Created in this order, so the file that can be hashed has the
		// highest id and the walk reaches it last.
		$stuck  = [
			$this->createTestFile( 'fcias_walk_stuck_a_' . time() . '.dat' )
			     ->getId(),
			$this->createTestFile( 'fcias_walk_stuck_b_' . time() . '.dat' )
			     ->getId(),
		];
		$behind = $this->createTestFile( 'fcias_walk_behind_' . time() . '.dat' )
		               ->getId()
		;
		$this->addCatchAllForceRule();

		$this->beginTransaction();

		foreach ( [ ...$stuck, $behind ] as $fileId )
		{
			$this->insertPendingMarker( $fileId, MetadataService::PENDING_FORCE );
		}

		$this->deleteOtherPendingRows( [ ...$stuck, $behind ] );

		foreach ( $stuck as $fileId )
		{
			$this->holdHashingLock( $fileId );
		}

		[ $tester, $fetches ] = $this->drainCountingFetches();
		$tester->execute(
			[
				'--all'        => true,
				'--batch-size' => '2',
			],
		);

		$this->assertSame( 0, $this->countPendingRows( [ $behind ] ), 'The walk stopped before the file behind.' );
		$this->assertArrayHasKey( 'sha1', $this->metadataService->getHashes( $behind ) );
		$this->assertSame( 2, $this->countPendingRows( $stuck ) );
		$this->assertSame( 3, $fetches->count, 'Two stuck files, the file behind, and the end of the queue.' );
		$this->assertStringContainsString( 'Hashed 1 files, 2 failed.', $tester->getDisplay() );
	}

	/**
	 * Two files at the head of the queue that cannot be hashed, a batch of
	 * two, and a file behind them that can: within a few runs of the job,
	 * that file is hashed.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testFilesThatCannotBeHashedDoNotHoldUpTheRest(): void
	{
		// Created in this order, so the file that can be hashed has the
		// highest id and sorts last.
		$stuck  = [
			$this->createTestFile( 'fcias_stuck_a_' . time() . '.dat' )
			     ->getId(),
			$this->createTestFile( 'fcias_stuck_b_' . time() . '.dat' )
			     ->getId(),
		];
		$behind = $this->createTestFile( 'fcias_behind_' . time() . '.dat' )
		               ->getId()
		;
		$this->addCatchAllForceRule();
		$this->setBatchLimit( 2 );

		$this->beginTransaction();

		foreach ( [ ...$stuck, $behind ] as $fileId )
		{
			$this->insertPendingMarker( $fileId, MetadataService::PENDING_FORCE );
		}

		$this->deleteOtherPendingRows( [ ...$stuck, $behind ] );

		foreach ( $stuck as $fileId )
		{
			$this->holdHashingLock( $fileId );
		}

		$job        = $this->buildJob();
		$reflection = new ReflectionMethod( ProcessPendingUpdates::class, 'run' );

		for ( $run = 0; $run < 3; $run ++ )
		{
			$reflection->invoke( $job, null );
		}

		$this->assertSame(
			0,
			$this->countPendingRows( [ $behind ] ),
			'The file behind two that cannot be hashed was never reached.',
		);
		$this->assertArrayHasKey( 'sha1', $this->metadataService->getHashes( $behind ) );
		$this->assertSame( 2, $this->countPendingRows( $stuck ), 'The files that cannot be hashed left the queue.' );
	}

	// ─── helpers ──────────────────────────────────────────────────────
	/**
	 * `queue:drain` over the real queue and the real hashing, its fetches
	 * counted and guarded: after {@see FETCH_GUARD} the queue reads as
	 * empty, so that a drain that would fetch for ever fails its test rather
	 * than hanging it.
	 *
	 * @return array{0: CommandTester, 1: object{count: int}}
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function drainCountingFetches(): array
	{
		$real     = $this->metadataService;
		$fetches  = (object) [ 'count' => 0 ];
		$metadata = $this->createMock( MetadataService::class );
		$metadata->method( 'fetchPendingBatch' )
		         ->willReturnCallback(
			         static fn(
				         int  $limit,
				         ?int $after = null,
			         ): array => ++ $fetches->count <= self::FETCH_GUARD
				         ? $real->fetchPendingBatch( $limit, $after )
				         : [],
		         )
		;
		$metadata->method( 'getPendingStats' )
		         ->willReturnCallback( static fn() => $real->getPendingStats() )
		;

		return [
			new CommandTester(
				new Drain(
					$metadata,
					Server::get( HashCalculationService::class ),
					$this->appConfig,
					Server::get( LoggerInterface::class ),
				),
			),
			$fetches,
		];
	}

	/**
	 * Hold the lock the drain takes before it reads a file, as another
	 * hashing of that file would; released in tearDown().
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function holdHashingLock( int $fileId ): void
	{
		$path = 'files/' . $fileId;

		Server::get( ILockingProvider::class )
		      ->acquireLock( $path, ILockingProvider::LOCK_EXCLUSIVE )
		;

		$this->heldLocks[] = $path;
	}

	/**
	 * Set how many queued files a run of the job takes; tearDown() restores
	 * the instance's own.
	 */
	private function setBatchLimit( int $limit ): void
	{
		if ( $this->batchLimitBefore === false )
		{
			$this->batchLimitBefore = $this->appConfig->hasKey( Application::APP_ID, self::BATCH_LIMIT_KEY )
				? $this->appConfig->getValueInt( Application::APP_ID, self::BATCH_LIMIT_KEY )
				: null;
		}

		$this->appConfig->setValueInt( Application::APP_ID, self::BATCH_LIMIT_KEY, $limit );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function buildJob(): ProcessPendingUpdates
	{
		return new ProcessPendingUpdates(
			Server::get( ITimeFactory::class ),
			Server::get( HashCalculationService::class ),
			$this->metadataService,
			Server::get( RuleService::class ),
			$this->appConfig,
			$this->jobList,
			Server::get( JobStatsService::class ),
			Server::get( LoggerInterface::class ),
		);
	}

	/**
	 * Add a catch-all rule with mode=force whose algorithms the drain uses.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function addCatchAllForceRule(): void
	{
		// The only rule while the test runs; tearDown() puts the instance's
		// own back. Placed before them it would not be enough: the first
		// match decides, but in band order, and an unenforced `*` rule is the
		// last band — any enabled rule of the instance's for home folders,
		// a group or the account itself would govern the file instead, with
		// its own algorithms.
		//
		// Written in the selector model, which is what the drain reads. This
		// used to carry `userScope: all` and neither a type nor an algorithm
		// list — the shape from before selectors, when the drain hashed with
		// every supported algorithm regardless of what the rule asked for.
		// It now resolves the governing rule at action time and takes its
		// list, so a rule naming none is a rule that computes nothing.
		$rules = [
			[
				'id'       => 'fcias_inttest_catchall',
				'enabled'  => true,
				'type'     => 'include',
				'path'     => '**',
				'mode'     => MetadataService::PENDING_MODE_FORCE,
				'selector' => '*',
				'algos'    => [
					'sha1',
					'sha256',
				],
			],
		];

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::RULE_CONFIG_KEY,
			json_encode( $rules, JSON_THROW_ON_ERROR ),
		);

		// The decoded list is memoised per process and invalidated only
		// through saveRules(), which writing the config key directly goes
		// around. Without this the drain resolves against the list as it was
		// before this rule existed — reads no include rule, drops both marks
		// without hashing, and the test fails on an empty hash set rather
		// than on the rule it thought it had installed.
		$this->ruleService->loadRules( refresh: true );
	}

	/**
	 * Create a real test file in the admin user's storage.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function createTestFile( string $name ): File
	{
		$userFolder = Server::get( IRootFolder::class )
		                    ->getUserFolder( 'admin' )
		;

		$file = $userFolder->newFile( $name, 'FCIAS cron integration — ' . microtime( true ) );

		$this->cleanupFiles[]   = $file;
		$this->cleanupFileIds[] = $file->getId();

		return $file;
	}

	/**
	 * A stamp row of 0 and the marker's state row, as `markPending()` gives a
	 * file the app has not considered before.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function insertPendingMarker(
		int    $fileId,
		string $marker,
	): void
	{
		foreach (
			[
				MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT => '',
				MetadataService::KEY_FILE_CHECKSUM_STATE      => $marker,
			] as $key => $value
		)
		{
			$this->getRawConnection()
			     ->executeStatement(
				     'INSERT INTO `*PREFIX*files_metadata_index` (`file_id`, `meta_key`, `meta_value_string`, `meta_value_int`) VALUES (?, ?, ?, 0)',
				     [
					     $fileId,
					     $key,
					     $value,
				     ],
			     )
			;
		}
	}

	/**
	 * Remove every pending row except those belonging to $keepFileIds.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function deleteOtherPendingRows( array $keepFileIds ): void
	{
		$placeholders = implode( ',', array_fill( 0, count( $keepFileIds ), '?' ) );

		$this->getRawConnection()
		     ->executeStatement(
			     "DELETE FROM `*PREFIX*files_metadata_index` WHERE `meta_key` = 'file-checksum-state' AND `meta_value_string` LIKE 'pending:%' AND `file_id` NOT IN ($placeholders)",
			     $keepFileIds,
		     )
		;
	}

	/**
	 * A queued file's failed attempts, as its state row counts them; null
	 * when it is not queued.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function failedAttemptsOf( int $fileId ): ?int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select( MetadataService::FIELD_META_VALUE_INT )
		   ->from( MetadataService::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq( MetadataService::FIELD_FILE_ID, $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->eq(
				      MetadataService::FIELD_META_KEY,
				      $qb->createNamedParameter( MetadataService::KEY_FILE_CHECKSUM_STATE ),
			      ),
			   $qb->expr()
			      ->like(
				      MetadataService::FIELD_META_VALUE_STRING,
				      $qb->createNamedParameter( MetadataService::PENDING_LIKE ),
			      ),
		   )
		;

		$attempts = $qb->executeQuery()
		               ->fetchOne()
		;

		return $attempts === false
			? null
			: (int) $attempts;
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function countPendingRows( array $fileIds ): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select(
			$qb->func()
			   ->count( '*', 'cnt' ),
		)
		   ->from( MetadataService::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq(
				      MetadataService::FIELD_META_KEY,
				      $qb->createNamedParameter( MetadataService::KEY_FILE_CHECKSUM_STATE ),
			      ),
			   $qb->expr()
			      ->like(
				      MetadataService::FIELD_META_VALUE_STRING,
				      $qb->createNamedParameter( MetadataService::PENDING_LIKE ),
			      ),
			   $qb->expr()
			      ->in(
				      MetadataService::FIELD_FILE_ID,
				      $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
			      ),
		   )
		;

		return (int) $qb->executeQuery()
		                ->fetchOne()
		;
	}

	/**
	 * @return array{id: int, class: string, last_run: int}|null
	 * @noinspection PhpUnhandledExceptionInspection
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	private function fetchJobRow( string $class ): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select( 'id', 'class', 'last_run' )
		   ->from( 'jobs' )
		   ->where(
			   $qb->expr()
			      ->eq( 'class', $qb->createNamedParameter( $class ) ),
		   )
		;

		$result = $qb->executeQuery();
		$row    = $result->fetchAssociative();
		$result->closeCursor();

		return $row === false
			? null
			: [
				'id'       => (int) $row['id'],
				'class'    => (string) $row['class'],
				'last_run' => (int) $row['last_run'],
			];
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function setJobLastRun(
		string $class,
		int    $lastRun,
	): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update( 'jobs' )
		   ->set( 'last_run', $qb->createNamedParameter( $lastRun, IQueryBuilder::PARAM_INT ) )
		   ->where(
			   $qb->expr()
			      ->eq( 'class', $qb->createNamedParameter( $class ) ),
		   )
		;

		$qb->executeStatement();
	}
}
