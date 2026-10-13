<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\DatabaseService;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\MimeTypeService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StatusService.
 *
 * Covers its public methods with mocked dependencies.
 */
class StatusServiceTest
    extends
    TestCase
{

//  private properties

	private DatabaseService&MockObject  $databaseService;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private IAppManager&MockObject      $appManager;

	private MetadataService&MockObject  $metadataService;

	private JobStatsService&MockObject  $jobStats;

	private ITimeFactory&MockObject     $time;

	private IAppConfig&MockObject       $appConfig;

	private MimeTypeService&MockObject  $mimeTypes;

	private StatusService               $service;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->databaseService = $this->createMock( DatabaseService::class );
		$this->appManager      = $this->createMock( IAppManager::class );
		$this->metadataService = $this->createMock( MetadataService::class );
		$this->jobStats        = $this->createMock( JobStatsService::class );
		$this->time            = $this->createMock( ITimeFactory::class );

		$this->time->method( 'getTime' )
		           ->willReturn( 1_759_300_000 )
		;

		// The interval's default: an hour.
		$this->appConfig = $this->createMock( IAppConfig::class );
		$this->appConfig->method( 'getValueInt' )
		                ->willReturnArgument( 2 )
		;

		$this->mimeTypes = $this->createMock( MimeTypeService::class );

		$this->service = new StatusService(
			$this->databaseService,
			$this->appManager,
			$this->metadataService,
			$this->jobStats,
			$this->time,
			$this->appConfig,
			$this->mimeTypes,
		);
	}


//  other non-static methods

	/**
	 * A stored count younger than the interval is given as it is: counting
	 * reads every hash row, which a cold cache makes take seconds.
	 */
	public function testAStoredHashRowCountWithinTheIntervalIsGivenAsItIs(): void
	{
		$this->storedCount( 1_759_300_000 - 3_599 );
		$this->metadataService->expects( $this->never() )
		                      ->method( 'countHashEntries' )
		;

		$this->assertSame(
			[
				'rows'      => 406_419,
				'mimeTypes' => 1_234,
				'at'        => 1_759_300_000 - 3_599,
			],
			$this->service->getHashRowCount(),
		);
		$this->assertFalse( $this->service->isHashRowCountDue() );
	}

	/**
	 * Older than the interval, as with the background count off after a
	 * quiet hour: counted now, the MIME types with them, and stored.
	 */
	public function testAStoredHashRowCountOlderThanTheIntervalIsTakenAgain(): void
	{
		$this->storedCount( 1_759_300_000 - 3_600 );
		$this->metadataService->expects( $this->once() )
		                      ->method( 'countHashEntries' )
		                      ->willReturn( 406_500 )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'countMimeTypeRows' )
		                      ->willReturn( 1_300 )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with(
			               JobStatsService::JOB_CHECKSUM_COUNT,
			               [
				               'rows'       => 406_500,
				               'mime_types' => 1_300,
			               ],
		               )
		;

		$this->assertTrue( $this->service->isHashRowCountDue() );
		$this->assertSame(
			[
				'rows'      => 406_500,
				'mimeTypes' => 1_300,
				'at'        => 1_759_300_000,
			],
			$this->service->getHashRowCount(),
		);
	}

	/**
	 * A count kept before the MIME types were counted is taken again,
	 * however young: the status would have no number to show for them.
	 */
	public function testACountKeptWithoutTheMimeTypesIsTakenAgain(): void
	{
		$this->jobStats->method( 'lastRuns' )
		               ->willReturn( [
			               JobStatsService::JOB_CHECKSUM_COUNT => [
				               'lastRun' => 1_759_300_000 - 1,
				               'counts'  => [ 'rows' => 406_419 ],
			               ],
		               ] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'countMimeTypeRows' )
		                      ->willReturn( 7 )
		;

		$this->assertSame( 7, $this->service->getHashRowCount()['mimeTypes'] );
	}

	/** Where detection stands, with the types counted with the checksums. */
	public function testTheMimeTypeStatusIsTheStateAndTheKeptCount(): void
	{
		$this->storedCount( 1_759_300_000 - 1 );
		$this->metadataService->expects( $this->never() )
		                      ->method( 'countMimeTypeRows' )
		;
		$this->mimeTypes->method( 'getState' )
		                ->willReturn( MimeTypeService::STATE_DISABLED )
		;
		$this->mimeTypes->method( 'getDeleteRequestedAt' )
		                ->willReturn( null )
		;

		$this->assertSame(
			[
				'state'             => MimeTypeService::STATE_DISABLED,
				'rows'              => 1_234,
				'at'                => 1_759_300_000 - 1,
				'deleteRequestedAt' => null,
			],
			$this->service->getMimeTypeStatus( $this->service->getHashRowCount() ),
		);
	}

	/**
	 * While a deletion runs, the types are counted anew: the kept count
	 * would say "files left" unchanged until the next one.
	 */
	public function testTheMimeTypesAreCountedAnewWhileADeletionRuns(): void
	{
		$this->storedCount( 1_759_300_000 - 1 );
		$this->metadataService->expects( $this->once() )
		                      ->method( 'countMimeTypeRows' )
		                      ->willReturn( 600 )
		;
		$this->mimeTypes->method( 'getState' )
		                ->willReturn( MimeTypeService::STATE_DELETING )
		;
		$this->mimeTypes->method( 'getDeleteRequestedAt' )
		                ->willReturn( 1_759_299_000 )
		;

		$status = $this->service->getMimeTypeStatus( $this->service->getHashRowCount() );

		$this->assertSame( 600, $status['rows'] );
		$this->assertSame( 1_759_299_000, $status['deleteRequestedAt'] );
	}

	/** The panel's Refresh counts whatever the stored count's age. */
	public function testARecountIsTakenHoweverYoungTheStoredCount(): void
	{
		$this->storedCount( 1_759_300_000 - 1 );
		$this->metadataService->expects( $this->once() )
		                      ->method( 'countHashEntries' )
		                      ->willReturn( 406_420 )
		;

		$this->assertSame( 406_420, $this->service->getHashRowCount( true )['rows'] );
	}

	/** Off unless switched on: the status then counts when it is asked. */
	public function testTheBackgroundCountIsReadFromItsSwitch(): void
	{
		$this->appConfig->expects( $this->once() )
		                ->method( 'getValueBool' )
		                ->with( 'file_checksum_search', 'checksum_count_background' )
		                ->willReturn( true )
		;

		$this->assertTrue( $this->service->isHashRowCountInBackground() );
	}

	/**
	 * Nothing counted yet, as on a fresh install: counted once, and stored,
	 * so the status shows a number and the next read finds it.
	 */
	public function testWithNothingStoredTheHashRowsAreCountedOnceAndStored(): void
	{
		$this->jobStats->method( 'lastRuns' )
		               ->willReturn( [
			               JobStatsService::JOB_CHECKSUM_COUNT => [
				               'lastRun' => null,
				               'counts'  => [],
			               ],
		               ] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'countHashEntries' )
		                      ->willReturn( 42 )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with(
			               JobStatsService::JOB_CHECKSUM_COUNT,
			               [
				               'rows'       => 42,
				               'mime_types' => 0,
			               ],
		               )
		;

		$this->assertSame(
			[
				'rows'      => 42,
				'mimeTypes' => 0,
				'at'        => 1_759_300_000,
			],
			$this->service->getHashRowCount(),
		);
	}

	public function testGetPendingRowCountSumsStats(): void
	{
		$this->metadataService->expects( $this->once() )
		                      ->method( 'getPendingStats' )
		                      ->willReturn( [ 'lazy' => 5, 'missing' => 3, 'force' => 2 ] )
		;

		$result = $this->service->getPendingRowCount();

		$this->assertSame( 10, $result );
	}

	/** A stored count of 406,419, taken at $at. */
	private function storedCount( int $at ): void
	{
		$this->jobStats->method( 'lastRuns' )
		               ->willReturn( [
			               JobStatsService::JOB_CHECKSUM_COUNT => [
				               'lastRun' => $at,
				               'counts'  => [
					               'rows'       => 406_419,
					               'mime_types' => 1_234,
				               ],
			               ],
		               ] )
		;
	}
}
