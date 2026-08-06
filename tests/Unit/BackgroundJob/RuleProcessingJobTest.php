<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\RuleProcessingJob;
use OCA\FileChecksumSearch\Service\CronJobService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

class RuleProcessingJobTest
	extends
	TestCase
{

	private MockObject|ITimeFactory    $time;

	private MockObject|CronJobService  $cronJobService;

	private MockObject|MetadataService $metadataService;

	private MockObject|IAppConfig      $appConfig;

	private MockObject|LoggerInterface $logger;

	private RuleProcessingJob          $job;


	protected function setUp(): void
	{

		parent::setUp();

		$this->time            = $this->createMock( ITimeFactory::class );
		$this->cronJobService  = $this->createMock( CronJobService::class );
		$this->metadataService = $this->createMock( MetadataService::class );
		$this->appConfig       = $this->createMock( IAppConfig::class );
		$this->logger          = $this->createMock( LoggerInterface::class );

		$this->appConfig->method( 'getValueInt' )
		                ->with( Application::APP_ID, 'rule_processing_interval', 300 )
		                ->willReturn( 300 )
		;

		$this->job = new RuleProcessingJob(
			$this->time,
			$this->cronJobService,
			$this->metadataService,
			$this->appConfig,
			$this->logger,
		);
	}


	public function testJobConstructsWithDefaultInterval(): void
	{

		$this->appConfig->expects( $this->once() )
		                ->method( 'getValueInt' )
		                ->with( Application::APP_ID, 'rule_processing_interval', 300 )
		;

		$job = new RuleProcessingJob(
			$this->time,
			$this->cronJobService,
			$this->metadataService,
			$this->appConfig,
			$this->logger,
		);

		$this->assertInstanceOf( RuleProcessingJob::class, $job );
	}


	public function testRunWithNoDefinitionsLogsAndReturns(): void
	{

		$this->cronJobService->expects( $this->once() )
		                     ->method( 'listDefinitions' )
		                     ->willReturn( [] )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'seedIndex' )
		;

		$this->logger->expects( $this->atLeastOnce() )
		             ->method( 'info' )
		;

		$reflection = new ReflectionMethod( RuleProcessingJob::class, 'run' );
		$reflection->invoke( $this->job, null );
	}


	public function testRunWithEnabledDefinitionsCallsSeedIndex(): void
	{

		$definitions = [
			[
				'enabled'   => true,
				'userScope' => 'admin',
				'algo'      => 'sha256',
				'path'      => '',
				'batchSize' => 50,
				'interval'  => 3600,
			],
		];

		$this->cronJobService->expects( $this->once() )
		                     ->method( 'listDefinitions' )
		                     ->willReturn( $definitions )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'seedIndex' )
		                      ->willReturn( 5 )
		;

		$reflection = new ReflectionMethod( RuleProcessingJob::class, 'run' );
		$reflection->invoke( $this->job, null );
	}


	public function testRunSkipsDisabledDefinitions(): void
	{

		$definitions = [
			[
				'enabled'   => false,
				'userScope' => 'admin',
				'algo'      => 'sha256',
			],
		];

		$this->cronJobService->expects( $this->once() )
		                     ->method( 'listDefinitions' )
		                     ->willReturn( $definitions )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'seedIndex' )
		;

		$reflection = new ReflectionMethod( RuleProcessingJob::class, 'run' );
		$reflection->invoke( $this->job, null );
	}


	public function testRunCatchesThrowable(): void
	{

		$this->cronJobService->expects( $this->once() )
		                     ->method( 'listDefinitions' )
		                     ->willThrowException( new RuntimeException( 'DB down' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		$reflection = new ReflectionMethod( RuleProcessingJob::class, 'run' );

		// Should not throw — exception is caught and logged.
		$reflection->invoke( $this->job, null );

		$this->assertTrue( true );
	}

}
