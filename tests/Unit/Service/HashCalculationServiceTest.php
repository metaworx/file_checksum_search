<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for HashCalculationService::processFile().
 *
 * Covers all four processing modes (lazy, force, auto, missing)
 * plus failure handling.
 */
class HashCalculationServiceTest
	extends
	FciasUnitTestCase
{

	private FilecacheService&MockObject    $filecacheService;

	private ILockingProvider&MockObject    $lockingProvider;

	private MetadataService&MockObject     $metadataService;

	private LoggerInterface&MockObject     $logger;

	/** @var HashCalculationService&MockObject */
	private HashCalculationService         $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->filecacheService = $this->createMock( FilecacheService::class );
		$this->lockingProvider  = $this->createMock( ILockingProvider::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->logger           = $this->createMock( LoggerInterface::class );

		$this->service = $this->getMockBuilder( HashCalculationService::class )
		                      ->onlyMethods( [ 'recalcHash' ] )
		                      ->setConstructorArgs(
			                      [
				                      $this->filecacheService,
				                      $this->lockingProvider,
				                      $this->metadataService,
				                      $this->logger,
			                      ],
		                      )
		                      ->getMock()
		;
	}


	public function testProcessFileLazyMode(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getMetadata' )
		                      ->with( 42 )
		                      ->willReturn( $metadata )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearMetadata' )
		                      ->with( $metadata, false )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		$this->service->expects( $this->never() )
		              ->method( 'recalcHash' )
		;

		$this->service->processFile( 42, 'lazy', [ 'sha1', 'sha256' ] );
	}


	public function testProcessFileForceMode(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getMetadata' )
		                      ->with( 42 )
		                      ->willReturn( $metadata )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearMetadata' )
		                      ->with( $metadata, false )
		;

		$this->service->expects( $this->exactly( 2 ) )
		              ->method( 'recalcHash' )
		              ->willReturnMap(
			              [
				              [ 42, 'sha1', true, $metadata, [ 'success' => true, 'hash' => 'abc' ] ],
				              [ 42, 'sha256', true, $metadata, [ 'success' => true, 'hash' => 'def' ] ],
			              ],
		              )
		;

		$metadata->expects( $this->exactly( 2 ) )
		         ->method( 'setString' )
		         ->willReturnSelf()
		;

		$metadata->expects( $this->once() )
		         ->method( 'setInt' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $this->anything() )
		         ->willReturnSelf()
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		$this->service->processFile( 42, 'force', [ 'sha1', 'sha256' ] );
	}


	public function testProcessFileAutoModeSkipsMissingKeys(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getMetadata' )
		                      ->with( 42 )
		                      ->willReturn( $metadata )
		;

		// sha1 key exists, sha256 does not
		$metadata->expects( $this->exactly( 2 ) )
		         ->method( 'hasKey' )
		         ->willReturnMap(
			         [
				         [ MetadataService::getHashKey( 'sha1' ), true ],
				         [ MetadataService::getHashKey( 'sha256' ), false ],
			         ],
		         )
		;

		// Only sha1 should be recalculated
		$this->service->expects( $this->once() )
		              ->method( 'recalcHash' )
		              ->with( 42, 'sha1', true, $metadata )
		              ->willReturn( [ 'success' => true, 'hash' => 'abc' ] )
		;

		$metadata->expects( $this->once() )
		         ->method( 'setString' )
		         ->willReturnSelf()
		;

		$metadata->expects( $this->once() )
		         ->method( 'setInt' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $this->anything() )
		         ->willReturnSelf()
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		$this->service->processFile( 42, 'auto', [ 'sha1', 'sha256' ] );
	}


	public function testProcessFileMissingMode(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getMetadata' )
		                      ->with( 42 )
		                      ->willReturn( $metadata )
		;

		// Missing mode does NOT call clearMetadata
		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearMetadata' )
		;

		$this->service->expects( $this->exactly( 2 ) )
		              ->method( 'recalcHash' )
		              ->willReturnMap(
			              [
				              [ 42, 'sha1', true, $metadata, [ 'success' => true, 'hash' => 'abc' ] ],
				              [ 42, 'sha256', true, $metadata, [ 'success' => true, 'hash' => 'def' ] ],
			              ],
		              )
		;

		$metadata->expects( $this->exactly( 2 ) )
		         ->method( 'setString' )
		         ->willReturnSelf()
		;

		$metadata->expects( $this->once() )
		         ->method( 'setInt' )
		         ->willReturnSelf()
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		$this->service->processFile( 42, 'missing', [ 'sha1', 'sha256' ] );
	}


	public function testProcessFileAutoModeAllKeysMissing(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getMetadata' )
		                      ->with( 42 )
		                      ->willReturn( $metadata )
		;

		// No keys exist
		$metadata->expects( $this->exactly( 2 ) )
		         ->method( 'hasKey' )
		         ->willReturn( false )
		;

		// recalcHash should not be called
		$this->service->expects( $this->never() )
		              ->method( 'recalcHash' )
		;

		$metadata->expects( $this->once() )
		         ->method( 'setInt' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $this->anything() )
		         ->willReturnSelf()
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		$this->service->processFile( 42, 'auto', [ 'sha1', 'sha256' ] );
	}


	public function testProcessFileFailureMarksPending(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getMetadata' )
		                      ->with( 42 )
		                      ->willReturn( $metadata )
		;

		// First algo succeeds, second fails
		$this->service->expects( $this->exactly( 2 ) )
		              ->method( 'recalcHash' )
		              ->willReturnMap(
			              [
				              [ 42, 'sha1', true, $metadata, [ 'success' => true, 'hash' => 'abc' ] ],
				              [
					              42,
					              'sha256',
					              true,
					              $metadata,
					              [ 'success' => false, 'error' => 'hash failed' ],
				              ],
			              ],
		              )
		;

		// Only sha1 setString should be called
		$metadata->expects( $this->once() )
		         ->method( 'setString' )
		         ->willReturnSelf()
		;

		// updated_at NOT set (early return before setInt)
		$metadata->expects( $this->never() )
		         ->method( 'setInt' )
		;

		// markPending called for the failed mode
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, MetadataService::PENDING_PREFIX . 'missing' )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		;

		// saveMetadata NOT called (early return)
		$this->metadataService->expects( $this->never() )
		                      ->method( 'saveMetadata' )
		;

		$this->service->processFile( 42, 'missing', [ 'sha1', 'sha256' ] );
	}

}
