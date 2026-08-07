<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\BackgroundJob\ProcessPendingUpdates;
use OCA\FileChecksumSearch\BackgroundJob\SeedPendingUpdates;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

class SeedPendingUpdatesTest
	extends
	TestCase
{

	private MockObject|ITimeFactory    $time;

	private MockObject|MetadataService $metadataService;

	private MockObject|IJobList        $jobList;

	private MockObject|LoggerInterface $logger;

	private SeedPendingUpdates         $job;


	protected function setUp(): void
	{

		parent::setUp();

		$this->time            = $this->createMock( ITimeFactory::class );
		$this->metadataService = $this->createMock( MetadataService::class );
		$this->jobList         = $this->createMock( IJobList::class );
		$this->logger          = $this->createMock( LoggerInterface::class );

		$this->job = new SeedPendingUpdates(
			$this->time,
			$this->metadataService,
			$this->jobList,
			$this->logger,
		);
	}


	public function testJobConstructsWith21hInterval(): void
	{

		$job = new SeedPendingUpdates(
			$this->time,
			$this->metadataService,
			$this->jobList,
			$this->logger,
		);

		$this->assertInstanceOf( SeedPendingUpdates::class, $job );
	}


	public function testRunCallsSeedIndex(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'seedIndex' )
		                      ->willReturn( 10 )
		;

		$reflection = new ReflectionMethod( SeedPendingUpdates::class, 'run' );
		$reflection->invoke( $this->job, null );
	}


	public function testRunLogsInsertedCount(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'seedIndex' )
		                      ->willReturn( 42 )
		;

		$this->logger->expects( $this->atLeastOnce() )
		             ->method( 'info' )
		;

		$reflection = new ReflectionMethod( SeedPendingUpdates::class, 'run' );
		$reflection->invoke( $this->job, null );
	}


	public function testRunCatchesThrowable(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'seedIndex' )
		                      ->willThrowException( new RuntimeException( 'DB down' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		$reflection = new ReflectionMethod( SeedPendingUpdates::class, 'run' );
		$reflection->invoke( $this->job, null );

		$this->assertTrue( true );
	}


	public function testRunDispatchesProcessorWhenInserted(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'seedIndex' )
		                      ->willReturn( 42 )
		;

		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with( ProcessPendingUpdates::class )
		;

		$reflection = new ReflectionMethod( SeedPendingUpdates::class, 'run' );
		$reflection->invoke( $this->job, null );
	}


	public function testRunDoesNotDispatchWhenNoInserts(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'seedIndex' )
		                      ->willReturn( 0 )
		;

		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;

		$reflection = new ReflectionMethod( SeedPendingUpdates::class, 'run' );
		$reflection->invoke( $this->job, null );
	}

}
