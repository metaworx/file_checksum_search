<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\BackgroundJob;

use OCA\FileChecksumSearch\BackgroundJob\ApplyRuleJob;
use OCA\FileChecksumSearch\Service\HintedInvalidArgumentException;
use OCA\FileChecksumSearch\Service\JobStatsService;
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

	private MockObject|JobStatsService $jobStats;

	private MockObject|LoggerInterface $logger;

	private ApplyRuleJob               $job;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->ruleService = $this->createMock( RuleService::class );
		$this->jobStats    = $this->createMock( JobStatsService::class );
		$this->logger      = $this->createMock( LoggerInterface::class );

		$this->job = new ApplyRuleJob(
			$this->createMock( ITimeFactory::class ),
			$this->ruleService,
			$this->jobStats,
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
			                  'matched' => 4,
			                  'marked'  => 1,
			                  'skipped' => 0,
			                  'fresh'   => 3,
		                  ] )
		;

		// Its run on the status, as the sweep's is.
		$this->jobStats->expects( $this->once() )
		               ->method( 'record' )
		               ->with(
			               JobStatsService::JOB_RULE_APPLY,
			               [
				               'matched' => 4,
				               'marked'  => 1,
			               ],
			               $this->isType( 'int' ),
		               )
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

		// The failure on the status, the last success left as it was.
		$this->jobStats->expects( $this->never() )
		               ->method( 'record' )
		;
		$this->jobStats->expects( $this->once() )
		               ->method( 'recordFailure' )
		               ->with(
			               JobStatsService::JOB_RULE_APPLY,
			               $this->isInstanceOf( RuntimeException::class ),
			               $this->isType( 'int' ),
		               )
		;

		$this->runJob( [ 'ruleId' => 'r1' ] );
	}

	/**
	 * The log reads English whatever language the refusal was translated
	 * into: RuleService's message, not its hint.
	 */
	public function testARefusalIsLoggedInEnglish(): void
	{
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( [ 'id' => 'r1' ] )
		;
		$this->ruleService->method( 'applyRule' )
		                  ->willThrowException(
			                  new HintedInvalidArgumentException( 'A disabled rule cannot be applied — enable it first.', '«translated»' ),
		                  )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		             ->with(
			             $this->anything(),
			             $this->callback(
				             static fn( array $context ): bool => $context['exception']->getMessage()
					             === 'A disabled rule cannot be applied — enable it first.',
			             ),
		             )
		;

		$this->runJob( [ 'ruleId' => 'r1' ] );
	}
}
