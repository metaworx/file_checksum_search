<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\ShowStatus;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\DB\IResult;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ShowStatusTest
	extends
	FciasUnitTestCase
{

	private MockObject|MetadataService $metadataService;

	private MockObject|IAppConfig      $appConfig;

	private MockObject|LoggerInterface $logger;

	private CommandTester              $tester;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db              = $this->createMock( \OCP\IDBConnection::class );
		$this->setUpQueryBuilderMock();

		$this->metadataService = $this->createMock( MetadataService::class );
		$this->appConfig        = $this->createMock( IAppConfig::class );
		$this->logger           = $this->createMock( LoggerInterface::class );

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchOne' )
		       ->willReturnOnConsecutiveCalls( 5000, 4200 )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$command      = new ShowStatus(
			$this->db,
			$this->metadataService,
			$this->appConfig,
			$this->logger,
		);
		$this->tester = new CommandTester( $command );
	}


	public function testPlainOutputShowsCountsAndVersion(): void
	{

		$this->appConfig->method( 'getValueString' )
		                ->with( 'file_checksum_search', 'installed_version', 'unknown' )
		                ->willReturn( '1.9.2' )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [ 'pending:auto' => 3, 'pending:force' => 1 ] )
		;

		$exitCode = $this->tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( '1.9.2', $display );
		$this->assertStringContainsString( '5000', $display );
		$this->assertStringContainsString( '4200', $display );
		$this->assertStringContainsString( 'Pending total:          4', $display );
		$this->assertStringContainsString( 'pending:auto', $display );
	}


	public function testPlainOutputOmitsPendingByModeWhenEmpty(): void
	{

		$this->appConfig->method( 'getValueString' )
		                ->willReturn( 'unknown' )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [] )
		;

		$this->tester->execute( [] );

		$this->assertStringNotContainsString( 'Pending by mode:', $this->tester->getDisplay() );
	}


	public function testJsonOutputFormat(): void
	{

		$this->appConfig->method( 'getValueString' )
		                ->willReturn( '1.9.2' )
		;
		$this->metadataService->method( 'getPendingStats' )
		                      ->willReturn( [ 'pending:auto' => 2 ] )
		;

		$this->tester->execute( [ '--output' => 'json' ] );

		$decoded = json_decode( trim( $this->tester->getDisplay() ), true );
		$this->assertSame( '1.9.2', $decoded['app_version'] );
		$this->assertSame( 5000, $decoded['filecache_rows'] );
		$this->assertSame( 4200, $decoded['metadata_rows'] );
		$this->assertSame( 2, $decoded['pending_total'] );
		$this->assertSame( [ 'pending:auto' => 2 ], $decoded['pending_by_mode'] );
	}

}
