<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Migration;

use OCA\FileChecksumSearch\Migration\RepairQuietStart;
use OCA\FileChecksumSearch\Service\MetadataService;
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

	private MockObject|MetadataService $metadataService;

	private MockObject|IJobList        $jobList;

	private MockObject|LoggerInterface $logger;

	private MockObject|IOutput         $output;

	private RepairQuietStart           $step;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db              = $this->createMock( IDBConnection::class );
		$this->ruleService     = $this->createMock( RuleService::class );
		$this->metadataService = $this->createMock( MetadataService::class );
		$this->jobList         = $this->createMock( IJobList::class );
		$this->logger          = $this->createMock( LoggerInterface::class );
		$this->output          = $this->createMock( IOutput::class );

		$this->setUpQueryBuilderMock();

		$this->step = new RepairQuietStart(
			$this->ruleService,
			$this->metadataService,
			$this->db,
			$this->jobList,
			$this->logger,
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testCreatesBothShippedDefaultsDisabledWhenAbsent(): void
	{

		$this->ruleService->method( 'loadRules' )
		                  ->willReturn( [
			                  [
				                  'id'       => 'u1',
				                  'selector' => 'home:alice',
				                  'path'     => '/docs/**',
			                  ],
		                  ] )
		;

		$created = [];
		$this->ruleService->method( 'ruleAdd' )
		                  ->willReturnCallback(
			                  static function (
				                  array   $rule,
				                  ?string $actor,
			                  ) use
			                  (
				                  &
				                  $created,
			                  ): string
			                  {

				                  $created[] = [
					                  $rule['selector'],
					                  $rule['enabled'],
					                  $actor,
				                  ];

				                  return 'id';
			                  },
		                  )
		;

		$this->step->run( $this->output );

		// Both defaults, both disabled, audited as the repair step. Enabling
		// the home one is safe; the universal one — external storage and
		// group folders included — is its own deliberate switch.
		$this->assertSame(
			[
				[
					'home:*',
					false,
					'repair',
				],
				[
					'*',
					false,
					'repair',
				],
			],
			$created,
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testLeavesExistingDefaultsExactlyAsTheyAre(): void
	{

		// An enabled default stays enabled: upgrades never turn off what an
		// administrator turned on, and never duplicate what exists.
		$this->ruleService->method( 'loadRules' )
		                  ->willReturn( [
			                  [
				                  'id'       => 'd1',
				                  'selector' => 'home:*',
				                  'path'     => '**',
				                  'enabled'  => true,
			                  ],
			                  [
				                  'id'       => 'd2',
				                  'selector' => '*',
				                  'path'     => '**',
				                  'enabled'  => true,
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
	public function testConvertsRetiredOffModeRulesToIgnore(): void
	{

		$this->ruleService->method( 'loadRules' )
		                  ->willReturn( [
			                  [
				                  'id'       => 'r1',
				                  'enabled'  => true,
				                  'type'     => 'include',
				                  'path'     => '/Archive/**',
				                  'selector' => 'home:*',
				                  'mode'     => 'off',
				                  'algos'    => [ 'sha1' ],
			                  ],
			                  [
				                  'id'       => 'r2',
				                  'enabled'  => true,
				                  'type'     => 'include',
				                  'path'     => '**',
				                  'selector' => '*',
				                  'mode'     => 'auto',
			                  ],
		                  ] )
		;

		// Only the `off` rule is touched, and it becomes an ignore rule with
		// no mode and no algorithms — an ignore rule computes nothing, so
		// there is nothing for those to say.
		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleUpdate' )
		                  ->with(
			                  'r1',
			                  $this->callback(
				                  static fn(
					                  array $definition,
				                  ): bool => $definition['type'] === 'ignore'
					                  && ! isset( $definition['mode'] )
					                  && ! isset( $definition['algos'] )
					                  && $definition['path'] === '/Archive/**',
			                  ),
			                  'repair',
		                  )
		;

		$this->step->run( $this->output );
	}


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


	/**
	 * The declaration is stored once, by the install migration. An instance
	 * that already ran it keeps being told the hash keys are Nextcloud's to
	 * index — which is what made every SHA-256 row fail to write. Restating
	 * it on repair is what makes the fix reach instances that already exist.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testItRefreshesTheMetadataKeyDeclarations(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'register' )
		;

		$this->step->run( $this->output );
	}


	/**
	 * Repair steps run on upgrade, and one that throws stops the rest.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARefusedRefreshDoesNotStopTheRepair(): void
	{

		$this->metadataService->method( 'register' )
		                      ->willThrowException( new RuntimeException( 'no' ) )
		;

		$this->step->run( $this->output );

		$this->addToAssertionCount( 1 );
	}

}
