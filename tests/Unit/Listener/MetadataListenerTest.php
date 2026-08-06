<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Listener;

use OCA\FileChecksumSearch\Listener\MetadataListener;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\FilesMetadata\Event\MetadataBackgroundEvent;
use OCP\FilesMetadata\Model\IFilesMetadata;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MetadataListenerTest
	extends
	TestCase
{

	private MockObject|MetadataService $metadataService;

	private MockObject|LoggerInterface $logger;

	private MetadataListener           $listener;


	protected function setUp(): void
	{

		parent::setUp();

		$this->metadataService = $this->createMock( MetadataService::class );
		$this->logger          = $this->createMock( LoggerInterface::class );

		$this->listener = new MetadataListener(
			$this->metadataService,
			$this->logger,
		);
	}


	public function testHandleSkipsNonMetadataBackgroundEvent(): void
	{

		$event = $this->createMock( Event::class );

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( $event );
	}


	public function testHandleSkipsNonFileNode(): void
	{

		$folder   = $this->createMock( Folder::class );
		$metadata = $this->createMock( IFilesMetadata::class );
		$event    = new MetadataBackgroundEvent( $folder, $metadata );

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( $event );
	}


	public function testHandleMarksPendingMissingWhenCountPositive(): void
	{

		$file     = $this->createMock( File::class );
		$metadata = $this->createMock( IFilesMetadata::class );
		$event    = new MetadataBackgroundEvent( $file, $metadata );

		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'countByFileId' )
		                      ->with( 42 )
		                      ->willReturn( 3 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, 'pending:missing' )
		;

		$this->listener->handle( $event );
	}


	public function testHandleMarksPendingNewWhenCountZero(): void
	{

		$file     = $this->createMock( File::class );
		$metadata = $this->createMock( IFilesMetadata::class );
		$event    = new MetadataBackgroundEvent( $file, $metadata );

		$file->method( 'getId' )
		     ->willReturn( 77 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'countByFileId' )
		                      ->with( 77 )
		                      ->willReturn( 0 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 77, 'pending:new' )
		;

		$this->listener->handle( $event );
	}


	public function testHandleCatchesThrowable(): void
	{

		$file     = $this->createMock( File::class );
		$metadata = $this->createMock( IFilesMetadata::class );
		$event    = new MetadataBackgroundEvent( $file, $metadata );

		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'countByFileId' )
		                      ->willThrowException( new RuntimeException( 'DB down' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		// Should not throw — exception is caught and logged.
		$this->listener->handle( $event );

		$this->assertTrue( true );
	}

}
