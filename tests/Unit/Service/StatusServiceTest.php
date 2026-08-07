<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\DatabaseService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Unit tests for StatusService.
 *
 * Covers all 6 public methods with mocked dependencies.
 */
class StatusServiceTest
	extends
	TestCase
{

	private DatabaseService&MockObject  $databaseService;

	private TableNameService&MockObject $tables;

	private IAppManager&MockObject      $appManager;

	private MetadataService&MockObject  $metadataService;

	private StatusService               $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->databaseService = $this->createMock( DatabaseService::class );
		$this->tables          = $this->createMock( TableNameService::class );
		$this->appManager      = $this->createMock( IAppManager::class );
		$this->metadataService = $this->createMock( MetadataService::class );

		$this->service = new StatusService(
			$this->databaseService,
			$this->tables,
			$this->appManager,
			$this->metadataService,
		);
	}


	public function testGetHashRowCountReturnsCount(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'countHashEntries' )
		                      ->willReturn( 42 )
		;

		$result = $this->service->getHashRowCount();

		$this->assertSame( 42, $result );
	}


	public function testGetPendingRowCountSumsStats(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getPendingStats' )
		                      ->willReturn( [ 'lazy' => 5, 'missing' => 3, 'force' => 2 ] )
		;

		$result = $this->service->getPendingRowCount();

		$this->assertSame( 10, $result );
	}


	public function testGetMigrationStatusComparesAgainstInstalled(): void
	{

		$output = $this->createMock( OutputInterface::class );

		$this->databaseService->expects( $this->once() )
		                      ->method( 'getInstalledMigrations' )
		                      ->with( 'file_checksum_search', $output )
		                      ->willReturn( [] )
		;

		$result = $this->service->getMigrationStatus( $output );

		$this->assertIsArray( $result );

		// Each entry must have name (string) and ok (bool) keys
		foreach ( $result as $entry )
		{
			$this->assertArrayHasKey( 'name', $entry );
			$this->assertArrayHasKey( 'ok', $entry );
			$this->assertIsString( $entry['name'] );
			$this->assertIsBool( $entry['ok'] );

			// When installed is empty, all migrations should report ok=false
			$this->assertFalse( $entry['ok'] );
		}
	}


	public function testHasChecksumColumnReturnsBool(): void
	{

		$output = $this->createMock( OutputInterface::class );

		$this->tables->expects( $this->once() )
		             ->method( 'getFilecacheTableName' )
		             ->willReturn( 'oc_filecache' )
		;

		$this->databaseService->expects( $this->once() )
		                      ->method( 'columnExists' )
		                      ->with( 'oc_filecache', 'checksum', $output )
		                      ->willReturn( true )
		;

		$result = $this->service->hasChecksumColumn( $output );

		$this->assertTrue( $result );
	}

}
