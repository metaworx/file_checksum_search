<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\HashIndexCheck;
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
 * The check that every stored hash has its index row, a slice per cron run:
 * the first run asks and stops when nothing is missing; otherwise it walks,
 * hands over when its time is up, resumes without asking again, and forgets
 * its place once it has read everything. Every run is booked for the status
 * views.
 */
class HashIndexCheckTest
    extends
    TestCase
{

//  private properties

	private MockObject|MetadataService $metadataService;

	private MockObject|IAppConfig      $appConfig;

	private MockObject|IJobList        $jobList;

	private MockObject|JobStatsService $jobStats;

	private MockObject|ITimeFactory    $time;

	private HashIndexCheck             $job;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->metadataService = $this->createMock( MetadataService::class );
		$this->appConfig       = $this->createMock( IAppConfig::class );
		$this->jobList         = $this->createMock( IJobList::class );
		$this->jobStats        = $this->createMock( JobStatsService::class );
		$this->time            = $this->createMock( ITimeFactory::class );

		$this->job = new HashIndexCheck(
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
	 * Nothing missing: the question is the whole run, and it is booked.
	 */
	public function testTheFirstRunAsksAndStopsWhenNothingIsMissing(): void
	{
		$this->appConfig->method( 'hasKey' )
		                ->with( Application::APP_ID, HashIndexCheck::CURSOR )
		                ->willReturn( false )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'hashIndexIsComplete' )
		                      ->willReturn( true )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'reindexHashesAfter' )
		;
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with( JobStatsService::JOB_HASH_INDEX_CHECK, [ 'repaired' => 0, 'done' => 1 ] )
		;

		$this->runJob();
	}

	/**
	 * Something missing: the walk starts at the first file, keeps its place
	 * when the time is up, and queues the next run.
	 */
	public function testTheFirstRunWalksFromTheStartWhenSomethingIsMissing(): void
	{
		$this->appConfig->method( 'hasKey' )
		                ->willReturn( false )
		;
		$this->metadataService->method( 'hashIndexIsComplete' )
		                      ->willReturn( false )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'reindexHashesAfter' )
		                      ->with( 0, HashIndexCheck::PAGE_SIZE, $this->isInstanceOf( \Closure::class ) )
		                      ->willReturn( [ 'fixed' => 3, 'last' => 8000, 'done' => false ] )
		;
		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueInt' )
		                ->with( Application::APP_ID, HashIndexCheck::CURSOR, 8000 )
		;
		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with( HashIndexCheck::class )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with( JobStatsService::JOB_HASH_INDEX_CHECK, [ 'repaired' => 3, 'done' => 0 ] )
		;

		$this->runJob();
	}

	/**
	 * A later run resumes where the cursor says, without asking again: the
	 * question is the costly part, and it was answered.
	 */
	public function testALaterRunResumesWithoutAskingAgain(): void
	{
		$this->appConfig->method( 'hasKey' )
		                ->willReturn( true )
		;
		$this->appConfig->method( 'getValueInt' )
		                ->with( Application::APP_ID, HashIndexCheck::CURSOR, 0 )
		                ->willReturn( 8000 )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'hashIndexIsComplete' )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'reindexHashesAfter' )
		                      ->with( 8000, HashIndexCheck::PAGE_SIZE, $this->isInstanceOf( \Closure::class ) )
		                      ->willReturn( [ 'fixed' => 0, 'last' => 9500, 'done' => true ] )
		;
		$this->appConfig->expects( $this->once() )
		                ->method( 'deleteKey' )
		                ->with( Application::APP_ID, HashIndexCheck::CURSOR )
		;
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with( JobStatsService::JOB_HASH_INDEX_CHECK, [ 'repaired' => 0, 'done' => 1 ] )
		;

		$this->runJob();
	}

	/**
	 * The stop check is the time budget, counted from after the question:
	 * true until it is spent.
	 */
	public function testItStopsWalkingWhenItsTimeIsUp(): void
	{
		$this->appConfig->method( 'hasKey' )
		                ->willReturn( true )
		;
		$this->time->method( 'getTime' )
		           ->willReturnOnConsecutiveCalls( 1000, 1000 + HashIndexCheck::TIME_BUDGET - 1, 1000 + HashIndexCheck::TIME_BUDGET )
		;

		$answers = [];
		$this->metadataService->method( 'reindexHashesAfter' )
		                      ->willReturnCallback(
			                      static function( int $after, int $pageSize, \Closure $keepGoing ) use ( &$answers ): array
			                      {
				                      $answers[] = $keepGoing();
				                      $answers[] = $keepGoing();

				                      return [ 'fixed' => 0, 'last' => 0, 'done' => false ];
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
		$this->appConfig->method( 'hasKey' )
		                ->willReturn( true )
		;
		$this->metadataService->method( 'reindexHashesAfter' )
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
		$this->jobStats->expects( $this->once() )
		               ->method( 'recordFailure' )
		               ->with(
			               JobStatsService::JOB_HASH_INDEX_CHECK,
			               $this->isInstanceOf( RuntimeException::class ),
			               $this->isType( 'int' ),
		               )
		;

		$this->runJob();
	}

	/**
	 * Queueing starts over: the cursor goes, so the first run asks.
	 */
	public function testQueueingStartsFromTheQuestion(): void
	{
		$this->jobList->method( 'has' )
		              ->with( HashIndexCheck::class, null )
		              ->willReturn( false )
		;
		$this->appConfig->expects( $this->once() )
		                ->method( 'deleteKey' )
		                ->with( Application::APP_ID, HashIndexCheck::CURSOR )
		;
		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with( HashIndexCheck::class )
		;

		$this->assertTrue( HashIndexCheck::queue( $this->jobList, $this->appConfig ) );
	}

	/**
	 * A check already waiting keeps its place.
	 */
	public function testQueueingLeavesACheckAlreadyWaitingAlone(): void
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

		$this->assertFalse( HashIndexCheck::queue( $this->jobList, $this->appConfig ) );
	}


//  config/init/exe/run methods

	private function runJob(): void
	{
		( new ReflectionMethod( HashIndexCheck::class, 'run' ) )->invoke( $this->job, null );
	}
}
