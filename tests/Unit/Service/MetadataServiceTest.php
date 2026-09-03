<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\DB\Exception;
use OCP\DB\IResult;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IAppConfig;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use TypeError;

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

	private AlgorithmCatalogue               $catalogue;

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

		// A real catalogue over a mocked app config: getValueArray() answers
		// [] and the catalogue falls back to the shipped default, which is
		// what an instance that never touched the allowlist has.
		$this->catalogue = new AlgorithmCatalogue( $this->createMock( IAppConfig::class ) );

		$this->service = new MetadataService(
			$this->db,
			$this->metadataManager,
			$this->filecacheService,
			$this->logger,
			$this->catalogue,
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


	/**
	 * Two of the ten algorithms this app ships enabled carry a hyphen, and
	 * the term parser used to reject every one of them: the search found
	 * nothing for `sha3-256:<hash>` while `sha256:<hash>` worked.
	 */
	public function testParseQueryTermAcceptsHyphenatedAlgorithmNames(): void
	{

		$hash = str_repeat( 'a', 64 );

		foreach ( [ 'sha3-256', 'sha3-384', 'sha3-512' ] as $algo )
		{
			$result = MetadataService::parseQueryTerm( "$algo:$hash" );

			$this->assertNotNull( $result, "$algo must parse" );
			$this->assertSame( $algo, $result['algo'] );
			$this->assertSame( $hash, $result['hash'] );
		}
	}


	/**
	 * Typing the algorithm the way it is usually written down.
	 */
	public function testParseQueryTermAcceptsAnUppercaseAlgorithmPrefix(): void
	{

		$hash   = str_repeat( 'b', 40 );
		$result = MetadataService::parseQueryTerm( "SHA3-256:$hash" );

		$this->assertNotNull( $result );
		$this->assertSame( 'sha3-256', $result['algo'] );
	}


	/**
	 * The parser reads a shape; whether the name means anything is the
	 * catalogue's business, and the caller's to act on.
	 */
	public function testParseQueryTermDoesNotJudgeTheAlgorithmName(): void
	{

		$result = MetadataService::parseQueryTerm( 'no-such-algo:' . str_repeat( 'c', 32 ) );

		$this->assertNotNull( $result );
		$this->assertSame( 'no-such-algo', $result['algo'] );
	}


	public function testRegisterInitializesAllAlgoKeys(): void
	{

		$expectedCalls = count( array_values( array_unique( array_merge( MetadataService::LEGACY_ALGOS, $this->catalogue->algorithms() ) ) ) ) + 1;

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

		foreach ( array_values( array_unique( array_merge( MetadataService::LEGACY_ALGOS, $this->catalogue->algorithms() ) ) ) as $algo )
		{
			// The hash prefix, not the app's: a key declared under the old
			// spelling would be re-declared by every repair, undoing the
			// withdrawal in the same run that performs it.
			$this->assertContains(
				MetadataService::getHashKey( $algo ),
				$registeredKeys,
				sprintf( 'Expected %s to be registered.', MetadataService::getHashKey( $algo ) ),
			);
			$this->assertNotContains(
				MetadataService::legacyHashKey( $algo ),
				$registeredKeys,
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

		// The row is there, so no INSERT leg runs. Existence is a question
		// asked of the table, not inferred from affected rows: an UPDATE
		// writing the value a row already holds reports zero on MySQL, and
		// reading that as "no row" inserted a duplicate.
		$this->queryBuilder->expects( $this->never() )
		                   ->method( 'insert' )
		;

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchOne' )
		       ->willReturn( 42 )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturn( 0 )
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

		// Two things follow the save, and both have to: the marker is written
		// after it because saving regenerates the row for updated_at, and the
		// hash rows are deleted because the hash keys are *not* indexed by
		// Nextcloud any more — nothing else would remove them, and the file
		// would keep answering searches by hashes it no longer has.
		$deleted = 0;
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

				                   if ( $table === MetadataService::TABLE_FILES_METADATA_INDEX )
				                   {
					                   $deleted ++;
				                   }

				                   return $this->queryBuilder;
			                   },
		                   )
		;

		$marker = null;
		$this->queryBuilder->method( 'set' )
		                   ->willReturnCallback(
			                   function (
				                   $column,
				                   $value,
			                   ) use
			                   (
				                   &
				                   $marker,
			                   )
			                   {

				                   if ( $column === MetadataService::FIELD_META_VALUE_STRING )
				                   {
					                   $marker = $value;
				                   }

				                   return $this->queryBuilder;
			                   },
		                   )
		;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturn( 1 )
		;

		$this->service->markEroded( 42 );

		$this->assertSame( 1, $deleted, 'the hash index rows go with the hashes' );
		$this->assertSame( MetadataService::STATE_ERODED, $marker );
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
			// Not indexed by Nextcloud: it would write the full value
			// into a varchar(63) column and fail for every hash longer
			// than that. syncHashIndex() writes the row, truncated.
			     ->with( MetadataService::getHashKey( 'md5' ), str_repeat( 'c', 32 ), false )
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

		// Hashes of the length their algorithms produce: anything else is
		// somebody's word rather than a hash, and is dropped on the way in.
		$added = $this->service->backfillHashes(
			42,
			[
				'sha1' => str_repeat( 'd', 40 ),
				'md5'  => str_repeat( 'c', 32 ),
			],
			1700000000,
		);

		$this->assertSame( 1, $added );
	}


	/**
	 * The column is the client's word: core stores the OC-Checksum header
	 * verbatim. A pair naming an algorithm this instance does not compute
	 * would otherwise become one of its metadata keys.
	 */
	public function testBackfillHashesDropsWhatThisInstanceCouldNotHaveComputed(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );
		$this->metadataManager->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;
		$metadata->expects( $this->never() )
		         ->method( 'setString' )
		;
		$this->metadataManager->expects( $this->never() )
		                      ->method( 'saveMetadata' )
		;

		$added = $this->service->backfillHashes(
			42,
			[
				'averylongalgorithmnamethatwouldoverflowthekey' => str_repeat( 'a', 40 ),
				'sha1'                                          => 'not-hex-and-far-too-short',
			],
			1700000000,
		);

		$this->assertSame( 0, $added );
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

		$this->queryBuilder->method( 'where' )
		                   ->willReturnSelf()
		;

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchOne' )
		       ->willReturn( 42 )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturn( 1 )
		;

		// The same two columns are compared by the update and by the
		// existence check that follows it, so the count is not the point —
		// what each comparison names is.
		$this->expr->method( 'eq' )
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

		// getHashes() reads what the document holds, so the document has to
		// say what it holds: one hash key per legacy algorithm here.
		$keys = array_map(
			static fn( string $algo ): string => MetadataService::getHashKey( $algo ),
			MetadataService::LEGACY_ALGOS,
		);
		$metadata->method( 'getKeys' )
		         ->willReturn( $keys )
		;
		$metadata->expects( $this->exactly( count( $keys ) ) )
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


	/**
	 * The failure this was written after: a file that cannot be cleared used
	 * to come back on the next page for ever, because the walk always asked
	 * for the *first* page and the file never left it. Live, that meant a
	 * reset that never returned and two million identical log lines.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testClearingEverythingFinishesEvenWhenAFileCannotBeCleared(): void
	{

		// Two pages: one row that always refuses, then nothing. Keyset paging
		// is what makes the second call return nothing rather than the same
		// row again — the loop asks for ids *after* the last one it saw.
		$afterIds = [];
		$this->expr->method( 'gt' )
		           ->willReturnCallback(
			           function (
				           $column,
				           $value,
			           ) use
			           (
				           &
				           $afterIds,
			           ): string
			           {

				           $afterIds[] = (int) $value;

				           return 'file_id > ?';
			           },
		           )
		;

		$result = $this->createMock( IResult::class );
		$result->method( 'fetch' )
		       ->willReturnOnConsecutiveCalls(
			       [ MetadataService::FIELD_FILE_ID => 42 ],
			       false,
			       false,
		       )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturn( 1 )
		;

		// What Nextcloud really throws for a file whose filecache row is gone:
		// it reads the storage id from there, gets false, and refuses.
		$this->metadataManager->method( 'saveMetadata' )
		                      ->willThrowException(
			                      new TypeError( 'setStorageId(): Argument #1 must be of type int, bool given' ),
		                      )
		;

		$cleared = $this->service->clearHashesNow();

		$this->assertSame( 1, $cleared, 'the orphan is dropped rather than retried for ever' );
		$this->assertContains( 42, $afterIds, 'the second page asks for ids after the one that failed' );
	}


	/**
	 * The defect this fixes: `meta_value_string` is varchar(63), Nextcloud
	 * inserts the value the metadata document holds, and a SHA-256 is 64
	 * characters. The insert failed, `IndexRequestService::updateIndex()`
	 * swallowed it as a logged warning, and searching for a SHA-256 found
	 * nothing at all.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testALongHashIsTruncatedToFitTheIndexColumn(): void
	{

		$stored = [];
		$this->queryBuilder->method( 'values' )
		                   ->willReturnCallback(
			                   function (
				                   array $values,
			                   ) use
			                   (
				                   &
				                   $stored,
			                   )
			                   {

				                   $stored[] = (string) ( $values[ MetadataService::FIELD_META_VALUE_STRING ] ?? '' );

				                   return $this->queryBuilder;
			                   },
		                   )
		;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturn( 1 )
		;

		$sha256 = str_repeat( 'a', 64 );
		$sha1   = str_repeat( 'b', 40 );

		$written = $this->service->syncHashIndex(
			42,
			[
				'sha256' => $sha256,
				'sha1'   => $sha1,
			],
		);

		$this->assertSame( 2, $written );
		$this->assertContains(
			substr( $sha256, 0, MetadataService::META_VALUE_STRING_MAX_LENGTH ),
			$stored,
			'a hash longer than the column is stored as its prefix, not refused',
		);
		$this->assertContains( $sha1, $stored, 'one that fits is stored whole' );
	}


	/**
	 * What makes a truncated row recognisable without storing a marker for
	 * it: no supported algorithm produces a digest of exactly the column's
	 * length, so a value of that length is always a prefix — and `meta_key`
	 * says which algorithm, hence how long the whole thing should be.
	 *
	 * If this ever fails, the confirmation step in `confirmFullHash()` would
	 * skip a truncated value believing it complete. Fail loudly here rather
	 * than quietly there.
	 */
	public function testNoSupportedAlgorithmProducesADigestExactlyTheColumnsLength(): void
	{

		// Everything this PHP build could be allowed to compute, not only what
		// is in force: an administrator may enable any of these tomorrow.
		foreach ( $this->catalogue->available() as $algo )
		{
			$length = strlen( hash( $algo, 'the quick brown fox' ) );

			$this->assertNotSame(
				MetadataService::META_VALUE_STRING_MAX_LENGTH,
				$length,
				$algo . ' would be indistinguishable from a truncated hash',
			);
		}
	}


	/**
	 * The check that makes truncation safe, in the one place it now lives.
	 *
	 * Two files whose SHA-256 hashes agree for 63 characters and differ in
	 * the 64th match the same index row. Only one of them is the file being
	 * searched for, and the metadata document is what says which.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAMatchOnTheTruncatedPrefixIsConfirmedAgainstTheDocument(): void
	{

		$wanted = str_repeat( 'a', 63 ) . '1';
		$other  = str_repeat( 'a', 63 ) . '2';

		// The rows carry the metadata document alongside them, the way
		// queryByHash() returns them: the index says which files are
		// candidates, the document says what they really hold.
		$rows = [
			$this->rowFor( 1, $wanted ),
			$this->rowFor( 2, $other ),
		];

		$confirmed = $this->service->confirmFullHash( $rows, $wanted );

		$this->assertCount( 1, $confirmed );
		$this->assertSame( 1, $confirmed[0][ MetadataService::FIELD_FILE_ID ] );
	}


	/**
	 * A hash the index stored whole was already compared in full, so there
	 * is nothing to confirm and no metadata document to read.
	 */
	public function testAShortHashIsNotCheckedAgain(): void
	{

		$rows = [ $this->rowFor( 1, str_repeat( 'a', 40 ), 'sha1' ) ];

		$this->metadataManager->expects( $this->never() )
		                      ->method( 'getMetadata' )
		;

		$this->assertSame( $rows, $this->service->confirmFullHash( $rows, str_repeat( 'a', 40 ) ) );
	}


	/**
	 * The backfill's whole point: it walks the **metadata documents**,
	 * because the rows it exists to create are the ones the index does not
	 * have.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testReindexingSkipsFilesWhoseRowsAlreadyMatch(): void
	{

		$document = json_encode(
			[
				MetadataService::getHashKey( 'sha256' ) => [
					'value'          => str_repeat( 'a', 64 ),
					'type'           => 'string',
					'etag'           => '',
					'indexed'        => false,
					'editPermission' => 0,
				],
			],
		);

		// One page of one metadata document, then nothing; the file already
		// has the row its document calls for.
		$pages = [
			[
				[
					MetadataService::FIELD_FILE_ID => 7,
					MetadataService::FIELD_JSON    => $document,
				],
			],
			[
				[
					MetadataService::FIELD_FILE_ID  => 7,
					MetadataService::FIELD_META_KEY => MetadataService::getHashKey( 'sha256' ),
				],
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

				       if ( $pages === [] )
				       {
					       return false;
				       }

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
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		// The proof that nothing was rewritten: no row was deleted, which is
		// how syncHashIndex() starts.
		$this->queryBuilder->expects( $this->never() )
		                   ->method( 'delete' )
		;

		$this->assertSame( 0, $this->service->reindexHashes() );
	}


	/**
	 * Two paths by length. A long hash matched on its first 63 characters
	 * only, so the metadata document is asked too — before the row is sent,
	 * so a file that merely shares the prefix is never fetched or decoded.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testALongHashAlsoAsksTheDocumentBeforeTheRowIsSent(): void
	{

		$hash = str_repeat( 'a', 64 );
		$this->givenTheQueryReturnsNothing();

		$this->service->queryByHash( $hash, 'sha256' );

		// The bare hash, not the key and value it sits in: a hex digest
		// appears verbatim however Nextcloud serialises the metadata
		// document, so this cannot exclude a file that really holds it.
		$this->assertContains( '%' . $hash . '%', $this->capturedLikes );
	}


	/**
	 * A hash the index stored whole was compared whole by the index, so
	 * there is nothing left to ask and no reason to pay for asking.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAShortHashDoesNotTouchTheDocument(): void
	{

		$hash = str_repeat( 'a', 40 );
		$this->givenTheQueryReturnsNothing();

		$this->service->queryByHash( $hash, 'sha1' );

		$this->assertNotContains( '%' . $hash . '%', $this->capturedLikes );
	}


	/**
	 * The guard's cheap answer: nothing to do, so no walk.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testACompleteIndexIsNotWalked(): void
	{

		// Same count on both sides — every file whose metadata document
		// mentions a hash has a row.
		$this->givenCountsOf( 100, 100 );

		$this->queryBuilder->expects( $this->never() )
		                   ->method( 'delete' )
		;

		$this->assertTrue( $this->service->hashIndexIsComplete() );
		$this->assertSame( 0, $this->service->reindexHashes() );
	}


	/**
	 * And when it says there is work, the walk happens — the guard decides
	 * whether to look, never how much to repair.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnIncompleteIndexIsWalked(): void
	{

		$this->givenCountsOf( 98, 100 );

		$this->assertFalse( $this->service->hashIndexIsComplete() );
	}


	/**
	 * The expensive pass does not ask. A file whose metadata document holds
	 * two algorithms and whose index holds one counts once on each side, so
	 * the counts agree while a row is still missing — this is the way to
	 * that file.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testForcingWalksEvenWhenTheCountsAgree(): void
	{

		$counted = 0;
		$result  = $this->createMock( IResult::class );
		$result->method( 'fetchOne' )
		       ->willReturnCallback(
			       static function () use
			       (
				       &
				       $counted,
			       ): int
			       {

				       $counted ++;

				       return 100;
			       },
		       )
		;
		$result->method( 'fetch' )
		       ->willReturn( false )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$this->service->reindexHashes( force: true );

		$this->assertSame( 0, $counted, 'a forced pass asks no counting question at all' );
	}


	/**
	 * The flaw this was written after: matching `file-checksum-%` in the
	 * metadata document counts every file the app has ever *considered*,
	 * because `file-checksum-updated_at` is one of those keys. On a real
	 * instance that was 455 metadata documents against 302 holding a hash —
	 * so the counts never agreed, the guard never fired, and the expensive
	 * walk ran on every repair while visiting half again as many rows as it
	 * needed.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheGuardLooksForHashesNotForTheStamp(): void
	{

		$this->givenCountsOf( 100, 100 );
		$this->service->hashIndexIsComplete();

		$this->assertContains(
			'%"' . MetadataService::KEY_FILE_CHECKSUM_HASH_PREFIX . '%',
			$this->capturedLikes,
			'one pattern on the prefix, which is what having a prefix is for',
		);
		$this->assertNotContains(
			'%"' . MetadataService::KEY_FILE_CHECKSUM_PREFIX . '%',
			$this->capturedLikes,
			'a pattern that broad matches the stamp as well as the hashes',
		);
		$this->assertNotContains(
			'%"' . MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT . '":%',
			$this->capturedLikes,
		);

		// The structural claim the one pattern rests on, asserted rather than
		// assumed: the stamp key does not begin with the hash prefix, so a
		// metadata document holding nothing but a stamp cannot match. Move
		// the stamp under that prefix and this fails, which is the point.
		$this->assertStringStartsNotWith(
			MetadataService::KEY_FILE_CHECKSUM_HASH_PREFIX,
			MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT,
		);
	}


	/**
	 * The repair, unlike everything else, must recognise the old spelling.
	 *
	 * A metadata document restored from before the rename is exactly what
	 * this walk exists to find, and the bulk rename cannot reach it: that
	 * one finds its work through the index, and these files have no hash
	 * rows at all.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheRepairsFinderLooksForBothSpellings(): void
	{

		$this->givenCountsOf( 100, 100 );
		$this->service->hashIndexIsComplete();

		$this->assertContains(
			'%"' . MetadataService::KEY_FILE_CHECKSUM_HASH_PREFIX . '%',
			$this->capturedLikes,
			'the current spelling needs one pattern, because it has a prefix',
		);

		// And the old one needs seven, because `file-checksum-` is the
		// ambiguous prefix this app moved away from: matching it would bring
		// back the false positives the rename removed. That asymmetry is the
		// rename's whole argument, so it is worth a test of its own.
		foreach ( MetadataService::LEGACY_ALGOS as $algo )
		{
			$this->assertContains(
				'%"' . MetadataService::legacyHashKey( $algo ) . '":%',
				$this->capturedLikes,
				'a document written before the rename is what the repair is for',
			);
		}

		$this->assertNotContains(
			'%"' . MetadataService::KEY_FILE_CHECKSUM_PREFIX . '%',
			$this->capturedLikes,
			'and never by the prefix they share with the stamp',
		);
	}


	/**
	 * And the walk renames before it reads. An old-spelled metadata document
	 * reads as holding no hashes at all, so syncing from it would delete the
	 * very index rows the walk exists to write.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnOldSpelledDocumentIsRenamedBeforeItIsRead(): void
	{

		$legacy   = MetadataService::legacyHashKey( 'sha256' );
		$document = json_encode(
			[
				$legacy => [
					'value'          => str_repeat( 'a', 64 ),
					'type'           => 'string',
					'etag'           => '',
					'indexed'        => false,
					'editPermission' => 0,
				],
			],
		);

		$pages = [
			[
				[
					MetadataService::FIELD_FILE_ID => 7,
					MetadataService::FIELD_JSON    => $document,
				],
			],
			[],
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

				       if ( $pages === [] )
				       {
					       return false;
				       }

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
		$result->method( 'fetchOne' )
		       ->willReturn( 0 )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$written = [];
		$this->queryBuilder->method( 'set' )
		                   ->willReturnCallback(
			                   function (
				                   $column,
				                   $value,
			                   ) use
			                   (
				                   &
				                   $written,
			                   )
			                   {

				                   if ( $column === MetadataService::FIELD_JSON )
				                   {
					                   $written[] = (string) $value;
				                   }

				                   return $this->queryBuilder;
			                   },
		                   )
		;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturn( 1 )
		;

		$this->service->reindexHashes( force: true );

		$this->assertCount( 1, $written, 'the document is rewritten once' );
		$this->assertStringContainsString( MetadataService::getHashKey( 'sha256' ), $written[0] );
		$this->assertStringNotContainsString( '"' . $legacy . '":', $written[0] );
	}


	/**
	 * The forgotten population is the same scan taken from the other side of
	 * the stamp subquery, which is the only thing that separates the two
	 * walks — so it is worth pinning that they differ in exactly that.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheForgottenWalkTakesTheOtherSideOfTheStampRow(): void
	{

		$result = $this->createMock( IResult::class );
		$result->method( 'fetch' )
		       ->willReturn( false )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;

		$this->service->reindexUnstampedHashes();

		$this->assertContains(
			MetadataService::FIELD_FILE_ID,
			$this->capturedSetTests['notIn'],
			'a file the index has forgotten is one with no stamp row',
		);
		$this->assertNotContains(
			MetadataService::FIELD_FILE_ID,
			$this->capturedSetTests['in'],
			'and the walk that needs one is the other step',
		);
	}


	/**
	 * The stamp row goes back with the hash rows, carrying the timestamp the
	 * metadata document itself holds. Without it the file would be repaired
	 * and still invisible to the next run of everything else, which all
	 * start from that row.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAForgottenFileGetsItsStampRowBack(): void
	{

		$document = json_encode(
			[
				MetadataService::getHashKey( 'sha256' )    => [
					'value'          => str_repeat( 'a', 64 ),
					'type'           => 'string',
					'etag'           => '',
					'indexed'        => false,
					'editPermission' => 0,
				],
				MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT => [
					'value'          => 1_700_000_000,
					'type'           => 'int',
					'etag'           => '',
					'indexed'        => true,
					'editPermission' => 0,
				],
			],
		);

		$pages = [
			[
				[
					MetadataService::FIELD_FILE_ID => 7,
					MetadataService::FIELD_JSON    => $document,
				],
			],
			[],
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

				       if ( $pages === [] )
				       {
					       return false;
				       }

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
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;
		$this->queryBuilder->method( 'executeStatement' )
		                   ->willReturn( 1 )
		;

		$inserted = [];
		$this->queryBuilder->method( 'values' )
		                   ->willReturnCallback(
			                   function (
				                   array $values,
			                   ) use
			                   (
				                   &
				                   $inserted,
			                   )
			                   {

				                   $inserted[] = $values;

				                   return $this->queryBuilder;
			                   },
		                   )
		;

		$this->assertSame( 1, $this->service->reindexUnstampedHashes() );

		$keys = array_column( $inserted, MetadataService::FIELD_META_KEY );

		$this->assertContains( MetadataService::getHashKey( 'sha256' ), $keys );
		$this->assertContains( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $keys );

		$stamp = $inserted[ array_search( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $keys, true ) ];

		$this->assertSame(
			1_700_000_000,
			$stamp[ MetadataService::FIELD_META_VALUE_INT ],
			'the stamp is the document\'s own, not the moment of the repair',
		);
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

		// Saving upserts the keys the metadata document has and leaves rows
		// for the ones it lost, so a cleared file went on answering searches
		// with hashes it no longer had. The document is the truth; the orphans
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


	/**
	 * A document that held nothing but this app's keys is deleted outright.
	 *
	 * Nextcloud does not do it: saveMetadata() serialises an emptied set to
	 * `{}` and stores that, so a purge which only saved would leave a row per
	 * deleted file forever. Deleting is safe exactly here — the set is empty,
	 * so nothing of another app's goes with it.
	 */
	public function testPurgingADocumentOfOursAloneDeletesTheRecord(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'getFileId' )
		         ->willReturn( 42 )
		;
		$metadata->expects( $this->once() )
		         ->method( 'removeStartsWith' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_PREFIX )
		;
		$metadata->method( 'getKeys' )
		         ->willReturn( [] )
		;

		$this->metadataManager->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;

		$this->metadataManager->expects( $this->once() )
		                      ->method( 'deleteMetadata' )
		                      ->with( 42 )
		;
		$this->metadataManager->expects( $this->never() )
		                      ->method( 'saveMetadata' )
		;

		$this->service->purgeMetadata( 42 );
	}


	/**
	 * A document another app still uses is kept, and only our keys leave it.
	 */
	public function testPurgingADocumentAnotherAppSharesKeepsTheRecord(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'getFileId' )
		         ->willReturn( 42 )
		;
		$metadata->expects( $this->once() )
		         ->method( 'removeStartsWith' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_PREFIX )
		;
		$metadata->method( 'getKeys' )
		         ->willReturn( [ 'photos-exif' ] )
		;

		$this->metadataManager->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;

		$this->metadataManager->expects( $this->never() )
		                      ->method( 'deleteMetadata' )
		;
		$this->metadataManager->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		$this->service->purgeMetadata( 42 );
	}


	public function testClearingWithoutSavingTouchesNoIndexRows(): void
	{

		// The caller keeps the metadata document to save later; pruning now
		// would delete rows the unsaved document still claims.
		$this->queryBuilder->expects( $this->never() )
		                   ->method( 'delete' )
		;

		$metadata = $this->createMock( IFilesMetadata::class );
		$this->service->clearMetadata( $metadata, false );

		$this->addToAssertionCount( 1 );
	}


	/**
	 * A row as {@see MetadataService::queryByHash()} returns one: the index
	 * columns plus the file's whole metadata document.
	 *
	 * @return array<string, mixed>
	 */
	private function rowFor(
		int    $fileId,
		string $hash,
		string $algo = 'sha256',
	): array {

		$metaKey = MetadataService::getHashKey( $algo );

		return [
			MetadataService::FIELD_FILE_ID    => $fileId,
			MetadataService::FIELD_META_KEY   => $metaKey,
			MetadataService::FIELD_JSON_ALIAS => json_encode(
				[
					$metaKey => [
						'value'          => $hash,
						'type'           => 'string',
						'etag'           => '',
						'indexed'        => false,
						'editPermission' => 0,
					],
				],
			),
		];
	}


	/**
	 * An empty result, for a test that is about the query rather than what
	 * comes back from it.
	 */
	private function givenTheQueryReturnsNothing(): void
	{

		$result = $this->createMock( IResult::class );
		$result->method( 'fetchAll' )
		       ->willReturn( [] )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;
	}


	/**
	 * The two numbers the guard compares: files the index knows a hash for,
	 * and metadata documents that hold one.
	 *
	 * @noinspection PhpSameParameterValueInspection
	 */
	private function givenCountsOf(
		int $indexed,
		int $holding,
	): void {

		// The pair, as often as it is asked for: a test may call the guard
		// directly and then again through the walk.
		$call   = 0;
		$result = $this->createMock( IResult::class );
		$result->method( 'fetchOne' )
		       ->willReturnCallback(
			       static function () use
			       (
				       &
				       $call,
				       $indexed,
				       $holding,
			       ): int
			       {

				       return $call ++ % 2 === 0
					       ? $indexed
					       : $holding;
			       },
		       )
		;
		$result->method( 'fetch' )
		       ->willReturn( false )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;
	}

}
