<?php
/** @noinspection SqlNoDataSourceInspection */

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\CronGenerateHashes;
use OCA\FileChecksumSearch\Listener\AppDisableListener;
use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Service\CronJobService;
use OCA\FileChecksumSearch\Service\TriggerInitializationService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\App\Events\AppDisableEvent;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Integration tests for cron job backup/restore on app disable-enable cycle.
 *
 * Architecture:
 * - On app disable, AppDisableListener::handle() calls CronJobService::backup()
 *   which serialises definitions to IAppConfig key `cron_job_definitions`.
 * - On app enable (boot), Application::boot() calls CronJobService::restore()
 *   which re-registers enabled definitions from the backup.
 *
 * These tests verify the full cycle against a real database.
 */
class AppDisableListenerTest
	extends
	DatabaseTestCase
{

	private IJobList        $jobList;

	private IAppConfig      $appConfig;

	private CronJobService  $cronJobService;

	private LoggerInterface $logger;

	/** @var list<int> IDs of CronGenerateHashes jobs present before the test run. */
	private array $preExistingJobIds = [];

	/** @var list<int> IDs created by the current test (cleaned up in tearDown). */
	private array $testJobIds = [];


	protected function setUp(): void
	{

		parent::setUp();

		/** @noinspection PhpUnhandledExceptionInspection */
		$this->jobList = Server::get( IJobList::class );
		/** @noinspection PhpUnhandledExceptionInspection */
		$this->appConfig = Server::get( IAppConfig::class );
		/** @noinspection PhpUnhandledExceptionInspection */
		$this->logger = Server::get( LoggerInterface::class );

		$this->cronJobService = new CronJobService(
			$this->jobList,
			$this->appConfig,
			$this->db,
			$this->logger,
		);

		// Snapshot pre-existing CronGenerateHashes job IDs so we
		// can clean up only the ones created during each test.
		$this->preExistingJobIds = $this->collectJobIds();

		// Ensure a clean backup state before each test.
		$this->clearBackupConfig();
	}


	protected function tearDown(): void
	{

		// Delete only the jobs created by this test.
		$postRunIds = $this->collectJobIds();
		$toDelete   = array_diff( $postRunIds, $this->preExistingJobIds );

		foreach ( $toDelete as $id )
		{
			try
			{
				$this->jobList->removeById( (string) $id );
			}
			catch ( Throwable )
			{
			}
		}

		$this->clearBackupConfig();

		parent::tearDown();
	}


	// ─── Backup Tests ─────────────────────────────────────────────────

	public function testBackupStoresDefinitionsInAppConfig(): void
	{

		$this->clearAllTestJobs();

		$this->createTestJob( [
			'userScope' => 'all',
			'path'      => '/Photos',
			'algo'      => 'sha256',
			'batchSize' => 50,
			'interval'  => 3600,
			'enabled'   => true,
			'_v'        => CronGenerateHashes::ARG_VERSION,
		] );

		$this->cronJobService->backup();

		$raw = $this->appConfig->getValueString(
			Application::APP_ID,
			'cron_job_definitions',
			'[]',
		);

		$definitions = json_decode( $raw, true );
		$this->assertIsArray( $definitions, 'Backup should decode as array.' );
		$this->assertCount( 1, $definitions, 'One definition should be backed up.' );

		$def = $definitions[0];
		$this->assertArrayNotHasKey( 'id', $def, 'Backup must not contain oc_jobs.id.' );
		$this->assertSame( 'all', $def['userScope'] ?? null );
		$this->assertSame( '/Photos', $def['path'] ?? null );
		$this->assertSame( 'sha256', $def['algo'] ?? null );
		$this->assertSame( 50, $def['batchSize'] ?? null );
		$this->assertSame( 3600, $def['interval'] ?? null );
		$this->assertTrue( $def['enabled'] ?? false );
	}


	public function testBackupWithMultipleJobsStoresAll(): void
	{

		$this->clearAllTestJobs();

		$this->createTestJob( [
			'userScope' => 'user1',
			'path'      => '/Documents',
			'algo'      => 'sha1',
			'batchSize' => 100,
			'interval'  => 600,
			'enabled'   => true,
			'_v'        => CronGenerateHashes::ARG_VERSION,
		] );
		$this->createTestJob( [
			'userScope' => 'user2',
			'path'      => '/Videos',
			'algo'      => 'md5',
			'batchSize' => 200,
			'interval'  => 1800,
			'enabled'   => true,
			'_v'        => CronGenerateHashes::ARG_VERSION,
		] );

		$this->cronJobService->backup();

		$raw         = $this->appConfig->getValueString( Application::APP_ID, 'cron_job_definitions', '[]' );
		$definitions = json_decode( $raw, true );

		$this->assertIsArray( $definitions );
		$this->assertCount( 2, $definitions, 'Both definitions should be backed up.' );
	}


	public function testBackupWithNoJobsStoresEmptyArray(): void
	{

		// Ensure no jobs exist.
		$this->clearAllTestJobs();

		$this->cronJobService->backup();

		$raw = $this->appConfig->getValueString( Application::APP_ID, 'cron_job_definitions', '[]' );
		$this->assertSame( '[]', $raw, 'Empty backup should store "[]".' );
	}


	// ─── Restore Tests ────────────────────────────────────────────────

	public function testRestoreReRegistersEnabledJobs(): void
	{

		// Seed backup directly via IAppConfig.
		$this->appConfig->setValueString(
			Application::APP_ID,
			'cron_job_definitions',
			json_encode( [
				[
					'userScope' => 'all',
					'path'      => '/Restored',
					'algo'      => 'sha256',
					'batchSize' => 75,
					'interval'  => 900,
					'enabled'   => true,
					'_v'        => CronGenerateHashes::ARG_VERSION,
				],
			], JSON_UNESCAPED_SLASHES ),
		);

		// Clear any existing jobs to simulate post-disable state.
		$this->clearAllTestJobs();

		$count = $this->cronJobService->restore();
		$this->assertSame( 1, $count, 'One job should be restored.' );

		$definitions = $this->cronJobService->listDefinitions();
		$this->assertCount( 1, $definitions, 'One job should exist after restore.' );

		$def = $definitions[0];
		$this->assertSame( 'all', $def['userScope'] ?? null );
		$this->assertSame( '/Restored', $def['path'] ?? null );
		$this->assertSame( 'sha256', $def['algo'] ?? null );
		$this->assertSame( 75, $def['batchSize'] ?? null );
		$this->assertSame( 900, $def['interval'] ?? null );
		$this->assertTrue( $def['enabled'] ?? false );

		// Verify backup is cleared after successful restore.
		$raw = $this->appConfig->getValueString( Application::APP_ID, 'cron_job_definitions', 'not found' );
		$this->assertSame( '[]', $raw, 'Backup should be cleared after restore.' );
	}


	public function testRestoreSkipsDisabledJobs(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'cron_job_definitions',
			json_encode( [
				[
					'userScope' => 'all',
					'path'      => '/Disabled',
					'algo'      => 'sha1',
					'batchSize' => 50,
					'interval'  => 300,
					'enabled'   => false,
					'_v'        => CronGenerateHashes::ARG_VERSION,
				],
				[
					'userScope' => 'all',
					'path'      => '/Enabled',
					'algo'      => 'md5',
					'batchSize' => 100,
					'interval'  => 600,
					'enabled'   => true,
					'_v'        => CronGenerateHashes::ARG_VERSION,
				],
			], JSON_UNESCAPED_SLASHES ),
		);

		$this->clearAllTestJobs();

		$count = $this->cronJobService->restore();
		$this->assertSame( 1, $count, 'Only the enabled job should be restored.' );

		$definitions = $this->cronJobService->listDefinitions();
		$this->assertCount( 1, $definitions );
		$this->assertSame( '/Enabled', $definitions[0]['path'] ?? null );
	}


	public function testRestoreWithNoBackupReturnsZero(): void
	{

		$this->clearBackupConfig();

		$count = $this->cronJobService->restore();
		$this->assertSame( 0, $count, 'Restore with empty backup should return 0.' );
	}


	public function testRestoreHandlesMalformedJson(): void
	{

		$this->appConfig->setValueString( Application::APP_ID, 'cron_job_definitions', '{invalid}' );

		$count = $this->cronJobService->restore();
		$this->assertSame( 0, $count, 'Malformed JSON should not restore any jobs.' );
	}


	// ─── Full Cycle Tests ─────────────────────────────────────────────

	public function testFullCycleBackupAndRestorePreservesProperties(): void
	{

		$original = [
			'userScope' => 'all',
			'path'      => '/FullCycle',
			'algo'      => 'sha256',
			'batchSize' => 42,
			'interval'  => 1200,
			'enabled'   => true,
			'_v'        => CronGenerateHashes::ARG_VERSION,
		];

		$this->createTestJob( $original );

		// Backup.
		$this->cronJobService->backup();

		$raw    = $this->appConfig->getValueString( Application::APP_ID, 'cron_job_definitions', '[]' );
		$backup = json_decode( $raw, true );
		$this->assertCount( 1, $backup, 'One definition should be in backup.' );

		// Clear jobs (simulate NC dropping them on disable).
		$this->clearAllTestJobs();
		$this->assertCount( 0, $this->cronJobService->listDefinitions(), 'Jobs should be cleared.' );

		// Restore.
		$count = $this->cronJobService->restore();
		$this->assertSame( 1, $count, 'One job should be restored.' );

		$definitions = $this->cronJobService->listDefinitions();
		$this->assertCount( 1, $definitions, 'One job should exist after restore.' );

		$restored = $definitions[0];

		// Verify all application-level properties survived the round-trip.
		// oc_jobs.id changes (new ID assigned), but everything else must match.
		$this->assertSame( $original['userScope'], $restored['userScope'], 'userScope must survive round-trip.' );
		$this->assertSame( $original['path'], $restored['path'], 'path must survive round-trip.' );
		$this->assertSame( $original['algo'], $restored['algo'], 'algo must survive round-trip.' );
		$this->assertSame( $original['batchSize'], $restored['batchSize'], 'batchSize must survive round-trip.' );
		$this->assertSame( $original['interval'], $restored['interval'], 'interval must survive round-trip.' );
		$this->assertTrue( $restored['enabled'], 'enabled must survive round-trip.' );
		$this->assertSame( $original['_v'], $restored['_v'] ?? null, '_v must survive round-trip.' );
	}


	public function testFullCycleWithMultipleJobs(): void
	{

		$job1 = [
			'userScope' => 'user_a',
			'path'      => '/A',
			'algo'      => 'sha1',
			'batchSize' => 10,
			'interval'  => 300,
			'enabled'   => true,
			'_v'        => CronGenerateHashes::ARG_VERSION,
		];
		$job2 = [
			'userScope' => 'user_b',
			'path'      => '/B',
			'algo'      => 'sha256',
			'batchSize' => 20,
			'interval'  => 600,
			'enabled'   => true,
			'_v'        => CronGenerateHashes::ARG_VERSION,
		];
		$job3 = [
			'userScope' => 'user_c',
			'path'      => '/C',
			'algo'      => 'md5',
			'batchSize' => 30,
			'interval'  => 900,
			'enabled'   => false,
			'_v'        => CronGenerateHashes::ARG_VERSION,
		];

		$this->createTestJob( $job1 );
		$this->createTestJob( $job2 );
		$this->createTestJob( $job3 );

		$this->cronJobService->backup();

		$this->clearAllTestJobs();
		$this->assertCount( 0, $this->cronJobService->listDefinitions() );

		$count = $this->cronJobService->restore();
		$this->assertSame( 2, $count, 'Only enabled jobs (2 of 3) should be restored.' );

		$definitions = $this->cronJobService->listDefinitions();
		$this->assertCount( 2, $definitions );

		$paths = array_column( $definitions, 'path' );
		sort( $paths );
		$this->assertSame(
			[
				'/A',
				'/B',
			],
			$paths,
			'Only enabled job paths should be restored.',
		);
	}


	// ─── AppDisableListener Integration ───────────────────────────────

	public function testListenerHandleBacksUpCronJobs(): void
	{

		$this->clearAllTestJobs();

		$this->createTestJob( [
			'userScope' => 'all',
			'path'      => '/ListenerTest',
			'algo'      => 'sha1',
			'batchSize' => 25,
			'interval'  => 1800,
			'enabled'   => true,
			'_v'        => CronGenerateHashes::ARG_VERSION,
		] );

		// TriggerInitializationService is readonly — use real instance from container.
		/** @noinspection PhpUnhandledExceptionInspection */
		$triggerInitService = Server::get( TriggerInitializationService::class );

		// LifecycleHandler is not readonly — mock to avoid DB trigger side effects.
		$lifecycleHandler = $this->createMock( LifecycleHandler::class );
		$lifecycleHandler->expects( $this->once() )
		                 ->method( 'stripTriggers' )
		;

		$listener = new AppDisableListener(
			$lifecycleHandler,
			$triggerInitService,
			$this->cronJobService,
			$this->logger,
		);

		$event = $this->createMock( AppDisableEvent::class );
		$event->method( 'getAppId' )
		      ->willReturn( Application::APP_ID )
		;

		$listener->handle( $event );

		// Verify backup was written.
		$raw         = $this->appConfig->getValueString( Application::APP_ID, 'cron_job_definitions', '[]' );
		$definitions = json_decode( $raw, true );

		$this->assertIsArray( $definitions );
		$this->assertCount( 1, $definitions, 'Listener should back up one definition.' );
		$this->assertArrayNotHasKey( 'id', $definitions[0] );
		$this->assertSame( '/ListenerTest', $definitions[0]['path'] ?? null );

		// Clean up: re-deploy triggers that markUndeployed may have toggled.
		/** @noinspection PhpUnhandledExceptionInspection */
		Server::get( TriggerInitializationService::class )
		      ->deployIfNeeded( Application::APP_ID )
		;
	}


	public function testListenerIgnoresNonFileChecksumSearchApp(): void
	{

		// TriggerInitializationService is readonly — use real instance.
		// The listener returns early when app ID doesn't match, so
		// markUndeployed won't be called.
		/** @noinspection PhpUnhandledExceptionInspection */
		$triggerInitService = Server::get( TriggerInitializationService::class );

		$lifecycleHandler = $this->createMock( LifecycleHandler::class );
		$lifecycleHandler->expects( $this->never() )
		                 ->method( 'stripTriggers' )
		;

		$listener = new AppDisableListener(
			$lifecycleHandler,
			$triggerInitService,
			$this->cronJobService,
			$this->logger,
		);

		$event = $this->createMock( AppDisableEvent::class );
		$event->method( 'getAppId' )
		      ->willReturn( 'some_other_app' )
		;

		// Should not throw and should not trigger backup.
		$listener->handle( $event );

		// Backup should be unchanged (still the empty default from setUp).
		$raw = $this->appConfig->getValueString( Application::APP_ID, 'cron_job_definitions', 'unreachable' );
		$this->assertSame( '[]', $raw, 'Backup should not be modified for other apps.' );
	}


	// ─── Job Properties Round-Trip ────────────────────────────────────


	/**
	 * @return array<string, array{userScope: string, path: string, algo: string, batchSize: int, interval: int,
	 *                       enabled: bool}>
	 */
	public static function jobPropertyProvider(): array
	{

		return [
			'sha1 default path'   => [
				[
					'userScope' => 'all',
					'path'      => '/',
					'algo'      => 'sha1',
					'batchSize' => 100,
					'interval'  => 300,
					'enabled'   => true,
				],
			],
			'sha256 custom path'  => [
				[
					'userScope' => 'userX',
					'path'      => '/data/projects',
					'algo'      => 'sha256',
					'batchSize' => 200,
					'interval'  => 3600,
					'enabled'   => true,
				],
			],
			'md5 large batch'     => [
				[
					'userScope' => 'all',
					'path'      => '/archive',
					'algo'      => 'md5',
					'batchSize' => 500,
					'interval'  => 7200,
					'enabled'   => true,
				],
			],
			'sha512 disabled'     => [
				[
					'userScope' => 'all',
					'path'      => '/tmp',
					'algo'      => 'sha512',
					'batchSize' => 50,
					'interval'  => 60,
					'enabled'   => false,
				],
			],
			'sha1 short interval' => [
				[
					'userScope' => 'all',
					'path'      => '/fast',
					'algo'      => 'sha1',
					'batchSize' => 10,
					'interval'  => 60,
					'enabled'   => true,
				],
			],
		];
	}


	/**
	 * @dataProvider jobPropertyProvider
	 */
	public function testJobPropertiesSurviveRoundTrip( array $props ): void
	{

		$props['_v']      = CronGenerateHashes::ARG_VERSION;
		$props['enabled'] = $props['enabled'] ?? true;

		$this->createTestJob( $props );

		$this->cronJobService->backup();
		$this->clearAllTestJobs();
		$count = $this->cronJobService->restore();

		if ( $props['enabled'] )
		{
			$this->assertSame( 1, $count, 'Enabled job should be restored.' );

			$definitions = $this->cronJobService->listDefinitions();
			$this->assertCount( 1, $definitions );

			$r = $definitions[0];
			$this->assertSame( $props['userScope'], $r['userScope'] );
			$this->assertSame( $props['path'], $r['path'] );
			$this->assertSame( $props['algo'], $r['algo'] );
			$this->assertSame( $props['batchSize'], $r['batchSize'] );
			$this->assertSame( $props['interval'], $r['interval'] );
			$this->assertTrue( $r['enabled'] );
		}
		else
		{
			$this->assertSame( 0, $count, 'Disabled job should not be restored.' );
		}
	}


	// ─── Edge Cases ───────────────────────────────────────────────────

	public function testIdempotentRestoreClearsBackup(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'cron_job_definitions',
			json_encode( [
				[
					'userScope' => 'all',
					'path'      => '/Idempotent',
					'algo'      => 'sha1',
					'batchSize' => 50,
					'interval'  => 300,
					'enabled'   => true,
					'_v'        => CronGenerateHashes::ARG_VERSION,
				],
			], JSON_UNESCAPED_SLASHES ),
		);

		$this->clearAllTestJobs();

		// First restore.
		$count1 = $this->cronJobService->restore();
		$this->assertSame( 1, $count1 );

		// Second restore (backup should now be empty).
		$count2 = $this->cronJobService->restore();
		$this->assertSame( 0, $count2, 'Second restore should find empty backup.' );

		// Only one job should exist (not duplicated).
		$this->assertCount( 1, $this->cronJobService->listDefinitions() );
	}


	// ─── Helpers ──────────────────────────────────────────────────────


	/**
	 * Create a test job via CronJobService and track its ID for cleanup.
	 */
	private function createTestJob( array $definition ): void
	{

		$beforeIds = $this->collectJobIds();

		$this->cronJobService->saveDefinition( $definition );

		$afterIds = $this->collectJobIds();
		$newIds   = array_diff( $afterIds, $beforeIds );

		foreach ( $newIds as $id )
		{
			if ( ! in_array( $id, $this->testJobIds, true ) )
			{
				$this->testJobIds[] = $id;
			}
		}
	}


	/**
	 * @return list<int>
	 */
	private function collectJobIds(): array
	{

		$ids = [];

		foreach ( $this->jobList->getJobsIterator( CronGenerateHashes::class, null, 0 ) as $job )
		{
			$ids[] = $job->getId();
		}

		return $ids;
	}


	/**
	 * Delete all CronGenerateHashes jobs from oc_jobs.
	 *
	 * Used to simulate the NC app-disable cleanup where NC drops
	 * all jobs belonging to the disabled app.
	 */
	private function clearAllTestJobs(): void
	{

		$ids = $this->collectJobIds();

		foreach ( $ids as $id )
		{
			try
			{
				$this->jobList->removeById( (string) $id );
			}
			catch ( Throwable )
			{
			}
		}

		$this->testJobIds = [];
	}


	/**
	 * Reset the cron_job_definitions backup key to its default.
	 */
	private function clearBackupConfig(): void
	{

		$this->appConfig->setValueString( Application::APP_ID, 'cron_job_definitions', '[]' );
	}

}
