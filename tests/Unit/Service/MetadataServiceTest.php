<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\DB\Exception;
use OCP\DB\IResult;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MetadataService.
 *
 * Verifies key registration, pending marking/fetching, hash queries,
 * duplicate detection, counting, staleness checks, erosion, and backfill.
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


	public function testParseQueryTermWith8CharHex(): void
	{

		$result = MetadataService::parseQueryTerm( '1a2b3c4d' );

		$this->assertNotNull( $result );
		$this->assertSame( '1a2b3c4d', $result['hash'] );
		$this->assertSame( '', $result['algo'] );
	}


	public function testParseQueryTermWith128CharHex(): void
	{

		$hex128 = str_repeat( 'a', 128 );

		$result = MetadataService::parseQueryTerm( $hex128 );

		$this->assertNotNull( $result );
		$this->assertSame( $hex128, $result['hash'] );
		$this->assertSame( '', $result['algo'] );
	}


	public function testParseQueryTermWithAlgoColonFormat(): void
	{

		$result = MetadataService::parseQueryTerm( 'sha256:abcdef1234567890abcdef1234567890abcdef12' );

		$this->assertNotNull( $result );
		$this->assertSame( 'abcdef1234567890abcdef1234567890abcdef12', $result['hash'] );
		$this->assertSame( 'sha256', $result['algo'] );
	}


	public function testParseQueryTermWithInvalidFormat(): void
	{

		$this->assertNull( MetadataService::parseQueryTerm( '' ) );
		$this->assertNull( MetadataService::parseQueryTerm( 'not-a-hash' ) );
		$this->assertNull( MetadataService::parseQueryTerm( 'abc' ) );
		$this->assertNull( MetadataService::parseQueryTerm( 'sha256:xyz' ) );
	}


	public function testRegisterInitializesAllAlgoKeys(): void
	{

		$expectedCalls = count( HashCalculationService::SUPPORTED_ALGOS ) + 1;

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

		$this->assertContains( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $registeredKeys );
	}


	public function testClearMetadataMarksUpdatedAtAsIndexed(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );

		$metadata->expects( $this->once() )
		         ->method( 'removeStartsWith' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_PREFIX )
		         ->willReturnSelf()
		;

		$metadata->expects( $this->once() )
		         ->method( 'setInt' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, 0, true )
		         ->willReturnSelf()
		;

		$this->metadataManager->expects( $this->never() )
		                      ->method( 'saveMetadata' )
		;

		$this->service->clearMetadata( $metadata, false );
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

		foreach ( HashCalculationService::SUPPORTED_ALGOS as $algo )
		{
			$this->assertContains(
				'file-checksum-' . $algo,
				$registeredKeys,
				"Expected key 'file-checksum-$algo' to be registered.",
			);
		}
	}


	public function testMarkPendingUpdatesTheExistingIndexRow(): void
	{

		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'update' )
		                   ->with( 'files_metadata_index' )
		                   ->willReturnSelf()
		;

		// The UPDATE hits, so no INSERT leg runs.
		$this->queryBuilder->expects( $this->never() )
		                   ->method( 'insert' )
		;

		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'executeStatement' )
		                   ->willReturn( 1 )
		;

		$this->service->markPending( 42, 'pending:auto' );
	}


	public function testMarkPendingInsertsWhenTheFileWasNeverConsidered(): void
	{

		// Regression: this used to be a silent no-op by documented contract
		// ("seeding handles that"), which made RuleProcessingJob's marking
		// silently fail for any file the 21-hour seed had not reached yet.
		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'update' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'insert' )
		                   ->with( 'files_metadata_index' )
		                   ->willReturnSelf()
		;

		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'values' )
		                   ->willReturnSelf()
		;

		// First executeStatement = the UPDATE (misses), second = the INSERT.
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturnOnConsecutiveCalls( 0, 1 )
		;

		$this->service->markPending( 42, 'pending:missing' );
	}


	public function testMarkPendingRetriesAsUpdateWhenLosingTheInsertRace(): void
	{

		$this->queryBuilder->method( 'update' )
		                   ->willReturnSelf()
		;
		$this->queryBuilder->method( 'insert' )
		                   ->willReturnSelf()
		;
		$this->queryBuilder->method( 'values' )
		                   ->willReturnSelf()
		;

		$calls = 0;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturnCallback(
			                   static function () use
			                   (
				                   &
				                   $calls,
			                   ): int
			                   {

				                   $calls ++;

				                   // 1st: UPDATE misses. 2nd: INSERT collides
				                   // with a concurrent writer. 3rd: retry
				                   // UPDATE, which now hits.
				                   if ( $calls === 2 )
				                   {
					                   throw new Exception( 'duplicate key' );
				                   }

				                   return $calls === 3
					                   ? 1
					                   : 0;
			                   },
		                   )
		;

		$this->service->markPending( 42, 'pending:auto' );

		$this->assertSame( 3, $calls );
	}


	public function testMarkErodedStripsHashesAndStampsTheIndexRow(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );
		$this->metadataManager->method( 'getMetadata' )
		                      ->with( 42, true )
		                      ->willReturn( $metadata )
		;

		$metadata->expects( $this->once() )
		         ->method( 'removeStartsWith' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_PREFIX )
		;
		$metadata->expects( $this->once() )
		         ->method( 'setInt' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, 0, true )
		;
		$this->metadataManager->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		// The string is written after the save, because saving regenerates
		// the index rows and would clobber anything written before it.
		$this->queryBuilder->method( 'update' )
		                   ->willReturnSelf()
		;
		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'executeStatement' )
		                   ->willReturn( 1 )
		;

		$this->service->markEroded( 42 );
	}


	public function testBackfillHashesAddsOnlyAbsentKeysAndStampsMtime(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );
		$this->metadataManager->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;

		// sha1 already exists in the metadata — the filecache copy is the
		// older claim and must not overwrite it.
		$metadata->method( 'hasKey' )
		         ->willReturnCallback(
			         static fn(
				         string $key,
			         ): bool => $key === MetadataService::getHashKey( 'sha1' ),
		         )
		;
		$metadata->expects( $this->once() )
		         ->method( 'setString' )
		         ->with( MetadataService::getHashKey( 'md5' ), 'cafe', true )
		;
		// No timestamp yet → stamped with the file's mtime, not now(): the
		// copied hash describes the content as of that mtime.
		$metadata->method( 'getInt' )
		         ->willReturn( 0 )
		;
		$metadata->expects( $this->once() )
		         ->method( 'setInt' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, 1700000000, true )
		;
		$this->metadataManager->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		;

		$added = $this->service->backfillHashes(
			42,
			[
				'sha1' => 'dead',
				'md5'  => 'cafe',
			],
			1700000000,
		);

		$this->assertSame( 1, $added );
	}


	public function testBackfillHashesIsANoOpWhenNothingIsAbsent(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );
		$this->metadataManager->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;
		$metadata->method( 'hasKey' )
		         ->willReturn( true )
		;

		$this->metadataManager->expects( $this->never() )
		                      ->method( 'saveMetadata' )
		;

		$this->assertSame( 0, $this->service->backfillHashes( 42, [ 'sha1' => 'dead' ], 1700000000 ) );
		$this->assertSame( 0, $this->service->backfillHashes( 42, [], 1700000000 ) );
	}


	public function testCountErodedCountsOnlyTheErodedState(): void
	{

		$this->queryBuilder->expects( $this->once() )
		                   ->method( 'selectAlias' )
		                   ->willReturnSelf()
		;

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchOne' )
		       ->willReturn( 7 )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$this->assertSame( 7, $this->service->countEroded() );
	}


	public function testErodedNeverMatchesThePendingQueueFilter(): void
	{

		// The queue fetch filters on 'pending:%'; the eroded state must be
		// invisible to it or eroded files would loop through the drain.
		$this->assertStringNotContainsString(
			MetadataService::PENDING_PREFIX,
			MetadataService::STATE_ERODED,
		);
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
			           ): string
			           {

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
			           ): string
			           {

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

		// Two comparisons of its own — the hash and the algorithm — plus the
		// two the stale-exclusion join contributes.
		$compared = [];
		$this->expr->method( 'eq' )
		           ->willReturnCallback(
			           static function (
				           $left,
				           $right,
			           ) use
			           (
				           &
				           $compared,
			           ): string
			           {

				           $compared[] = (string) $left;

				           return '1=1';
			           },
		           )
		;

		$rows = $this->service->queryByHash( 'def456', 'sha256' );

		$this->assertContains( 'i.meta_key', $compared );
		$this->assertContains( 'stale.file_id', $compared );

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


	public function testQueryByHashTruncatesLongHashForIndexComparison(): void
	{

		// Regression test for FCIAS Review §6, Finding 6: the index
		// column truncates values longer than META_VALUE_STRING_MAX_LENGTH,
		// so the search term must be truncated the same way or a full
		// SHA-512/SHA3-512 hash never matches its (truncated) index row.
		$longHash  = str_repeat( 'a', 128 );
		$truncated = substr( $longHash, 0, MetadataService::META_VALUE_STRING_MAX_LENGTH );

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchAll' )
		       ->willReturn( [] )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$compared = [];
		$this->expr->method( 'eq' )
		           ->willReturnCallback(
			           static function (
				           $left,
				           $right,
			           ) use
			           (
				           &
				           $compared,
			           ): string
			           {

				           $compared[ (string) $left ] = $right;

				           return '1=1';
			           },
		           )
		;

		$this->service->queryByHash( $longHash );

		$this->assertSame(
			$truncated,
			$compared[ 'i.' . MetadataService::FIELD_META_VALUE_STRING ] ?? null,
		);
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
		$this->assertSame(
			[
				42,
				108,
				256,
			],
			$groups[0]['file_ids'],
		);
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

		$compared = [];
		$this->expr->method( 'eq' )
		           ->willReturnCallback(
			           static function (
				           $left,
				           $right,
			           ) use
			           (
				           &
				           $compared,
			           ): string
			           {

				           $compared[] = (string) $left;

				           return '1=1';
			           },
		           )
		;

		$groups = $this->service->queryDuplicates( 'sha256' );

		$this->assertContains( 'i.meta_key', $compared );
		$this->assertContains( 'stale.file_id', $compared );

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


	/**
	 * @noinspection PhpRedundantOptionalArgumentInspection
	 */
	public function testQueryDuplicatesSplitsFalsePositiveTruncatedGroup(): void
	{

		// Regression test for FCIAS Review §6, Finding 7: SQL grouped
		// files by the truncated index value, so two files whose full
		// hashes only agree on the truncated prefix were reported as
		// duplicates without checking the full value. 42 and 108 share
		// $fullHashA; 256 shares only the 63-char prefix but differs
		// after that — it must be split out of the group.
		$sharedPrefix = str_repeat( 'a', MetadataService::META_VALUE_STRING_MAX_LENGTH );
		$fullHashA    = $sharedPrefix . '1';
		$fullHashB    = $sharedPrefix . '2';

		$mockRows = [
			[
				'meta_key'  => 'file-checksum-sha256',
				'cnt'       => '3',
				'file_ids'  => '42,108,256',
				'meta_json' => json_encode( [ 'file-checksum-sha256' => $fullHashA ] ),
			],
		];

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchAll' )
		       ->willReturn( $mockRows )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$metaA = $this->createMock( IFilesMetadata::class );
		$metaA->method( 'getString' )
		      ->willReturn( $fullHashA )
		;
		$metaB = $this->createMock( IFilesMetadata::class );
		$metaB->method( 'getString' )
		      ->willReturn( $fullHashB )
		;

		$this->metadataManager->method( 'getMetadata' )
		                      ->willReturnMap( [
			                      [
				                      42,
				                      true,
				                      $metaA,
			                      ],
			                      [
				                      108,
				                      true,
				                      $metaA,
			                      ],
			                      [
				                      256,
				                      true,
				                      $metaB,
			                      ],
		                      ] )
		;

		$groups = $this->service->queryDuplicates( 'sha256', 2 );

		$this->assertCount( 1, $groups );
		$this->assertSame(
			[
				42,
				108,
			],
			$groups[0]['file_ids'],
		);
		$this->assertSame( $fullHashA, $groups[0]['meta_value_string'] );
	}


	/**
	 * @noinspection PhpRedundantOptionalArgumentInspection
	 */
	public function testQueryDuplicatesKeepsVerifiedTruncatedGroupIntact(): void
	{

		$sharedPrefix = str_repeat( 'a', MetadataService::META_VALUE_STRING_MAX_LENGTH );
		$fullHash     = $sharedPrefix . '1';

		$mockRows = [
			[
				'meta_key'  => 'file-checksum-sha256',
				'cnt'       => '2',
				'file_ids'  => '42,108',
				'meta_json' => json_encode( [ 'file-checksum-sha256' => $fullHash ] ),
			],
		];

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchAll' )
		       ->willReturn( $mockRows )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$meta = $this->createMock( IFilesMetadata::class );
		$meta->method( 'getString' )
		     ->willReturn( $fullHash )
		;

		$this->metadataManager->method( 'getMetadata' )
		                      ->willReturn( $meta )
		;

		$groups = $this->service->queryDuplicates( 'sha256', 2 );

		$this->assertCount( 1, $groups );
		$this->assertSame(
			[
				42,
				108,
			],
			$groups[0]['file_ids'],
		);
		$this->assertSame( 2, $groups[0]['file_count'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testSaveMetadataSyncsToFilecache(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'getFileId' )
		         ->willReturn( 42 )
		;

		$algoCount = count( HashCalculationService::SUPPORTED_ALGOS );
		$metadata->expects( $this->exactly( $algoCount ) )
		         ->method( 'getString' )
		         ->willReturn( 'dummyhash' )
		;

		$this->metadataManager->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		$this->filecacheService->expects( $this->once() )
		                       ->method( 'setHashes' )
		                       ->with( 42, $this->isType( 'array' ) )
		;

		$this->service->saveMetadata( $metadata );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testGetMetadataCreatesFromRawArray(): void
	{

		$rawData = [
			'file-checksum-sha1' => [
				'value' => 'abc123',
				'type'  => 'string',
			],
			'file-checksum-md5'  => [
				'value' => 'def456',
				'type'  => 'string',
			],
		];

		$metadata = $this->service->getMetadata( 42, $rawData );

		$this->assertInstanceOf( IFilesMetadata::class, $metadata );
		$this->assertSame( 42, $metadata->getFileId() );
		$this->assertSame( 'abc123', $metadata->getString( 'file-checksum-sha1' ) );
		$this->assertSame( 'def456', $metadata->getString( 'file-checksum-md5' ) );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testGetMetadataCreatesFromString(): void
	{

		$jsonString
			= '{"file-checksum-sha256":{"value":"abc123","type":"string"},"file-checksum-sha512":{"value":"def456","type":"string"}}';

		$metadata = $this->service->getMetadata( 42, $jsonString );

		$this->assertInstanceOf( IFilesMetadata::class, $metadata );
		$this->assertSame( 42, $metadata->getFileId() );
		$this->assertSame( 'abc123', $metadata->getString( 'file-checksum-sha256' ) );
		$this->assertSame( 'def456', $metadata->getString( 'file-checksum-sha512' ) );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testGetMetadataCreatesFromDbRow(): void
	{

		$dbRow = [ 'meta_json' => '{"file-checksum-sha1":{"value":"abc123","type":"string"}}' ];

		$metadata = $this->service->getMetadata( 42, $dbRow );

		$this->assertInstanceOf( IFilesMetadata::class, $metadata );
		$this->assertSame( 42, $metadata->getFileId() );
		$this->assertSame( 'abc123', $metadata->getString( 'file-checksum-sha1' ) );
	}


	public function testExtractAlgorithmReturnsCorrectAlgo(): void
	{

		$row = [
			'meta_key'  => 'file-checksum-sha256',
			'meta_json' => '{"file-checksum-sha256":{"value":"abc123def","type":"string"}}',
		];

		$result = $this->service->extractAlgorithm( 42, $row );

		$this->assertSame( 'sha256', $result['algo'] );
		$this->assertSame( 'abc123def', $result['hash'] );
	}


	public function testEnsureMetadataInitializesEmptyMetadata(): void
	{

		$metadata = null;

		$dummyMetadata = $this->createMock( IFilesMetadata::class );

		$this->metadataManager->expects( $this->once() )
		                      ->method( 'getMetadata' )
		                      ->with( 42, true )
		                      ->willReturn( $dummyMetadata )
		;

		$result = $this->service->ensureMetadata( 42, $metadata );

		$this->assertTrue( $result );
		$this->assertSame( $dummyMetadata, $metadata );
	}


	public function testStaleStatesNeverLookLikeQueuedWork(): void
	{

		// The two namespaces have to stay disjoint: a file whose hashes are
		// disowned is not a file waiting to be hashed, and the queue's LIKE
		// must not sweep it up.
		$this->assertStringStartsWith(
			MetadataService::STATE_STALE_PREFIX,
			MetadataService::STATE_ERODED,
		);
		$this->assertStringStartsWith(
			MetadataService::STATE_STALE_PREFIX,
			MetadataService::STATE_RESET,
		);

		foreach (
			[
				MetadataService::STATE_ERODED,
				MetadataService::STATE_RESET,
			] as $state
		)
		{
			$this->assertStringStartsNotWith( MetadataService::PENDING_PREFIX, $state );
			// STALE_LIKE is a SQL pattern; fnmatch speaks glob, so `%` → `*`.
			$this->assertTrue(
				fnmatch( str_replace( '%', '*', MetadataService::STALE_LIKE ), $state ),
				$state . ' must be found by the stale namespace pattern',
			);
		}
	}


	public function testCountByStateMatchesAPatternWithLike(): void
	{

		// A pattern asks a different question of the database than an exact
		// value does, and the namespace exists so the pattern is askable.
		$this->expr->expects( $this->once() )
		           ->method( 'like' )
		           ->willReturn( 'meta_value_string LIKE :p' )
		;
		$result = $this->createMock( IResult::class );
		$result->method( 'fetchOne' )
		       ->willReturn( 4 )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$this->assertSame( 4, $this->service->countByState( MetadataService::STALE_LIKE ) );
	}


	public function testScansExcludeDisownedFilesButPerFileReadsDoNot(): void
	{

		// The guarantee that lets a reset defer its work: a disowned hash
		// stops being findable when it is marked, not when the job clears
		// it. Asserted on the join the scans build, since the exclusion is
		// structural rather than a value the mock can return.
		$joined = [];
		$this->queryBuilder->method( 'leftJoin' )
		                   ->willReturnCallback(
			                   static function (
				                   $fromAlias,
				                   $join,
				                   $alias,
			                   ) use
			                   (
				                   &
				                   $joined,
			                   )
			                   {

				                   $joined[] = (string) $alias;

				                   return null;
			                   },
		                   )
		;
		$this->expr->method( 'like' )
		           ->willReturn( '1=1' )
		;
		$this->expr->method( 'isNull' )
		           ->willReturn( 'x IS NULL' )
		;

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchAll' )
		       ->willReturn( [] )
		;
		$result->method( 'fetch' )
		       ->willReturn( false )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$this->service->queryByHash( 'abc123' );
		$this->assertContains( 'stale', $joined, 'search must exclude disowned files' );

		$joined = [];
		$this->service->queryDuplicates();
		$this->assertContains( 'stale', $joined, 'duplicate groups must exclude disowned files' );

		// …but the file's own page keeps showing what is stored, labelled.
		$joined   = [];
		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'getKeys' )
		         ->willReturn( [] )
		;
		$this->metadataManager->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;

		$this->service->getHashes( 42 );
		$this->assertNotContains( 'stale', $joined, 'a file the user opened shows its own hashes' );
	}


	public function testMarkStaleWritesOnlyTheMarker(): void
	{

		// The whole point of deferring: one UPDATE over the index, with the
		// hashes and the freshness stamp untouched for the drain — or for an
		// import that gets there first.
		$sets = [];
		$this->queryBuilder->method( 'set' )
		                   ->willReturnCallback(
			                   function (
				                   $column,
				                   $value,
			                   ) use
			                   (
				                   &
				                   $sets,
			                   )
			                   {

				                   $sets[] = (string) $column;

				                   return $this->queryBuilder;
			                   },
		                   )
		;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturn( 3 )
		;
		$this->expr->method( 'in' )
		           ->willReturn( 'file_id IN (:ids)' )
		;

		$marked = $this->service->markStale( [
			1,
			2,
			3,
		] );

		$this->assertSame( 3, $marked );
		$this->assertSame( [ MetadataService::FIELD_META_VALUE_STRING ], $sets );
	}


	/**
	 * Only files that hold hashes. The marker shares its column with the
	 * queue, so marking a file writes over whatever it was waiting for — and
	 * a file with no hashes has nothing to disown, which would make resetting
	 * the hashes quietly reset the queue as well.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testMarkingEverythingReachesOnlyFilesThatHoldHashes(): void
	{

		$pages = [
			[
				[ MetadataService::FIELD_FILE_ID => 1 ],
				[ MetadataService::FIELD_FILE_ID => 2 ],
			],
			[],
		];

		$result = $this->createMock( IResult::class );
		$result->method( 'fetch' )
		       ->willReturnCallback(
			       static function () use
			       (
				       &
				       $pages,
			       ): array|false
			       {

				       $row = array_shift( $pages[0] );

				       if ( $row === null )
				       {
					       array_shift( $pages );

					       return false;
				       }

				       return $row;
			       },
		       )
		;

		// The page query is the one that names a hash key; without that
		// restriction a never-hashed file in the queue would be disowned too.
		$likes = [];
		$this->expr->method( 'like' )
		           ->willReturnCallback(
			           function (
				           $column,
				           $value,
			           ) use
			           (
				           &
				           $likes,
			           ): string
			           {

				           $likes[] = (string) $value;

				           return 'like';
			           },
		           )
		;

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturn( 2 )
		;

		$this->assertSame( 2, $this->service->markAllStale() );
		$this->assertContains( MetadataService::KEY_FILE_CHECKSUM_LIKE, $likes );
	}


	public function testMarkStaleIsANoOpForAnEmptyList(): void
	{

		$this->queryBuilder->expects( $this->never() )
		                   ->method( 'update' )
		;

		$this->assertSame( 0, $this->service->markStale( [] ) );
	}


	public function testFetchStaleBatchAsksForResetsOnly(): void
	{

		// An eroded file has no hashes left to clear, so handing it to the
		// drain would be work with nothing to do.
		$compared = [];
		$this->expr->method( 'eq' )
		           ->willReturnCallback(
			           static function (
				           $left,
				           $right,
			           ) use
			           (
				           &
				           $compared,
			           ): string
			           {

				           $compared[ (string) $left ] = $right;

				           return '1=1';
			           },
		           )
		;

		$result = $this->createMock( IResult::class );
		$result->method( 'fetch' )
		       ->willReturnOnConsecutiveCalls(
			       [ MetadataService::FIELD_FILE_ID => '42' ],
			       false,
		       )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$this->assertSame( [ 42 ], $this->service->fetchStaleBatch() );
		$this->assertSame(
			MetadataService::STATE_RESET,
			$compared[ MetadataService::FIELD_META_VALUE_STRING ] ?? null,
		);
	}


	public function testClearQueueStateLeavesTheFreshnessStampAlone(): void
	{

		// The stamp belongs to the hashes, not to the queue: clearing it
		// would make every file look as though it had never been hashed.
		$sets = [];
		$this->queryBuilder->method( 'set' )
		                   ->willReturnCallback(
			                   function (
				                   $column,
				                   $value,
			                   ) use
			                   (
				                   &
				                   $sets,
			                   )
			                   {

				                   $sets[] = (string) $column;

				                   return $this->queryBuilder;
			                   },
		                   )
		;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturn( 7 )
		;
		$this->expr->method( 'isNotNull' )
		           ->willReturn( 'x IS NOT NULL' )
		;

		$this->assertSame( 7, $this->service->clearQueueState() );
		$this->assertSame( [ MetadataService::FIELD_META_VALUE_STRING ], $sets );
		$this->assertNotContains( MetadataService::FIELD_META_VALUE_INT, $sets );
	}


	public function testClearingRemovesTheIndexRowsSavingLeavesBehind(): void
	{

		// Saving upserts the keys the document has and leaves rows for the
		// ones it lost, so a cleared file went on answering searches with
		// hashes it no longer had. The document is the truth; the orphans
		// have to be collected explicitly.
		$deleted = false;
		$this->queryBuilder->method( 'delete' )
		                   ->willReturnCallback(
			                   function (
				                   $table,
			                   ) use
			                   (
				                   &
				                   $deleted,
			                   )
			                   {

				                   $deleted = ( $table === MetadataService::TABLE_FILES_METADATA_INDEX );

				                   return $this->queryBuilder;
			                   },
		                   )
		;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturn( 2 )
		;
		$this->expr->method( 'like' )
		           ->willReturn( 'meta_key LIKE :p' )
		;
		$this->expr->method( 'neq' )
		           ->willReturn( 'meta_key <> :p' )
		;

		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'getFileId' )
		         ->willReturn( 42 )
		;
		$this->metadataManager->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;

		$this->service->clearMetadata( 42 );

		$this->assertTrue( $deleted, 'the orphaned hash rows must be deleted' );
	}


	public function testClearingWithoutSavingTouchesNoIndexRows(): void
	{

		// The caller keeps the document to save later; pruning now would
		// delete rows the unsaved document still claims.
		$this->queryBuilder->expects( $this->never() )
		                   ->method( 'delete' )
		;

		$metadata = $this->createMock( IFilesMetadata::class );
		$this->service->clearMetadata( $metadata, false );

		$this->addToAssertionCount( 1 );
	}

}
