<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\ProcessPendingUpdates;
use OCA\FileChecksumSearch\BackgroundJob\RuleProcessingJob;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
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

	private MockObject|RuleService     $ruleService;

	private MockObject|IAppConfig      $appConfig;

	private MockObject|IJobList        $jobList;

	private MockObject|LoggerInterface $logger;

	private RuleProcessingJob          $job;


	protected function setUp(): void
	{

		parent::setUp();

		$this->time        = $this->createMock( ITimeFactory::class );
		$this->ruleService = $this->createMock( RuleService::class );
		$this->appConfig   = $this->createMock( IAppConfig::class );
		$this->jobList     = $this->createMock( IJobList::class );
		$this->logger      = $this->createMock( LoggerInterface::class );

		$this->appConfig->method( 'getValueInt' )
		                ->with( Application::APP_ID, 'rule_processing_interval', 300 )
		                ->willReturn( 300 )
		;

		$this->job = new RuleProcessingJob(
			$this->time,
			$this->ruleService,
			$this->appConfig,
			$this->jobList,
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
			$this->ruleService,
			$this->appConfig,
			$this->jobList,
			$this->logger,
		);

		$this->assertInstanceOf( RuleProcessingJob::class, $job );
	}


	public function testRunDelegatesToRuleService(): void
	{

		$this->ruleService->expects( $this->once() )
		                  ->method( 'evaluateRules' )
		                  ->willReturn( [
			                  'marked'  => 0,
			                  'matched' => 0,
		                  ] )
		;

		// No marks → no dispatch
		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;

		$reflection = new ReflectionMethod( RuleProcessingJob::class, 'run' );
		$reflection->invoke( $this->job, null );
	}


	public function testRunDispatchesWhenMarked(): void
	{

		$this->ruleService->expects( $this->once() )
		                  ->method( 'evaluateRules' )
		                  ->willReturn( [
			                  'marked'  => 5,
			                  'matched' => 10,
		                  ] )
		;

		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with( ProcessPendingUpdates::class )
		;

		$reflection = new ReflectionMethod( RuleProcessingJob::class, 'run' );
		$reflection->invoke( $this->job, null );
	}


	public function testRunCatchesThrowableAndLogsError(): void
	{

		$this->ruleService->expects( $this->once() )
		                  ->method( 'evaluateRules' )
		                  ->willThrowException( new RuntimeException( 'DB down' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		$reflection = new ReflectionMethod( RuleProcessingJob::class, 'run' );
		$reflection->invoke( $this->job, null );

		$this->assertTrue( true );
	}

}
