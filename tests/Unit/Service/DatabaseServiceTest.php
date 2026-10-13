<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use Doctrine\DBAL\Connection as DoctrineConnection;
use OCA\FileChecksumSearch\Service\DatabaseService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

class DatabaseServiceTest
    extends
    FciasUnitTestCase
{

//  private properties

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private LoggerInterface&MockObject    $logger;

	private DatabaseService               $service;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private DoctrineConnection&MockObject $doctrineConn;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->db           = $this->createMock( IDBConnection::class );
		$this->logger       = $this->createMock( LoggerInterface::class );
		$this->doctrineConn = $this->createMock( DoctrineConnection::class );

		// Partial mock: override only getRawConnection() so the Doctrine
		// layer is isolated; keep real implementations of methods under test.
		$this->service = $this->getMockBuilder( DatabaseService::class )
		                      ->setConstructorArgs(
			                      [
				                      $this->db,
				                      $this->logger,
			                      ],
		                      )
		                      ->onlyMethods( [ 'getRawConnection' ] )
		                      ->getMock()
		;

		$this->service->method( 'getRawConnection' )
		              ->willReturn( $this->doctrineConn )
		;
	}


//  other non-static methods

	/**
	 * Regression: a failed query was reported through getErrorOutput(),
	 * which only a console output has, so any other output — a buffered
	 * one, as here — turned the "unknown" this method promises into a fatal
	 * error. The error line lands on the one stream such an output has.
	 */
	public function testAFailedQueryIsReportedOnAnOutputWithoutAnErrorStream(): void
	{
		$this->doctrineConn->method( 'executeQuery' )
		                   ->willThrowException( new RuntimeException( 'gone away' ) )
		;
		$output = new BufferedOutput();

		$this->assertSame( 'unknown', $this->service->getDatabaseVersion( $output ) );
		$this->assertStringContainsString( 'gone away', $output->fetch() );
	}
}
