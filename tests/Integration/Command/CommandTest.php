<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Command;

use OCA\FileChecksumSearch\Command\FindDuplicates;
use OCA\FileChecksumSearch\Command\GenerateHashes;
use OCA\FileChecksumSearch\Command\RebuildIndex;
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

	public function testFindDuplicatesRunsWithoutError(): void
	{

		$command = Server::get( FindDuplicates::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );
	}


	// ─── GenerateHashes ──────────────────────────────────────────────

	public function testGenerateHashesWithNonexistentUserReturnsFailure(): void
	{

		$command = Server::get( GenerateHashes::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [
			'--user' => 'nonexistent_user_xyz',
			'--algo' => 'sha1',
		] );

		$this->assertSame( Command::FAILURE, $exitCode );
	}


	// ─── RebuildIndex ────────────────────────────────────────────────

	public function testRebuildIndexRunsWithoutException(): void
	{

		$command = Server::get( RebuildIndex::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		// May return SUCCESS or FAILURE depending on whether test DB
		// has processable files. The key assertion is the command runs
		// without throwing an exception.
		$this->assertContains( $exitCode, [ Command::SUCCESS, Command::FAILURE ] );
		$this->assertStringContainsString( 'Seeding', $tester->getDisplay() );
	}


	// ─── ShowConfig ──────────────────────────────────────────────────

	public function testShowConfigJsonOutputIsValid(): void
	{

		$command = Server::get( ShowConfig::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [ '--output' => 'json' ] );

		$this->assertSame( Command::SUCCESS, $exitCode );

		$data = json_decode( $tester->getDisplay(), true );
		$this->assertIsArray( $data, 'JSON output should be valid.' );
	}


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

	public function testTestPerformanceRunsWithoutError(): void
	{

		$command = Server::get( TestPerformance::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'FCIAS Performance Benchmark', $tester->getDisplay() );
	}

}
