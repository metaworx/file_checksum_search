<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
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

	private MockObject|ITimeFactory    $time;

	private MockObject|RuleService     $ruleService;

	private MockObject|MetadataService $metadataService;

	private MockObject|IAppConfig      $appConfig;

	private MockObject|IJobList        $jobList;

	private MockObject|JobStatsService $jobStats;

	private MockObject|LoggerInterface $logger;

	private RuleProcessingJob          $job;


	protected function setUp(): void
	{

		parent::setUp();

		$this->time        = $this->createMock( ITimeFactory::class );
		$this->ruleService = $this->createMock( RuleService::class );
		$this->metadataService = $this->createMock( MetadataService::class );
		$this->appConfig   = $this->createMock( IAppConfig::class );
		$this->jobList     = $this->createMock( IJobList::class );
		$this->jobStats    = $this->createMock( JobStatsService::class );
		$this->logger      = $this->createMock( LoggerInterface::class );

		// Answered by key rather than pinned with with(): the purge gate in
		// run() reads its own clock and interval through the same method, and
		// the mocked clock stands at zero, so by default the purge is never
		// due and the rule-sweep tests below see exactly what they always did.
		$this->appConfig->method( 'getValueInt' )
		                ->willReturnCallback(
			                static fn( string $app, string $key, int $default ): int => $default,
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

		$reflection = new ReflectionMethod( RuleProcessingJob::class, 'run' );
		$reflection->invoke( $this->job, null );

		$this->assertTrue( true );
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
			               static function ( string $job, array $counts ) use ( &$recorded ): void {
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

}
