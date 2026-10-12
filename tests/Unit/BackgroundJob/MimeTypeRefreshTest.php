<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\BackgroundJob\MimeTypeRefresh;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\MimeTypeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The refresh queues hashed files for their MIME type a slice per cron run,
 * keeps its place in its argument, stops where detection is off, and books
 * every run.
 */
class MimeTypeRefreshTest
    extends
    TestCase
{

//  private properties

	private MetadataService&MockObject $metadataService;

	private MimeTypeService&MockObject $mimeTypes;

	private IJobList&MockObject        $jobList;

	private JobStatsService&MockObject $jobStats;

	private MimeTypeRefresh            $job;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->metadataService = $this->createMock( MetadataService::class );
		$this->mimeTypes       = $this->createMock( MimeTypeService::class );
		$this->jobList         = $this->createMock( IJobList::class );
		$this->jobStats        = $this->createMock( JobStatsService::class );

		$this->job = new MimeTypeRefresh(
			$this->createMock( ITimeFactory::class ),
			$this->metadataService,
			$this->mimeTypes,
			$this->jobList,
			$this->jobStats,
			$this->createMock( LoggerInterface::class ),
		);
	}


//  other non-static methods

	/**
	 * Off, what it was asked for is not wanted any more: it stops, and its
	 * last run is over, with what it had queued, rather than "not finished
	 * yet" for good.
	 */
	public function testItStopsWhereDetectionIsOff(): void
	{
		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( false )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markForMimeTypeAfter' )
		;
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;
		$this->jobStats->method( 'lastRuns' )
		               ->willReturn( [ JobStatsService::JOB_MIMETYPE_REFRESH => [ 'counts' => [ 'marked' => 70, 'done' => 0 ] ] ] )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with( JobStatsService::JOB_MIMETYPE_REFRESH, [ 'marked' => 70, 'done' => 1 ] )
		;

		$this->runJob(
			[
				'all'   => false,
				'after' => 0,
			],
		);
	}

	/** The time up, it queues the next run from where it stopped. */
	public function testItGoesOnFromWhereItStopped(): void
	{
		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( true )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markForMimeTypeAfter' )
		                      ->with( 500, MimeTypeRefresh::PAGE_SIZE, true, $this->isType( 'callable' ) )
		                      ->willReturn(
			                      [
				                      'marked' => 1000,
				                      'last'   => 1700,
				                      'done'   => false,
			                      ],
		                      )
		;
		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with(
			              MimeTypeRefresh::class,
			              [
				              'all'   => true,
				              'after' => 1700,
			              ],
		              )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with(
			               JobStatsService::JOB_MIMETYPE_REFRESH,
			               [
				               'marked' => 1000,
				               'done'   => 0,
			               ],
		               )
		;

		$this->runJob(
			[
				'all'   => true,
				'after' => 500,
			],
		);
	}

	public function testTheLastRunQueuesNoOther(): void
	{
		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( true )
		;
		$this->metadataService->method( 'markForMimeTypeAfter' )
		                      ->willReturn(
			                      [
				                      'marked' => 3,
				                      'last'   => 1700,
				                      'done'   => true,
			                      ],
		                      )
		;
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with(
			               JobStatsService::JOB_MIMETYPE_REFRESH,
			               [
				               'marked' => 3,
				               'done'   => 1,
			               ],
		               )
		;

		$this->runJob( [] );
	}

	/**
	 * A failed run is booked as one, and not queued again: a refresh asked
	 * for anew passes the files this one queued.
	 */
	public function testAFailedRunIsBookedAndNotQueuedAgain(): void
	{
		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( true )
		;
		$this->metadataService->method( 'markForMimeTypeAfter' )
		                      ->willThrowException( new RuntimeException( 'boom' ) )
		;
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'recordFailure' )
		               ->with( JobStatsService::JOB_MIMETYPE_REFRESH )
		;

		$this->runJob( [] );
	}


//  config/init/exe/run methods

	/**
	 * @param  array{all?: bool, after?: int}  $argument
	 */
	private function runJob( array $argument ): void
	{
		( new ReflectionMethod( MimeTypeRefresh::class, 'run' ) )->invoke( $this->job, $argument );
	}
}
