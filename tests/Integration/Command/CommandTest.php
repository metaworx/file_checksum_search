<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Command;

use OCA\FileChecksumSearch\Command\FindDuplicates;
use OCA\FileChecksumSearch\Command\HashFiles;
use OCA\FileChecksumSearch\Command\Repair;
use OCA\FileChecksumSearch\Command\SearchHash;
use OCA\FileChecksumSearch\Command\ShowConfig;
use OCA\FileChecksumSearch\Command\ShowStatus;
use OCA\FileChecksumSearch\Command\TestPerformance;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests for CLI commands via CommandTester.
 */
class CommandTest
	extends
	DatabaseTestCase
{

	private MockObject|LoggerInterface $logger;


	protected function setUp(): void
	{

		parent::setUp();

		$this->logger = $this->createMock( LoggerInterface::class );
	}


	// ─── SearchHash ──────────────────────────────────────────────────

	public function testSearchHashWithUnknownHashReturnsFailure(): void
	{

		$hashIndexService = $this->createMock( HashIndexService::class );
		$hashIndexService->method( 'findByHash' )
		                 ->willReturn( [] )
		;

		$command = new SearchHash( $hashIndexService, $this->logger );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [ 'query' => 'deadbeefdeadbeefdeadbeefdeadbeef' ] );

		$this->assertSame( Command::FAILURE, $exitCode );
		$this->assertStringContainsString( 'No files found.', $tester->getDisplay() );
	}


	public function testSearchHashWithAlgoColonFormat(): void
	{

		$hashIndexService = $this->createMock( HashIndexService::class );
		$hashIndexService->expects( $this->once() )
		                 ->method( 'findByHash' )
		                 ->with( 'abc123abc123abc123abc123abc123ab', 'sha1' )
		                 ->willReturn( [
			                 [
				                 'fileid' => 42,
				                 'algo'   => 'sha1',
				                 'path'   => '/Docs',
				                 'name'   => 'report.pdf',
			                 ],
		                 ] )
		;

		$command = new SearchHash( $hashIndexService, $this->logger );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [ 'query' => 'sha1:abc123abc123abc123abc123abc123ab' ] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'report.pdf', $tester->getDisplay() );
	}


	// ─── ShowStatus ──────────────────────────────────────────────────


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testShowStatusJsonOutputIsValid(): void
	{

		$command = Server::get( ShowStatus::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [ '--output' => 'json' ] );

		$this->assertSame( Command::SUCCESS, $exitCode );

		$data = json_decode( $tester->getDisplay(), true );
		$this->assertIsArray( $data, 'JSON output should be valid.' );
		$this->assertArrayHasKey( 'app_version', $data );
		$this->assertArrayHasKey( 'filecache_rows', $data );
		$this->assertArrayHasKey( 'metadata_rows', $data );
		$this->assertArrayHasKey( 'pending_total', $data );
		$this->assertArrayHasKey( 'pending_by_mode', $data );
		$this->assertIsArray( $data['pending_by_mode'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testShowStatusPlainOutputContainsExpectedSections(): void
	{

		$command = Server::get( ShowStatus::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );

		$display = $tester->getDisplay();
		$this->assertStringContainsString( 'FCIAS Status', $display );
		$this->assertStringContainsString( 'Filecache entries:', $display );
		$this->assertStringContainsString( 'Metadata updated_at:', $display );
		$this->assertStringContainsString( 'Pending total:', $display );
	}


	// ─── FindDuplicates ──────────────────────────────────────────────


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testFindDuplicatesRunsWithoutError(): void
	{

		$command = Server::get( FindDuplicates::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );
	}


	// ─── HashFiles ───────────────────────────────────────────────────


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testHashFilesWithNonexistentUserReturnsFailure(): void
	{

		$command = Server::get( HashFiles::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [
			'--user' => 'nonexistent_user_xyz',
			'--algo' => [ 'sha1' ],
		] );

		$this->assertSame( Command::FAILURE, $exitCode );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testHashFilesVerboseReportsZeroCollection(): void
	{

		$command = Server::get( HashFiles::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute(
			[
				'--user' => 'admin',
				'--algo' => [ 'sha1' ],
				'--path' => 'no-such-glob-xyz/**',
			],
			[ 'verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE ],
		);

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'No files collected', $tester->getDisplay() );
	}


	// ─── Repair ──────────────────────────────────────────────────────


	/**
	 * The successor to the retired `rebuild`, whose three phases are now
	 * three of this command's steps.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRepairListsItsStepsWithoutRunningThem(): void
	{

		$tester = new CommandTester( Server::get( Repair::class ) );

		$this->assertSame( Command::SUCCESS, $tester->execute( [ '--list' => true ] ) );

		$display = $tester->getDisplay();

		foreach (
			[
				'rebuild-from-filecache',
				'rebuild-from-metadata',
				'unindexed-hashes',
				'clear-disowned',
			] as $step
		)
		{
			$this->assertStringContainsString( $step, $display );
		}

		// Not a repair step, and the only one of these that reads file
		// content: computing hashes lives in `fcias:queue:drain`, because
		// every repair step runs on every upgrade and hashing an instance is
		// not something an upgrade should decide to do.
		$this->assertStringNotContainsString( 'drain-queue', $display );

		// The step that costs a full scan to find out it has nothing to do
		// says so here, since that is the only place an administrator finds
		// out why a plain run skipped it.
		$this->assertStringContainsString( 'only when asked for', $display );
	}


	/**
	 * The steps run against a real container, which is what this suite is
	 * for: the anonymous IOutput the command hands them only meets the real
	 * interface here.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRepairRunsOneStepAgainstARealInstance(): void
	{

		$tester = new CommandTester( Server::get( Repair::class ) );

		$this->assertSame(
			Command::SUCCESS,
			$tester->execute( [ '--step' => [ 'metadata-keys' ] ] ),
		);
		$this->assertStringContainsString( 'Ran 1 step(s)', $tester->getDisplay() );
	}


	// ─── ShowConfig ──────────────────────────────────────────────────


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testShowConfigJsonOutputIsValid(): void
	{

		$command = Server::get( ShowConfig::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [ '--output' => 'json' ] );

		$this->assertSame( Command::SUCCESS, $exitCode );

		$data = json_decode( $tester->getDisplay(), true );
		$this->assertIsArray( $data, 'JSON output should be valid.' );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testShowConfigPlainOutput(): void
	{

		$command = Server::get( ShowConfig::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );

		$display = $tester->getDisplay();
		// Plain output may be empty if no config values are set;
		// verify the command completes without error.
		$this->assertNotNull( $display );
	}


	// ─── TestPerformance ─────────────────────────────────────────────


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTestPerformanceRunsWithoutError(): void
	{

		$command = Server::get( TestPerformance::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'FCIAS Performance Benchmark', $tester->getDisplay() );
	}

}
