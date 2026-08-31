<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\Reset;
use OCA\FileChecksumSearch\Service\AppConfigService;
use OCA\FileChecksumSearch\Service\ExportService;
use OCA\FileChecksumSearch\Service\MetadataService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A command whose whole point is not doing anything.
 *
 * What it removes cannot be recovered by any other means, so the assertions
 * that matter are the ones about restraint: a run without `--force` must
 * reach no mutating method at all, and a run whose backup failed must reach
 * none either.
 */
class ResetTest
	extends
	TestCase
{

	private AppConfigService&MockObject $appConfigService;

	private MetadataService&MockObject  $metadataService;

	private ExportService&MockObject    $exportService;

	private CommandTester               $tester;

	private string                      $tempDir;


	protected function setUp(): void
	{

		parent::setUp();

		$this->appConfigService = $this->createMock( AppConfigService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->exportService    = $this->createMock( ExportService::class );

		$this->appConfigService->method( 'export' )
		                       ->willReturn( [ 'rule_definitions' => '[]' ] )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [ 'pending:auto' => 3 ] )
		;
		$this->metadataService->method( 'getStaleStats' )
		                      ->willReturn( [ 'stale:eroded' => 2 ] )
		;
		$this->metadataService->method( 'countHashedFiles' )
		                      ->willReturn( 1140 )
		;

		$this->tester  = new CommandTester( $this->command() );
		$this->tempDir = sys_get_temp_dir() . '/fcias-reset-test-' . getmypid();
		@mkdir( $this->tempDir, 0o777, true );
	}


	protected function tearDown(): void
	{

		foreach (
			glob( $this->tempDir . '/*' )
				?: [] as $file
		)
		{
			@unlink( $file );
		}
		@rmdir( $this->tempDir );

		parent::tearDown();
	}


	/**
	 * The central assertion of the whole command.
	 */
	public function testWithoutForceItChangesNothingAtAll(): void
	{

		$this->expectNoMutation();

		$this->assertSame( Command::SUCCESS, $this->tester->execute( [] ) );
	}


	public function testWithoutForceItSaysWhatWouldHappenAndHow(): void
	{

		$this->tester->execute( [] );
		$display = $this->tester->getDisplay();

		$this->assertStringContainsString( '1 keys would be forgotten', $display );
		$this->assertStringContainsString( '3 queued and 2 untrusted markers', $display );
		$this->assertStringContainsString( '1140 files would lose their checksums', $display );
		$this->assertStringContainsString( '--force', $display );
	}


	public function testNamingNoSliceResetsAllOfThem(): void
	{

		$this->appConfigService->expects( $this->once() )
		                       ->method( 'clear' )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearQueueState' )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markAllStale' )
		;

		$this->assertSame( Command::SUCCESS, $this->tester->execute( [ '--force' => true ] ) );
	}


	public function testNamingSlicesLeavesTheOthersAlone(): void
	{

		$this->appConfigService->expects( $this->never() )
		                       ->method( 'clear' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearQueueState' )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markAllStale' )
		;

		$this->tester->execute(
			[
				'--hashes' => true,
				'--force'  => true,
			],
		);
	}


	/**
	 * Disowning is one write per thousand files; clearing is one metadata
	 * document rewrite each. The default defers, and says so.
	 */
	public function testHashesAreDisownedRatherThanCleared(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markAllStale' )
		                      ->willReturn( 1140 )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearHashesNow' )
		;

		$this->tester->execute(
			[
				'--hashes' => true,
				'--force'  => true,
			],
		);

		$this->assertStringContainsString( '1140 files disowned', $this->tester->getDisplay() );
	}


	public function testNowClearsInTheForegroundInstead(): void
	{

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markAllStale' )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearHashesNow' )
		                      ->willReturn( 1140 )
		;

		$this->tester->execute(
			[
				'--hashes' => true,
				'--force'  => true,
				'--now'    => true,
			],
		);

		$this->assertStringContainsString( '1140 files cleared', $this->tester->getDisplay() );
	}


	public function testTheBackupIsWrittenBeforeAnythingIsReset(): void
	{

		$order = [];

		$this->exportService->method( 'export' )
		                    ->willReturnCallback(
			                    static function () use
			                    (
				                    &
				                    $order,
			                    ): array
			                    {

				                    $order[] = 'backup';

				                    return [];
			                    },
		                    )
		;
		$this->metadataService->method( 'markAllStale' )
		                      ->willReturnCallback(
			                      static function () use
			                      (
				                      &
				                      $order,
			                      ): int
			                      {

				                      $order[] = 'reset';

				                      return 1;
			                      },
		                      )
		;

		$this->tester->execute(
			[
				'--hashes' => true,
				'--force'  => true,
				'--backup' => $this->tempDir . '/before.json',
			],
		);

		$this->assertSame(
			[
				'backup',
				'reset',
			],
			$order,
		);
	}


	/**
	 * A safety net that tears is worse than none, because the operator went
	 * ahead believing they had one.
	 */
	public function testAFailedBackupResetsNothing(): void
	{

		$this->exportService->method( 'export' )
		                    ->willThrowException( new RuntimeException( 'disk full' ) )
		;
		$this->expectNoMutation();

		$this->assertSame(
			Command::FAILURE,
			$this->tester->execute(
				[
					'--force'  => true,
					'--backup' => $this->tempDir . '/before.json',
				],
			),
		);
		$this->assertStringContainsString( 'Nothing was reset', $this->tester->getDisplay() );
	}


	public function testAnUnwritableBackupPathResetsNothing(): void
	{

		$this->exportService->expects( $this->never() )
		                    ->method( 'export' )
		;
		$this->expectNoMutation();

		$this->assertSame(
			Command::FAILURE,
			$this->tester->execute(
				[
					'--force'  => true,
					'--backup' => $this->tempDir . '/no/such/directory/before.json',
				],
			),
		);
	}


	/**
	 * Somebody has to be able to find out afterwards who did this.
	 */
	public function testForcingLogsOneAuditLineNamingTheActorAndTheSlices(): void
	{

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )
		       ->method( 'warning' )
		       ->with(
			       $this->stringContains( 'reset carried out' ),
			       $this->callback(
				       static fn(
					       array $context,
				       ): bool => $context['slices'] === 'config, hashes'
					       && ( $context['actor'] ?? '' ) !== '',
			       ),
		       )
		;

		( new CommandTester( $this->command( $logger ) ) )->execute(
			[
				'--config' => true,
				'--hashes' => true,
				'--force'  => true,
			],
		);
	}


	public function testAPlanIsNotAudited(): void
	{

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->never() )
		       ->method( 'warning' )
		;

		( new CommandTester( $this->command( $logger ) ) )->execute( [] );
	}


	/**
	 * Every way this command can change something, forbidden at once.
	 */
	private function expectNoMutation(): void
	{

		$this->appConfigService->expects( $this->never() )
		                       ->method( 'clear' )
		;
		$this->appConfigService->expects( $this->never() )
		                       ->method( 'import' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearQueueState' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markAllStale' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearHashesNow' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;
	}


	private function command( ?LoggerInterface $logger = null ): Reset
	{

		return new Reset(
			$this->appConfigService,
			$this->metadataService,
			$this->exportService,
			$logger ?? $this->createMock( LoggerInterface::class ),
		);
	}

}
