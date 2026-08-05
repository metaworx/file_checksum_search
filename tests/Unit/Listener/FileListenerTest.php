<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Listener\FileListener;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileListenerTest
	extends
	TestCase
{

	private MockObject|HashIndexService $hashIndexService;

	private MockObject|IAppConfig       $appConfig;

	private MockObject|LoggerInterface  $logger;

	private FileListener                $listener;


	protected function setUp(): void
	{

		parent::setUp();

		$this->hashIndexService = $this->createMock( HashIndexService::class );
		$this->appConfig        = $this->createMock( IAppConfig::class );
		$this->logger           = $this->createMock( LoggerInterface::class );

		$this->listener = new FileListener(
			$this->hashIndexService,
			$this->appConfig,
			$this->logger,
		);
	}


	public function testOnCopyCopiesHashesAndChecksum(): void
	{

		$source = $this->createMock( File::class );
		$target = $this->createMock( File::class );

		$source->method( 'getId' )
		       ->willReturn( 1 )
		;
		$target->method( 'getId' )
		       ->willReturn( 2 )
		;

		$event = new NodeCopiedEvent( $source, $target );

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'copyFilecacheChecksum' )
		                       ->with( $source, $target )
		;
		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'copyHashes' )
		                       ->with( 1, 2 )
		;

		$this->listener->handle( $event );
	}


	public function testOnCopySkipsNonFileNodes(): void
	{

		$source = $this->createMock( File::class );
		$target = $this->createMock( \OCP\Files\Folder::class );

		$event = new NodeCopiedEvent( $source, $target );

		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'copyFilecacheChecksum' )
		;
		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'copyHashes' )
		;

		$this->listener->handle( $event );
	}


	public function testOnWriteForceRecalcsAllAlgos(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$event = new NodeWrittenEvent( $file );

		$this->appConfig->method( 'getValueString' )
		                ->with( Application::APP_ID, 'update_hash_on_file_write', 'auto' )
		                ->willReturn( 'force' )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcAllExistingAlgos' )
		                       ->with( 42 )
		;

		$this->listener->handle( $event );
	}


	public function testOnWriteLazyDeletesAndQueues(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$event = new NodeWrittenEvent( $file );

		$this->appConfig->method( 'getValueString' )
		                ->with( Application::APP_ID, 'update_hash_on_file_write', 'auto' )
		                ->willReturn( 'lazy' )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'deleteHashes' )
		                       ->with( 42 )
		;
		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'addPending' )
		                       ->with( 42, HashIndexService::EVENT_TYPE_WRITE )
		;

		$this->listener->handle( $event );
	}


	public function testOnWriteAutoRecalcsIfHashExists(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$event = new NodeWrittenEvent( $file );

		$this->appConfig->method( 'getValueString' )
		                ->with( Application::APP_ID, 'update_hash_on_file_write', 'auto' )
		                ->willReturn( 'auto' )
		;

		$this->hashIndexService->method( 'countHashes' )
		                       ->with( 42 )
		                       ->willReturn( 3 )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcAllExistingAlgos' )
		                       ->with( 42 )
		;

		$this->listener->handle( $event );
	}


	public function testOnWriteAutoSkipsIfNoHash(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$event = new NodeWrittenEvent( $file );

		$this->appConfig->method( 'getValueString' )
		                ->with( Application::APP_ID, 'update_hash_on_file_write', 'auto' )
		                ->willReturn( 'auto' )
		;

		$this->hashIndexService->method( 'countHashes' )
		                       ->with( 42 )
		                       ->willReturn( 0 )
		;

		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'recalcAllExistingAlgos' )
		;

		$this->listener->handle( $event );
	}


	public function testOnWriteOffDoesNothing(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$event = new NodeWrittenEvent( $file );

		$this->appConfig->method( 'getValueString' )
		                ->with( Application::APP_ID, 'update_hash_on_file_write', 'auto' )
		                ->willReturn( 'off' )
		;

		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'recalcAllExistingAlgos' )
		;
		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'deleteHashes' )
		;

		$this->listener->handle( $event );
	}


	public function testOnCreateForceRecalcsDefaultAlgo(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$event = new NodeCreatedEvent( $file );

		$this->appConfig->method( 'getValueString' )
		                ->with( Application::APP_ID, 'update_hash_on_file_create', 'off' )
		                ->willReturn( 'force' )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcFileHash' )
		                       ->with( $file, HashIndexService::getDefaultAlgo() )
		;

		$this->listener->handle( $event );
	}


	public function testOnCreateLazyQueues(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$event = new NodeCreatedEvent( $file );

		$this->appConfig->method( 'getValueString' )
		                ->with( Application::APP_ID, 'update_hash_on_file_create', 'off' )
		                ->willReturn( 'lazy' )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'addPending' )
		                       ->with( 42, HashIndexService::EVENT_TYPE_CREATE )
		;

		$this->listener->handle( $event );
	}


	public function testOnCreateOffDoesNothing(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$event = new NodeCreatedEvent( $file );

		$this->appConfig->method( 'getValueString' )
		                ->with( Application::APP_ID, 'update_hash_on_file_create', 'off' )
		                ->willReturn( 'off' )
		;

		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'recalcFileHash' )
		;
		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'addPending' )
		;

		$this->listener->handle( $event );
	}


	public function testOnDeleteOnRemovesHashes(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$event = new NodeDeletedEvent( $file );

		$this->appConfig->method( 'getValueString' )
		                ->with( Application::APP_ID, 'update_hash_on_file_delete', 'off' )
		                ->willReturn( 'on' )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'deleteHashes' )
		                       ->with( 42 )
		;

		$this->listener->handle( $event );
	}


	public function testOnDeleteOffDoesNothing(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$event = new NodeDeletedEvent( $file );

		$this->appConfig->method( 'getValueString' )
		                ->with( Application::APP_ID, 'update_hash_on_file_delete', 'off' )
		                ->willReturn( 'off' )
		;

		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'deleteHashes' )
		;

		$this->listener->handle( $event );
	}


	public function testDefaultConfigWriteIsAuto(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$event = new NodeWrittenEvent( $file );

		// Return 'auto' when config is not set (default)
		$this->appConfig->method( 'getValueString' )
		                ->with( Application::APP_ID, 'update_hash_on_file_write', 'auto' )
		                ->willReturn( 'auto' )
		;

		$this->hashIndexService->method( 'countHashes' )
		                       ->with( 42 )
		                       ->willReturn( 1 )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcAllExistingAlgos' )
		;

		$this->listener->handle( $event );
	}

}
