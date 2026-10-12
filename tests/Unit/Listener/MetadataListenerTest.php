<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Listener;

use OCA\FileChecksumSearch\Listener\MetadataListener;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\FilesMetadata\Event\MetadataBackgroundEvent;
use OCP\FilesMetadata\Model\IFilesMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MetadataListenerTest
    extends
    TestCase
{

//  private properties

	private MockObject|MetadataService $metadataService;

	private MockObject|RuleService     $ruleService;

	private MockObject|LoggerInterface $logger;

	private MetadataListener           $listener;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->metadataService = $this->createMock( MetadataService::class );
		$this->ruleService     = $this->createMock( RuleService::class );
		$this->logger          = $this->createMock( LoggerInterface::class );

		$this->listener = new MetadataListener(
			$this->metadataService,
			$this->ruleService,
			$this->logger,
		);
	}


//  static methods

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function states(): iterable
	{
		yield 'revoked' => [ MetadataService::STATE_REVOKED ];
		yield 'queued lazy' => [ MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_LAZY ];
		yield 'queued force' => [ MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_FORCE ];
		yield 'gone' => [ MetadataService::STATE_GONE ];
		yield 'eroded' => [ MetadataService::STATE_ERODED ];
	}


//  other non-static methods

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

	/**
	 * A file the app has not hashed is queued in its rule's mode, as a
	 * write would queue it.
	 */
	public function testHandleQueuesAFileInItsRulesMode(): void
	{
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( $this->rule( MetadataService::PENDING_MODE_AUTO ) )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, MetadataService::PENDING_AUTO )
		;

		$this->listener->handle( $this->eventFor( 42 ) );
	}

	/**
	 * A file with a state is left as it is: metadata generation is no
	 * write, and overwriting the state undid a reset.
	 */
	#[DataProvider( 'states' )]
	public function testHandleLeavesAFileWithAStateAlone( string $state ): void
	{
		$this->metadataService->method( 'getMarker' )
		                      ->willReturn( $state )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( $this->rule( MetadataService::PENDING_MODE_MISSING ) )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( $this->eventFor( 42 ) );
	}

	/**
	 * Hashes that still vouch for the file need nothing.
	 */
	public function testHandleLeavesAVouchedFileAlone(): void
	{
		$this->metadataService->method( 'isVouched' )
		                      ->willReturn( true )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( $this->rule( MetadataService::PENDING_MODE_FORCE ) )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( $this->eventFor( 42 ) );
	}

	/**
	 * No include rule maintains the file: nothing queued.
	 */
	public function testHandleQueuesNothingWithoutAnIncludeRule(): void
	{
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( null )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->listener->handle( $this->eventFor( 42 ) );
	}

	public function testHandleCatchesThrowable(): void
	{
		$file     = $this->createMock( File::class );
		$metadata = $this->createMock( IFilesMetadata::class );
		$event    = new MetadataBackgroundEvent( $file, $metadata );

		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( $this->rule( MetadataService::PENDING_MODE_AUTO ) )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->willThrowException( new RuntimeException( 'DB down' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		// Should not throw — exception is caught and logged.
		$this->listener->handle( $event );

		$this->assertTrue( true );
	}

	private function eventFor( int $fileId ): MetadataBackgroundEvent
	{
		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( $fileId )
		;

		return new MetadataBackgroundEvent( $file, $this->createMock( IFilesMetadata::class ) );
	}

	/**
	 * An include rule in $mode.
	 *
	 * @return array<string, mixed>
	 */
	private function rule( string $mode ): array
	{
		return [
			'id'    => 'r1',
			'type'  => 'include',
			'mode'  => $mode,
			'algos' => [ 'sha256' ],
		];
	}
}
