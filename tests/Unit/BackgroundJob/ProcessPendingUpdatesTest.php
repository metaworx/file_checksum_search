<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\ProcessPendingUpdates;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

class ProcessPendingUpdatesTest
	extends
	TestCase
{

	private MockObject|ITimeFactory           $time;

	private MockObject|HashCalculationService $hashCalc;

	private MockObject|MetadataService        $metadataService;

	private MockObject|IAppConfig             $appConfig;

	private MockObject|LoggerInterface        $logger;

	private ProcessPendingUpdates             $job;


	protected function setUp(): void
	{

		parent::setUp();

		$this->time            = $this->createMock( ITimeFactory::class );
		$this->hashCalc        = $this->createMock( HashCalculationService::class );
		$this->metadataService = $this->createMock( MetadataService::class );
		$this->appConfig       = $this->createMock( IAppConfig::class );
		$this->logger          = $this->createMock( LoggerInterface::class );

		$this->appConfig->method( 'getValueInt' )
		                ->willReturnMap(
			                [
				                [
					                Application::APP_ID,
					                'process_pending_interval',
					                60,
					                60,
				                ],
				                [
					                Application::APP_ID,
					                'pending_batch_limit',
					                50,
					                50,
				                ],
			                ],
		                )
		;

		$this->job = new ProcessPendingUpdates(
			$this->time,
			$this->hashCalc,
			$this->metadataService,
			$this->appConfig,
			$this->logger,
		);
	}


	public function testJobConstructsWithDefaultInterval(): void
	{

		$job = new ProcessPendingUpdates(
			$this->time,
			$this->hashCalc,
			$this->metadataService,
			$this->appConfig,
			$this->logger,
		);

		$this->assertInstanceOf( ProcessPendingUpdates::class, $job );
	}


	public function testRunWithEmptyPendingBatchLogsAndReturns(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'fetchPendingBatch' )
		                      ->with( 50 )
		                      ->willReturn( [] )
		;

		$this->hashCalc->expects( $this->never() )
		               ->method( 'processFile' )
		;

		$this->logger->expects( $this->atLeastOnce() )
		             ->method( 'debug' )
		             ->with(
			             $this->stringContains( 'no pending rows' ),
			             $this->anything(),
		             )
		;

		$reflection = new ReflectionMethod( ProcessPendingUpdates::class, 'run' );
		$reflection->invoke( $this->job, null );
	}


	public function testRunProcessesPendingBatch(): void
	{

		$pendingRows = [
			[
				MetadataService::FIELD_FILE_ID           => 42,
				MetadataService::FIELD_META_VALUE_STRING => 'pending:auto',
			],
			[
				MetadataService::FIELD_FILE_ID           => 99,
				MetadataService::FIELD_META_VALUE_STRING => 'pending:new',
			],
		];

		$this->metadataService->expects( $this->once() )
		                      ->method( 'fetchPendingBatch' )
		                      ->with( 50 )
		                      ->willReturn( $pendingRows )
		;

		$this->hashCalc->expects( $this->exactly( 2 ) )
		               ->method( 'processFile' )
		               ->with(
			               $this->logicalOr( 42, 99 ),
			               $this->logicalOr( 'auto', 'new' ),
			               $this->anything(),
		               )
		;

		$reflection = new ReflectionMethod( ProcessPendingUpdates::class, 'run' );
		$reflection->invoke( $this->job, null );
	}


	public function testRunParsesPendingPrefixFromStatus(): void
	{

		$pendingRows = [
			[
				MetadataService::FIELD_FILE_ID           => 10,
				MetadataService::FIELD_META_VALUE_STRING => 'pending:force',
			],
		];

		$this->metadataService->expects( $this->once() )
		                      ->method( 'fetchPendingBatch' )
		                      ->willReturn( $pendingRows )
		;

		$this->hashCalc->expects( $this->once() )
		               ->method( 'processFile' )
		               ->with( 10, 'force', $this->anything() )
		;

		$reflection = new ReflectionMethod( ProcessPendingUpdates::class, 'run' );
		$reflection->invoke( $this->job, null );
	}


	public function testRunContinuesAfterProcessFailure(): void
	{

		$pendingRows = [
			[
				MetadataService::FIELD_FILE_ID           => 42,
				MetadataService::FIELD_META_VALUE_STRING => 'pending:auto',
			],
			[
				MetadataService::FIELD_FILE_ID           => 99,
				MetadataService::FIELD_META_VALUE_STRING => 'pending:auto',
			],
		];

		$this->metadataService->expects( $this->once() )
		                      ->method( 'fetchPendingBatch' )
		                      ->willReturn( $pendingRows )
		;

		// First call throws, second succeeds.
		$this->hashCalc->expects( $this->exactly( 2 ) )
		               ->method( 'processFile' )
		               ->willReturnCallback(
			               function (
			                int    $fileId,
			                string $_mode,
			                array  $_algos,
			               ): void {

				               if ( $fileId === 42 )
				               {
					               throw new RuntimeException( 'File not found' );
				               }
			               },
		               )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		;

		$reflection = new ReflectionMethod( ProcessPendingUpdates::class, 'run' );
		$reflection->invoke( $this->job, null );
	}


	public function testRunCatchesTopLevelThrowable(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'fetchPendingBatch' )
		                      ->willThrowException( new RuntimeException( 'DB down' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		$reflection = new ReflectionMethod( ProcessPendingUpdates::class, 'run' );
		$reflection->invoke( $this->job, null );

		$this->assertTrue( true );
	}

}
