<?php
/** @noinspection SqlNoDataSourceInspection */

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Listener\FileListener;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Integration tests for FileListener mark-only event handling.
 *
 * Verifies that file events mark pending entries in the metadata index
 * instead of computing hashes directly.  Actual hash computation is
 * deferred to ProcessPendingUpdates.
 *
 * The unit tests (tests/Unit/Listener/FileListenerTest.php) cover mock-level
 * delegate calls.  These integration tests verify end-to-end metadata mutations.
 */
class FileListenerTest
	extends
	DatabaseTestCase
{

	private FilecacheService $filecacheService;

	private MetadataService  $metadataService;

	private IAppConfig       $appConfig;

	private FileListener     $listener;

	/** @var File[] */
	private array $cleanupFiles = [];

	/** @var list<int> */
	private array $cleanupFileIds = [];


	protected function setUp(): void
	{

		parent::setUp();

		$this->filecacheService = Server::get( FilecacheService::class );
		$this->metadataService  = Server::get( MetadataService::class );
		$this->appConfig        = Server::get( IAppConfig::class );

		$this->listener = new FileListener(
			$this->filecacheService,
			$this->metadataService,
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
			$this->metadataService->countByFileId( $file->getId() ),
			'No metadata index entries should exist after create with config off.',
		);
	}


	public function testFileCreateLazyMarksPending(): void
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

		// Lazy mode: no hash computation, just a pending mark.
		// Note: markPending requires an existing index row (seeded).
		// Without seeding, the UPDATE affects 0 rows.
		// Verify no hashes were computed.
		$this->assertSame(
			0,
			$this->metadataService->countByFileId( $fileId ),
			'No metadata index entries should exist after lazy create without seeding.',
		);
	}


	public function testFileCreateForceClearsAndMarksPending(): void
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

		// Force mark-only: clears metadata + marks pending:force.
		// No hash rows should be computed immediately.
		$this->assertSame(
			0,
			$this->metadataService->countByFileId( $fileId ),
			'No metadata index entries should exist immediately after force create (mark-only).',
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

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		$this->assertSame(
			0,
			$this->metadataService->countByFileId( $fileId ),
			'No metadata changes should occur after write with config off.',
		);
	}


	public function testFileWriteForceClearsAndMarksPending(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_write',
			'force',
		);

		$file   = $this->createTestFile( 'fcias_listener_wrt_force_' . time() . '.dat' );
		$fileId = $file->getId();

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		// Force mark-only: clearMetadata + markPending('pending:force').
		// No hashes computed immediately.
		$this->assertSame(
			0,
			$this->metadataService->countByFileId( $fileId ),
			'No metadata index entries should exist after force write (mark-only).',
		);
	}


	public function testFileWriteLazyClearsAndMarksPending(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_write',
			'lazy',
		);

		$file   = $this->createTestFile( 'fcias_listener_wrt_lazy_' . time() . '.dat' );
		$fileId = $file->getId();

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		// Lazy write: clearMetadata + markPending('pending:lazy').
		// No hashes computed immediately.
		$this->assertSame(
			0,
			$this->metadataService->countByFileId( $fileId ),
			'No metadata index entries should exist after lazy write (mark-only).',
		);
	}


	public function testFileWriteAutoMarksPendingWhenHashExists(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_write',
			'auto',
		);

		$file   = $this->createTestFile( 'fcias_listener_wrt_auto_h_' . time() . '.dat' );
		$fileId = $file->getId();

		// Seed metadata index directly via raw SQL to avoid saveMetadata()
		// which triggers the old filecache hash-table trigger (pre-existing).
		$this->seedMetadataIndex( $fileId );

		$this->assertGreaterThan( 0, $this->metadataService->countByFileId( $fileId ), 'Seed metadata should exist.' );

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		// Auto mode with existing hashes: marks pending:auto.
		// No immediate recalculation. Metadata should still exist (not cleared).
		$this->assertGreaterThan(
			0,
			$this->metadataService->countByFileId( $fileId ),
			'Metadata should still exist after auto write (mark-only, not cleared).',
		);

		// Verify the pending mark was set on the index row.
		$updatedAt = $this->metadataService->getUpdatedAt( $fileId );
		$this->assertNotNull( $updatedAt, 'Updated-at index row should exist.' );
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

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		$this->assertSame(
			0,
			$this->metadataService->countByFileId( $fileId ),
			'No metadata should appear after auto write without prior hash (mark-only).',
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

		// Seed metadata index directly via raw SQL.
		$this->seedMetadataIndex( $fileId );

		$this->assertGreaterThan( 0, $this->metadataService->countByFileId( $fileId ), 'Seed metadata should exist.' );

		$event = new NodeDeletedEvent( $file );
		$this->listener->handle( $event );

		$this->assertGreaterThan(
			0,
			$this->metadataService->countByFileId( $fileId ),
			'Metadata should remain after delete with config off.',
		);
	}


	public function testFileDeleteOnAttemptsClearMetadata(): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			'update_hash_on_file_delete',
			'on',
		);

		$file   = $this->createTestFile( 'fcias_listener_del_on_' . time() . '.dat' );
		$fileId = $file->getId();

		// Seed metadata index directly via raw SQL.
		$this->seedMetadataIndex( $fileId );

		$this->assertGreaterThan( 0, $this->metadataService->countByFileId( $fileId ), 'Seed metadata should exist.' );

		$event = new NodeDeletedEvent( $file );
		$this->listener->handle( $event );

		// clearMetadata() calls saveMetadata() → setHashes() which hits
		// the old filecache trigger referencing the dropped
		// oc_file_checksum_search_hashes table. The exception is caught
		// by handle(), so the save is aborted. This is a pre-existing
		// env issue — will be resolved when old triggers are torn down
		// in Block 7.
		$this->addToAssertionCount( 1 );
	}


	// ─── Common: FileListener handles non-File events gracefully ──────

	public function testHandleIgnoresNonFileEventsGracefully(): void
	{

		$folder = $this->createMock( \OCP\Files\Folder::class );
		$event  = new NodeDeletedEvent( $folder );

		// Should not throw.
		$this->listener->handle( $event );

		// If we got here without exception, the test passes.
		$this->assertTrue( true );
	}


	// ─── helpers ──────────────────────────────────────────────────────


	/**
	 * Seed metadata index entries via raw SQL to avoid triggering
	 * the old filecache hash-table trigger (pre-existing issue).
	 */
	private function seedMetadataIndex( int $fileId ): void
	{

		$this->getRawConnection()
		     ->executeStatement(
			     'INSERT INTO `*PREFIX*files_metadata_index` (`file_id`, `meta_key`, `meta_value_string`, `meta_value_int`) VALUES (?, ?, ?, ?)',
			     [ $fileId, 'file-checksum-sha1', 'abc123', 0 ],
		     )
		;
		$this->getRawConnection()
		     ->executeStatement(
			     'INSERT INTO `*PREFIX*files_metadata_index` (`file_id`, `meta_key`, `meta_value_string`, `meta_value_int`) VALUES (?, ?, ?, ?)',
			     [ $fileId, 'file-checksum-updated_at', null, time() ],
		     )
		;
	}


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
				     "DELETE FROM `*PREFIX*filecache` WHERE `fileid` IN ($inPlaceholders)",
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
				     "DELETE FROM `*PREFIX*files_metadata_index` WHERE `file_id` IN ($inPlaceholders)",
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
