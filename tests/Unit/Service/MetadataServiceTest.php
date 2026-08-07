<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\DB\IResult;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
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
	FciasUnitTestCase
{

	private IFilesMetadataManager&MockObject $metadataManager;

	private FilecacheService&MockObject      $filecacheService;

	private LoggerInterface&MockObject       $logger;

	private MetadataService                  $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db               = $this->createMock( IDBConnection::class );
		$this->metadataManager  = $this->createMock( IFilesMetadataManager::class );
		$this->filecacheService = $this->createMock( FilecacheService::class );
		$this->logger           = $this->createMock( LoggerInterface::class );

		$this->setUpQueryBuilderMock();

		$this->service = new MetadataService(
			$this->db,
			$this->metadataManager,
			$this->filecacheService,
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
			                      ): void {

				                      $registeredKeys[] = $key;
			                      },
		                      )
		;

		$this->service->register();

		$this->assertContains( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $registeredKeys );
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
			                      ): void {

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
				"Expected key 'file-checksum-{$algo}' to be registered.",
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

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$rows = $this->service->fetchPendingBatch( 10 );

		$this->assertCount( 0, $rows );
	}


	public function testCountByFileIdReturnsCount(): void
	{

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchOne' )
		       ->willReturn( '3' )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$count = $this->service->countByFileId( 42 );

		$this->assertSame( 3, $count );
	}


	public function testGetUpdatedAtReturnsTimestamp(): void
	{

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchOne' )
		       ->willReturn( '1712345678' )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$ts = $this->service->getUpdatedAt( 42 );

		$this->assertSame( 1712345678, $ts );
	}


	public function testGetUpdatedAtReturnsNullWhenNotSet(): void
	{

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchOne' )
		       ->willReturn( false )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
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


	public function testSeedIndexSqlContainsExpectedTables(): void
	{

		$capturedSql = null;

		$this->db->expects( $this->once() )
		         ->method( 'executeStatement' )
		         ->willReturnCallback(
			         function (
				         string $sql,
			         ) use
			         (
				         &
				         $capturedSql,
			         ): int {

				         $capturedSql = $sql;

				         return 0;
			         },
		         )
		;

		$this->service->seedIndex();

		$this->assertNotNull( $capturedSql, 'SQL should have been captured.' );
		$this->assertStringContainsString( 'files_metadata_index', $capturedSql );
		$this->assertStringContainsString( 'filecache', $capturedSql );
		$this->assertStringContainsString( 'file-checksum-updated_at', $capturedSql );
		$this->assertStringContainsString( 'pending:new', $capturedSql );
		$this->assertStringContainsString( 'INSERT INTO', $capturedSql );
		$this->assertStringContainsString( 'NOT IN', $capturedSql );
	}


	public function testMarkPendingSqlContainsExpectedClauses(): void
	{

		$capturedParams = [];

		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'update' )
		                   ->with( 'files_metadata_index' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'set' )
		                   ->with( 'meta_value_string', 'pending:auto' )
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

		$this->expr->expects( $this->exactly( 2 ) )
		           ->method( 'eq' )
		           ->willReturnCallback(
			           function (
				           string $column,
				           $value,
			           ) use
			           (
				           &
				           $capturedParams,
			           ): string {

				           $capturedParams[ $column ] = $value;

				           return '1=1';
			           },
		           )
		;

		$this->service->markPending( 42, 'pending:auto' );

		$this->assertArrayHasKey( 'file_id', $capturedParams );
		$this->assertSame( 42, $capturedParams['file_id'] );
		$this->assertArrayHasKey( 'meta_key', $capturedParams );
		$this->assertSame( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $capturedParams['meta_key'] );
	}


	public function testFetchPendingBatchQueriesCorrectKey(): void
	{

		$capturedLike = null;

		$this->expr->expects( $this->once() )
		           ->method( 'eq' )
		           ->willReturn( '1=1' )
		;

		$this->expr->expects( $this->once() )
		           ->method( 'like' )
		           ->willReturnCallback(
			           function (
				           string $column,
				           string $value,
			           ) use
			           (
				           &
				           $capturedLike,
			           ): string {

				           $capturedLike = $value;

				           return '1=1';
			           },
		           )
		;

		$result = $this->createMock( IResult::class );
		$result->method( 'fetch' )
		       ->willReturn( false )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$this->service->fetchPendingBatch( 25 );

		$this->assertSame( 'pending:%', $capturedLike );
	}


	public function testQueryByHashReturnsMatchingRows(): void
	{

		$mockRows = [
			[
				'file_id'   => '42',
				'meta_key'  => 'file-checksum-sha1',
				'meta_json' => '{"file-checksum-sha1":"abc123"}',
			],
			[
				'file_id'   => '108',
				'meta_key'  => 'file-checksum-sha1',
				'meta_json' => '{"file-checksum-sha1":"abc123"}',
			],
		];

		$result = $this->createMock( IResult::class );
		$result->expects( $this->once() )
		       ->method( 'fetchAll' )
		       ->willReturn( $mockRows )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$rows = $this->service->queryByHash( 'abc123' );

		$this->assertCount( 2, $rows );
		$this->assertSame( '42', $rows[0]['file_id'] );
		$this->assertSame( '108', $rows[1]['file_id'] );
	}


	public function testQueryByHashWithAlgoFilter(): void
	{

		$mockRows = [
			[
				'file_id'   => '42',
				'meta_key'  => 'file-checksum-sha256',
				'meta_json' => '{"file-checksum-sha256":"def456"}',
			],
		];

		$result = $this->createMock( IResult::class );
		$result->expects( $this->once() )
		       ->method( 'fetchAll' )
		       ->willReturn( $mockRows )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$this->expr->expects( $this->exactly( 2 ) )
		           ->method( 'eq' )
		           ->willReturn( '1=1' )
		;

		$rows = $this->service->queryByHash( 'def456', 'sha256' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'file-checksum-sha256', $rows[0]['meta_key'] );
	}


	public function testQueryByHashReturnsEmpty(): void
	{

		$result = $this->createMock( IResult::class );
		$result->expects( $this->once() )
		       ->method( 'fetchAll' )
		       ->willReturn( [] )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$rows = $this->service->queryByHash( 'nonexistent' );

		$this->assertCount( 0, $rows );
	}


	public function testQueryDuplicatesReturnsGroups(): void
	{

		$mockRows = [
			[
				'meta_key'  => 'file-checksum-sha1',
				'cnt'       => '3',
				'file_ids'  => '42,108,256',
				'meta_json' => '{"file-checksum-sha1":"abc123"}',
			],
		];

		$result = $this->createMock( IResult::class );
		$result->expects( $this->once() )
		       ->method( 'fetchAll' )
		       ->willReturn( $mockRows )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$groups = $this->service->queryDuplicates();

		$this->assertCount( 1, $groups );
		$this->assertSame( 'file-checksum-sha1', $groups[0]['meta_key'] );
		$this->assertSame( 'abc123', $groups[0]['meta_value_string'] );
		$this->assertSame( 3, $groups[0]['file_count'] );
		$this->assertSame( [ 42, 108, 256 ], $groups[0]['file_ids'] );
	}


	public function testQueryDuplicatesWithAlgoFilter(): void
	{

		$mockRows = [
			[
				'meta_key'  => 'file-checksum-sha256',
				'cnt'       => '2',
				'file_ids'  => '42,108',
				'meta_json' => '{"file-checksum-sha256":"def456"}',
			],
		];

		$result = $this->createMock( IResult::class );
		$result->expects( $this->once() )
		       ->method( 'fetchAll' )
		       ->willReturn( $mockRows )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$this->expr->expects( $this->once() )
		           ->method( 'eq' )
		           ->willReturn( '1=1' )
		;

		$groups = $this->service->queryDuplicates( 'sha256' );

		$this->assertCount( 1, $groups );
		$this->assertSame( 'file-checksum-sha256', $groups[0]['meta_key'] );
	}


	public function testQueryDuplicatesWithMinCount(): void
	{

		$mockRows = [
			[
				'meta_key'  => 'file-checksum-sha1',
				'cnt'       => '5',
				'file_ids'  => '1,2,3,4,5',
				'meta_json' => '{"file-checksum-sha1":"dup123"}',
			],
		];

		$result = $this->createMock( IResult::class );
		$result->expects( $this->once() )
		       ->method( 'fetchAll' )
		       ->willReturn( $mockRows )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$this->expr->expects( $this->once() )
		           ->method( 'gte' )
		           ->willReturn( '1=1' )
		;

		$groups = $this->service->queryDuplicates( minCount: 5 );

		$this->assertCount( 1, $groups );
		$this->assertSame( 5, $groups[0]['file_count'] );
	}

}
