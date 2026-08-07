<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use OCA\FileChecksumSearch\Service\DatabaseService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class DatabaseServiceTest
	extends
	FciasUnitTestCase
{

	private LoggerInterface&MockObject  $logger;

	private DatabaseService             $service;

	private DoctrineConnection&MockObject     $doctrineConn;

	private AbstractSchemaManager&MockObject  $schemaManager;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db     = $this->createMock( \OCP\IDBConnection::class );
		$this->logger = $this->createMock( LoggerInterface::class );

		$this->setUpQueryBuilderMock();

		// Doctrine-level mocks for schema-manager methods used by
		// columnExists / tableExist via getSchemaManager().
		$this->doctrineConn  = $this->createMock( DoctrineConnection::class );
		$this->schemaManager = $this->createMock( AbstractSchemaManager::class );

		$this->doctrineConn->method( 'createSchemaManager' )
		                   ->willReturn( $this->schemaManager )
		;

		// Partial mock: override only getRawConnection() so the Doctrine
		// layer is isolated; keep real implementations of methods under test.
		$this->service = $this->getMockBuilder( DatabaseService::class )
		                      ->setConstructorArgs( [ $this->db, $this->logger ] )
		                      ->onlyMethods( [ 'getRawConnection' ] )
		                      ->getMock()
		;

		$this->service->method( 'getRawConnection' )
		              ->willReturn( $this->doctrineConn )
		;
	}


	public function testCountRowsReturnsInt(): void
	{

		$queryFunction = $this->createMock( IQueryFunction::class );

		$this->func->method( 'count' )
		           ->willReturn( $queryFunction )
		;

		$result = $this->createMock( IResult::class );

		$result->method( 'fetchOne' )
		       ->willReturn( '42' )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$count = $this->service->countRows( 'some_table' );

		$this->assertSame( 42, $count );
	}


	public function testColumnExistsReturnsTrueForExisting(): void
	{

		$column = $this->createMock( Column::class );

		$column->method( 'getName' )
		       ->willReturn( 'expected_col' )
		;

		$this->schemaManager->method( 'listTableColumns' )
		                    ->with( 'test_table' )
		                    ->willReturn( [ $column ] )
		;

		$result = $this->service->columnExists( 'test_table', 'expected_col' );

		$this->assertTrue( $result );
	}


	public function testColumnExistsReturnsFalseForMissing(): void
	{

		$column = $this->createMock( Column::class );

		$column->method( 'getName' )
		       ->willReturn( 'other_col' )
		;

		$this->schemaManager->method( 'listTableColumns' )
		                    ->with( 'test_table' )
		                    ->willReturn( [ $column ] )
		;

		$result = $this->service->columnExists( 'test_table', 'missing_col' );

		$this->assertFalse( $result );
	}


	public function testTableExistReturnsBool(): void
	{

		$this->schemaManager->method( 'tablesExist' )
		                    ->with( [ 'existing_table' ] )
		                    ->willReturn( true )
		;

		$result = $this->service->tableExist( 'existing_table' );

		$this->assertTrue( $result );
	}


	public function testGetInstalledMigrationsReturnsArray(): void
	{

		$result = $this->createMock( IResult::class );

		$result->method( 'fetchAll' )
		       ->willReturn( [
			       [ 'version' => '1000Date20260806100000' ],
			       [ 'version' => '1001Date20260807100000' ],
		       ] )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$migrations = $this->service->getInstalledMigrations( 'file_checksum_search' );

		$this->assertIsArray( $migrations );
		$this->assertCount( 2, $migrations );
		$this->assertContains( '1000Date20260806100000', $migrations );
		$this->assertContains( '1001Date20260807100000', $migrations );
	}

}
