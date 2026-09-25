<?php
/** @noinspection SqlNoDataSourceInspection */

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Listener;

use OCA\FileChecksumSearch\Listener\FileListener;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
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

//  private properties

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private FilecacheService $filecacheService;

	private MetadataService  $metadataService;

	private RuleService      $ruleService;

	private FileListener     $listener;

	/** @var File[] */
	private array $cleanupFiles = [];

	/** @var list<int> */
	private array $cleanupFileIds = [];

	/** @var list<callable(): void> */
	private array $cleanup = [];


//  getters / setters / is* / has*

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->filecacheService = Server::get( FilecacheService::class );
		$this->metadataService  = Server::get( MetadataService::class );
		$this->ruleService      = Server::get( RuleService::class );

		$this->listener = new FileListener(
			$this->filecacheService,
			$this->metadataService,
			$this->ruleService,
			Server::get( LoggerInterface::class ),
		);

		// Emptied for the tests below, and put back afterwards: the rules on
		// the instance this runs against are somebody's configuration.
		$this->preserveStoredRules();
		$this->resetRules();
	}


//  other non-static methods

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

		foreach ( array_reverse( $this->cleanup ) as $undo )
		{
			try
			{
				$undo();
			}
			catch ( Throwable )
			{
				// A fixture already gone is the outcome we wanted.
			}
		}

		$this->cleanup = [];

		$this->resetRules();

		parent::tearDown();
	}

	// ─── File Create ──────────────────────────────────────────────────
	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testFileCreateUnderAnIgnoreRuleDoesNothing(): void
	{
		$this->setCatchAllIgnoreRule();

		$file  = $this->createTestFile( 'fcias_listener_crt_off_' . time() . '.dat' );
		$event = new NodeCreatedEvent( $file );

		$this->listener->handle( $event );

		// Nothing queued and nothing stored: an ignore rule claims the file
		// and asks for no work, so there is no stamp row to carry a state.
		$this->assertNull(
			$this->stateOf( $file->getId() ),
			'An ignore rule queues nothing on create.',
		);
		$this->assertSame(
			0,
			$this->metadataService->countByFileId( $file->getId() ),
			'And computes no hashes.',
		);
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testFileCreateLazyMarksPending(): void
	{
		$this->setCatchAllRule( 'lazy' );

		$file   = $this->createTestFile( 'fcias_listener_crt_lazy_' . time() . '.dat' );
		$fileId = $file->getId();
		$event  = new NodeCreatedEvent( $file );

		$this->listener->handle( $event );

		// The mark is the point, and it is what this used to assert the
		// absence of: markPending() became an upsert when the seeding job
		// was retired, so a file with no rows gets one rather than silently
		// updating nothing.
		$this->assertSame(
			MetadataService::PENDING_LAZY,
			$this->stateOf( $fileId ),
			'Lazy queues the file rather than hashing it.',
		);

		// And computes nothing now, which is what lazy means.
		$this->assertSame( 0, $this->metadataService->countByFileId( $fileId ) );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testFileCreateForceClearsAndMarksPending(): void
	{
		$this->setCatchAllRule( 'force' );

		$file   = $this->createTestFile( 'fcias_listener_crt_force_' . time() . '.dat' );
		$fileId = $file->getId();
		$event  = new NodeCreatedEvent( $file );

		$this->listener->handle( $event );

		$this->assertSame(
			MetadataService::PENDING_FORCE,
			$this->stateOf( $fileId ),
			'Force queues the file for recomputation.',
		);

		// Mark-only: the drain reads file content, and an event listener is
		// not the place to do that.
		$this->assertSame( 0, $this->metadataService->countByFileId( $fileId ) );
	}

	// ─── File Write ───────────────────────────────────────────────────
	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testFileWriteUnderAnIgnoreRuleDoesNothing(): void
	{
		$this->setCatchAllIgnoreRule();

		$file   = $this->createTestFile( 'fcias_listener_wrt_off_' . time() . '.dat' );
		$fileId = $file->getId();

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		$this->assertNull(
			$this->stateOf( $fileId ),
			'An ignore rule queues nothing on write.',
		);
		$this->assertSame( 0, $this->metadataService->countByFileId( $fileId ) );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testFileWriteForceClearsAndMarksPending(): void
	{
		$this->setCatchAllRule( 'force' );

		$file   = $this->createTestFile( 'fcias_listener_wrt_force_' . time() . '.dat' );
		$fileId = $file->getId();

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		$this->assertSame(
			MetadataService::PENDING_FORCE,
			$this->stateOf( $fileId ),
			'Force queues the rewritten file.',
		);
		$this->assertSame( 0, $this->metadataService->countByFileId( $fileId ) );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testFileWriteLazyClearsAndMarksPending(): void
	{
		$this->setCatchAllRule( 'lazy' );

		$file   = $this->createTestFile( 'fcias_listener_wrt_lazy_' . time() . '.dat' );
		$fileId = $file->getId();

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		$this->assertSame(
			MetadataService::PENDING_LAZY,
			$this->stateOf( $fileId ),
			'Lazy queues the rewritten file.',
		);
		$this->assertSame( 0, $this->metadataService->countByFileId( $fileId ) );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testFileWriteAutoMarksPendingWhenHashExists(): void
	{
		$this->setCatchAllRule( 'auto' );

		$file   = $this->createTestFile( 'fcias_listener_wrt_auto_h_' . time() . '.dat' );
		$fileId = $file->getId();

		// Seed metadata index directly via raw SQL to avoid saveMetadata()
		// which triggers the old filecache hash-table trigger (pre-existing).
		$this->seedMetadataIndex( $fileId );

		$this->assertGreaterThan( 0, $this->metadataService->countByFileId( $fileId ), 'Seed metadata should exist.' );

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		// The mark is what changed, and it is the only thing here that
		// distinguishes a listener that ran from one that did not: the seed
		// already left both a hash row and a stamp row, so "the count is
		// still positive" and "the stamp row exists" were true before
		// handle() was called.
		$this->assertSame(
			MetadataService::PENDING_AUTO,
			$this->stateOf( $fileId ),
			'Auto queues a file that already has hashes to refresh.',
		);

		// Mark-only: the stored hashes stay until the drain replaces them,
		// because a hash that is merely suspect is better than none while
		// the queue catches up.
		$this->assertGreaterThan(
			0,
			$this->metadataService->countByFileId( $fileId ),
			'Auto does not clear what it queues.',
		);
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testFileWriteAutoSkipsWhenNoHash(): void
	{
		$this->setCatchAllRule( 'auto' );

		$file   = $this->createTestFile( 'fcias_listener_wrt_auto_n_' . time() . '.dat' );
		$fileId = $file->getId();

		$event = new NodeWrittenEvent( $file );
		$this->listener->handle( $event );

		// `auto` means "refresh hashes that exist when they go outdated". A
		// file with none has nothing to refresh, so nothing is queued —
		// which is a decision, not an oversight: a first hash for a new file
		// is `missing`'s job or `force`'s.
		$this->assertNull(
			$this->stateOf( $fileId ),
			'Auto queues nothing for a file that has no hashes yet.',
		);
		$this->assertSame( 0, $this->metadataService->countByFileId( $fileId ) );
	}

	// ─── A file somebody else owns ────────────────────────────────────
	/**
	 * The property the whole FileLocation rework exists for.
	 *
	 * A recipient writing into a share is governed by the *owner's* rules,
	 * because the file is the owner's. Resolving from the actor's own path
	 * instead was the defect: a recipient sees a mount they may have
	 * renamed, so pairing that path with the owner's uid produced a
	 * mismatched identity that could silently pick the wrong rule.
	 *
	 * Written through the recipient's view on purpose — that is the only
	 * way a share is ever written, and the only way the two paths differ.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARecipientsWriteIsJudgedByTheOwnersRule(): void
	{
		[
			$ownerUid,
			$ownerPassword,
		]
			 = self::makeAccount( 'fcias_listener_owner' );

		[
			$recipientUid,
			$recipientPassword,
		]
			 = self::makeAccount( 'fcias_listener_recipient' );

		$rootFolder    = Server::get( IRootFolder::class );
		$ownerFolder   = $rootFolder->getUserFolder( $ownerUid );
		$sharedDirName = 'fcias_shared_' . bin2hex( random_bytes( 4 ) );
		$sharedDir     = $ownerFolder->newFolder( $sharedDirName );

		$shareManager = Server::get( IShareManager::class );
		$share        = $shareManager->newShare();
		$share->setNode( $sharedDir )
		      ->setShareType( IShare::TYPE_USER )
		      ->setSharedWith( $recipientUid )
		      ->setSharedBy( $ownerUid )
		      ->setPermissions( Constants::PERMISSION_ALL )
		;
		$shareManager->createShare( $share );

		// An exclude over the owner's copy of the folder. The recipient has
		// never seen this rule and cannot edit it.
		$this->ruleService->ruleAdd( [
			'enabled'  => true,
			'type'     => 'exclude',
			'path'     => '/' . $sharedDirName . '/**',
			'selector' => 'home:' . $ownerUid,
		] );

		// The recipient's own view of the same folder, which is a different
		// path in a different user's tree.
		$recipientView = $rootFolder->getUserFolder( $recipientUid )
		                            ->get( $sharedDirName )
		;
		$written       = $recipientView->newFile(
			'from_the_recipient.txt',
			'written by somebody who is not the owner',
		);

		$this->cleanupFiles[]   = $written;
		$this->cleanupFileIds[] = $written->getId();

		$location = $this->filecacheService->locate( $written->getId() );

		$this->assertNotNull( $location );

		// Identity comes from the file, not from who is holding it: the
		// owner's uid and the path inside the *owner's* home.
		$this->assertSame( $ownerUid, $location->owner );
		$this->assertStringContainsString(
			'/' . $sharedDirName . '/',
			(string) $location->relativePath,
			'the path is the one inside the owner\'s home',
		);

		$governing = $this->ruleService->governingRuleForLocation( $location );

		$this->assertNotNull( $governing, 'the owner\'s rule reaches the recipient\'s write' );
		$this->assertSame( 'exclude', $governing['type'] );

		$this->cleanup[] = static fn (): mixed => $sharedDir->delete();
	}

	// ─── File Delete ──────────────────────────────────────────────────
	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testFileDeleteClearsMetadataEvenUnderAnIgnoreRule(): void
	{
		$this->setCatchAllIgnoreRule();

		$file   = $this->createTestFile( 'fcias_listener_del_off_' . time() . '.dat' );
		$fileId = $file->getId();

		// Seed metadata index directly via raw SQL.
		$this->seedMetadataIndex( $fileId );

		$this->assertGreaterThan( 0, $this->metadataService->countByFileId( $fileId ), 'Seed metadata should exist.' );

		$event = new NodeDeletedEvent( $file );
		$this->listener->handle( $event );

		// No rule can spare a deleted file. By the time this event arrives
		// the filecache row is gone or moved to trash, so nothing can be
		// said to govern it — and the hashes describe content the user
		// removed. This test used to assert the opposite, from before that
		// was settled.
		$this->assertSame(
			0,
			$this->metadataService->countByFileId( $fileId ),
			'Deleting a file clears its metadata whatever the rules say.',
		);
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testFileDeleteClearsTheHashesAndQueuesNothing(): void
	{
		$this->setCatchAllRule( 'auto' );

		$file   = $this->createTestFile( 'fcias_listener_del_on_' . time() . '.dat' );
		$fileId = $file->getId();

		// Seed metadata index directly via raw SQL.
		$this->seedMetadataIndex( $fileId );

		$this->assertGreaterThan( 0, $this->metadataService->countByFileId( $fileId ), 'Seed metadata should exist.' );

		$event = new NodeDeletedEvent( $file );
		$this->listener->handle( $event );

		// Deleting clears, whatever the rules say: by the time this event
		// arrives the filecache row is gone or in the trash, so no rule can
		// be said to govern the file, and the hashes describe content the
		// user removed.
		//
		// This used to assert nothing at all, blamed on a filecache trigger
		// that its sibling above disproves by asserting exactly this.
		$this->assertSame(
			0,
			$this->metadataService->countByFileId( $fileId ),
			'Deleting a file clears its hashes.',
		);
		// The stamp row itself survives, emptied rather than removed — the
		// file is gone, so nothing is waiting for it. Asserting the row's
		// absence would be asserting a detail of how clearing is done
		// rather than that nothing is queued.
		$this->assertStringNotContainsString(
			MetadataService::PENDING_PREFIX,
			(string) $this->stateOf( $fileId ),
			'And leaves nothing queued for a file that is gone.',
		);
	}

	// ─── Common: FileListener handles non-File events gracefully ──────
	public function testHandleIgnoresNonFileEventsGracefully(): void
	{
		$folder = $this->createMock( Folder::class );
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
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	/**
	 * What the file is waiting for, read from the row that records it.
	 *
	 * `countByFileId()` cannot answer this and never could since the hash
	 * keys got a prefix of their own: it counts `file-checksum-hash-%`
	 * rows, which is what its callers in FileListener mean by "does this
	 * file have hashes". A pending mark is the *stamp* row's string half,
	 * so every test here that asserted a count of 0 after marking one was
	 * asserting something true whether the listener ran or not.
	 *
	 * @return string|null  The state, or null when the file has no stamp row.
	 */
	private function stateOf( int $fileId ): ?string
	{
		$result = $this->getRawConnection()
		               ->executeQuery(
			               'SELECT `meta_value_string` FROM `*PREFIX*files_metadata_index` '
			               . 'WHERE `file_id` = ? AND `meta_key` = ?',
			               [
				               $fileId,
				               MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT,
			               ],
		               )
		;

		$value = $result->fetchOne();
		$result->free();

		return $value === false
			? null
			: ( $value === null
				? ''
				: (string) $value );
	}

	private function seedMetadataIndex( int $fileId ): void
	{
		$this->getRawConnection()
		     ->executeStatement(
			     'INSERT INTO `*PREFIX*files_metadata_index` (`file_id`, `meta_key`, `meta_value_string`, `meta_value_int`) VALUES (?, ?, ?, ?)',
			     [
				     $fileId,
				     MetadataService::getHashKey( 'sha1' ),
				     'abc123',
				     0,
			     ],
		     )
		;
		$this->getRawConnection()
		     ->executeStatement(
			     'INSERT INTO `*PREFIX*files_metadata_index` (`file_id`, `meta_key`, `meta_value_string`, `meta_value_int`) VALUES (?, ?, ?, ?)',
			     [
				     $fileId,
				     'file-checksum-updated_at',
				     null,
				     time(),
			     ],
		     )
		;
	}

	/**
	 * Set a catch-all rule with the given mode for the current test.
	 *
	 * Uses RuleService::ruleAdd() to persist a rule matching all files.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function setCatchAllRule(
		string $mode,
		string $type = 'include',
	): void
	{
		$this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => $type,
				'path'     => '**',
				'mode'     => $mode,
				'selector' => '*',
			],
		);
	}

	/** The verdict that replaced the retired mode `off`: claim, queue nothing. */
	private function setCatchAllIgnoreRule(): void
	{
		$this->setCatchAllRule( 'auto', 'ignore' );
	}

	/**
	 * Create a real test file in the admin user's storage.
	 *
	 * Registers the file and its fileId for automatic cleanup in tearDown().
	 *
	 * @noinspection PhpUnhandledExceptionInspection
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
	 * Remove all rules to ensure clean state between tests.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function resetRules(): void
	{
		$rules = $this->ruleService->loadRules();

		foreach ( $rules as $rule )
		{
			$id = $rule['id'] ?? null;

			if ( $id !== null )
			{
				$this->ruleService->ruleDelete( $id );
			}
		}
	}
}
