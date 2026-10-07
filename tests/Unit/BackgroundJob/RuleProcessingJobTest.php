<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\Service\DatabaseService;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\App\IAppManager;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\ProcessPendingUpdates;
use OCA\FileChecksumSearch\BackgroundJob\RuleProcessingJob;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

class RuleProcessingJobTest
    extends
    TestCase
{

//  private properties

	private MockObject|ITimeFactory    $time;

	private MockObject|RuleService     $ruleService;

	private MockObject|MetadataService $metadataService;

	private MockObject|IAppConfig      $appConfig;

	private MockObject|IJobList        $jobList;

	private MockObject|JobStatsService $jobStats;

	private MockObject|LoggerInterface $logger;

	private RuleProcessingJob          $job;

	/**
	 * When the checksum count last ran, as the job-stats mock reports it.
	 * The mocked clock stands at zero, so zero means "just counted" and the
	 * count is not due; null means never counted, and it is.
	 */
	private ?int                       $countLastRun = 0;

	/** The background count's switch, as the config mock reports it. */
	private bool                       $countInBackground = false;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->time            = $this->createMock( ITimeFactory::class );
		$this->ruleService     = $this->createMock( RuleService::class );
		$this->metadataService = $this->createMock( MetadataService::class );
		$this->appConfig       = $this->createMock( IAppConfig::class );
		$this->jobList         = $this->createMock( IJobList::class );
		$this->jobStats        = $this->createMock( JobStatsService::class );
		$this->logger          = $this->createMock( LoggerInterface::class );

		// Answered by key rather than pinned with with(): the purge gate in
		// run() reads its own clock and interval through the same method, and
		// the mocked clock stands at zero, so by default the purge is never
		// due and the rule-sweep tests below see exactly what they always did.
		$this->appConfig->method( 'getValueInt' )
		                ->willReturnCallback(
			                static fn( string $app, string $key, int $default ): int => $default,
		                )
		;
		$this->appConfig->method( 'getValueBool' )
		                ->willReturnCallback(
			                fn( string $app, string $key ): bool => $key === 'checksum_count_background'
				                && $this->countInBackground,
		                )
		;

		// The same for the checksum count: not due unless a test says so,
		// so the tests above it see only the stats they always did.
		$this->jobStats->method( 'lastRuns' )
		               ->willReturnCallback(
			               fn(): array => [
				               JobStatsService::JOB_CHECKSUM_COUNT => [
					               'lastRun' => $this->countLastRun,
					               'counts'  => [ 'rows' => 1 ],
				               ],
			               ],
		               )
		;

		$this->job = new RuleProcessingJob(
			$this->time,
			$this->ruleService,
			$this->metadataService,
			$this->appConfig,
			$this->jobList,
			$this->jobStats,
			$this->logger,
			$this->statusService(),
		);
	}


//  other non-static methods

	/**
	 * StatusService is readonly, so not a mock: a real one over this test's
	 * own metadata and job-stats mocks, which is where the count is read and
	 * recorded.
	 */
	private function statusService(): StatusService
	{
		return new StatusService(
			$this->createMock( DatabaseService::class ),
			$this->createMock( TableNameService::class ),
			$this->createMock( IAppManager::class ),
			$this->metadataService,
			$this->jobStats,
			$this->time,
			$this->appConfig,
		);
	}

	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testJobConstructsWithDefaultInterval(): void
	{
		$this->appConfig->expects( $this->once() )
		                ->method( 'getValueInt' )
		                ->with( Application::APP_ID, 'rule_processing_interval', 300 )
		;

		$job = new RuleProcessingJob(
			$this->time,
			$this->ruleService,
			$this->metadataService,
			$this->appConfig,
			$this->jobList,
			$this->jobStats,
			$this->logger,
			$this->statusService(),
		);

		$this->assertInstanceOf( RuleProcessingJob::class, $job );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRunDelegatesToRuleService(): void
	{
		$this->ruleService->expects( $this->once() )
		                  ->method( 'evaluateRules' )
		                  ->willReturn( [
			                  'marked'  => 0,
			                  'matched' => 0,
		                  ] )
		;

		// No marks → no dispatch
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;

		$reflection = new ReflectionMethod( RuleProcessingJob::class, 'run' );
		$reflection->invoke( $this->job, null );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRunDispatchesWhenMarked(): void
	{
		$this->ruleService->expects( $this->once() )
		                  ->method( 'evaluateRules' )
		                  ->willReturn( [
			                  'marked'  => 5,
			                  'matched' => 10,
		                  ] )
		;

		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with( ProcessPendingUpdates::class )
		;

		$reflection = new ReflectionMethod( RuleProcessingJob::class, 'run' );
		$reflection->invoke( $this->job, null );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRunCatchesThrowableAndLogsError(): void
	{
		$this->ruleService->expects( $this->once() )
		                  ->method( 'evaluateRules' )
		                  ->willThrowException( new RuntimeException( 'DB down' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		// The sweep's failure on the status, its last success left as it was.
		$this->jobStats->expects( $this->once() )
		               ->method( 'recordFailure' )
		               ->with(
			               JobStatsService::JOB_RULE_SWEEP,
			               $this->isInstanceOf( RuntimeException::class ),
			               $this->isType( 'int' ),
		               )
		;

		$reflection = new ReflectionMethod( RuleProcessingJob::class, 'run' );
		$reflection->invoke( $this->job, null );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRunRecordsItsStats(): void
	{
		$this->ruleService->method( 'evaluateRules' )
		                  ->willReturn( [
			                  'marked'  => 3,
			                  'matched' => 12,
		                  ] )
		;

		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with(
			               JobStatsService::JOB_RULE_SWEEP,
			               [
				               'matched' => 12,
				               'marked'  => 3,
			               ],
		               )
		;

		$reflection = new ReflectionMethod( RuleProcessingJob::class, 'run' );
		$reflection->invoke( $this->job, null );
	}

	public function testThePurgeIsNotRunBeforeItsIntervalHasPassed(): void
	{
		$this->ruleService->method( 'evaluateRules' )
		                  ->willReturn( [ 'marked' => 0, 'matched' => 0 ] )
		;

		// The clock stands a minute past the (default, zero) last run and the
		// interval is a day. Moved via the clock rather than by re-stubbing
		// getValueInt(): setUp() already answers that, and PHPUnit keeps the
		// first stub it was given.
		$this->time->method( 'getTime' )
		           ->willReturn( 60 )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'purgeOrphanedMetadata' )
		;

		( new ReflectionMethod( RuleProcessingJob::class, 'run' ) )->invoke( $this->job, null );
	}

	/**
	 * Due, with a backlog that takes two batches: both are run, the clock is
	 * booked, and the heartbeat carries the totals.
	 */
	public function testADuePurgeRunsInBatchesAndBooksTheDay(): void
	{
		$this->ruleService->method( 'evaluateRules' )
		                  ->willReturn( [ 'marked' => 0, 'matched' => 0 ] )
		;
		$this->time->method( 'getTime' )
		           ->willReturn( 1_700_100_000 )
		;

		// Default clock (0) and default interval: a day has passed since never.
		// Batch limit default 50: the first batch is full, the second is not.
		$this->metadataService->expects( $this->exactly( 2 ) )
		                      ->method( 'purgeOrphanedMetadata' )
		                      ->with( 50 )
		                      ->willReturnOnConsecutiveCalls( 50, 7 )
		;

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueInt' )
		                ->with( Application::APP_ID, RuleProcessingJob::ORPHAN_PURGE_LAST_RUN, 1_700_100_000 )
		;

		$recorded = [];
		$this->jobStats->method( 'record' )
		               ->willReturnCallback(
			               static function( string $job, array $counts ) use ( &$recorded ): void
			               {
				               $recorded[ $job ] = $counts;
			               },
		               )
		;

		( new ReflectionMethod( RuleProcessingJob::class, 'run' ) )->invoke( $this->job, null );

		$this->assertSame(
			[ 'purged' => 57, 'batches' => 2 ],
			$recorded[ JobStatsService::JOB_ORPHAN_PURGE ] ?? null,
		);
	}

	/**
	 * A run that hits its batch cap has not emptied the backlog, so it leaves
	 * the clock alone: the next tick carries on instead of waiting a day.
	 */
	public function testAPurgeThatHitsItsCapDoesNotBookTheDay(): void
	{
		$this->ruleService->method( 'evaluateRules' )
		                  ->willReturn( [ 'marked' => 0, 'matched' => 0 ] )
		;
		$this->time->method( 'getTime' )
		           ->willReturn( 1_700_100_000 )
		;

		$this->metadataService->expects( $this->exactly( RuleProcessingJob::ORPHAN_PURGE_MAX_BATCHES ) )
		                      ->method( 'purgeOrphanedMetadata' )
		                      ->willReturn( 50 )
		;

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueInt' )
		;

		( new ReflectionMethod( RuleProcessingJob::class, 'run' ) )->invoke( $this->job, null );
	}

	/**
	 * A purge that fails books neither the day nor a run: its failure goes
	 * on the status, the clock stays, and the next tick tries again.
	 */
	public function testAFailedPurgeRecordsItsFailure(): void
	{
		$this->ruleService->method( 'evaluateRules' )
		                  ->willReturn( [ 'marked' => 0, 'matched' => 0 ] )
		;
		$this->time->method( 'getTime' )
		           ->willReturn( 1_700_100_000 )
		;
		$this->metadataService->method( 'purgeOrphanedMetadata' )
		                      ->willReturnOnConsecutiveCalls( 50, $this->throwException( new RuntimeException( 'DB down' ) ) )
		;

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueInt' )
		;
		$recorded = [];
		$this->jobStats->method( 'record' )
		               ->willReturnCallback(
			               static function( string $job ) use ( &$recorded ): void
			               {
				               $recorded[] = $job;
			               },
		               )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'recordFailure' )
		               ->with(
			               JobStatsService::JOB_ORPHAN_PURGE,
			               $this->isInstanceOf( RuntimeException::class ),
			               $this->isType( 'int' ),
		               )
		;

		( new ReflectionMethod( RuleProcessingJob::class, 'run' ) )->invoke( $this->job, null );

		$this->assertNotContains( JobStatsService::JOB_ORPHAN_PURGE, $recorded );
	}

	/**
	 * Switched on and never counted, so due: the job counts the indexed
	 * checksums and keeps the count as the checksum count's stats, which is
	 * where the status reads it.
	 */
	public function testADueChecksumCountIsTakenAndKept(): void
	{
		$this->countInBackground = true;
		$this->countLastRun      = null;
		$this->ruleService->method( 'evaluateRules' )
		                  ->willReturn( [ 'marked' => 0, 'matched' => 0 ] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'countHashEntries' )
		                      ->willReturn( 406_419 )
		;

		$recorded = [];
		$this->jobStats->method( 'record' )
		               ->willReturnCallback(
			               static function( string $job, array $counts ) use ( &$recorded ): void
			               {
				               $recorded[ $job ] = $counts;
			               },
		               )
		;

		( new ReflectionMethod( RuleProcessingJob::class, 'run' ) )->invoke( $this->job, null );

		$this->assertSame( [ 'rows' => 406_419 ], $recorded[ JobStatsService::JOB_CHECKSUM_COUNT ] ?? null );
	}

	/**
	 * Due but switched off, the default: the job leaves the count to the
	 * status, which takes it when it is asked.
	 */
	public function testAChecksumCountIsNotTakenWithTheBackgroundCountOff(): void
	{
		$this->countLastRun = null;
		$this->ruleService->method( 'evaluateRules' )
		                  ->willReturn( [ 'marked' => 0, 'matched' => 0 ] )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'countHashEntries' )
		;

		( new ReflectionMethod( RuleProcessingJob::class, 'run' ) )->invoke( $this->job, null );
	}

	/**
	 * Switched on and counted within the hour: the next tick leaves it be,
	 * so the count's cost is paid once an hour, not every five minutes.
	 */
	public function testAChecksumCountWithinItsIntervalIsNotTakenAgain(): void
	{
		$this->countInBackground = true;
		$this->countLastRun      = 1_700_100_000 - 60;
		$this->time->method( 'getTime' )
		           ->willReturn( 1_700_100_000 )
		;
		$this->ruleService->method( 'evaluateRules' )
		                  ->willReturn( [ 'marked' => 0, 'matched' => 0 ] )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'countHashEntries' )
		;

		( new ReflectionMethod( RuleProcessingJob::class, 'run' ) )->invoke( $this->job, null );
	}
}
