<?php
/** @noinspection SqlNoDataSourceInspection */

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\BackgroundJob;

use OCA\FileChecksumSearch\BackgroundJob\DrainPendingUpdates;
use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\PendingQueueService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Lock\ILockingProvider;
use OCP\Server;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * Integration tests for the pending queue drain pipeline.
 *
 * Verifies the full lifecycle: add files to the pending queue →
 * execute DrainPendingUpdates (or HashIndexService::drainPending) →
 * verify hashes are computed and queue entries removed.
 *
 * Also tests idempotent drain and locked-file behaviour.
 */
class DrainPendingUpdatesTest
	extends
	DatabaseTestCase
{

	private HashIndexService    $hashIndexService;

	private PendingQueueService $pendingQueue;

	private string              $hashTable;

	private string              $pendingTable;

	/** @var File[] */
	private array $cleanupFiles = [];

	/** @var list<int> */
	private array $cleanupFileIds = [];


	protected function setUp(): void
	{

		parent::setUp();

		$this->hashIndexService = Server::get( HashIndexService::class );
		$this->pendingQueue     = Server::get( PendingQueueService::class );
		$this->hashTable        = $this->getHashTableName();
		$this->pendingTable     = $this->getPendingTableName();

		Server::get( LifecycleHandler::class )
		      ->createTables()
		;

		// Wipe all pending entries to guarantee clean state per test.
		$this->truncatePendingTable();
		$this->cleanupLeftovers();
	}


	protected function tearDown(): void
	{

		$this->cleanupLeftovers();

		foreach ( $this->cleanupFiles as $file )
		{
			try
			{
				$file->delete();
			}
			catch ( Throwable )
			{
			}
		}

		parent::tearDown();
	}


	// ─── Queue Add ────────────────────────────────────────────────────

	public function testAddPendingInsertsQueueEntry(): void
	{

		$fileId = $this->createTestFile( 'fcias_drain_qadd_' . time() . '.dat' );

		$before = $this->pendingQueue->getPendingRowCount();

		$this->hashIndexService->addPending( $fileId, HashIndexService::EVENT_TYPE_WRITE );

		$after = $this->pendingQueue->getPendingRowCount();

		$this->assertSame( $before + 1, $after, 'Pending row count should increase by one after add.' );
	}


	public function testAddPendingIsIdempotent(): void
	{

		$fileId = $this->createTestFile( 'fcias_drain_idem_' . time() . '.dat' );

		$this->hashIndexService->addPending( $fileId, HashIndexService::EVENT_TYPE_WRITE );

		$afterFirst = $this->pendingQueue->getPendingRowCount();

		// Second add of the same fileId should be silently ignored (INSERT IGNORE).
		$this->hashIndexService->addPending( $fileId, HashIndexService::EVENT_TYPE_WRITE );

		$afterSecond = $this->pendingQueue->getPendingRowCount();

		$this->assertSame(
			$afterFirst,
			$afterSecond,
			'Duplicate addPending should not increase queue count (INSERT IGNORE).',
		);
	}


	// ─── Drain Processing ─────────────────────────────────────────────

	public function testDrainPendingProcessesEntries(): void
	{

		$fileId = $this->createTestFile( 'fcias_drain_proc_' . time() . '.dat' );

		$this->hashIndexService->addPending( $fileId, HashIndexService::EVENT_TYPE_WRITE );

		$before = $this->pendingQueue->getPendingRowCount();
		$this->assertGreaterThan( 0, $before, 'Queue should have at least one entry before drain.' );

		$result = $this->hashIndexService->drainPending();

		$after = $this->pendingQueue->getPendingRowCount();

		$this->assertSame( 0, $after, 'Queue should be empty after draining all entries.' );
		$this->assertSame( 1, $result['processed'], 'One entry should have been processed.' );
		$this->assertSame( 1, $result['deleted'], 'One entry should have been deleted from the queue.' );
	}


	public function testDrainPendingComputesHash(): void
	{

		$fileId = $this->createTestFile( 'fcias_drain_hash_' . time() . '.dat' );

		$this->hashIndexService->addPending( $fileId, HashIndexService::EVENT_TYPE_WRITE );

		// Before drain, no hash row should exist for this file.
		$beforeHashes = $this->fetchHashRows( $fileId );
		$this->assertEmpty( $beforeHashes, 'No hash rows should exist before drain.' );

		$this->hashIndexService->drainPending();

		$afterHashes = $this->fetchHashRows( $fileId );

		$this->assertCount( 1, $afterHashes, 'One hash row should exist after drain.' );
		$this->assertSame( strtoupper( HashIndexService::getDefaultAlgo() ), $afterHashes[0]['algo'] );
		$this->assertNotEmpty( $afterHashes[0]['hash_value'], 'Hash value should be non-empty.' );
	}


	public function testDrainPendingHandlesNonexistentFile(): void
	{

		// Use a large positive file ID that definitely doesn't exist in filecache.
		$fakeFileId = 99999050;

		$this->hashIndexService->addPending( $fakeFileId, HashIndexService::EVENT_TYPE_WRITE );

		$result = $this->hashIndexService->drainPending();

		// The non-existent file should stay in the queue (not deleted).
		$after = $this->pendingQueue->getPendingRowCount();

		$this->assertSame( 1, $after, 'Non-existent file should remain in pending queue.' );
		$this->assertSame( 0, $result['processed'], 'No entry should have been processed.' );
		$this->assertSame( 0, $result['deleted'], 'No entry should have been deleted.' );

		// Clean up the fake pending entry manually since it won't be auto-removed.
		$this->cleanupFileIds[] = $fakeFileId;
	}


	// ─── Idempotent Drain ─────────────────────────────────────────────

	public function testDrainPendingIsIdempotent(): void
	{

		// First drain on an empty queue should succeed with zero processed.
		$result1 = $this->hashIndexService->drainPending();

		$this->assertSame( 0, $result1['processed'], 'Empty queue drain should process zero entries.' );
		$this->assertSame( 0, $result1['deleted'], 'Empty queue drain should delete zero entries.' );

		// Second drain should also succeed.
		$result2 = $this->hashIndexService->drainPending();

		$this->assertSame( 0, $result2['processed'], 'Second empty queue drain should also process zero.' );
		$this->assertSame( 0, $result2['deleted'], 'Second empty queue drain should also delete zero.' );
	}


	// ─── Locked File Behaviour ────────────────────────────────────────

	public function testLockedFileStaysPending(): void
	{

		$fileId = $this->createTestFile( 'fcias_drain_lock_' . time() . '.dat' );

		$lockingProvider = Server::get( ILockingProvider::class );

		// Acquire an exclusive lock to simulate a locked file.
		$lockingProvider->acquireLock( 'files/' . $fileId, ILockingProvider::LOCK_EXCLUSIVE );

		try
		{
			$this->hashIndexService->addPending( $fileId, HashIndexService::EVENT_TYPE_WRITE );

			$beforeDrain = $this->pendingQueue->getPendingRowCount();

			$result = $this->hashIndexService->drainPending();

			// Locked file should not be processed or deleted.
			$this->assertSame( 0, $result['processed'], 'Locked file should not be processed.' );
			$this->assertSame( 0, $result['deleted'], 'Locked file should not be deleted from queue.' );

			$queueCount = $this->pendingQueue->getPendingRowCount();
			$this->assertSame( $beforeDrain, $queueCount, 'Queue count should be unchanged after locked drain.' );
		}
		finally
		{
			$lockingProvider->releaseLock( 'files/' . $fileId, ILockingProvider::LOCK_EXCLUSIVE );
		}

		// After releasing the lock, a second drain should process it.
		$queueBeforeSecondDrain = $this->pendingQueue->getPendingRowCount();

		$result2 = $this->hashIndexService->drainPending();

		$this->assertSame( 1, $result2['processed'], 'After unlock, file should be processed.' );
		$this->assertSame( 1, $result2['deleted'], 'After unlock, queue entry should be deleted.' );

		$queueCount = $this->pendingQueue->getPendingRowCount();
		$this->assertSame(
			$queueBeforeSecondDrain - 1,
			$queueCount,
			'Queue should decrease by one after processing unlocked file.',
		);
	}


	// ─── DrainPendingUpdates Job (via reflection) ─────────────────────

	public function testDrainPendingUpdatesJobRunsWithoutError(): void
	{

		$fileId = $this->createTestFile( 'fcias_drain_job_' . time() . '.dat' );

		$this->hashIndexService->addPending( $fileId, HashIndexService::EVENT_TYPE_WRITE );

		$queueBefore = $this->pendingQueue->getPendingRowCount();

		// Instantiate DrainPendingUpdates and call its protected run() method.
		$job = new DrainPendingUpdates(
			Server::get( ITimeFactory::class ),
			$this->hashIndexService,
			Server::get( LoggerInterface::class ),
		);

		$reflectionMethod = new ReflectionMethod( DrainPendingUpdates::class, 'run' );

		$reflectionMethod->invoke( $job, null );

		$queueAfter = $this->pendingQueue->getPendingRowCount();

		$this->assertSame(
			$queueBefore - 1,
			$queueAfter,
			'Queue should decrease by one after DrainPendingUpdates job runs.',
		);
	}


	// ─── helpers ──────────────────────────────────────────────────────


	/**
	 * Create a real test file in the admin user's storage.
	 *
	 * Registers the file for automatic cleanup in tearDown().
	 */
	private function createTestFile( string $name ): int
	{

		$userFolder = Server::get( IRootFolder::class )
		                    ->getUserFolder( 'admin' )
		;

		$file = $userFolder->newFile( $name, 'Test content for drain pipeline — ' . microtime( true ) );

		$this->cleanupFiles[] = $file;

		$fileId                 = $file->getId();
		$this->cleanupFileIds[] = $fileId;

		return $fileId;
	}


	/**
	 * @return array<int, array{algo: string, hash_value: string}>
	 */
	private function fetchHashRows( int $fileId ): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->automaticTablePrefix( false );

		$qb->select( 'algo', 'hash_value' )
		   ->from( $this->hashTable )
		   ->where(
			   $qb->expr()
			      ->eq( 'fileid', $qb->createNamedParameter( $fileId ) ),
		   )
		;

		/** @var array<int, array{algo: string, hash_value: string}> $rows */
		$rows = $qb->executeQuery()
		           ->fetchAll()
		;

		return $rows;
	}


	private function truncatePendingTable(): void
	{

		try
		{
			$this->getRawConnection()
			     ->executeStatement( "DELETE FROM `$this->pendingTable`" )
			;
		}
		catch ( Throwable )
		{
		}
	}


	private function cleanupLeftovers(): void
	{

		if ( empty( $this->cleanupFileIds ) )
		{
			return;
		}

		$inPlaceholders = implode( ',', array_fill( 0, count( $this->cleanupFileIds ), '?' ) );

		try
		{
			$this->getRawConnection()
			     ->executeStatement(
				     "DELETE FROM `$this->pendingTable` WHERE `fileid` IN ($inPlaceholders)",
				     $this->cleanupFileIds,
			     )
			;
		}
		catch ( Throwable )
		{
		}

		try
		{
			$this->getRawConnection()
			     ->executeStatement(
				     "DELETE FROM `$this->hashTable` WHERE `fileid` IN ($inPlaceholders)",
				     $this->cleanupFileIds,
			     )
			;
		}
		catch ( Throwable )
		{
		}
	}

}
