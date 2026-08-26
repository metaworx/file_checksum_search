<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Listener;

use OCA\FileChecksumSearch\Listener\FileListener;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileListenerTest
	extends
	TestCase
{

	private MockObject|FilecacheService $filecacheService;

	private MockObject|MetadataService  $metadataService;

	private MockObject|RuleService      $ruleService;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private MockObject|LoggerInterface $logger;

	private FileListener               $listener;


	protected function setUp(): void
	{

		parent::setUp();

		$this->filecacheService = $this->createMock( FilecacheService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->ruleService      = $this->createMock( RuleService::class );
		$this->logger           = $this->createMock( LoggerInterface::class );

		$this->listener = new FileListener(
			$this->filecacheService,
			$this->metadataService,
			$this->ruleService,
			$this->logger,
		);
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
		                  ->with( '/files/user/foo.txt', 'owner-uid' )
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
		                  ->with( '/files/user/foo.txt', 'owner-uid' )
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


	public function testOnWriteAutoMarksPendingIfHashExists(): void
	{

		$file = $this->makeFileMock( 42, '/files/user/foo.txt' );

		$event = new NodeWrittenEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( '/files/user/foo.txt', 'owner-uid' )
		                  ->willReturn( [ 'mode' => 'auto' ] )
		;

		$this->metadataService->method( 'countByFileId' )
		                      ->with( 42 )
		                      ->willReturn( 3 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, 'pending:auto' )
		;

		$this->listener->handle( $event );
	}


	public function testOnWriteAutoSkipsIfNoHash(): void
	{

		$file = $this->makeFileMock( 42, '/files/user/foo.txt' );

		$event = new NodeWrittenEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( '/files/user/foo.txt', 'owner-uid' )
		                  ->willReturn( [ 'mode' => 'auto' ] )
		;

		$this->metadataService->method( 'countByFileId' )
		                      ->with( 42 )
		                      ->willReturn( 0 )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( $event );
	}


	public function testOnWriteOffDoesNothing(): void
	{

		$file = $this->makeFileMock( 42, '/files/user/foo.txt' );

		$event = new NodeWrittenEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( '/files/user/foo.txt', 'owner-uid' )
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
		                  ->with( '/files/user/untracked.txt', 'owner-uid' )
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
		                  ->with( '/files/user/new.txt', 'owner-uid' )
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
		                  ->with( '/files/user/new.txt', 'owner-uid' )
		                  ->willReturn( [ 'mode' => 'lazy' ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, 'pending:lazy' )
		;

		$this->listener->handle( $event );
	}


	public function testOnCreateAutoMarksPendingIfHashExists(): void
	{

		$file = $this->makeFileMock( 42, '/files/user/new.txt' );

		$event = new NodeCreatedEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( '/files/user/new.txt', 'owner-uid' )
		                  ->willReturn( [ 'mode' => 'auto' ] )
		;

		$this->metadataService->method( 'countByFileId' )
		                      ->with( 42 )
		                      ->willReturn( 1 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, 'pending:auto' )
		;

		$this->listener->handle( $event );
	}


	public function testOnCreateOffDoesNothing(): void
	{

		$file = $this->makeFileMock( 42, '/files/user/new.txt' );

		$event = new NodeCreatedEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( '/files/user/new.txt', 'owner-uid' )
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
		                  ->with( '/files/user/untracked.txt', 'owner-uid' )
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


	public function testOnDeleteClearsMetadataWhenRuleMatches(): void
	{

		$file = $this->makeFileMock( 42, '/files/user/foo.txt' );

		$event = new NodeDeletedEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( '/files/user/foo.txt', 'owner-uid' )
		                  ->willReturn( [ 'mode' => 'auto' ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearMetadata' )
		                      ->with( 42 )
		;

		$this->listener->handle( $event );
	}


	public function testOnDeleteOffModeDoesNothing(): void
	{

		$file = $this->makeFileMock( 42, '/files/user/foo.txt' );

		$event = new NodeDeletedEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( '/files/user/foo.txt', 'owner-uid' )
		                  ->willReturn( [ 'mode' => 'off' ] )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;

		$this->listener->handle( $event );

		$this->addToAssertionCount( 1 );
	}


	public function testOnDeleteNoMatchingRuleDoesNothing(): void
	{

		$file = $this->makeFileMock( 42, '/files/user/untracked.txt' );

		$event = new NodeDeletedEvent( $file );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( '/files/user/untracked.txt', 'owner-uid' )
		                  ->willReturn( null )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;

		$this->listener->handle( $event );

		$this->addToAssertionCount( 1 );
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
	): MockObject|File {

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


	public function testOnWritePassesFileOwnerToRuleLookup(): void
	{

		// Regression test for FCIAS Review §6, Finding 4: real-time rule
		// matching must resolve the file's owner and pass it through, so
		// a rule scoped to a different user can't fire on this file.
		$file = $this->makeFileMock( 42, '/files/user/foo.txt' );

		$event = new NodeWrittenEvent( $file );

		$this->ruleService->expects( $this->once() )
		                  ->method( 'findFirstMatchingRule' )
		                  ->with( '/files/user/foo.txt', 'owner-uid' )
		                  ->willReturn( null )
		;

		$this->listener->handle( $event );
	}

}
