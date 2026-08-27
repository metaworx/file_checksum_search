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

	private MockObject|IAppConfig      $appConfig;

	private MockObject|ITimeFactory    $timeFactory;

	private MockObject|LoggerInterface $logger;

	private JobStatsService            $service;


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


	public function testRecordWritesTimestampAndCounts(): void
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
		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with(
			                'file_checksum_search',
			                'stats_rule_sweep_last_counts',
			                json_encode( [
				                'matched' => 12,
				                'marked'  => 3,
			                ] ),
		                )
		;

		$this->service->record(
			JobStatsService::JOB_RULE_SWEEP,
			[
				'matched' => 12,
				'marked'  => 3,
			],
		);
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
			                ): string => $key === 'stats_rule_sweep_last_counts'
				                ? '{"matched":12,"marked":3}'
				                : 'not json at all',
		                )
		;

		$runs = $this->service->lastRuns();

		$this->assertSame( 1700000000, $runs['rule_sweep']['lastRun'] );
		$this->assertSame( 12, $runs['rule_sweep']['counts']['matched'] );

		// Never ran: null timestamp; unreadable counts: empty, not fatal.
		$this->assertNull( $runs['pending_drain']['lastRun'] );
		$this->assertSame( [], $runs['pending_drain']['counts'] );
	}

}
