<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\DatabaseService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
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
	private LoggerInterface&MockObject $logger;

	private DatabaseService            $service;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->db      = $this->createMock( IDBConnection::class );
		$this->logger  = $this->createMock( LoggerInterface::class );
		$this->service = new DatabaseService( $this->db, $this->logger );
	}


//  static methods

	/**
	 * Each backend as it answers, and as the status shows it.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function backends(): array
	{
		return [
			'MariaDB'    => [
				IDBConnection::PLATFORM_MARIADB,
				'SELECT VERSION()',
				'10.11.6-MariaDB-ubu2204',
				'MariaDB 10.11.6',
			],
			'MySQL'      => [
				IDBConnection::PLATFORM_MYSQL,
				'SELECT VERSION()',
				'8.0.39',
				'MySQL 8.0.39',
			],
			'PostgreSQL' => [
				IDBConnection::PLATFORM_POSTGRES,
				'SELECT VERSION()',
				'PostgreSQL 16.4 on x86_64-pc-linux-gnu, compiled by gcc (Debian 12.2.0-14) 12.2.0, 64-bit',
				'PostgreSQL 16.4',
			],
			// No VERSION() there: asked it, SQLite fails, and the status said "unknown".
			'SQLite'     => [
				IDBConnection::PLATFORM_SQLITE,
				'SELECT sqlite_version()',
				'3.45.1',
				'SQLite 3.45.1',
			],
		];
	}


//  other non-static methods

	#[DataProvider( 'backends' )]
	public function testTheDatabaseIsNamedWithItsVersion(
		string $provider,
		string $query,
		string $answer,
		string $shown,
	): void
	{
		$result = $this->createMock( IResult::class );
		$result->method( 'fetchOne' )
		       ->willReturn( $answer )
		;
		$this->db->method( 'getDatabaseProvider' )
		         ->with( true )
		         ->willReturn( $provider )
		;
		$this->db->expects( $this->once() )
		         ->method( 'executeQuery' )
		         ->with( $query )
		         ->willReturn( $result )
		;

		$this->assertSame( $shown, $this->service->getDatabaseVersion() );
	}

	/**
	 * A failed query still names the database, and the status does not fail.
	 *
	 * Regression, too: a failed query was reported through getErrorOutput(),
	 * which only a console output has, so any other output — a buffered one,
	 * as here — turned the "unknown" this method promises into a fatal
	 * error. The error line lands on the one stream such an output has.
	 */
	public function testAFailedQueryNamesTheDatabaseAndSaysSo(): void
	{
		$this->db->method( 'getDatabaseProvider' )
		         ->willReturn( IDBConnection::PLATFORM_MARIADB )
		;
		$this->db->method( 'executeQuery' )
		         ->willThrowException( new RuntimeException( 'gone away' ) )
		;
		$output = new BufferedOutput();

		$this->assertSame( 'MariaDB unknown', $this->service->getDatabaseVersion( $output ) );
		$this->assertStringContainsString( 'gone away', $output->fetch() );
	}
}
