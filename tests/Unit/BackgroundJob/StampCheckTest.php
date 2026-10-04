<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\StampCheck;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The walk that stamps hashes saved without a stamp, a slice per cron run:
 * it starts where its cursor says, hands over when its time is up, and
 * forgets its place once it has read everything. Every run is booked for
 * the status views.
 */
class StampCheckTest
    extends
    TestCase
{

//  private properties

	private MockObject|MetadataService $metadataService;

	private MockObject|IAppConfig      $appConfig;

	private MockObject|IJobList        $jobList;

	private MockObject|JobStatsService $jobStats;

	private MockObject|ITimeFactory    $time;

	private StampCheck                 $job;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->metadataService = $this->createMock( MetadataService::class );
		$this->appConfig       = $this->createMock( IAppConfig::class );
		$this->jobList         = $this->createMock( IJobList::class );
		$this->jobStats        = $this->createMock( JobStatsService::class );
		$this->time            = $this->createMock( ITimeFactory::class );

		$this->job = new StampCheck(
			$this->time,
			$this->metadataService,
			$this->appConfig,
			$this->jobList,
			$this->jobStats,
			$this->createMock( LoggerInterface::class ),
		);
	}


//  other non-static methods

	/**
	 * Cut short: the place is kept and the next run queued.
	 */
	public function testARunCutShortKeepsItsPlaceAndQueuesTheNext(): void
	{
		$this->appConfig->method( 'getValueInt' )
		                ->with( Application::APP_ID, StampCheck::CURSOR, 0 )
		                ->willReturn( 0 )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'stampUnstampedAfter' )
		                      ->with( 0, StampCheck::PAGE_SIZE, $this->isInstanceOf( \Closure::class ) )
		                      ->willReturn( [ 'stamped' => 500, 'queued' => 3, 'last' => 8000, 'done' => false ] )
		;
		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueInt' )
		                ->with( Application::APP_ID, StampCheck::CURSOR, 8000 )
		;
		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with( StampCheck::class )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with( JobStatsService::JOB_STAMP_CHECK, [ 'stamped' => 500, 'queued' => 3, 'done' => 0 ] )
		;

		$this->runJob();
	}

	/**
	 * The last run starts at the cursor and clears it.
	 */
	public function testTheLastRunResumesAndForgetsItsPlace(): void
	{
		$this->appConfig->method( 'getValueInt' )
		                ->willReturn( 8000 )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'stampUnstampedAfter' )
		                      ->with( 8000, StampCheck::PAGE_SIZE, $this->isInstanceOf( \Closure::class ) )
		                      ->willReturn( [ 'stamped' => 12, 'queued' => 0, 'last' => 9500, 'done' => true ] )
		;
		$this->appConfig->expects( $this->once() )
		                ->method( 'deleteKey' )
		                ->with( Application::APP_ID, StampCheck::CURSOR )
		;
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with( JobStatsService::JOB_STAMP_CHECK, [ 'stamped' => 12, 'queued' => 0, 'done' => 1 ] )
		;

		$this->runJob();
	}

	/**
	 * The stop check is the time budget: true until it is spent.
	 */
	public function testItStopsWalkingWhenItsTimeIsUp(): void
	{
		$this->time->method( 'getTime' )
		           ->willReturnOnConsecutiveCalls( 1000, 1000 + StampCheck::TIME_BUDGET - 1, 1000 + StampCheck::TIME_BUDGET )
		;

		$answers = [];
		$this->metadataService->method( 'stampUnstampedAfter' )
		                      ->willReturnCallback(
			                      static function( int $after, int $pageSize, \Closure $keepGoing ) use ( &$answers ): array
			                      {
				                      $answers[] = $keepGoing();
				                      $answers[] = $keepGoing();

				                      return [ 'stamped' => 0, 'queued' => 0, 'last' => 0, 'done' => false ];
			                      },
		                      )
		;

		$this->runJob();

		$this->assertSame( [ true, false ], $answers );
	}

	/**
	 * A failed run keeps the cursor where it was, does not queue itself and
	 * books nothing: the next repair queues it, and it resumes from there.
	 */
	public function testAFailedRunKeepsItsPlace(): void
	{
		$this->metadataService->method( 'stampUnstampedAfter' )
		                      ->willThrowException( new RuntimeException( 'database went away' ) )
		;
		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueInt' )
		;
		$this->appConfig->expects( $this->never() )
		                ->method( 'deleteKey' )
		;
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;
		$this->jobStats->expects( $this->never() )
		               ->method( 'record' )
		;

		$this->runJob();
	}

	/**
	 * Queueing starts over: the cursor goes.
	 */
	public function testQueueingStartsFromTheBeginning(): void
	{
		$this->jobList->method( 'has' )
		              ->with( StampCheck::class, null )
		              ->willReturn( false )
		;
		$this->appConfig->expects( $this->once() )
		                ->method( 'deleteKey' )
		                ->with( Application::APP_ID, StampCheck::CURSOR )
		;
		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with( StampCheck::class )
		;

		$this->assertTrue( StampCheck::queue( $this->jobList, $this->appConfig ) );
	}

	/**
	 * A walk already waiting keeps its place.
	 */
	public function testQueueingLeavesAWalkAlreadyWaitingAlone(): void
	{
		$this->jobList->method( 'has' )
		              ->willReturn( true )
		;
		$this->appConfig->expects( $this->never() )
		                ->method( 'deleteKey' )
		;
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;

		$this->assertFalse( StampCheck::queue( $this->jobList, $this->appConfig ) );
	}


//  config/init/exe/run methods

	private function runJob(): void
	{
		( new ReflectionMethod( StampCheck::class, 'run' ) )->invoke( $this->job, null );
	}
}
