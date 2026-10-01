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
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

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

	private TableNameService&MockObject $tables;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private IAppManager&MockObject      $appManager;

	private MetadataService&MockObject  $metadataService;

	private JobStatsService&MockObject  $jobStats;

	private ITimeFactory&MockObject     $time;

	private IAppConfig&MockObject       $appConfig;

	private StatusService               $service;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->databaseService = $this->createMock( DatabaseService::class );
		$this->tables          = $this->createMock( TableNameService::class );
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

		$this->service = new StatusService(
			$this->databaseService,
			$this->tables,
			$this->appManager,
			$this->metadataService,
			$this->jobStats,
			$this->time,
			$this->appConfig,
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
				'rows' => 406_419,
				'at'   => 1_759_300_000 - 3_599,
			],
			$this->service->getHashRowCount(),
		);
		$this->assertFalse( $this->service->isHashRowCountDue() );
	}

	/**
	 * Older than the interval, as with the background count off after a
	 * quiet hour: counted now, and stored.
	 */
	public function testAStoredHashRowCountOlderThanTheIntervalIsTakenAgain(): void
	{
		$this->storedCount( 1_759_300_000 - 3_600 );
		$this->metadataService->expects( $this->once() )
		                      ->method( 'countHashEntries' )
		                      ->willReturn( 406_500 )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with( JobStatsService::JOB_CHECKSUM_COUNT, [ 'rows' => 406_500 ] )
		;

		$this->assertTrue( $this->service->isHashRowCountDue() );
		$this->assertSame(
			[
				'rows' => 406_500,
				'at'   => 1_759_300_000,
			],
			$this->service->getHashRowCount(),
		);
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
		               ->with( JobStatsService::JOB_CHECKSUM_COUNT, [ 'rows' => 42 ] )
		;

		$this->assertSame(
			[
				'rows' => 42,
				'at'   => 1_759_300_000,
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

	public function testGetMigrationStatusComparesAgainstInstalled(): void
	{
		$output = $this->createMock( OutputInterface::class );

		$this->databaseService->expects( $this->once() )
		                      ->method( 'getInstalledMigrations' )
		                      ->with( 'file_checksum_search', $output )
		                      ->willReturn( [] )
		;

		$result = $this->service->getMigrationStatus( $output );

		$this->assertIsArray( $result );

		// Each entry must have name (string) and ok (bool) keys
		foreach ( $result as $entry )
		{
			$this->assertArrayHasKey( 'name', $entry );
			$this->assertArrayHasKey( 'ok', $entry );
			$this->assertIsString( $entry['name'] );
			$this->assertIsBool( $entry['ok'] );

			// When installed is empty, all migrations should report ok=false
			$this->assertFalse( $entry['ok'] );
		}
	}

	public function testHasChecksumColumnReturnsBool(): void
	{
		$output = $this->createMock( OutputInterface::class );

		$this->tables->expects( $this->once() )
		             ->method( 'getFilecacheTableName' )
		             ->willReturn( 'oc_filecache' )
		;

		$this->databaseService->expects( $this->once() )
		                      ->method( 'columnExists' )
		                      ->with( 'oc_filecache', 'checksum', $output )
		                      ->willReturn( true )
		;

		$result = $this->service->hasChecksumColumn( $output );

		$this->assertTrue( $result );
	}

	/** A stored count of 406,419, taken at $at. */
	private function storedCount( int $at ): void
	{
		$this->jobStats->method( 'lastRuns' )
		               ->willReturn( [
			               JobStatsService::JOB_CHECKSUM_COUNT => [
				               'lastRun' => $at,
				               'counts'  => [ 'rows' => 406_419 ],
			               ],
		               ] )
		;
	}
}
