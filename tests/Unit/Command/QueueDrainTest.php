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
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * How much of the queue one run takes, and what it says about the rest.
 */
class QueueDrainTest
    extends
    TestCase
{

//  constants

	/**
	 * How many fetches a test's queue answers before it reads as empty: a
	 * drain that would fetch for ever then ends, and the test fails on the
	 * count rather than hanging.
	 */
	private const FETCH_GUARD = 5;


//  private properties

	private MetadataService&MockObject        $metadataService;

	private HashCalculationService&MockObject $hashCalc;

	private IAppConfig&MockObject             $appConfig;

	private CommandTester                     $tester;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->metadataService = $this->createMock( MetadataService::class );
		$this->hashCalc        = $this->createMock( HashCalculationService::class );
		$this->appConfig       = $this->createMock( IAppConfig::class );

		$this->tester = new CommandTester(
			new Drain(
				$this->metadataService,
				$this->hashCalc,
				$this->appConfig,
				$this->createMock( LoggerInterface::class ),
			),
		);
	}


//  other non-static methods

	/**
	 * With neither flag, the batch is the one the background job uses — an
	 * administrator who tuned that setting gets it honoured here too.
	 */
	public function testTheConfiguredLimitIsUsedWhenNoFlagIsGiven(): void
	{
		$this->appConfig->method( 'getValueInt' )
		                ->willReturn( 7 )
		;
		// One batch, in the queue's own order: no lower bound.
		$this->metadataService->expects( $this->once() )
		                      ->method( 'fetchPendingBatch' )
		                      ->with( 7, null )
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
	 * A file that fails on every attempt stays queued. `--all` tries it once
	 * and walks on past it, to the end of the queue, rather than fetching it
	 * again for ever.
	 */
	public function testAllTriesAFailingFileOnce(): void
	{
		$queue = $this->queueHolding( [ 1 ] );
		$this->hashing( $queue, times: 1, throwing: [ 1 ] );

		$this->tester->execute( [ '--all' => true ] );

		$this->assertSame( 2, $queue->fetches, 'The drain fetched the file that failed again.' );
		$this->assertStringContainsString( 'Hashed 0 files, 1 failed.', $this->tester->getDisplay() );
	}

	/**
	 * The usual shape: a failing file at the head of the queue, others
	 * behind it that hash. The walk passes the one and takes the rest.
	 */
	public function testAllPassesAFailingFileAndTakesTheRest(): void
	{
		$queue = $this->queueHolding( [ 1, 2, 3 ] );
		$this->hashing( $queue, times: 3, throwing: [ 1 ] );

		$this->tester->execute(
			[
				'--all'        => true,
				'--batch-size' => '1',
			],
		);

		$this->assertSame( 4, $queue->fetches );
		$this->assertStringContainsString( 'Hashed 2 files, 1 failed.', $this->tester->getDisplay() );
	}

	/**
	 * A batch in which every file failed — an offline storage at the head
	 * of the queue — ends nothing: the files behind it are tried too.
	 */
	public function testAllTriesTheFilesBehindABatchThatFailedWhole(): void
	{
		$queue = $this->queueHolding( [ 1, 2, 3, 4 ] );
		$this->hashing( $queue, times: 4, throwing: [ 1 ], leftQueued: [ 2 ] );

		$this->tester->execute(
			[
				'--all'        => true,
				'--batch-size' => '2',
			],
		);

		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( 'Hashed 2 files, 2 failed.', $display );
		$this->assertStringContainsString( '2 still waiting', $display );
	}

	/**
	 * The usual failure throws nothing: processFile() leaves the file queued
	 * and says so. It is neither counted hashed nor fetched again.
	 */
	public function testAFileLeftQueuedIsNotCountedAsHashed(): void
	{
		$queue = $this->queueHolding( [ 1 ] );
		$this->hashing( $queue, times: 1, leftQueued: [ 1 ] );

		$this->tester->execute( [ '--all' => true ] );

		$this->assertSame( 2, $queue->fetches, 'The drain fetched the file it could not hash again.' );
		$this->assertStringContainsString( 'Hashed 0 files, 1 failed.', $this->tester->getDisplay() );
	}

	/**
	 * A queue of $fileIds that answers the drain as the real one does: a
	 * walk's fetch from its lower bound by file id, any other in file id
	 * order too, these files having no failed attempts to order by. After
	 * {@see FETCH_GUARD} fetches it reads as empty, so that a drain that
	 * would fetch for ever fails its test rather than hanging it.
	 *
	 * @param  list<int>  $fileIds
	 *
	 * @return object{fetches: int, queued: list<int>}  The fetches made and the files still queued.
	 */
	private function queueHolding( array $fileIds ): object
	{
		$queue = (object) [
			'fetches' => 0,
			'queued'  => $fileIds,
		];

		$this->appConfig->method( 'getValueInt' )
		                ->willReturn( 50 )
		;
		$this->metadataService->method( 'fetchPendingBatch' )
		                      ->willReturnCallback(
			                      function(
				                      int  $limit,
				                      ?int $after = null,
			                      ) use
			                      (
				                      $queue,
			                      ): array
			                      {
				                      if ( ++ $queue->fetches > self::FETCH_GUARD )
				                      {
					                      return [];
				                      }

				                      $taken = array_filter(
					                      $queue->queued,
					                      static fn( int $fileId ) => $after === null || $fileId > $after,
				                      );
				                      sort( $taken );

				                      return $this->rows( array_slice( $taken, 0, $limit ) );
			                      },
		                      )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturnCallback( static fn() => [ 'pending:auto' => count( $queue->queued ) ] )
		;

		return $queue;
	}

	/**
	 * Hash the files of $queue, expecting $times attempts: a file in
	 * $throwing throws, one in $leftQueued stays queued without, and any
	 * other is hashed and leaves the queue.
	 *
	 * @param  list<int>  $throwing
	 * @param  list<int>  $leftQueued
	 */
	private function hashing(
		object $queue,
		int    $times,
		array  $throwing = [],
		array  $leftQueued = [],
	): void
	{
		$this->hashCalc->expects( $this->exactly( $times ) )
		               ->method( 'processFile' )
		               ->willReturnCallback(
			               static function( int $fileId ) use ( $queue, $throwing, $leftQueued ): bool
			               {
				               if ( in_array( $fileId, $throwing, true ) )
				               {
					               throw new RuntimeException( 'unreadable' );
				               }

				               if ( in_array( $fileId, $leftQueued, true ) )
				               {
					               return false;
				               }

				               $queue->queued = array_values( array_diff( $queue->queued, [ $fileId ] ) );

				               return true;
			               },
		               )
		;
	}

	/**
	 * @param  list<int>  $fileIds
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows( array $fileIds ): array
	{
		return array_map(
			static fn( int $fileId ) => [
				MetadataService::FIELD_FILE_ID           => $fileId,
				MetadataService::FIELD_META_VALUE_STRING => 'pending:auto',
			],
			$fileIds,
		);
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
