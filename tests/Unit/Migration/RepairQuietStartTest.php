<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Migration;

use OCA\FileChecksumSearch\Migration\RepairQuietStart;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

class RepairQuietStartTest
	extends
	FciasUnitTestCase
{

	private MockObject|RuleService     $ruleService;

	private MockObject|IAppConfig      $appConfig;

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
		$this->appConfig       = $this->createMock( IAppConfig::class );
		$this->metadataService = $this->createMock( MetadataService::class );
		$hashIndexService      = $this->createMock( HashIndexService::class );
		$this->jobList         = $this->createMock( IJobList::class );
		$this->logger          = $this->createMock( LoggerInterface::class );
		$this->output          = $this->createMock( IOutput::class );

		$this->setUpQueryBuilderMock();

		$this->step = new RepairQuietStart(
			$this->ruleService,
			$this->metadataService,
			$hashIndexService,
			$this->appConfig,
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
	 * The declaration stops it happening again; this fixes what already
	 * happened — every hash sitting in a document with no index row, which
	 * is every long hash on every instance that ran before the fix.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testItIndexesHashesThatWereNeverIndexed(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'reindexHashes' )
		                      ->willReturn( 127 )
		;
		$this->output->expects( $this->atLeastOnce() )
		             ->method( 'info' )
		;

		$this->step->run( $this->output );
	}


	/**
	 * Repair steps run on upgrade, and one that throws stops the rest.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFailedBackfillDoesNotStopTheRepair(): void
	{

		$this->metadataService->method( 'reindexHashes' )
		                      ->willThrowException( new RuntimeException( 'no' ) )
		;

		$this->step->run( $this->output );

		$this->addToAssertionCount( 1 );
	}


	/**
	 * The declaration is stored once, by the install migration. An instance
	 * that already ran it keeps being told the hash keys are Nextcloud's to
	 * index — which is what made every SHA-256 row fail to write. Restating
	 * it on repair is what makes the fix reach instances that already exist.
	 *
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
	 *
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


	/**
	 * The registry cannot rot.
	 *
	 * `run()` iterates what reflection finds, so a step method without the
	 * attribute simply never runs — silently, with nothing to notice it. This
	 * is what notices.
	 */
	public function testEveryStepIsDeclaredAndDistinct(): void
	{

		$names = [];

		foreach ( $this->step->steps() as $entry )
		{
			$declared = $entry['step'];

			$this->assertMatchesRegularExpression(
				'/^[a-z][a-z-]*[a-z]$/',
				$declared->name,
				'step names are lower case and dashed, because --step has to be typed',
			);
			$this->assertNotSame( '', trim( $declared->title ) );
			$this->assertNotSame(
				'',
				trim( $declared->description ),
				$declared->name . ' has nothing to tell an administrator',
			);

			$names[] = $declared->name;
		}

		$this->assertSame( $names, array_unique( $names ), 'two steps answer to one name' );
		$this->assertGreaterThanOrEqual( 9, count( $names ) );
	}


	/**
	 * A private method that looks like a step but carries no attribute is
	 * dead code at best and a step nobody runs at worst.
	 */
	public function testNoStepMethodIsLeftUndeclared(): void
	{

		$declared = array_map(
			static fn(
				array $entry,
			): string => $entry['method']->getName(),
			$this->step->steps(),
		);

		// Helpers that share a step's shape without being one. Naming them
		// here is the point: a new method of this shape fails the test until
		// somebody decides which it is, rather than quietly never running.
		$helpers = [
			// Part of selector-model, and ordered inside it: the canonical
			// resave has to happen before it, and the defaults after.
			'retireOffMode',
			// Part of key-namespace: the declarations are withdrawn once the
			// keys they name are gone, in the same step and after it.
			'withdrawLegacyDeclarations',
		];

		$suspects = [];

		foreach ( ( new ReflectionClass( RepairQuietStart::class ) )->getMethods() as $method )
		{
			// The shape of a step: private, one IOutput parameter, returns nothing.
			if ( ! $method->isPrivate() || $method->getNumberOfParameters() !== 1 )
			{
				continue;
			}

			if ( (string) $method->getParameters()[0]->getType() !== IOutput::class )
			{
				continue;
			}

			if ( in_array( $method->getName(), $declared, true )
				|| in_array( $method->getName(), $helpers, true ) )
			{
				continue;
			}

			$suspects[] = $method->getName();
		}

		$this->assertSame(
			[],
			$suspects,
			'these look like steps but carry no #[RepairStep], so run() will never call them',
		);
	}


	/**
	 * Declaration order is the running order, so a method moved in the file
	 * moves in the repair. This pins the order that matters: the key
	 * declaration has to be refreshed before anything saves metadata, or
	 * Nextcloud tries to index a hash it cannot fit and the row is lost.
	 */
	public function testTheStepsRunInAnOrderThatWorks(): void
	{

		$names = array_map(
			static fn(
				array $entry,
			): string => $entry['step']->name,
			$this->step->steps(),
		);

		$this->assertSame(
			[
				'selector-model',
				'metadata-keys',
				'rebuild-from-filecache',
				'key-namespace',
				'rebuild-from-metadata',
				'unindexed-hashes',
				'clear-disowned',
				'stale-states',
				'legacy-pending',
				'legacy-seed-job',
			],
			$names,
		);
	}

	/**
	 * The whole point of the flag: the step costs a full table scan to find
	 * out there is nothing to do, so an upgrade must not pay for it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheScanForForgottenHashesDoesNotRunOnItsOwn(): void
	{

		$this->metadataService->expects( $this->never() )
		                      ->method( 'reindexUnstampedHashes' )
		;

		$ran = $this->step->runSteps( $this->output );

		$this->assertNotContains( 'unindexed-hashes', $ran );
	}


	/**
	 * Naming it counts as asking.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testNamingTheScanRunsIt(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'reindexUnstampedHashes' )
		                      ->willReturn( 3 )
		;

		$ran = $this->step->runSteps( $this->output, [ 'unindexed-hashes' ] );

		$this->assertSame( [ 'unindexed-hashes' ], $ran );
	}


	/**
	 * So does the flag that already means "do not ask me first".
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testIncludeExpensiveRunsTheScanToo(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'reindexUnstampedHashes' )
		                      ->willReturn( 0 )
		;

		$ran = $this->step->withExpensive()
		                  ->runSteps( $this->output )
		;

		$this->assertContains( 'unindexed-hashes', $ran );
	}


	/**
	 * It runs on upgrade like the rest, so a throw would stop the steps
	 * after it — and this one only ever runs when an administrator has
	 * already gone looking for trouble.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFailedScanDoesNotStopTheRepair(): void
	{

		$this->metadataService->method( 'reindexUnstampedHashes' )
		                      ->willThrowException( new RuntimeException( 'no' ) )
		;

		$ran = $this->step->withExpensive()
		                  ->runSteps( $this->output )
		;

		$this->assertContains( 'legacy-seed-job', $ran );
	}

}
