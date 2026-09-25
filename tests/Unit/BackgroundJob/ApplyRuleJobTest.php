<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\BackgroundJob\ApplyRuleJob;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

class ApplyRuleJobTest
    extends
    TestCase
{

//  private properties

	private MockObject|RuleService     $ruleService;

	private MockObject|LoggerInterface $logger;

	private ApplyRuleJob               $job;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->ruleService = $this->createMock( RuleService::class );
		$this->logger      = $this->createMock( LoggerInterface::class );

		$this->job = new ApplyRuleJob(
			$this->createMock( ITimeFactory::class ),
			$this->ruleService,
			$this->logger,
		);
	}


//  config/init/exe/run methods

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function runJob( array $argument ): void
	{
		( new ReflectionMethod( ApplyRuleJob::class, 'run' ) )->invoke( $this->job, $argument );
	}


//  other non-static methods

	public function testAppliesTheRuleWithTheEnqueuingActor(): void
	{
		$rule = [
			'id'      => 'r1',
			'enabled' => true,
			'type'    => 'include',
		];
		$this->ruleService->method( 'findRuleById' )
		                  ->with( 'r1' )
		                  ->willReturn( $rule )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'applyRule' )
		                  ->with( $rule, null, null, 'alice' )
		                  ->willReturn( [
			                  'matched' => 1,
			                  'marked'  => 1,
			                  'skipped' => 0,
			                  'fresh'   => 0,
		                  ] )
		;

		$this->runJob(
			[
				'ruleId' => 'r1',
				'actor'  => 'alice',
			],
		);
	}

	public function testARuleDeletedBetweenEnqueueAndRunIsALogLineNotAnError(): void
	{
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( null )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'applyRule' )
		;
		$this->logger->expects( $this->once() )
		             ->method( 'info' )
		;
		$this->logger->expects( $this->never() )
		             ->method( 'error' )
		;

		$this->runJob( [ 'ruleId' => 'gone' ] );
	}

	public function testAFailureIsLoggedAndNeverEscapesTheJob(): void
	{
		// A rule disabled between enqueue and run makes applyRule throw;
		// the job's contract is "apply it if it still makes sense".
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn(
			                  [
				                  'id'      => 'r1',
				                  'enabled' => false,
			                  ],
		                  )
		;
		$this->ruleService->method( 'applyRule' )
		                  ->willThrowException( new RuntimeException( 'disabled' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		$this->runJob( [ 'ruleId' => 'r1' ] );

		$this->addToAssertionCount( 1 );
	}
}
