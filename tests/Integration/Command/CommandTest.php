<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Command;

use OCA\FileChecksumSearch\Command\CreateTable;
use OCA\FileChecksumSearch\Command\DeployTriggers;
use OCA\FileChecksumSearch\Command\FindDuplicates;
use OCA\FileChecksumSearch\Command\GenerateHashes;
use OCA\FileChecksumSearch\Command\PurgeIndex;
use OCA\FileChecksumSearch\Command\RebuildIndex;
use OCA\FileChecksumSearch\Command\RemoveTable;
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
		$this->assertArrayHasKey( 'hash_rows', $data );
		$this->assertArrayHasKey( 'tables', $data );
		$this->assertIsArray( $data['tables'] );
	}


	public function testShowStatusPlainOutputContainsExpectedSections(): void
	{

		$command = Server::get( ShowStatus::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );

		$display = $tester->getDisplay();
		$this->assertStringContainsString( 'FCIAS Status', $display );
		$this->assertStringContainsString( 'Tables:', $display );
		$this->assertStringContainsString( 'Stored Procedure:', $display );
		$this->assertStringContainsString( 'Triggers:', $display );
		$this->assertStringContainsString( 'Migrations:', $display );
	}


	// ─── PurgeIndex ──────────────────────────────────────────────────

	public function testPurgeIndexRequiresForceFlag(): void
	{

		$command = Server::get( PurgeIndex::class );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		// Without --force, should fail.
		$this->assertSame( Command::FAILURE, $exitCode );
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


	// ─── CreateTable ─────────────────────────────────────────────────

	public function testCreateTableRequiresForceFlag(): void
	{

		$hashIndexService = $this->createMock( HashIndexService::class );
		$hashIndexService->expects( $this->never() )
		                 ->method( 'createTable' )
		;

		$command = new CreateTable( $hashIndexService, $this->logger );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		$this->assertSame( Command::FAILURE, $exitCode );
		$this->assertStringContainsString( 'Use --force to confirm', $tester->getDisplay() );
	}


	public function testCreateTableWithForceSucceeds(): void
	{

		$hashIndexService = $this->createMock( HashIndexService::class );
		$hashIndexService->expects( $this->once() )
		                 ->method( 'createTable' )
		;

		$command = new CreateTable( $hashIndexService, $this->logger );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [ '--force' => true ] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'Hash table created', $tester->getDisplay() );
	}


	// ─── DeployTriggers ──────────────────────────────────────────────

	public function testDeployTriggersRequiresForceFlag(): void
	{

		$hashIndexService = $this->createMock( HashIndexService::class );
		$hashIndexService->expects( $this->never() )
		                 ->method( 'deployTriggers' )
		;

		$command = new DeployTriggers( $hashIndexService, $this->logger );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		$this->assertSame( Command::FAILURE, $exitCode );
		$this->assertStringContainsString( 'Use --force to confirm', $tester->getDisplay() );
	}


	public function testDeployTriggersWithForceSucceeds(): void
	{

		$hashIndexService = $this->createMock( HashIndexService::class );
		$hashIndexService->expects( $this->once() )
		                 ->method( 'deployTriggers' )
		;

		$command = new DeployTriggers( $hashIndexService, $this->logger );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [ '--force' => true ] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'Triggers created', $tester->getDisplay() );
	}


	// ─── RebuildIndex ────────────────────────────────────────────────

	public function testRebuildIndexRunsAndReturnsSuccess(): void
	{

		$hashIndexService = $this->createMock( HashIndexService::class );
		$hashIndexService->expects( $this->once() )
		                 ->method( 'rebuildIndex' )
		                 ->willReturn( [ 'processed' => 0 ] )
		;

		$command = new RebuildIndex( $hashIndexService, $this->logger );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'Done.', $tester->getDisplay() );
	}


	// ─── RemoveTable ─────────────────────────────────────────────────

	public function testRemoveTableRequiresForceFlag(): void
	{

		$hashIndexService = $this->createMock( HashIndexService::class );
		$hashIndexService->expects( $this->never() )
		                 ->method( 'removeTable' )
		;

		$command = new RemoveTable( $hashIndexService, $this->logger );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [] );

		$this->assertSame( Command::FAILURE, $exitCode );
		$this->assertStringContainsString( 'Use --force to confirm', $tester->getDisplay() );
	}


	public function testRemoveTableWithForceSucceeds(): void
	{

		$hashIndexService = $this->createMock( HashIndexService::class );
		$hashIndexService->expects( $this->once() )
		                 ->method( 'removeTable' )
		;

		$command = new RemoveTable( $hashIndexService, $this->logger );
		$tester  = new CommandTester( $command );

		$exitCode = $tester->execute( [ '--force' => true ] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'Hash table dropped', $tester->getDisplay() );
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
