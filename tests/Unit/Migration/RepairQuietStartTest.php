<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Migration;

use OCA\FileChecksumSearch\Migration\RepairQuietStart;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;

class RepairQuietStartTest
	extends
	FciasUnitTestCase
{

	private MockObject|RuleService     $ruleService;

	private MockObject|IJobList        $jobList;

	private MockObject|LoggerInterface $logger;

	private MockObject|IOutput         $output;

	private RepairQuietStart           $step;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db          = $this->createMock( IDBConnection::class );
		$this->ruleService = $this->createMock( RuleService::class );
		$this->jobList     = $this->createMock( IJobList::class );
		$this->logger      = $this->createMock( LoggerInterface::class );
		$this->output      = $this->createMock( IOutput::class );

		$this->setUpQueryBuilderMock();

		$this->step = new RepairQuietStart(
			$this->ruleService,
			$this->db,
			$this->jobList,
			$this->logger,
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testCreatesTheDefaultRuleDisabledWhenNoGlobalRuleExists(): void
	{

		$this->ruleService->method( 'loadRules' )
		                  ->willReturn( [
			                  [
				                  'id'        => 'u1',
				                  'userScope' => 'alice',
				                  'path'      => '/docs/**',
			                  ],
		                  ] )
		;

		// The one property everything else hangs on: it is created disabled.
		// No app silently starts doing work the admin has not configured.
		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleAdd' )
		                  ->with(
			                  $this->callback(
				                  static fn(
					                  array $rule,
				                  ): bool => $rule['enabled'] === false
					                  && $rule['pinned'] === true
					                  && $rule['userScope'] === RuleService::SCOPE_ALL
					                  && $rule['path'] === '**'
					                  && $rule['type'] === RuleService::TYPE_INCLUDE,
			                  ),
		                  )
		;

		$this->step->run( $this->output );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testLeavesAnExistingGlobalRuleExactlyAsItIs(): void
	{

		// Upgrades never turn off what an administrator turned on: an
		// enabled catch-all stays enabled, and no second one is added.
		$this->ruleService->method( 'loadRules' )
		                  ->willReturn( [
			                  [
				                  'id'        => 'g1',
				                  'userScope' => 'all',
				                  'path'      => '**',
				                  'enabled'   => true,
				                  'pinned'    => true,
			                  ],
		                  ] )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleAdd' )
		;

		$this->step->run( $this->output );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testPurgesLegacyPendingNewRows(): void
	{

		$this->ruleService->method( 'loadRules' )
		                  ->willReturn( [ [ 'userScope' => 'all' ] ] )
		;

		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'delete' )
		                   ->with( 'files_metadata_index' )
		                   ->willReturnSelf()
		;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturn( 12 )
		;

		$this->output->expects( $this->atLeastOnce() )
		             ->method( 'info' )
		;

		$this->step->run( $this->output );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRemovesTheLegacySeedJobOnlyWhenScheduled(): void
	{

		$this->ruleService->method( 'loadRules' )
		                  ->willReturn( [ [ 'userScope' => 'all' ] ] )
		;

		// The class is deleted, so the job list is addressed by its FQCN
		// string — Nextcloud does not clean up scheduled instances of a
		// class that no longer exists.
		$this->jobList->method( 'has' )
		              ->with( 'OCA\\FileChecksumSearch\\BackgroundJob\\SeedPendingUpdates', null )
		              ->willReturn( true )
		;
		$this->jobList->expects( $this->once() )
		              ->method( 'remove' )
		              ->with( 'OCA\\FileChecksumSearch\\BackgroundJob\\SeedPendingUpdates' )
		;

		$this->step->run( $this->output );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testWarnsInsteadOfThrowingSoTheUpgradeFinishes(): void
	{

		// A throwing repair step aborts the whole Nextcloud upgrade;
		// everything here is recoverable by hand, so it must not.
		$this->ruleService->method( 'loadRules' )
		                  ->willThrowException( new RuntimeException( 'config unreadable' ) )
		;

		$this->output->expects( $this->atLeastOnce() )
		             ->method( 'warning' )
		;
		$this->logger->expects( $this->atLeastOnce() )
		             ->method( 'error' )
		;

		$this->step->run( $this->output );
	}

}
