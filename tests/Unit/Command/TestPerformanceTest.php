<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\TestPerformance;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestPerformanceTest
	extends
	FciasUnitTestCase
{

	private MockObject|MetadataService $metadataService;

	private CommandTester              $tester;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db = $this->createMock( IDBConnection::class );
		$this->setUpQueryBuilderMock();

		$this->metadataService = $this->createMock( MetadataService::class );

		$this->metadataService->method( 'queryByHash' )
		                      ->willReturn( [] )
		;

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchAll' )
		       ->willReturn( [] )
		;
		$result->method( 'fetchOne' )
		       ->willReturnOnConsecutiveCalls( 12000, 9500 )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$command      = new TestPerformance( $this->db, $this->metadataService );
		$this->tester = new CommandTester( $command );
	}


	public function testRunsBothBenchmarksAndReportsCounts(): void
	{

		$this->metadataService->expects( $this->exactly( 100 ) )
		                      ->method( 'queryByHash' )
		;

		$exitCode = $this->tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );

		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( 'Metadata index lookup x100:', $display );
		$this->assertStringContainsString( 'Filecache LIKE scan x100:', $display );
		$this->assertStringContainsString( 'Speedup:', $display );
		$this->assertStringContainsString( 'Filecache entries:            12000', $display );
		$this->assertStringContainsString( 'Metadata updated_at entries:  9500', $display );
	}

}
