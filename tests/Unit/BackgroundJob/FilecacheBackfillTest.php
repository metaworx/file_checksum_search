<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\FilecacheBackfill;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The copy of the filecache's checksums, a slice per cron run: it resumes
 * where the last run stopped, hands over to the next run when its time is
 * up, and forgets its place once it has read everything.
 */
class FilecacheBackfillTest
    extends
    TestCase
{

//  private properties

	private MockObject|HashIndexService $hashIndexService;

	private MockObject|IAppConfig       $appConfig;

	private MockObject|IJobList         $jobList;

	private MockObject|ITimeFactory     $time;

	private FilecacheBackfill           $job;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->hashIndexService = $this->createMock( HashIndexService::class );
		$this->appConfig        = $this->createMock( IAppConfig::class );
		$this->jobList          = $this->createMock( IJobList::class );
		$this->time             = $this->createMock( ITimeFactory::class );

		$this->job = new FilecacheBackfill(
			$this->time,
			$this->hashIndexService,
			$this->appConfig,
			$this->jobList,
			$this->createMock( LoggerInterface::class ),
		);
	}


//  other non-static methods

	public function testItResumesWhereTheLastRunStoppedAndQueuesTheNext(): void
	{
		$this->appConfig->method( 'getValueInt' )
		                ->with( Application::APP_ID, FilecacheBackfill::CURSOR, 0 )
		                ->willReturn( 4000 )
		;
		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'backfillFromFilecache' )
		                       ->with( null, FilecacheBackfill::PAGE_SIZE, 4000, $this->isInstanceOf( \Closure::class ) )
		                       ->willReturn( [ 'files' => 10, 'hashes' => 12, 'last' => 9000, 'done' => false ] )
		;
		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueInt' )
		                ->with( Application::APP_ID, FilecacheBackfill::CURSOR, 9000 )
		;
		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with( FilecacheBackfill::class )
		;

		$this->runJob();
	}

	public function testItForgetsItsPlaceWhenItHasReadEverything(): void
	{
		$this->appConfig->method( 'getValueInt' )
		                ->willReturn( 9000 )
		;
		$this->hashIndexService->method( 'backfillFromFilecache' )
		                       ->willReturn( [ 'files' => 0, 'hashes' => 0, 'last' => 9500, 'done' => true ] )
		;
		$this->appConfig->expects( $this->once() )
		                ->method( 'deleteKey' )
		                ->with( Application::APP_ID, FilecacheBackfill::CURSOR )
		;
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;

		$this->runJob();
	}

	/**
	 * The stop check is the time budget: true until it is spent.
	 */
	public function testItStopsReadingWhenItsTimeIsUp(): void
	{
		$this->time->method( 'getTime' )
		           ->willReturnOnConsecutiveCalls( 1000, 1000 + FilecacheBackfill::TIME_BUDGET - 1, 1000 + FilecacheBackfill::TIME_BUDGET )
		;

		$answers = [];
		$this->hashIndexService->method( 'backfillFromFilecache' )
		                       ->willReturnCallback(
			                       static function( $output, int $pageSize, int $after, \Closure $keepGoing ) use ( &$answers ): array
			                       {
				                       $answers[] = $keepGoing();
				                       $answers[] = $keepGoing();

				                       return [ 'files' => 0, 'hashes' => 0, 'last' => 0, 'done' => false ];
			                       },
		                       )
		;

		$this->runJob();

		$this->assertSame( [ true, false ], $answers );
	}

	/**
	 * A failed run keeps the cursor where it was and does not queue itself:
	 * the next repair or install queues it, and it resumes from there.
	 */
	public function testAFailedRunKeepsItsPlace(): void
	{
		$this->hashIndexService->method( 'backfillFromFilecache' )
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

		$this->runJob();
	}

	public function testQueueingStartsFromTheBeginning(): void
	{
		$this->jobList->method( 'has' )
		              ->with( FilecacheBackfill::class, null )
		              ->willReturn( false )
		;
		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueInt' )
		                ->with( Application::APP_ID, FilecacheBackfill::CURSOR, 0 )
		;
		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with( FilecacheBackfill::class )
		;

		$this->assertTrue( FilecacheBackfill::queue( $this->jobList, $this->appConfig ) );
	}

	/**
	 * A copy already waiting keeps its place: queueing again would send it
	 * back to the first file.
	 */
	public function testQueueingLeavesACopyAlreadyWaitingAlone(): void
	{
		$this->jobList->method( 'has' )
		              ->willReturn( true )
		;
		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueInt' )
		;
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;

		$this->assertFalse( FilecacheBackfill::queue( $this->jobList, $this->appConfig ) );
	}


//  config/init/exe/run methods

	private function runJob(): void
	{
		( new ReflectionMethod( FilecacheBackfill::class, 'run' ) )->invoke( $this->job, null );
	}
}
