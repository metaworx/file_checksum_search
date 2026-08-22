<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\RebuildIndex;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RebuildIndexTest
	extends
	TestCase
{

	private MockObject|MetadataService        $metadataService;

	private MockObject|HashCalculationService $hashCalc;

	private MockObject|HashIndexService       $hashIndexService;

	private MockObject|LoggerInterface        $logger;

	private CommandTester                     $tester;


	protected function setUp(): void
	{

		parent::setUp();

		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->hashCalc         = $this->createMock( HashCalculationService::class );
		$this->hashIndexService = $this->createMock( HashIndexService::class );
		$this->logger           = $this->createMock( LoggerInterface::class );

		$command      = new RebuildIndex(
			$this->metadataService,
			$this->hashCalc,
			$this->hashIndexService,
			$this->logger,
		);
		$this->tester = new CommandTester( $command );
	}


	public function testStopsAfterSeedingWhenNothingIsPending(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'seedIndex' )
		                      ->willReturn( 7 )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [] )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'fetchPendingBatch' )
		;

		$exitCode = $this->tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( '7 new files added to index.', $display );
		$this->assertStringContainsString( 'No pending files to process.', $display );
	}


	public function testProcessesPendingBatchAndReportsSuccess(): void
	{

		$this->metadataService->method( 'seedIndex' )
		                      ->willReturn( 0 )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [ 'pending:auto' => 2 ] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'fetchPendingBatch' )
		                      ->with( 100 )
		                      ->willReturn( [
			                      [
				                      MetadataService::FIELD_FILE_ID           => 42,
				                      MetadataService::FIELD_META_VALUE_STRING => 'pending:auto',
			                      ],
			                      [
				                      MetadataService::FIELD_FILE_ID           => 108,
				                      MetadataService::FIELD_META_VALUE_STRING => 'pending:force',
			                      ],
		                      ] )
		;

		$this->hashCalc->expects( $this->exactly( 2 ) )
		               ->method( 'processFile' )
		               ->willReturnMap( [
			               [ 42, 'auto', HashCalculationService::SUPPORTED_ALGOS, null ],
			               [ 108, 'force', HashCalculationService::SUPPORTED_ALGOS, null ],
		               ] )
		;

		$exitCode = $this->tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( '2 files processed, 0 failed.', $this->tester->getDisplay() );
	}


	public function testReturnsFailureWhenAFileFailsToProcess(): void
	{

		$this->metadataService->method( 'seedIndex' )
		                      ->willReturn( 0 )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [ 'pending:auto' => 1 ] )
		;
		$this->metadataService->method( 'fetchPendingBatch' )
		                      ->willReturn( [
			                      [
				                      MetadataService::FIELD_FILE_ID           => 42,
				                      MetadataService::FIELD_META_VALUE_STRING => 'pending:auto',
			                      ],
		                      ] )
		;

		$this->hashCalc->method( 'processFile' )
		               ->willThrowException( new RuntimeException( 'boom' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		$exitCode = $this->tester->execute( [] );

		$this->assertSame( Command::FAILURE, $exitCode );
		$this->assertStringContainsString( '0 files processed, 1 failed.', $this->tester->getDisplay() );
	}


	public function testBatchSizeOptionIsPassedToFetchPendingBatch(): void
	{

		$this->metadataService->method( 'seedIndex' )
		                      ->willReturn( 0 )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [ 'pending:auto' => 1 ] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'fetchPendingBatch' )
		                      ->with( 25 )
		                      ->willReturn( [] )
		;

		$this->tester->execute( [ '--batch-size' => '25' ] );
	}


	public function testNonPositiveBatchSizeIsClampedToOne(): void
	{

		$this->metadataService->method( 'seedIndex' )
		                      ->willReturn( 0 )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [ 'pending:auto' => 1 ] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'fetchPendingBatch' )
		                      ->with( 1 )
		                      ->willReturn( [] )
		;

		$this->tester->execute( [ '--batch-size' => '0' ] );
	}

}
