<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Server;

/**
 * A queued file's failed attempts: counted in its state row's int, kept
 * when the file is queued again, gone when it leaves the queue, and the
 * order the queue is served in.
 *
 * The state row needs no file behind it, so these work on file ids no
 * instance has, inside a transaction that tearDown() rolls back.
 */
class FailedAttemptsTest
    extends
    DatabaseTestCase
{

//  constants

	/** Above any file id a test instance has. */
	private const FILE_ID = 2000000001;


//  private properties

	private MetadataService $metadataService;


//  getters / setters / is* / has*

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->metadataService = Server::get( MetadataService::class );

		$this->beginTransaction();
	}


//  other non-static methods

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testEachFailedAttemptIsCounted(): void
	{
		$this->metadataService->markPending( self::FILE_ID, MetadataService::PENDING_AUTO );

		$this->assertSame( 1, $this->metadataService->recordFailedAttempt( self::FILE_ID, MetadataService::PENDING_AUTO ) );
		$this->assertSame( 2, $this->metadataService->recordFailedAttempt( self::FILE_ID, MetadataService::PENDING_AUTO ) );

		$this->assertSame( [ MetadataService::PENDING_AUTO, 2 ], $this->stateOf( self::FILE_ID ) );
	}

	/**
	 * The rule sweep queues every file whose hashes are older than it, and a
	 * file that keeps failing stays that: were its count reset, the sweep
	 * would put it back at the head of the queue each time.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testQueuingAFileAgainKeepsItsCountAndTakesTheNewMode(): void
	{
		$this->metadataService->markPending( self::FILE_ID, MetadataService::PENDING_AUTO );
		$this->metadataService->recordFailedAttempt( self::FILE_ID, MetadataService::PENDING_AUTO );
		$this->metadataService->recordFailedAttempt( self::FILE_ID, MetadataService::PENDING_AUTO );

		$this->metadataService->markPending( self::FILE_ID, MetadataService::PENDING_FORCE );

		$this->assertSame( [ MetadataService::PENDING_FORCE, 2 ], $this->stateOf( self::FILE_ID ) );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileThatLeftTheQueueStartsAgainAtZero(): void
	{
		$this->metadataService->markPending( self::FILE_ID, MetadataService::PENDING_AUTO );
		$this->metadataService->recordFailedAttempt( self::FILE_ID, MetadataService::PENDING_AUTO );

		$this->metadataService->clearComputedMarker( self::FILE_ID );
		$this->assertNull( $this->stateOf( self::FILE_ID ) );

		$this->metadataService->markPending( self::FILE_ID, MetadataService::PENDING_AUTO );
		$this->assertSame( [ MetadataService::PENDING_AUTO, 0 ], $this->stateOf( self::FILE_ID ) );
	}

	/**
	 * A failed attempt at a file the attempt finds not queued queues it, as
	 * the re-mark the count replaced did.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnAttemptAtAFileNotQueuedQueuesIt(): void
	{
		$marker = MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_MISSING;

		$this->assertSame( 1, $this->metadataService->recordFailedAttempt( self::FILE_ID, $marker ) );

		$this->assertSame( [ $marker, 1 ], $this->stateOf( self::FILE_ID ) );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheQueueServesTheFewestFailedAttemptsFirst(): void
	{
		[ $once, $twice, $never ] = [ self::FILE_ID, self::FILE_ID + 1, self::FILE_ID + 2 ];

		foreach ( [ $once, $twice, $never ] as $fileId )
		{
			$this->metadataService->markPending( $fileId, MetadataService::PENDING_AUTO );
		}

		$this->metadataService->recordFailedAttempt( $once, MetadataService::PENDING_AUTO );
		$this->metadataService->recordFailedAttempt( $twice, MetadataService::PENDING_AUTO );
		$this->metadataService->recordFailedAttempt( $twice, MetadataService::PENDING_AUTO );
		$this->leaveOnlyTheQueued( [ $once, $twice, $never ] );

		$this->assertSame(
			[ $never, $once, $twice ],
			array_column( $this->metadataService->fetchPendingBatch( 3 ), MetadataService::FIELD_FILE_ID ),
		);
	}

	/**
	 * A walk takes the queue by file id from its bound, however often each
	 * file has failed: `queue:drain --all` tries every file once.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAWalkTakesTheQueueByFileIdFromItsBound(): void
	{
		[ $first, $second, $third ] = [ self::FILE_ID, self::FILE_ID + 1, self::FILE_ID + 2 ];

		foreach ( [ $first, $second, $third ] as $fileId )
		{
			$this->metadataService->markPending( $fileId, MetadataService::PENDING_AUTO );
		}

		$this->metadataService->recordFailedAttempt( $first, MetadataService::PENDING_AUTO );
		$this->metadataService->recordFailedAttempt( $second, MetadataService::PENDING_AUTO );
		$this->metadataService->recordFailedAttempt( $second, MetadataService::PENDING_AUTO );
		$this->leaveOnlyTheQueued( [ $first, $second, $third ] );

		$this->assertSame(
			[ $first, $second, $third ],
			array_column( $this->metadataService->fetchPendingBatch( 3, 0 ), MetadataService::FIELD_FILE_ID ),
		);
		$this->assertSame(
			[ $second, $third ],
			array_column( $this->metadataService->fetchPendingBatch( 3, $first ), MetadataService::FIELD_FILE_ID ),
		);
		$this->assertSame( [], $this->metadataService->fetchPendingBatch( 3, $third ) );
	}

	/**
	 * The status counts the queued files that failed at least once.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheQueuedFilesThatFailedAreCounted(): void
	{
		[ $failed, $fresh ] = [ self::FILE_ID, self::FILE_ID + 1 ];

		$before = $this->metadataService->countFailingQueued();

		$this->metadataService->markPending( $failed, MetadataService::PENDING_AUTO );
		$this->metadataService->markPending( $fresh, MetadataService::PENDING_AUTO );
		$this->metadataService->recordFailedAttempt( $failed, MetadataService::PENDING_AUTO );
		$this->metadataService->recordFailedAttempt( $failed, MetadataService::PENDING_AUTO );

		$this->assertSame( $before + 1, $this->metadataService->countFailingQueued() );
	}

	/**
	 * The file's state row as [marker, failed attempts], or null without one.
	 *
	 * @return array{0: string, 1: int}|null
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function stateOf( int $fileId ): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select( MetadataService::FIELD_META_VALUE_STRING, MetadataService::FIELD_META_VALUE_INT )
		   ->from( MetadataService::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq( MetadataService::FIELD_FILE_ID, $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->eq(
				      MetadataService::FIELD_META_KEY,
				      $qb->createNamedParameter( MetadataService::KEY_FILE_CHECKSUM_STATE ),
			      ),
		   )
		;

		$row = $qb->executeQuery()
		          ->fetchAssociative()
		;

		return $row === false
			? null
			: [
				(string) $row[ MetadataService::FIELD_META_VALUE_STRING ],
				(int) $row[ MetadataService::FIELD_META_VALUE_INT ],
			];
	}

	/**
	 * Take every other file off the queue, inside the test's transaction, so
	 * that a fetch sees these alone.
	 *
	 * @param  list<int>  $fileIds
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function leaveOnlyTheQueued( array $fileIds ): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete( MetadataService::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq(
				      MetadataService::FIELD_META_KEY,
				      $qb->createNamedParameter( MetadataService::KEY_FILE_CHECKSUM_STATE ),
			      ),
			   $qb->expr()
			      ->like(
				      MetadataService::FIELD_META_VALUE_STRING,
				      $qb->createNamedParameter( MetadataService::PENDING_LIKE ),
			      ),
			   $qb->expr()
			      ->notIn(
				      MetadataService::FIELD_FILE_ID,
				      $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
			      ),
		   )
		;

		$qb->executeStatement();
	}
}
