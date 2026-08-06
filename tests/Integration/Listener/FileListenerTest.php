<?php
/** @noinspection SqlNoDataSourceInspection */

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\DrainPendingUpdates;
use OCA\FileChecksumSearch\Listener\FileListener;
use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\PendingQueueService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Server;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * Integration tests for FileListener event handling across config modes.
 *
 * Verifies real DB state changes when file events fire with different
 * combinations of update_hash_on_file_write, update_hash_on_file_create,
 * and update_hash_on_file_delete configuration values.
 *
 * The unit tests (tests/Unit/Listener/FileListenerTest.php) cover mock-level
 * delegate calls.  These integration tests verify end-to-end DB mutations.
 */
class FileListenerTest
	extends
	DatabaseTestCase
{

	private HashIndexService    $hashIndexService;

	private PendingQueueService $pendingQueue;

	private IAppConfig          $appConfig;

	private FileListener        $listener;

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
		$this->appConfig        = Server::get( IAppConfig::class );
		$this->hashTable        = $this->getHashTableName();
		$this->pendingTable     = $this->getPendingTableName();

		Server::get( LifecycleHandler::class )
		      ->createTables()
		;

		$this->truncatePendingTable();

		$this->listener = new FileListener(
			$this->hashIndexService,
			$this->appConfig,
			Server::get( LoggerInterface::class ),
		);

		$this->resetConfigToDefaults();
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

		$this->resetConfigToDefaults();

		parent::tearDown();
	}


	// ─── File Create ──────────────────────────────────────────────────

	public function testFileCreateOffDoesNothing(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_create',
			'off',
		);

		$file  = $this->createTestFile( 'fcias_listener_crt_off_' . time() . '.dat' );
		$event = new NodeCreatedEvent( $file );

		$this->listener->handle( $event );

		$this->assertSame(
			0,
			$this->countHashes( $file->getId() ),
			'No hash rows should exist after create with config off.',
		);
		$this->assertSame(
			0,
			$this->pendingQueue->getPendingRowCount(),
			'No pending entries should exist after create with config off.',
		);
	}


	public function testFileCreateLazyAddsToPending(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_create',
			'lazy',
		);

		$file   = $this->createTestFile( 'fcias_listener_crt_lazy_' . time() . '.dat' );
		$fileId = $file->getId();
		$event  = new NodeCreatedEvent( $file );

		$this->listener->handle( $event );

		// Lazy mode: no hash, but pending entry should exist.
		$this->assertSame(
			0,
			$this->countHashes( $fileId ),
			'No hash rows should exist immediately after lazy create.',
		);
		$this->assertSame(
			1,
			$this->pendingQueue->getPendingRowCount(),
			'One pending entry should exist after lazy create.',
		);

		// Drain pending: hash should appear.
		$this->hashIndexService->drainPending();

		$afterHashes = $this->fetchHashRows( $fileId );
		$this->assertCount( 1, $afterHashes, 'One hash row should exist after draining pending.' );
		$this->assertSame(
			strtoupper( HashIndexService::getDefaultAlgo() ),
			$afterHashes[0]['algo'],
		);
		$this->assertNotEmpty( $afterHashes[0]['hash_value'] );
		$this->assertSame(
			0,
			$this->pendingQueue->getPendingRowCount(),
			'Pending queue should be empty after drain.',
		);
	}


	public function testFileCreateForceComputesHashImmediately(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_create',
			'force',
		);

		$file   = $this->createTestFile( 'fcias_listener_crt_force_' . time() . '.dat' );
		$fileId = $file->getId();
		$event  = new NodeCreatedEvent( $file );

		$this->listener->handle( $event );

		// Force mode: hash should exist immediately, no pending.
		$afterHashes = $this->fetchHashRows( $fileId );
		$this->assertCount( 1, $afterHashes, 'One hash row should exist after force create.' );
		$this->assertSame(
			strtoupper( HashIndexService::getDefaultAlgo() ),
			$afterHashes[0]['algo'],
		);
		$this->assertNotEmpty( $afterHashes[0]['hash_value'] );
		$this->assertSame(
			0,
			$this->pendingQueue->getPendingRowCount(),
			'No pending entries should exist after force create.',
		);
	}


	// ─── File Write ───────────────────────────────────────────────────

	public function testFileWriteOffDoesNothing(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_write',
			'off',
		);

		$file   = $this->createTestFile( 'fcias_listener_wrt_off_' . time() . '.dat' );
		$fileId = $file->getId();

		// Seed a hash row first so we can verify it survives.
		$this->hashIndexService->recalcFileHash( $file, HashIndexService::getDefaultAlgo() );

		$beforeHashes = $this->countHashes( $fileId );
		$this->assertGreaterThan( 0, $beforeHashes, 'Seed hash should exist before write.' );

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		$this->assertSame(
			$beforeHashes,
			$this->countHashes( $fileId ),
			'Hash rows should be unchanged after write with config off.',
		);
		$this->assertSame(
			0,
			$this->pendingQueue->getPendingRowCount(),
			'No pending entries should exist after write with config off.',
		);
	}


	public function testFileWriteForceRecalculatesHash(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_write',
			'force',
		);

		$file   = $this->createTestFile( 'fcias_listener_wrt_force_' . time() . '.dat' );
		$fileId = $file->getId();

		// Compute a hash first so that recalcAllExistingAlgos has an algo to work on.
		$this->hashIndexService->recalcFileHash( $file, HashIndexService::getDefaultAlgo() );

		$beforeHashes = $this->countHashes( $fileId );
		$this->assertGreaterThan( 0, $beforeHashes, 'Seed hash should exist before write.' );

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		// Hash should still exist (recalculated, not deleted).
		$afterHashes = $this->fetchHashRows( $fileId );
		$this->assertCount( 1, $afterHashes, 'Hash row should still exist after force write.' );
		$this->assertNotEmpty( $afterHashes[0]['hash_value'] );
		$this->assertSame(
			0,
			$this->pendingQueue->getPendingRowCount(),
			'No pending entries should exist after successful force write.',
		);
	}


	public function testFileWriteLazyDeletesHashAndQueues(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_write',
			'lazy',
		);

		$file   = $this->createTestFile( 'fcias_listener_wrt_lazy_' . time() . '.dat' );
		$fileId = $file->getId();

		// Seed a hash so we can verify it is deleted by the lazy listener.
		$this->hashIndexService->recalcFileHash( $file, HashIndexService::getDefaultAlgo() );
		$this->assertGreaterThan( 0, $this->countHashes( $fileId ), 'Seed hash should exist.' );

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		// Lazy write: existing hash deleted, pending entry queued.
		$this->assertSame(
			0,
			$this->countHashes( $fileId ),
			'Hash rows should be deleted after lazy write.',
		);
		$this->assertSame(
			1,
			$this->pendingQueue->getPendingRowCount(),
			'One pending entry should exist after lazy write.',
		);

		// Drain pending: stale checksum in filecache causes
		// isHashUpToDate → false, fresh hash computed.
		$result = $this->hashIndexService->drainPending();
		$this->assertSame( 1, $result['processed'], 'Drain should process one entry.' );
		$this->assertSame( 1, $result['deleted'], 'Pending entry should be deleted.' );

		$afterHashes = $this->fetchHashRows( $fileId );
		$this->assertCount( 1, $afterHashes, 'Hash row should exist after draining pending.' );
		$this->assertNotEmpty( $afterHashes[0]['hash_value'] );
		$this->assertSame( 0, $this->pendingQueue->getPendingRowCount() );
	}


	public function testFileWriteAutoRecalcsWhenHashExists(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_write',
			'auto',
		);

		$file   = $this->createTestFile( 'fcias_listener_wrt_auto_h_' . time() . '.dat' );
		$fileId = $file->getId();

		// Seed a hash — auto mode should recalculate.
		$this->hashIndexService->recalcFileHash( $file, HashIndexService::getDefaultAlgo() );
		$this->assertGreaterThan( 0, $this->countHashes( $fileId ), 'Seed hash should exist.' );

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		// Hash should still exist (recalculated).
		$this->assertGreaterThan(
			0,
			$this->countHashes( $fileId ),
			'Hash should still exist after auto write with existing hash.',
		);
		$this->assertSame(
			0,
			$this->pendingQueue->getPendingRowCount(),
			'No pending entries in auto mode with existing hash.',
		);
	}


	public function testFileWriteAutoSkipsWhenNoHash(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_write',
			'auto',
		);

		$file   = $this->createTestFile( 'fcias_listener_wrt_auto_n_' . time() . '.dat' );
		$fileId = $file->getId();

		// No seed hash — auto mode should skip.
		$this->assertSame( 0, $this->countHashes( $fileId ), 'No hash should exist before write.' );

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		$this->assertSame(
			0,
			$this->countHashes( $fileId ),
			'No hash should appear after auto write without prior hash.',
		);
		$this->assertSame(
			0,
			$this->pendingQueue->getPendingRowCount(),
			'No pending entries in auto mode without existing hash.',
		);
	}


	// ─── File Delete ──────────────────────────────────────────────────

	public function testFileDeleteOffDoesNothing(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_delete',
			'off',
		);

		$file   = $this->createTestFile( 'fcias_listener_del_off_' . time() . '.dat' );
		$fileId = $file->getId();

		$this->hashIndexService->recalcFileHash( $file, HashIndexService::getDefaultAlgo() );
		$this->assertGreaterThan( 0, $this->countHashes( $fileId ), 'Seed hash should exist.' );

		$event = new NodeDeletedEvent( $file );
		$this->listener->handle( $event );

		$this->assertGreaterThan(
			0,
			$this->countHashes( $fileId ),
			'Hash rows should remain after delete with config off.',
		);
	}


	public function testFileDeleteOnRemovesHashes(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_delete',
			'on',
		);

		$file   = $this->createTestFile( 'fcias_listener_del_on_' . time() . '.dat' );
		$fileId = $file->getId();

		$this->hashIndexService->recalcFileHash( $file, HashIndexService::getDefaultAlgo() );
		$this->assertGreaterThan( 0, $this->countHashes( $fileId ), 'Seed hash should exist.' );

		$event = new NodeDeletedEvent( $file );
		$this->listener->handle( $event );

		$this->assertSame(
			0,
			$this->countHashes( $fileId ),
			'Hash rows should be removed after delete with config on.',
		);
	}


	// ─── Integration: Lazy Mode + DrainPendingUpdates Job ──────────────

	public function testLazyCreateDrainViaBackgroundJob(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_create',
			'lazy',
		);

		$file   = $this->createTestFile( 'fcias_listener_job_' . time() . '.dat' );
		$fileId = $file->getId();
		$event  = new NodeCreatedEvent( $file );

		$this->listener->handle( $event );

		// Pending entry should exist, no hash yet.
		$this->assertSame( 0, $this->countHashes( $fileId ) );
		$this->assertSame( 1, $this->pendingQueue->getPendingRowCount() );

		// Run the DrainPendingUpdates background job via reflection.
		$job = new DrainPendingUpdates(
			Server::get( ITimeFactory::class ),
			$this->hashIndexService,
			Server::get( LoggerInterface::class ),
		);

		$reflectionMethod = new ReflectionMethod( DrainPendingUpdates::class, 'run' );
		$reflectionMethod->invoke( $job, null );

		// Hash should now exist, queue should be empty.
		$afterHashes = $this->fetchHashRows( $fileId );
		$this->assertCount( 1, $afterHashes, 'Hash should exist after DrainPendingUpdates job runs.' );
		$this->assertNotEmpty( $afterHashes[0]['hash_value'] );
		$this->assertSame(
			0,
			$this->pendingQueue->getPendingRowCount(),
			'Pending queue should be empty after drain job.',
		);
	}


	// ─── Integration: Force Mode + Lock → Pending → Retry ─────────────

	public function testForceWriteLockedFileStaysPendingThenDrains(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_write',
			'force',
		);

		$file   = $this->createTestFile( 'fcias_listener_lock_' . time() . '.dat' );
		$fileId = $file->getId();

		// Seed a hash (populates filecache checksum + hash table), then
		// delete the hash row.  The stale checksum in filecache forces
		// recalcFileHash past skipExisting (isHashUpToDate → false),
		// which then proceeds to the lock-attempt path.
		$this->hashIndexService->recalcFileHash( $file, HashIndexService::getDefaultAlgo() );
		$this->assertGreaterThan( 0, $this->countHashes( $fileId ), 'Seed hash should exist.' );

		$this->hashIndexService->deleteHashes( $fileId );
		$this->assertSame( 0, $this->countHashes( $fileId ), 'Hash should be deleted (stale checksum remains).' );

		$lockingProvider = Server::get( ILockingProvider::class );

		// Acquire exclusive lock to simulate a locked file.
		$lockingProvider->acquireLock( 'files/' . $fileId, ILockingProvider::LOCK_EXCLUSIVE );

		try
		{
			$event = new NodeWrittenEvent( $file );
			$this->listener->handle( $event );

			// Force mode with lock: recalcAllExistingAlgos queries the
			// (now-empty) hash table and falls back to sha1.  The stale
			// checksum in filecache causes recalcFileHash to proceed
			// past skipExisting, hit the lock, and return locked=true.
			// The listener then deletes any remaining hashes and queues
			// a pending entry.
			$this->assertSame(
				0,
				$this->countHashes( $fileId ),
				'No hashes should remain after locked force write.',
			);
			$this->assertSame(
				1,
				$this->pendingQueue->getPendingRowCount(),
				'Pending entry should exist for locked force write.',
			);
		}
		finally
		{
			$lockingProvider->releaseLock( 'files/' . $fileId, ILockingProvider::LOCK_EXCLUSIVE );
		}

		// After unlocking, drain should process the pending entry.
		$result = $this->hashIndexService->drainPending();

		$this->assertSame( 1, $result['processed'], 'Unlocked file should be processed by drain.' );
		$this->assertSame( 1, $result['deleted'], 'Pending entry should be deleted after processing.' );

		$afterHashes = $this->fetchHashRows( $fileId );
		$this->assertCount( 1, $afterHashes, 'Hash should exist after drain processes unlocked file.' );
		$this->assertNotEmpty( $afterHashes[0]['hash_value'] );
		$this->assertSame( 0, $this->pendingQueue->getPendingRowCount() );
	}


	// ─── Common: FileListener handles non-File events gracefully ──────

	public function testHandleIgnoresNonFileEventsGracefully(): void
	{

		// Non-File events (e.g., Folder) should be silently ignored.
		// NodeDeletedEvent accepts any Node, but FileListener checks instanceof File.
		$folder = $this->createMock( \OCP\Files\Folder::class );
		$event  = new NodeDeletedEvent( $folder );

		// Should not throw.
		$this->listener->handle( $event );

		$this->assertSame( 0, $this->pendingQueue->getPendingRowCount() );
		// If we got here without exception, the test passes.
		$this->assertTrue( true );
	}


	// ─── helpers ──────────────────────────────────────────────────────


	/**
	 * Create a real test file in the admin user's storage.
	 *
	 * Registers the file and its fileId for automatic cleanup in tearDown().
	 */
	private function createTestFile( string $name ): File
	{

		$userFolder = Server::get( IRootFolder::class )
		                    ->getUserFolder( 'admin' )
		;

		$file = $userFolder->newFile( $name, 'Test content for FileListener — ' . microtime( true ) );

		$this->cleanupFiles[]   = $file;
		$this->cleanupFileIds[] = $file->getId();

		return $file;
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


	private function countHashes( int $fileId ): int
	{

		$qb = $this->db->getQueryBuilder();
		$qb->automaticTablePrefix( false );

		$qb->select(
			$qb->func()
			   ->count( '*', 'cnt' ),
		)
		   ->from( $this->hashTable )
		   ->where(
			   $qb->expr()
			      ->eq( 'fileid', $qb->createNamedParameter( $fileId ) ),
		   )
		;

		return (int) $qb->executeQuery()
		                ->fetchOne()
		;
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


	/**
	 * Reset all event-related config keys to their defaults.
	 *
	 * Called in setUp() and tearDown() to ensure clean state per test.
	 */
	private function resetConfigToDefaults(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_write',
			'auto',
		);
		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_create',
			'off',
		);
		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_delete',
			'off',
		);
	}

}
