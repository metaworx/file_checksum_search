<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\ShowStatus;
use OCA\FileChecksumSearch\Service\DatabaseService;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ShowStatusTest
    extends
    FciasUnitTestCase
{

//  private properties

	private MockObject|MetadataService $metadataService;

	private MockObject|IAppConfig      $appConfig;

	private MockObject|JobStatsService $jobStats;

	private ShowStatus                 $command;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private MockObject|LoggerInterface $logger;

	private CommandTester              $tester;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->db = $this->createMock( IDBConnection::class );
		$this->setUpQueryBuilderMock();

		$this->metadataService = $this->createMock( MetadataService::class );
		$this->appConfig       = $this->createMock( IAppConfig::class );
		$this->logger          = $this->createMock( LoggerInterface::class );
		$this->jobStats        = $this->createMock( JobStatsService::class );
		$this->jobStats->method( 'lastRuns' )
		               ->willReturn(
			               [
				               'rule_sweep'         => [ 'lastRun' => 1700000000, 'counts' => [ 'matched' => 12, 'marked' => 3 ] ],
				               'filecache_backfill' => [ 'lastRun' => 1700000100, 'counts' => [ 'copied' => 1200, 'files' => 900, 'done' => 0 ] ],
				               'hash_index_check'   => [ 'lastRun' => null, 'counts' => [] ],
				               // Counted a second before the mocked clock, so kept.
				               JobStatsService::JOB_CHECKSUM_COUNT => [ 'lastRun' => 1700000499, 'counts' => [ 'rows' => 406419 ] ],
			               ],
		               )
		;
		// The interval's default, an hour; the background count off.
		$this->appConfig->method( 'getValueInt' )
		                ->willReturnArgument( 2 )
		;

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchOne' )
		       ->willReturnOnConsecutiveCalls( 5000, 4200 )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$time = $this->createMock( ITimeFactory::class );
		$time->method( 'getTime' )
		     ->willReturn( 1700000500 )
		;

		// StatusService is readonly, so not a mock: a real one over this
		// test's own metadata, job-stats and config mocks.
		$this->command = new ShowStatus(
			$this->db,
			$this->metadataService,
			$this->appConfig,
			new StatusService(
				$this->createMock( DatabaseService::class ),
				$this->createMock( TableNameService::class ),
				$this->createMock( IAppManager::class ),
				$this->metadataService,
				$this->jobStats,
				$time,
				$this->appConfig,
			),
			$this->logger,
		);
		$this->tester  = new CommandTester( $this->command );
	}


//  other non-static methods

	public function testPlainOutputShowsCountsAndVersion(): void
	{
		$this->appConfig->method( 'getValueString' )
		                ->with( 'file_checksum_search', 'installed_version', 'unknown' )
		                ->willReturn( '1.9.2' )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn(
			                      [
				                      'pending:auto'  => 3,
				                      'pending:force' => 1,
			                      ],
		                      )
		;

		$exitCode = $this->tester->execute( [ '--full' => true ] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( '1.9.2', $display );
		$this->assertStringContainsString( 'Filecache entries:      5000', $display );
		$this->assertStringContainsString( 'Metadata updated_at:    4200', $display );
		$this->assertStringContainsString( 'Pending total:          4', $display );
		$this->assertStringContainsString( 'pending:auto', $display );
	}

	/**
	 * Without --full, nothing that reads a whole table: the filecache and the
	 * stamp rows go uncounted and say how to have them, and the checksum
	 * count is the kept one, with when it was taken.
	 */
	public function testWithoutFullTheWholeTableCountsAreLeftOut(): void
	{
		$this->appConfig->method( 'getValueString' )
		                ->willReturn( '1.9.2' )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [] )
		;
		$this->queryBuilder->expects( $this->never() )
		                   ->method( 'executeQuery' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'countHashEntries' )
		;

		$this->tester->execute( [] );
		$display = $this->tester->getDisplay();

		$this->assertMatchesRegularExpression( '/Indexed checksums:\s+406419\s+\(counted \d{4}-\d{2}-\d{2} [\d:]+ \S+\)/', $display );
		$this->assertStringContainsString( 'Filecache entries:      (with --full)', $display );
		$this->assertStringContainsString( 'Metadata updated_at:    (with --full)', $display );

		$this->tester->execute( [ '--output' => 'json' ] );
		$decoded = json_decode( trim( $this->tester->getDisplay() ), true );

		$this->assertSame( 406419, $decoded['checksum_rows'] );
		$this->assertSame( 1700000499, $decoded['checksum_rows_at'] );
		$this->assertArrayNotHasKey( 'filecache_rows', $decoded );
		$this->assertArrayNotHasKey( 'metadata_rows', $decoded );
	}

	/** --full counts the indexed checksums anew, whatever the kept count's age. */
	public function testFullCountsTheChecksumsAnew(): void
	{
		$this->appConfig->method( 'getValueString' )
		                ->willReturn( '1.9.2' )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'countHashEntries' )
		                      ->willReturn( 406500 )
		;

		$this->tester->execute( [ '--output' => 'json', '--full' => true ] );
		$decoded = json_decode( trim( $this->tester->getDisplay() ), true );

		$this->assertSame( 406500, $decoded['checksum_rows'] );
		$this->assertSame( 1700000500, $decoded['checksum_rows_at'] );
	}

	/** The checksum count is a background job only while switched on, as on the page. */
	public function testTheChecksumCountIsListedAsAJobOnlyWhileSwitchedOn(): void
	{
		$this->appConfig->method( 'getValueString' )
		                ->willReturn( '1.9.2' )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [] )
		;

		$this->tester->execute( [] );

		$this->assertStringNotContainsString( 'Checksum count', $this->tester->getDisplay() );
	}

	/**
	 * The background jobs, each with its last run or "never ran yet" and its
	 * counts: where a queued copy or check can be followed from the console.
	 */
	public function testPlainOutputListsTheBackgroundJobs(): void
	{
		$this->appConfig->method( 'getValueString' )
		                ->willReturn( 'unknown' )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [] )
		;

		$this->tester->execute( [] );

		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( 'Background jobs:', $display );
		$this->assertMatchesRegularExpression( '/Rule sweep\s+\d{4}-\d{2}-\d{2} [\d:]+ \S+\s+matched 12, marked 3/', $display );
		$this->assertStringContainsString( 'copied 1200, files 900, done 0', $display );
		$this->assertMatchesRegularExpression( '/Checksum index check\s+never ran yet/', $display );
	}

	public function testJsonOutputCarriesTheJobsInThePagesShape(): void
	{
		$this->appConfig->method( 'getValueString' )
		                ->willReturn( 'unknown' )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [] )
		;

		$this->tester->execute( [ '--output' => 'json' ] );

		$decoded = json_decode( trim( $this->tester->getDisplay() ), true );
		$this->assertSame( 1700000100, $decoded['jobs']['filecache_backfill']['lastRun'] );
		$this->assertSame( [ 'copied' => 1200, 'files' => 900, 'done' => 0 ], $decoded['jobs']['filecache_backfill']['counts'] );
		$this->assertNull( $decoded['jobs']['hash_index_check']['lastRun'] );
	}

	public function testItAnswersToTheShortName(): void
	{
		$this->assertContains( 'fcias:status', $this->command->getAliases() );
	}

	public function testPlainOutputOmitsPendingByModeWhenEmpty(): void
	{
		$this->appConfig->method( 'getValueString' )
		                ->willReturn( 'unknown' )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [] )
		;

		$this->tester->execute( [] );

		$this->assertStringNotContainsString( 'Pending by mode:', $this->tester->getDisplay() );
	}

	public function testJsonOutputFormat(): void
	{
		$this->appConfig->method( 'getValueString' )
		                ->willReturn( '1.9.2' )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [ 'pending:auto' => 2 ] )
		;

		$this->tester->execute( [ '--output' => 'json', '--full' => true ] );

		$decoded = json_decode( trim( $this->tester->getDisplay() ), true );
		$this->assertSame( '1.9.2', $decoded['app_version'] );
		$this->assertSame( 5000, $decoded['filecache_rows'] );
		$this->assertSame( 4200, $decoded['metadata_rows'] );
		$this->assertSame( 2, $decoded['pending_total'] );
		$this->assertSame( [ 'pending:auto' => 2 ], $decoded['pending_by_mode'] );
	}

	/**
	 * One total and a breakdown, because the reasons are opposites in what
	 * they leave behind: erosion has already thrown the hashes away and
	 * heals itself, a reset has not and is waiting for the drain.
	 */
	public function testUntrustedHashesAreReportedByReason(): void
	{
		$this->appConfig->method( 'getValueString' )
		                ->willReturn( '1.9.2' )
		;
		$this->metadataService->method( 'getStaleStats' )
		                      ->willReturn(
			                      [
				                      MetadataService::STATE_ERODED => 4,
				                      MetadataService::STATE_RESET  => 9,
			                      ],
		                      )
		;

		$this->tester->execute( [] );
		$display = $this->tester->getDisplay();

		$this->assertStringContainsString( 'Untrusted total:        13', $display );
		$this->assertStringContainsString( 'stale:eroded', $display );
		$this->assertStringContainsString( 'heals once a rule covers', $display );
		$this->assertStringContainsString( 'stale:reset', $display );
		$this->assertStringContainsString( 'disowned by a reset', $display );
	}

	public function testUntrustedHashesAreInTheJsonToo(): void
	{
		$this->appConfig->method( 'getValueString' )
		                ->willReturn( '1.9.2' )
		;
		$this->metadataService->method( 'getStaleStats' )
		                      ->willReturn( [ MetadataService::STATE_RESET => 9 ] )
		;

		$this->tester->execute( [ '--output' => 'json' ] );
		$decoded = json_decode( trim( $this->tester->getDisplay() ), true );

		$this->assertSame( 9, $decoded['untrusted_total'] );
		$this->assertSame( [ MetadataService::STATE_RESET => 9 ], $decoded['untrusted_by_reason'] );
	}

	public function testNothingUntrustedOmitsTheBreakdown(): void
	{
		$this->appConfig->method( 'getValueString' )
		                ->willReturn( 'unknown' )
		;
		$this->metadataService->method( 'getStaleStats' )
		                      ->willReturn( [] )
		;

		$this->tester->execute( [] );
		$display = $this->tester->getDisplay();

		$this->assertStringContainsString( 'Untrusted total:        0', $display );
		$this->assertStringNotContainsString( 'Untrusted by reason:', $display );
	}
}
