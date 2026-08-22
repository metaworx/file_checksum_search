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
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
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

	private FilecacheService&MockObject $filecacheService;

	private ILockingProvider&MockObject $lockingProvider;

	private MetadataService&MockObject  $metadataService;

	private RuleService&MockObject      $ruleService;

	private LoggerInterface&MockObject  $logger;

	/** @var HashCalculationService&MockObject */
	private HashCalculationService $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->filecacheService = $this->createMock( FilecacheService::class );
		$this->lockingProvider  = $this->createMock( ILockingProvider::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->ruleService      = $this->createMock( RuleService::class );
		$this->logger           = $this->createMock( LoggerInterface::class );

		$this->service = $this->getMockBuilder( HashCalculationService::class )
		                      ->onlyMethods( [ 'recalcHash' ] )
		                      ->setConstructorArgs(
			                      [
				                      $this->filecacheService,
				                      $this->lockingProvider,
				                      $this->metadataService,
				                      $this->ruleService,
				                      $this->logger,
			                      ],
		                      )
		                      ->getMock()
		;
	}


	// isValidAlgo

	public function testIsValidAlgoAcceptsSupportedAlgo(): void
	{

		$this->assertTrue( HashCalculationService::isValidAlgo( 'sha256' ) );
	}


	public function testIsValidAlgoRejectsUnsupportedString(): void
	{

		$this->assertFalse( HashCalculationService::isValidAlgo( 'bogus' ) );
	}


	public function testIsValidAlgoRejectsNonString(): void
	{

		$this->assertFalse( HashCalculationService::isValidAlgo( 42 ) );
		$this->assertFalse( HashCalculationService::isValidAlgo( null ) );
		$this->assertFalse( HashCalculationService::isValidAlgo( [ 'sha256' ] ) );
	}


	// recalcFileHash

	public function testRecalcFileHashSavesMetadataBeforeReleasingLock(): void
	{

		// Regression test for FCIAS Review §6, Finding 5: the finally
		// block used to release the lock before saving metadata, so a
		// concurrent recalcFileHash() for a different algo on the same
		// file could interleave its save and silently drop this one's
		// hash. Save must happen while still holding the lock.
		$tmpFile = tempnam( sys_get_temp_dir(), 'fcias_test_' );
		file_put_contents( $tmpFile, 'hello world' );

		$storage = $this->createMock( IStorage::class );
		$storage->method( 'isLocal' )
		        ->willReturn( true )
		;
		$storage->method( 'getLocalFile' )
		        ->willReturn( $tmpFile )
		;

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getMTime' )
		     ->willReturn( 1000 )
		;
		$file->method( 'getStorage' )
		     ->willReturn( $storage )
		;
		$file->method( 'getInternalPath' )
		     ->willReturn( 'files/test.txt' )
		;

		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'hasKey' )
		         ->willReturn( false )
		;
		$metadata->method( 'setString' )
		         ->willReturnSelf()
		;

		$this->metadataService->method( 'ensureMetadata' )
		                      ->willReturnCallback(
			                      function (
				                      $fileOrId,
				                      &$metadataRef,
			                      ) use ( $metadata ): bool
			                      {

				                      $metadataRef = $metadata;

				                      return true;
			                      },
		                      )
		;
		$this->filecacheService->method( 'getChecksums' )
		                       ->willReturn( [] )
		;

		$order = [];
		$this->metadataService->method( 'saveMetadata' )
		                      ->willReturnCallback(
			                      function () use ( &$order ): void
			                      {

				                      $order[] = 'save';
			                      },
		                      )
		;
		$this->lockingProvider->method( 'releaseLock' )
		                      ->willReturnCallback(
			                      function () use ( &$order ): void
			                      {

				                      $order[] = 'release';
			                      },
		                      )
		;

		try
		{
			$result = $this->service->recalcFileHash( $file, 'sha1' );

			$this->assertTrue( $result['success'] );
			$this->assertSame( [ 'save', 'release' ], $order );
		}
		finally
		{
			@unlink( $tmpFile );
		}
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

		$this->service->processFile(
			42,
			'lazy',
			[
				'sha1',
				'sha256',
			],
		);
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
				              [
					              42,
					              'sha1',
					              true,
					              $metadata,
					              [
						              'success' => true,
						              'hash'    => 'abc',
					              ],
				              ],
				              [
					              42,
					              'sha256',
					              true,
					              $metadata,
					              [
						              'success' => true,
						              'hash'    => 'def',
					              ],
				              ],
			              ],
		              )
		;

		$metadata->expects( $this->exactly( 2 ) )
		         ->method( 'setString' )
		         ->willReturnSelf()
		;

		$metadata->expects( $this->once() )
		         ->method( 'setInt' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $this->anything(), true )
		         ->willReturnSelf()
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		$this->service->processFile(
			42,
			'force',
			[
				'sha1',
				'sha256',
			],
		);
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
				         [
					         MetadataService::getHashKey( 'sha1' ),
					         true,
				         ],
				         [
					         MetadataService::getHashKey( 'sha256' ),
					         false,
				         ],
			         ],
		         )
		;

		// Only sha1 should be recalculated
		$this->service->expects( $this->once() )
		              ->method( 'recalcHash' )
		              ->with( 42, 'sha1', true, $metadata )
		              ->willReturn(
			              [
				              'success' => true,
				              'hash'    => 'abc',
			              ],
		              )
		;

		$metadata->expects( $this->once() )
		         ->method( 'setString' )
		         ->willReturnSelf()
		;

		$metadata->expects( $this->once() )
		         ->method( 'setInt' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $this->anything(), true )
		         ->willReturnSelf()
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		$this->service->processFile(
			42,
			'auto',
			[
				'sha1',
				'sha256',
			],
		);
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
				              [
					              42,
					              'sha1',
					              true,
					              $metadata,
					              [
						              'success' => true,
						              'hash'    => 'abc',
					              ],
				              ],
				              [
					              42,
					              'sha256',
					              true,
					              $metadata,
					              [
						              'success' => true,
						              'hash'    => 'def',
					              ],
				              ],
			              ],
		              )
		;

		$metadata->expects( $this->exactly( 2 ) )
		         ->method( 'setString' )
		         ->willReturnSelf()
		;

		$metadata->expects( $this->once() )
		         ->method( 'setInt' )
		         ->with( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $this->anything(), true )
		         ->willReturnSelf()
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		$this->service->processFile(
			42,
			'missing',
			[
				'sha1',
				'sha256',
			],
		);
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
		         ->with( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $this->anything(), true )
		         ->willReturnSelf()
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		$this->service->processFile(
			42,
			'auto',
			[
				'sha1',
				'sha256',
			],
		);
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
				              [
					              42,
					              'sha1',
					              true,
					              $metadata,
					              [
						              'success' => true,
						              'hash'    => 'abc',
					              ],
				              ],
				              [
					              42,
					              'sha256',
					              true,
					              $metadata,
					              [
						              'success' => false,
						              'error'   => 'hash failed',
					              ],
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

		$this->service->processFile(
			42,
			'missing',
			[
				'sha1',
				'sha256',
			],
		);
	}


	public function testRecalcAllExistingAlgosOnlyRecalculatesExisting(): void
	{

		$fileId = 99;
		$file   = $this->createMock( \OCP\Files\File::class );
		$file->method( 'getId' )
		     ->willReturn( $fileId )
		;

		$metadata = $this->createMock( \OCP\FilesMetadata\Model\IFilesMetadata::class );

		$this->filecacheService->expects( $this->once() )
		                       ->method( 'getFile' )
		                       ->with( $fileId )
		                       ->willReturn( $file )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getMetadata' )
		                      ->with( $file )
		                      ->willReturn( $metadata )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'countByFileId' )
		                      ->with( $fileId )
		                      ->willReturn( 2 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( $metadata )
		                      ->willReturn(
			                      [
				                      'sha1',
				                      'sha256',
			                      ],
		                      )
		;

		$this->service->expects( $this->exactly( 2 ) )
		              ->method( 'recalcHash' )
		              ->willReturnMap(
			              [
				              [
					              $file,
					              'sha1',
					              true,
					              $metadata,
					              [
						              'success' => true,
						              'hash'    => 'aaa',
					              ],
				              ],
				              [
					              $file,
					              'sha256',
					              true,
					              $metadata,
					              [
						              'success' => true,
						              'hash'    => 'bbb',
					              ],
				              ],
			              ],
		              )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		;

		$result = $this->service->recalcAllExistingAlgos( $fileId );

		$this->assertSame( 2, $result['processed'] );
		$this->assertSame(
			[
				'sha1',
				'sha256',
			],
			$result['algos'],
		);
		$this->assertFalse( $result['locked'] );
	}


	public function testGenerateMissingHashesCollectsAndGenerates(): void
	{

		$userId = 'testuser';
		$algo = 'sha1';
		$userFolderPath = '/testuser/files';

		$this->filecacheService->expects( $this->once() )
		                       ->method( 'getUserFolderPath' )
		                       ->with( $userId )
		                       ->willReturn( $userFolderPath )
		;

		$folderMock = $this->createMock( \OCP\Files\Folder::class );
		$folderMock->method( 'get' )
		           ->with( '' )
		           ->willReturn( $folderMock )
		;
		$folderMock->method( 'getDirectoryListing' )
		           ->willReturn( [] )
		;

		$this->filecacheService->expects( $this->once() )
		                       ->method( 'getUserFolder' )
		                       ->with( $userId )
		                       ->willReturn( $folderMock )
		;

		$result = $this->service->generateMissingHashes( $userId, $algo );

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( 0, $result['skipped'] );
	}


	private function createCollectingServiceMock(): HashCalculationService&MockObject
	{

		return $this->getMockBuilder( HashCalculationService::class )
		            ->onlyMethods( [ 'recalcFileHash' ] )
		            ->setConstructorArgs(
			            [
				            $this->filecacheService,
				            $this->lockingProvider,
				            $this->metadataService,
				            $this->ruleService,
				            $this->logger,
			            ],
		            )
		            ->getMock()
		;
	}


	public function testGenerateMissingHashesProcessesFilesWithZeroBatchSize(): void
	{

		$userId         = 'testuser';
		$algo           = 'sha1';
		$userFolderPath = '/testuser/files';

		$this->filecacheService->method( 'getUserFolderPath' )
		                       ->with( $userId )
		                       ->willReturn( $userFolderPath )
		;

		$file = $this->createMock( \OCP\Files\File::class );
		$file->method( 'getChecksum' )
		     ->willReturn( '' )
		;
		$file->method( 'getPath' )
		     ->willReturn( $userFolderPath . '/a.txt' )
		;
		$file->method( 'getId' )
		     ->willReturn( 101 )
		;

		$folder = $this->createMock( \OCP\Files\Folder::class );
		$folder->method( 'get' )
		       ->with( '' )
		       ->willReturn( $folder )
		;
		$folder->method( 'getDirectoryListing' )
		       ->willReturn( [ $file ] )
		;

		$this->filecacheService->method( 'getUserFolder' )
		                       ->with( $userId )
		                       ->willReturn( $folder )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->once() )
		        ->method( 'recalcFileHash' )
		        ->with( $file, $algo )
		        ->willReturn(
			        [
				        'success' => true,
				        'algo'    => $algo,
				        'hash'    => 'abc',
				        'existed' => false,
			        ],
		        )
		;

		// 0 = unlimited; the command passes 0 when --batch-size is omitted.
		$result = $service->generateMissingHashes( $userId, $algo, null, 0 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 0, $result['skipped'] );
	}


	public function testGenerateMissingHashesSkipsAlreadyHashedFiles(): void
	{

		$userId         = 'testuser';
		$algo           = 'sha1';
		$userFolderPath = '/testuser/files';

		$this->filecacheService->method( 'getUserFolderPath' )
		                       ->with( $userId )
		                       ->willReturn( $userFolderPath )
		;

		$file = $this->createMock( \OCP\Files\File::class );
		$file->method( 'getChecksum' )
		     ->willReturn( 'SHA1:deadbeef' )
		;
		$file->method( 'getPath' )
		     ->willReturn( $userFolderPath . '/a.txt' )
		;

		$folder = $this->createMock( \OCP\Files\Folder::class );
		$folder->method( 'get' )
		       ->with( '' )
		       ->willReturn( $folder )
		;
		$folder->method( 'getDirectoryListing' )
		       ->willReturn( [ $file ] )
		;

		$this->filecacheService->method( 'getUserFolder' )
		                       ->with( $userId )
		                       ->willReturn( $folder )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->never() )
		        ->method( 'recalcFileHash' )
		;

		$result = $service->generateMissingHashes( $userId, $algo, null, 0 );

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( 0, $result['skipped'] );
	}


	public function testGenerateMissingHashesAppliesPathGlob(): void
	{

		$userId         = 'testuser';
		$algo           = 'sha1';
		$userFolderPath = '/testuser/files';

		$this->filecacheService->method( 'getUserFolderPath' )
		                       ->with( $userId )
		                       ->willReturn( $userFolderPath )
		;

		$pdf = $this->createMock( \OCP\Files\File::class );
		$pdf->method( 'getChecksum' )
		    ->willReturn( '' )
		;
		$pdf->method( 'getPath' )
		    ->willReturn( $userFolderPath . '/a.pdf' )
		;
		$pdf->method( 'getId' )
		    ->willReturn( 201 )
		;

		$txt = $this->createMock( \OCP\Files\File::class );
		$txt->method( 'getChecksum' )
		    ->willReturn( '' )
		;
		$txt->method( 'getPath' )
		    ->willReturn( $userFolderPath . '/b.txt' )
		;
		$txt->method( 'getId' )
		    ->willReturn( 202 )
		;

		$folder = $this->createMock( \OCP\Files\Folder::class );
		$folder->method( 'get' )
		       ->with( '' )
		       ->willReturn( $folder )
		;
		$folder->method( 'getDirectoryListing' )
		       ->willReturn( [ $pdf, $txt ] )
		;

		$this->filecacheService->method( 'getUserFolder' )
		                       ->with( $userId )
		                       ->willReturn( $folder )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->once() )
		        ->method( 'recalcFileHash' )
		        ->with( $pdf, $algo )
		        ->willReturn(
			        [
				        'success' => true,
				        'algo'    => $algo,
				        'hash'    => 'abc',
				        'existed' => false,
			        ],
		        )
		;

		$result = $service->generateMissingHashes( $userId, $algo, '*.pdf', 0 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 0, $result['skipped'] );
	}

}
