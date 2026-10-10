<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\BackgroundJob\MimeTypeDelete;
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
 * The deletion runs while one is requested and detection is off, a slice
 * per cron run, until no type is left, and then clears the request; a run
 * that fails queues the next all the same.
 */
class MimeTypeDeleteTest
    extends
    TestCase
{

//  private properties

	private MetadataService&MockObject $metadataService;

	private MimeTypeService&MockObject $mimeTypes;

	private IJobList&MockObject        $jobList;

	private JobStatsService&MockObject $jobStats;

	private MimeTypeDelete             $job;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->metadataService = $this->createMock( MetadataService::class );
		$this->mimeTypes       = $this->createMock( MimeTypeService::class );
		$this->jobList         = $this->createMock( IJobList::class );
		$this->jobStats        = $this->createMock( JobStatsService::class );

		$this->job = new MimeTypeDelete(
			$this->createMock( ITimeFactory::class ),
			$this->metadataService,
			$this->mimeTypes,
			$this->jobList,
			$this->jobStats,
			$this->createMock( LoggerInterface::class ),
		);
	}


//  other non-static methods

	public function testNothingIsDeletedWithoutARequest(): void
	{
		$this->mimeTypes->method( 'getDeleteRequestedAt' )
		                ->willReturn( null )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'deleteMimeTypes' )
		;

		$this->runJob();
	}

	/** The types detected since are not the request's to take away. */
	public function testNothingIsDeletedWhileDetectionIsOn(): void
	{
		$this->mimeTypes->method( 'getDeleteRequestedAt' )
		                ->willReturn( 1_759_300_000 )
		;
		$this->mimeTypes->method( 'isEnabled' )
		                ->willReturn( true )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'deleteMimeTypes' )
		;

		$this->runJob();
	}

	/** Types left: the next run goes on, the request stands. */
	public function testTypesLeftQueueTheNextRun(): void
	{
		$this->requested();
		$this->metadataService->method( 'deleteMimeTypes' )
		                      ->willReturn(
			                      [
				                      'deleted' => 500,
				                      'done'    => false,
			                      ],
		                      )
		;
		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with( MimeTypeDelete::class )
		;
		$this->mimeTypes->expects( $this->never() )
		                ->method( 'setDeleteRequestedAt' )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with(
			               JobStatsService::JOB_MIMETYPE_DELETE,
			               [
				               'deleted' => 500,
				               'done'    => 0,
			               ],
		               )
		;

		$this->runJob();
	}

	/** None left: the request is cleared, which lets detection on again. */
	public function testTheLastRunClearsTheRequest(): void
	{
		$this->requested();
		$this->metadataService->method( 'deleteMimeTypes' )
		                      ->willReturn(
			                      [
				                      'deleted' => 12,
				                      'done'    => true,
			                      ],
		                      )
		;
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;
		$this->mimeTypes->expects( $this->once() )
		                ->method( 'setDeleteRequestedAt' )
		                ->with( null )
		;

		$this->runJob();
	}

	/** The request stands until the deletion is done, and only this finishes it. */
	public function testAFailedRunQueuesTheNextAllTheSame(): void
	{
		$this->requested();
		$this->metadataService->method( 'deleteMimeTypes' )
		                      ->willThrowException( new RuntimeException( 'boom' ) )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'recordFailure' )
		               ->with( JobStatsService::JOB_MIMETYPE_DELETE )
		;
		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with( MimeTypeDelete::class )
		;

		$this->runJob();
	}

	public function testItIsQueuedOnce(): void
	{
		$this->jobList->method( 'has' )
		              ->willReturnOnConsecutiveCalls( false, true )
		;
		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		;

		$this->assertTrue( MimeTypeDelete::queue( $this->jobList ) );
		$this->assertFalse( MimeTypeDelete::queue( $this->jobList ) );
	}

	//  private methods
	/** A deletion requested, detection off. */
	private function requested(): void
	{
		$this->mimeTypes->method( 'getDeleteRequestedAt' )
		                ->willReturn( 1_759_300_000 )
		;
		$this->mimeTypes->method( 'isEnabled' )
		                ->willReturn( false )
		;
	}


//  config/init/exe/run methods

	private function runJob(): void
	{
		( new ReflectionMethod( MimeTypeDelete::class, 'run' ) )->invoke( $this->job, null );
	}
}
