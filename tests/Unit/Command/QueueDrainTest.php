<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\Queue\Drain;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * How much of the queue one run takes, and what it says about the rest.
 */
class QueueDrainTest
	extends
	TestCase
{

	private MetadataService&MockObject $metadataService;

	private IAppConfig&MockObject      $appConfig;

	private CommandTester              $tester;


	protected function setUp(): void
	{

		parent::setUp();

		$this->metadataService = $this->createMock( MetadataService::class );
		$this->appConfig       = $this->createMock( IAppConfig::class );

		$this->tester = new CommandTester(
			new Drain(
				$this->metadataService,
				$this->createMock( HashCalculationService::class ),
				$this->appConfig,
				$this->createMock( LoggerInterface::class ),
			),
		);
	}


	/**
	 * With neither flag, the batch is the one the background job uses — an
	 * administrator who tuned that setting gets it honoured here too.
	 */
	public function testTheConfiguredLimitIsUsedWhenNoFlagIsGiven(): void
	{

		$this->appConfig->method( 'getValueInt' )
		                ->willReturn( 7 )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'fetchPendingBatch' )
		                      ->with( 7 )
		                      ->willReturn( $this->pending( 7 ) )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [ 'pending:auto' => 3 ] )
		;

		$this->tester->execute( [] );

		// And the run says which setting stopped it: a run that took the
		// default silently reads as though the queue were shorter than it is.
		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( '3 still waiting', $display );
		$this->assertStringContainsString( 'pending_batch_limit setting (7)', $display );
		$this->assertStringContainsString( '--batch-size', $display );
		$this->assertStringContainsString( '--all', $display );
	}


	public function testAnExplicitBatchSizeNamesItself(): void
	{

		$this->metadataService->method( 'fetchPendingBatch' )
		                      ->with( 2 )
		                      ->willReturn( $this->pending( 2 ) )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [ 'pending:auto' => 5 ] )
		;

		$this->tester->execute( [ '--batch-size' => '2' ] );

		$this->assertStringContainsString( '--batch-size (2)', $this->tester->getDisplay() );
	}


	/**
	 * Nothing left, nothing to explain.
	 */
	public function testAFinishedQueueSaysNothingAboutLimits(): void
	{

		$this->appConfig->method( 'getValueInt' )
		                ->willReturn( 50 )
		;
		$this->metadataService->method( 'fetchPendingBatch' )
		                      ->willReturn( $this->pending( 1 ) )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [] )
		;

		$this->tester->execute( [] );

		$this->assertStringNotContainsString( 'still waiting', $this->tester->getDisplay() );
	}


	/**
	 * A run that already took everything must not be told to add `--all`.
	 */
	public function testAllDoesNotSuggestAll(): void
	{

		$this->appConfig->method( 'getValueInt' )
		                ->willReturn( 50 )
		;
		$this->metadataService->method( 'fetchPendingBatch' )
		                      ->willReturnOnConsecutiveCalls( $this->pending( 1 ), [] )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [ 'pending:auto' => 1 ] )
		;

		$this->tester->execute( [ '--all' => true ] );

		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( 'still waiting', $display );
		$this->assertStringNotContainsString( 'or --all', $display );
	}


	public function testAnEmptyQueueSaysSo(): void
	{

		$this->appConfig->method( 'getValueInt' )
		                ->willReturn( 50 )
		;
		$this->metadataService->method( 'fetchPendingBatch' )
		                      ->willReturn( [] )
		;

		$this->tester->execute( [] );

		$this->assertStringContainsString( 'Nothing was waiting', $this->tester->getDisplay() );
	}


	/**
	 * @return list<array<string, mixed>>
	 */
	private function pending( int $count ): array
	{

		$rows = [];

		for ( $i = 1; $i <= $count; $i ++ )
		{
			$rows[] = [
				MetadataService::FIELD_FILE_ID           => $i,
				MetadataService::FIELD_META_VALUE_STRING => 'pending:auto',
			];
		}

		return $rows;
	}

}
