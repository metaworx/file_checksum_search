<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Listener;

use OCA\FileChecksumSearch\Listener\FileListener;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Service\VersionCapture;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileListenerTest
    extends
    TestCase
{

//  private properties

	private MockObject|FilecacheService $filecacheService;

	private MockObject|MetadataService  $metadataService;

	private MockObject|RuleService      $ruleService;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private MockObject|LoggerInterface $logger;

	private MockObject|VersionCapture  $versionCapture;

	private FileListener               $listener;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->filecacheService = $this->createMock( FilecacheService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->ruleService      = $this->createMock( RuleService::class );
		$this->logger           = $this->createMock( LoggerInterface::class );
		$this->versionCapture   = $this->createMock( VersionCapture::class );

		$this->listener = new FileListener(
			$this->filecacheService,
			$this->metadataService,
			$this->ruleService,
			$this->logger,
			$this->versionCapture,
		);
	}


//  other non-static methods

	/**
	 * The content a write is about to replace keeps its hashes for the
	 * version, where a rule maintains them; the write hands them on.
	 */
	public function testAWriteOfAMaintainedFileHandsItsHashesToTheVersion(): void
	{
		$node = $this->createMock( File::class );
		$node->method( 'getId' )->willReturn( 7 );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'   => 'docs',
			                  'type' => 'include',
			                  'mode' => 'auto',
		                  ] )
		;

		$this->versionCapture->expects( $this->once() )->method( 'beforeWrite' )->with( 7 );
		$this->versionCapture->expects( $this->once() )->method( 'afterWrite' )->with( 7 );

		$this->listener->handle( new BeforeNodeWrittenEvent( $node ) );
		$this->listener->handle( new NodeWrittenEvent( $node ) );
	}

	public function testAFileNoRuleMaintainsKeepsNothingForItsVersion(): void
	{
		$node = $this->createMock( File::class );
		$node->method( 'getId' )->willReturn( 7 );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'   => 'archive',
			                  'type' => 'exclude',
		                  ] )
		;

		$this->versionCapture->expects( $this->never() )->method( 'beforeWrite' );

		$this->listener->handle( new BeforeNodeWrittenEvent( $node ) );
	}

	/**
	 * A file about to be created has no id and nothing to replace: no rule
	 * is asked, and nothing is logged as an error.
	 */
	public function testAFileNotWrittenYetIsLeftAlone(): void
	{
		$node = $this->createMock( File::class );
		$node->method( 'getId' )->willThrowException( new NotFoundException() );

		$this->ruleService->expects( $this->never() )->method( 'findFirstMatchingRule' );
		$this->versionCapture->expects( $this->never() )->method( 'beforeWrite' );
		$this->logger->expects( $this->never() )->method( 'error' );

		$this->listener->handle( new BeforeNodeWrittenEvent( $node ) );
	}

	public function testOnWriteDropsStaleHashesForAFileTheRulesNoLongerMaintain(): void
	{
		$node = $this->createMock( File::class );
		$node->method( 'getId' )
		     ->willReturn( 7 )
		;
		$node->method( 'getPath' )
		     ->willReturn( '/files/Archive/big.iso' )
		;

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'   => 'archive',
			                  'type' => 'exclude',
		                  ] )
		;

		$this->metadataService->method( 'countByFileId' )
		                      ->with( 7 )
		                      ->willReturn( 2 )
		;

		// Excluding a file leaves its hashes alone — they still describe the
		// content. Modifying it does not: the stored hash is now provably
		// wrong, and a wrong hash is worse than none, because it makes a
		// changed file look intact and can pair it with unrelated files.
		// The loss is recorded as 'eroded' rather than silently cleared, so
		// the status page can count it.
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markEroded' )
		                      ->with( 7 )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( new NodeWrittenEvent( $node ) );
	}

	public function testOnWriteTouchesNothingForAnUnmaintainedFileWithNoHashes(): void
	{
		$node = $this->createMock( File::class );
		$node->method( 'getId' )
		     ->willReturn( 8 )
		;
		$node->method( 'getPath' )
		     ->willReturn( '/files/Archive/other.iso' )
		;

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'   => 'archive',
			                  'type' => 'ignore',
		                  ] )
		;

		$this->metadataService->method( 'countByFileId' )
		                      ->willReturn( 0 )
		;

		// Nothing stored means nothing to invalidate — no pointless write.
		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;

		$this->listener->handle( new NodeWrittenEvent( $node ) );
	}

	public function testOnCreateQueuesNothingForAnExcludedPath(): void
	{
		$node = $this->createMock( File::class );
		$node->method( 'getId' )
		     ->willReturn( 9 )
		;
		$node->method( 'getPath' )
		     ->willReturn( '/files/Archive/new.iso' )
		;

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'   => 'archive',
			                  'type' => 'exclude',
		                  ] )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( new NodeCreatedEvent( $node ) );
	}

	public function testOnCopyCarriesNothingIntoAnExcludedDestination(): void
	{
		$source = $this->createMock( File::class );
		$target = $this->createMock( File::class );

		$source->method( 'getId' )
		       ->willReturn( 1 )
		;
		$target->method( 'getId' )
		       ->willReturn( 2 )
		;
		$target->method( 'getPath' )
		       ->willReturn( '/files/Archive/copy.txt' )
		;

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'   => 'archive',
			                  'type' => 'exclude',
		                  ] )
		;

		// Copying a checksum in would create precisely the stored hash the
		// rule exists to prevent, so neither the copy nor the queueing runs.
		$this->filecacheService->expects( $this->never() )
		                       ->method( 'copyFilecacheChecksum' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( new NodeCopiedEvent( $source, $target ) );
	}

	public function testOnCopyCopiesChecksumAndMarksPending(): void
	{
		$source = $this->createMock( File::class );
		$target = $this->createMock( File::class );

		$source->method( 'getId' )
		       ->willReturn( 1 )
		;
		$target->method( 'getId' )
		       ->willReturn( 2 )
		;
		$target->method( 'getPath' )
		       ->willReturn( '/files/copy.txt' )
		;

		// The copy's destination decides, so the listener now consults the
		// rule governing the target path.
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn(
			                  [
				                  'id'   => 'r1',
				                  'mode' => 'auto',
			                  ],
		                  )
		;

		$event = new NodeCopiedEvent( $source, $target );

		$this->filecacheService->expects( $this->once() )
		                       ->method( 'copyFilecacheChecksum' )
		                       ->with( $source, $target )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 2, 'pending:auto' )
		;

		$this->listener->handle( $event );
	}

	public function testOnCopySkipsNonFileNodes(): void
	{
		$source = $this->createMock( File::class );
		$target = $this->createMock( Folder::class );

		$event = new NodeCopiedEvent( $source, $target );

		$this->filecacheService->expects( $this->never() )
		                       ->method( 'copyFilecacheChecksum' )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( $event );
	}

	public function testOnWriteForceClearsAndMarksPending(): void
	{
		$file = $this->makeFileMock( 42, '/files/user/foo.txt' );

		$event = new NodeWrittenEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
		                  ->willReturn( [ 'mode' => 'force' ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearMetadata' )
		                      ->with( 42 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, 'pending:force' )
		;

		$this->listener->handle( $event );
	}

	public function testOnWriteLazyClearsAndMarksPending(): void
	{
		$file = $this->makeFileMock( 42, '/files/user/foo.txt' );

		$event = new NodeWrittenEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
		                  ->willReturn( [ 'mode' => 'lazy' ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearMetadata' )
		                      ->with( 42 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, 'pending:lazy' )
		;

		$this->listener->handle( $event );
	}

	/**
	 * Under `auto` and `missing` alike, a write takes the file's hashes out
	 * of every search and queues it in the rule's mode: they describe
	 * content the file no longer has.
	 */
	#[DataProvider( 'keepingModes' )]
	public function testOnWriteHidesTheHashesAndQueuesTheFile( string $mode ): void
	{
		$file = $this->makeFileMock( 42, '/files/user/foo.txt' );

		$event = new NodeWrittenEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
		                  ->willReturn( [ 'mode' => $mode ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markOutdated' )
		                      ->with( 42, $mode )
		                      ->willReturn( true )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;

		$this->listener->handle( $event );
	}

	/**
	 * A file without hashes is queued for its first, under `auto` as under
	 * `missing`: the rule wants them, and the write is the moment to say so
	 * rather than the sweep's next pass.
	 */
	#[DataProvider( 'keepingModes' )]
	public function testOnWriteQueuesAFileWithoutHashesForItsFirst( string $mode ): void
	{
		$file = $this->makeFileMock( 42, '/files/user/foo.txt' );

		$event = new NodeWrittenEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
		                  ->willReturn( [ 'mode' => $mode ] )
		;

		// markOutdated() answers whether there was anything to take out, and
		// queues only then.
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markOutdated' )
		                      ->willReturn( false )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, 'pending:' . $mode )
		;

		$this->listener->handle( $event );
	}

	public function testOnWriteQueuesNothingForARetiredMode(): void
	{
		$file = $this->makeFileMock( 42, '/files/user/foo.txt' );

		$event = new NodeWrittenEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
			// 'off' is retired; the repair step converts such
			// rules to `ignore`. Until it runs, a rule still
			// carrying it must queue nothing rather than fail.
			              ->willReturn( [ 'mode' => 'off' ] )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( $event );
	}

	public function testOnWriteNoMatchingRuleDoesNothing(): void
	{
		$file = $this->makeFileMock( 42, '/files/user/untracked.txt' );

		$event = new NodeWrittenEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
		                  ->willReturn( null )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( $event );
	}

	public function testOnCreateForceClearsAndMarksPending(): void
	{
		$file = $this->makeFileMock( 42, '/files/user/new.txt' );

		$event = new NodeCreatedEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
		                  ->willReturn( [ 'mode' => 'force' ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearMetadata' )
		                      ->with( 42 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, 'pending:force' )
		;

		$this->listener->handle( $event );
	}

	public function testOnCreateLazyMarksPending(): void
	{
		$file = $this->makeFileMock( 42, '/files/user/new.txt' );

		$event = new NodeCreatedEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
		                  ->willReturn( [ 'mode' => 'lazy' ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, 'pending:lazy' )
		;

		$this->listener->handle( $event );
	}

	/**
	 * A new file is queued for its first hashes under `auto` as under
	 * `missing`, rather than left to the sweep or to a metadata event that
	 * Nextcloud fires only where another app asks for one.
	 */
	#[DataProvider( 'keepingModes' )]
	public function testOnCreateQueuesTheFileForItsFirstHashes( string $mode ): void
	{
		$file = $this->makeFileMock( 42, '/files/user/new.txt' );

		$event = new NodeCreatedEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
		                  ->willReturn( [ 'mode' => $mode ] )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, 'pending:' . $mode )
		;

		$this->listener->handle( $event );
	}

	public function testOnCreateQueuesNothingForARetiredMode(): void
	{
		$file = $this->makeFileMock( 42, '/files/user/new.txt' );

		$event = new NodeCreatedEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
			// 'off' is retired; the repair step converts such
			// rules to `ignore`. Until it runs, a rule still
			// carrying it must queue nothing rather than fail.
			              ->willReturn( [ 'mode' => 'off' ] )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( $event );
	}

	public function testOnCreateNoMatchingRuleDoesNothing(): void
	{
		$file = $this->makeFileMock( 42, '/files/user/untracked.txt' );

		$event = new NodeCreatedEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
		                  ->willReturn( null )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( $event );
	}

	/**
	 * Moved into the trash with hashes of this app's: they are cleared,
	 * whatever rule governed the file before — no rule governs the trash,
	 * so none is consulted.
	 */
	public function testOnDeleteClearsATrashedFilesHashes(): void
	{
		$metadata = $this->trashedFile( 42, true );

		$this->ruleService->expects( $this->never() )
		                  ->method( 'findFirstMatchingRule' )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearMetadata' )
		                      ->with( $metadata )
		;

		$this->listener->handle( new NodeDeletedEvent( $this->makeFileMock( 42, '/files/user/foo.txt' ) ) );
	}

	/**
	 * A trashed file this app holds nothing for is left alone: clearing
	 * would write it a document of this app's, a stamp and all.
	 */
	public function testOnDeleteLeavesATrashedFileWithoutHashesAlone(): void
	{
		$this->trashedFile( 42, false );

		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;

		$this->listener->handle( new NodeDeletedEvent( $this->makeFileMock( 42, '/files/user/untracked.txt' ) ) );
	}

	/**
	 * Deleted for good, the filecache row is gone and Nextcloud takes the
	 * metadata with it. Saving after that is refused, or, where Nextcloud
	 * lets it through, writes a document for a file that no longer exists:
	 * nothing is read or written.
	 */
	public function testOnDeleteLeavesAFileDeletedForGoodToNextcloud(): void
	{
		$this->filecacheService->method( 'locate' )
		                       ->with( 42 )
		                       ->willReturn( null )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'getMetadata' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;

		$this->listener->handle( new NodeDeletedEvent( $this->makeFileMock( 42, '/files/user/foo.txt' ) ) );
	}

	/**
	 * A file the filecache still has — moved into the trash — whose
	 * metadata holds this app's data or not.
	 */
	private function trashedFile(
		int  $fileId,
		bool $holdsAppData,
	): IFilesMetadata&MockObject
	{
		$metadata = $this->createMock( IFilesMetadata::class );

		$this->filecacheService->method( 'locate' )
		                       ->with( $fileId )
		                       ->willReturn( FileLocation::fromRow( $fileId, 'home::user', 'files_trashbin/files/foo.txt.d1', 0 ) )
		;
		$this->metadataService->method( 'getMetadata' )
		                      ->with( $fileId )
		                      ->willReturn( $metadata )
		;
		$this->metadataService->method( 'holdsAppData' )
		                      ->with( $metadata )
		                      ->willReturn( $holdsAppData )
		;

		return $metadata;
	}

	/**
	 * Create a File mock with getId(), getPath(), and getOwner()
	 * configured — the owner UID is always 'owner-uid' in this suite.
	 *
	 * @noinspection PhpSameParameterValueInspection
	 */
	private function makeFileMock(
		int    $id,
		string $path,
	): MockObject|File
	{
		$owner = $this->createMock( IUser::class );
		$owner->method( 'getUID' )
		      ->willReturn( 'owner-uid' )
		;

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( $id )
		;
		$file->method( 'getPath' )
		     ->willReturn( $path )
		;
		$file->method( 'getOwner' )
		     ->willReturn( $owner )
		;

		return $file;
	}

	public function testOnWriteResolvesRulesByFileIdNotViewPath(): void
	{
		// The share-edit regression, listener half: this node comes from a
		// recipient's context — its getPath() is the recipient's renamed
		// mount, its getOwner() answers for the mount. Neither may reach the
		// rule lookup; only the file id does, and RuleService resolves the
		// canonical identity (the owner and the owner's path) from it.
		$file = $this->makeFileMock( 42, '/bob/files/RenamedShare/foo.txt' );

		$event = new NodeWrittenEvent( $file );

		$this->ruleService->expects( $this->once() )
		                  ->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
		                  ->willReturn( null )
		;

		$this->listener->handle( $event );
	}


//  static methods

	/**
	 * The modes that keep a file's hashes across a write until the queue
	 * recomputes them.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function keepingModes(): array
	{
		return [
			'auto'    => [ 'auto' ],
			'missing' => [ 'missing' ],
		];
	}
}
