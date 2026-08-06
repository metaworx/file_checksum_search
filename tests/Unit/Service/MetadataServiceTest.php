<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for MetadataService.
 *
 * Verifies key registration, pending marking/fetching, hash queries,
 * duplicate detection, counting, staleness checks, and seeding.
 */
class MetadataServiceTest
	extends
	TestCase
{

	private IDBConnection&MockObject         $db;

	private IFilesMetadataManager&MockObject $metadataManager;

	private LoggerInterface&MockObject       $logger;

	private MetadataService                  $service;

	/** @var IQueryBuilder&MockObject */
	private IQueryBuilder $queryBuilder;

	/** @var IExpressionBuilder&MockObject */
	private IExpressionBuilder $expr;

	/** @var IFunctionBuilder&MockObject */
	private IFunctionBuilder $func;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db              = $this->createMock( IDBConnection::class );
		$this->metadataManager = $this->createMock( IFilesMetadataManager::class );
		$this->logger          = $this->createMock( LoggerInterface::class );
		$this->queryBuilder    = $this->createMock( IQueryBuilder::class );
		$this->expr            = $this->createMock( IExpressionBuilder::class );
		$this->func            = $this->createMock( IFunctionBuilder::class );

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $this->queryBuilder )
		;

		$this->queryBuilder->method( 'expr' )
		                   ->willReturn( $this->expr )
		;

		$this->queryBuilder->method( 'func' )
		                   ->willReturn( $this->func )
		;

		$this->queryBuilder->method( 'createNamedParameter' )
		                   ->willReturnCallback(
			                   fn(
				                   $value,
				                   $type = null,
			                   ) => $value,
		                   )
		;

		$this->expr->method( 'eq' )
		           ->willReturn( '1=1' )
		;

		$this->expr->method( 'like' )
		           ->willReturn( '1=1' )
		;

		$this->expr->method( 'gte' )
		           ->willReturn( '1=1' )
		;

		$this->service = new MetadataService(
			$this->db,
			$this->metadataManager,
			$this->logger,
		);
	}


	public function testRegisterInitializesAllAlgoKeys(): void
	{

		$expectedCalls = count( HashIndexService::SUPPORTED_ALGOS ) + 1;

		$this->metadataManager->expects( $this->exactly( $expectedCalls ) )
		                      ->method( 'initMetadata' )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'debug' )
		;

		$this->service->register();
	}


	public function testRegisterIncludesUpdatedAtKey(): void
	{

		$registeredKeys = [];

		$this->metadataManager->method( 'initMetadata' )
		                      ->willReturnCallback(
			                      function (
				                      string $key,
			                      ) use
			                      (
				                      &
				                      $registeredKeys,
			                      ): void
			                      {

				                      $registeredKeys[] = $key;
			                      },
		                      )
		;

		$this->service->register();

		$this->assertContains( 'file-checksum-updated_at', $registeredKeys );
	}


	public function testRegisterIncludesAllAlgos(): void
	{

		$registeredKeys = [];

		$this->metadataManager->method( 'initMetadata' )
		                      ->willReturnCallback(
			                      function (
				                      string $key,
			                      ) use
			                      (
				                      &
				                      $registeredKeys,
			                      ): void
			                      {

				                      $registeredKeys[] = $key;
			                      },
		                      )
		;

		$this->service->register();

		foreach ( HashIndexService::SUPPORTED_ALGOS as $algo )
		{
			$this->assertContains(
				'file-checksum-' . $algo,
				$registeredKeys,
				"Expected key 'file-checksum-$algo' to be registered.",
			);
		}
	}


	public function testMarkPendingUpdatesIndexRow(): void
	{

		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'update' )
		                   ->with( 'files_metadata_index' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'set' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'where' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'executeStatement' )
		                   ->willReturn( 1 )
		;

		$this->service->markPending( 42, 'pending:auto' );
	}


	public function testFetchPendingBatchReturnsRows(): void
	{

		$result = $this->createMock( IResult::class );

		$result->method( 'fetch' )
		       ->willReturnOnConsecutiveCalls(
			       [
				       'file_id'           => '1',
				       'meta_value_string' => 'pending:auto',
			       ],
			       [
				       'file_id'           => '2',
				       'meta_value_string' => 'pending:missing',
			       ],
			       false,
		       )
		;

		$this->queryBuilder->method( 'select' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'from' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'where' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'orderBy' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'setMaxResults' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$rows = $this->service->fetchPendingBatch( 10 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 1, $rows[0]['file_id'] );
		$this->assertSame( 'pending:auto', $rows[0]['meta_value_string'] );
		$this->assertSame( 2, $rows[1]['file_id'] );
		$this->assertSame( 'pending:missing', $rows[1]['meta_value_string'] );
	}


	public function testFetchPendingBatchReturnsEmpty(): void
	{

		$result = $this->createMock( IResult::class );
		$result->method( 'fetch' )
		       ->willReturn( false )
		;

		$this->queryBuilder->method( 'select' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'from' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'where' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'orderBy' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'setMaxResults' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$rows = $this->service->fetchPendingBatch( 10 );

		$this->assertCount( 0, $rows );
	}


	public function testCountByFileIdReturnsCount(): void
	{

		$result = $this->createMock( IResult::class );

		$this->queryBuilder->method( 'select' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'from' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'where' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$result->method( 'fetchOne' )
		       ->willReturn( '3' )
		;

		$count = $this->service->countByFileId( 42 );

		$this->assertSame( 3, $count );
	}


	public function testGetUpdatedAtReturnsTimestamp(): void
	{

		$result = $this->createMock( IResult::class );

		$this->queryBuilder->method( 'select' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'from' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'where' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$result->method( 'fetchOne' )
		       ->willReturn( '1712345678' )
		;

		$ts = $this->service->getUpdatedAt( 42 );

		$this->assertSame( 1712345678, $ts );
	}


	public function testGetUpdatedAtReturnsNullWhenNotSet(): void
	{

		$result = $this->createMock( IResult::class );

		$this->queryBuilder->method( 'select' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'from' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'where' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$result->method( 'fetchOne' )
		       ->willReturn( false )
		;

		$ts = $this->service->getUpdatedAt( 42 );

		$this->assertNull( $ts );
	}


	public function testSeedIndexReturnsInsertedCount(): void
	{

		$this->db->expects( $this->once() )
		         ->method( 'executeStatement' )
		         ->willReturn( 150 )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'info' )
		;

		$inserted = $this->service->seedIndex();

		$this->assertSame( 150, $inserted );
	}


	public function testSeedIndexReturnsZeroOnException(): void
	{

		$this->db->expects( $this->once() )
		         ->method( 'executeStatement' )
		         ->willThrowException( new RuntimeException( 'DB error' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		$inserted = $this->service->seedIndex();

		$this->assertSame( 0, $inserted );
	}

}
