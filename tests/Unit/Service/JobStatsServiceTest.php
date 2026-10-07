<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\JobStatsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class JobStatsServiceTest
    extends
    TestCase
{

//  private properties

	private MockObject|IAppConfig      $appConfig;

	private MockObject|ITimeFactory    $timeFactory;

	private MockObject|LoggerInterface $logger;

	private JobStatsService            $service;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->appConfig   = $this->createMock( IAppConfig::class );
		$this->timeFactory = $this->createMock( ITimeFactory::class );
		$this->logger      = $this->createMock( LoggerInterface::class );

		$this->service = new JobStatsService(
			$this->appConfig,
			$this->timeFactory,
			$this->logger,
		);
	}


//  other non-static methods

	public function testRecordWritesTimestampCountsAndAttempt(): void
	{
		$this->timeFactory->method( 'getTime' )
		                  ->willReturn( 1700000000 )
		;

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueInt' )
		                ->with(
			                'file_checksum_search',
			                'stats_rule_sweep_last_run',
			                1700000000,
		                )
		;
		$written = $this->captureStrings();

		$this->service->record(
			JobStatsService::JOB_RULE_SWEEP,
			[
				'matched' => 12,
				'marked'  => 3,
			],
			1234,
		);

		$this->assertSame(
			[
				'stats_rule_sweep_last_counts'  => '{"matched":12,"marked":3}',
				'stats_rule_sweep_last_attempt' => '{"at":1700000000,"ok":true,"durationMs":1234}',
			],
			$written->getArrayCopy(),
		);
	}

	/**
	 * A failed run is its attempt alone: the last successful run and its
	 * counts stay, so the status can say when the job last worked.
	 */
	public function testAFailureWritesTheAttemptAndLeavesTheLastSuccess(): void
	{
		$this->timeFactory->method( 'getTime' )
		                  ->willReturn( 1700000300 )
		;
		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueInt' )
		;
		$written = $this->captureStrings();

		$this->service->recordFailure(
			JobStatsService::JOB_PENDING_DRAIN,
			new \RuntimeException( "Database\ngone away" ),
			56,
		);

		$this->assertSame(
			[
				'stats_pending_drain_last_attempt' => '{"at":1700000300,"ok":false,"durationMs":56,"reason":"RuntimeException: Database gone away"}',
			],
			$written->getArrayCopy(),
		);
	}

	/**
	 * The reason is one line of the status: a long message is cut, and says
	 * so.
	 */
	public function testALongReasonIsCut(): void
	{
		$written = $this->captureStrings();

		$this->service->recordFailure( JobStatsService::JOB_STAMP_CHECK, new \RuntimeException( str_repeat( 'x', 500 ) ) );

		$reason = json_decode( $written['stats_stamp_check_last_attempt'], true )['reason'];
		$this->assertSame( 200, mb_strlen( $reason ) );
		$this->assertStringEndsWith( 'x…', $reason );
	}

	public function testRecordingAFailureNeverThrows(): void
	{
		$this->appConfig->method( 'setValueString' )
		                ->willThrowException( new \RuntimeException( 'config store down' ) )
		;
		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		;

		$this->service->recordFailure( JobStatsService::JOB_RULE_SWEEP, new \RuntimeException( 'boom' ) );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @return \ArrayObject<string, string>  The strings written, by key.
	 */
	private function captureStrings(): \ArrayObject
	{
		$written = new \ArrayObject();

		$this->appConfig->method( 'setValueString' )
		                ->willReturnCallback(
			                static function(
				                string $app,
				                string $key,
				                string $value,
			                ) use
			                (
				                $written,
			                ): bool
			                {
				                $written[ $key ] = $value;

				                return true;
			                },
		                )
		;

		return $written;
	}

	public function testRecordNeverThrows(): void
	{
		// Bookkeeping must not be able to fail the job it books.
		$this->appConfig->method( 'setValueInt' )
		                ->willThrowException( new \RuntimeException( 'config store down' ) )
		;
		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		;

		$this->service->record( JobStatsService::JOB_PENDING_DRAIN, [] );

		$this->addToAssertionCount( 1 );
	}

	public function testLastRunsReportsEveryJobWithNullForNeverRan(): void
	{
		$this->appConfig->method( 'getValueInt' )
		                ->willReturnCallback(
			                static fn(
				                string $app,
				                string $key,
			                ): int => $key === 'stats_rule_sweep_last_run'
				                ? 1700000000
				                : 0,
		                )
		;
		$this->appConfig->method( 'getValueString' )
		                ->willReturnCallback(
			                static fn(
				                string $app,
				                string $key,
				                string $default = '',
			                ): string => match ( $key )
			                {
				                'stats_rule_sweep_last_counts'     => '{"matched":12,"marked":3}',
				                'stats_rule_sweep_last_attempt'    => '{"at":1700000600,"ok":false,"durationMs":56,"reason":"RuntimeException: boom"}',
				                'stats_orphan_purge_last_attempt'  => '{"at":1700000000,"ok":true}',
				                'stats_pending_drain_last_attempt' => '',
				                default                            => 'not json at all',
			                },
		                )
		;

		$runs = $this->service->lastRuns();

		$this->assertSame( 1700000000, $runs['rule_sweep']['lastRun'] );
		$this->assertSame( 12, $runs['rule_sweep']['counts']['matched'] );

		// The last attempt failed after the last success, which stays.
		$this->assertSame(
			[
				'at'         => 1700000600,
				'ok'         => false,
				'durationMs' => 56,
				'reason'     => 'RuntimeException: boom',
			],
			$runs['rule_sweep']['attempt'],
		);

		// An attempt recorded without a duration has none.
		$this->assertTrue( $runs['orphan_purge']['attempt']['ok'] );
		$this->assertNull( $runs['orphan_purge']['attempt']['durationMs'] );

		// Never ran: null timestamp; unreadable counts: empty, not fatal; no
		// attempt recorded, or one that does not read as one: null.
		$this->assertNull( $runs['pending_drain']['lastRun'] );
		$this->assertSame( [], $runs['pending_drain']['counts'] );
		$this->assertNull( $runs['pending_drain']['attempt'] );
		$this->assertNull( $runs['stamp_check']['attempt'] );

		// The three queued jobs and the rule applied on request are listed
		// beside the four timed ones, and every one has a name for the
		// console.
		$this->assertSame(
			[ 'rule_sweep', 'rule_apply', 'pending_drain', 'orphan_purge', 'filecache_backfill', 'hash_index_check', 'stamp_check', 'checksum_count' ],
			array_keys( $runs ),
		);
		$this->assertSame( array_keys( $runs ), array_keys( JobStatsService::LABELS ) );
	}
}
