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
use OCA\FileChecksumSearch\Service\RuleOverrides;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\Files\File;
use OCP\Files\Folder;
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
		                      ->onlyMethods( [ 'recalcHashes' ] )
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


	/**
	 * Canary for FCIAS Review §2, Finding 7: this list is mirrored by
	 * hand in src/algorithms.ts (SUPPORTED_ALGOS), with no automated
	 * cross-language check. If this test forces you to update it,
	 * update the TS mirror (and its own canary test in
	 * src/algorithms.spec.ts) in the same commit.
	 */
	public function testSupportedAlgosMatchesFrontendMirror(): void
	{

		$this->assertSame(
			[
				'sha1',
				'md5',
				'adler32',
				'crc32',
				'sha256',
				'sha512',
				'sha3-256',
				'sha3-512',
			],
			HashCalculationService::SUPPORTED_ALGOS,
		);
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
			                      ) use
			                      (
				                      $metadata,
			                      ): bool
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
			                      function () use
			                      (
				                      &
				                      $order,
			                      ): void
			                      {

				                      $order[] = 'save';
			                      },
		                      )
		;
		$this->lockingProvider->method( 'releaseLock' )
		                      ->willReturnCallback(
			                      function () use
			                      (
				                      &
				                      $order,
			                      ): void
			                      {

				                      $order[] = 'release';
			                      },
		                      )
		;

		try
		{
			$result = $this->createRealService()
			               ->recalcFileHash( $file, 'sha1' )
			;

			$this->assertTrue( $result['success'] );
			$this->assertSame(
				[
					'save',
					'release',
				],
				$order,
			);
		}
		finally
		{
			@unlink( $tmpFile );
		}
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileWithoutAlgosTakesThemFromTheGoverningRule(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );
		$this->metadataService->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;

		$file = $this->createMock( File::class );
		$file->method( 'getPath' )
		     ->willReturn( '/alice/files/a.txt' )
		;
		$this->filecacheService->method( 'getFile' )
		                       ->with( 42 )
		                       ->willReturn( $file )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'    => 'r1',
			                  'type'  => 'include',
			                  'algos' => [ 'sha256' ],
			                  'mode'  => 'auto',
		                  ] )
		;

		// The rule's list, not all eight: before this the drain hashed every
		// marked file with every supported algorithm.
		$service = $this->createCollectingServiceMock();
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with( $this->anything(), [ 'sha256' ], true, $metadata )
		        ->willReturn(
			        [
				        'results' => [
					        'sha256' => [
						        'success' => true,
						        'hash'    => 'abc',
						        'existed' => false,
					        ],
				        ],
				        'locked'  => false,
			        ],
		        )
		;

		$service->processFile( 42, 'missing' );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileDropsTheMarkWhenNoIncludeRuleGoverns(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );
		$this->metadataService->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;

		$file = $this->createMock( File::class );
		$file->method( 'getPath' )
		     ->willReturn( '/alice/files/metered/a.txt' )
		;
		$this->filecacheService->method( 'getFile' )
		                       ->willReturn( $file )
		;
		// Rules may change between mark and drain — verdicts are resolved at
		// action time, and an exclude that arrived in between wins.
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'   => 'metered',
			                  'type' => 'exclude',
		                  ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, '' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'saveMetadata' )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->never() )
		        ->method( 'recalcHashes' )
		;

		$service->processFile( 42, 'missing' );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileDropsTheMarkWhenNoRuleMatchesAtAll(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );
		$this->metadataService->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;

		$file = $this->createMock( File::class );
		$file->method( 'getPath' )
		     ->willReturn( '/alice/files/a.txt' )
		;
		$this->filecacheService->method( 'getFile' )
		                       ->willReturn( $file )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( null )
		;

		// Quiet start: unmatched means untouched — the background path no
		// longer invents "all eight algorithms" for a file nothing governs.
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, '' )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->never() )
		        ->method( 'recalcHashes' )
		;

		$service->processFile( 42, 'auto' );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileDropsAnUnknownModeInsteadOfLoopingIt(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );
		$this->metadataService->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;

		// A leftover 'pending:new' from before the quiet-start migration:
		// the mark is cleared (with a warning), never re-fetched forever.
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, '' )
		;
		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		;

		$this->service->processFile( 42, 'new', [ 'sha1' ] );
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
		              ->method( 'recalcHashes' )
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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

		$this->service->expects( $this->once() )
		              ->method( 'recalcHashes' )
		              ->with(
			              42,
			              [
				              'sha1',
				              'sha256',
			              ],
			              true,
			              $metadata,
		              )
		              ->willReturn(
			              [
				              'results' => [
					              'sha1'   => [
						              'success' => true,
						              'hash'    => 'abc',
						              'existed' => false,
					              ],
					              'sha256' => [
						              'success' => true,
						              'hash'    => 'def',
						              'existed' => false,
					              ],
				              ],
				              'locked'  => false,
			              ],
		              )
		;

		// The key, not merely the call count. Every setString expectation in
		// this class asserted how many times it was called and nothing about
		// what it wrote, which is how this method went on writing the
		// pre-rename spelling after the rename: the background job's hashes
		// landed under a name no reader looks for, and only a repair could
		// find them again.
		$written = [];

		$metadata->expects( $this->exactly( 2 ) )
		         ->method( 'setString' )
		         ->willReturnCallback( function (
			         string $key,
		         ) use
		         (
			         &
			         $written,
			         $metadata,
		         ) {

			         $written[] = $key;

			         return $metadata;
		         } )
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

		$this->assertSame(
			[
				MetadataService::getHashKey( 'sha1' ),
				MetadataService::getHashKey( 'sha256' ),
			],
			$written,
			'the hashes go under the keys the readers look for',
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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
		              ->method( 'recalcHashes' )
		              ->with( 42, [ 'sha1' ], true, $metadata )
		              ->willReturn(
			              [
				              'results' => [
					              'sha1' => [
						              'success' => true,
						              'hash'    => 'abc',
						              'existed' => false,
					              ],
				              ],
				              'locked'  => false,
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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

		$this->service->expects( $this->once() )
		              ->method( 'recalcHashes' )
		              ->with(
			              42,
			              [
				              'sha1',
				              'sha256',
			              ],
			              true,
			              $metadata,
		              )
		              ->willReturn(
			              [
				              'results' => [
					              'sha1'   => [
						              'success' => true,
						              'hash'    => 'abc',
						              'existed' => false,
					              ],
					              'sha256' => [
						              'success' => true,
						              'hash'    => 'def',
						              'existed' => false,
					              ],
				              ],
				              'locked'  => false,
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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

		// recalcHashes should not be called
		$this->service->expects( $this->never() )
		              ->method( 'recalcHashes' )
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


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileFailureMarksPending(): void
	{

		$metadata = $this->createMock( IFilesMetadata::class );

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getMetadata' )
		                      ->with( 42 )
		                      ->willReturn( $metadata )
		;

		// sha1 succeeds, sha256 fails
		$this->service->expects( $this->once() )
		              ->method( 'recalcHashes' )
		              ->with(
			              42,
			              [
				              'sha1',
				              'sha256',
			              ],
			              true,
			              $metadata,
		              )
		              ->willReturn(
			              [
				              'results' => [
					              'sha1'   => [
						              'success' => true,
						              'hash'    => 'abc',
						              'existed' => false,
					              ],
					              'sha256' => [
						              'success' => false,
						              'hash'    => '',
						              'existed' => false,
						              'error'   => 'hash failed',
					              ],
				              ],
				              'locked'  => false,
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
		$file   = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( $fileId )
		;

		$metadata = $this->createMock( IFilesMetadata::class );

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

		$this->service->expects( $this->once() )
		              ->method( 'recalcHashes' )
		              ->with(
			              $file,
			              [
				              'sha1',
				              'sha256',
			              ],
			              true,
			              $metadata,
		              )
		              ->willReturn(
			              [
				              'results' => [
					              'sha1'   => [
						              'success' => true,
						              'hash'    => 'aaa',
						              'existed' => false,
					              ],
					              'sha256' => [
						              'success' => true,
						              'hash'    => 'bbb',
						              'existed' => false,
					              ],
				              ],
				              'locked'  => false,
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

		$userId         = 'testuser';
		$algo           = 'sha1';
		$userFolderPath = '/testuser/files';

		$this->filecacheService->expects( $this->once() )
		                       ->method( 'getUserFolderPath' )
		                       ->with( $userId )
		                       ->willReturn( $userFolderPath )
		;

		$folderMock = $this->createMock( Folder::class );
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
		            ->onlyMethods( [ 'recalcHashes' ] )
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

		// The direct path now honours rule verdicts, so a file needs a rule
		// that says to hash it — as it always did under --mark.
		$this->ruleService->method( 'governingRulesForFileIds' )
		                  ->willReturnCallback(
			                  static fn(
				                  array $fileIds,
			                  ): array => array_fill_keys(
				                  $fileIds,
				                  [
					                  'id'   => 'r1',
					                  'type' => 'include',
				                  ],
			                  ),
		                  )
		;

		$userId         = 'testuser';
		$algo           = 'sha1';
		$userFolderPath = '/testuser/files';

		$this->filecacheService->method( 'getUserFolderPath' )
		                       ->with( $userId )
		                       ->willReturn( $userFolderPath )
		;

		$file = $this->createMock( File::class );
		$file->method( 'getChecksum' )
		     ->willReturn( '' )
		;
		$file->method( 'getPath' )
		     ->willReturn( $userFolderPath . '/a.txt' )
		;
		$file->method( 'getId' )
		     ->willReturn( 101 )
		;

		$folder = $this->createMock( Folder::class );
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
		        ->method( 'recalcHashes' )
		        ->with( $file, [ $algo ], true )
		        ->willReturn(
			        [
				        'results' => [
					        $algo => [
						        'success' => true,
						        'hash'    => 'abc',
						        'existed' => false,
					        ],
				        ],
				        'locked'  => false,
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

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getChecksum' )
		     ->willReturn( 'SHA1:deadbeef' )
		;
		$file->method( 'getPath' )
		     ->willReturn( $userFolderPath . '/a.txt' )
		;

		$folder = $this->createMock( Folder::class );
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
		        ->method( 'recalcHashes' )
		;

		$result = $service->generateMissingHashes( $userId, $algo, null, 0 );

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( 0, $result['skipped'] );
	}


	public function testGenerateMissingHashesAppliesPathGlob(): void
	{

		// The direct path now honours rule verdicts, so a file needs a rule
		// that says to hash it — as it always did under --mark.
		$this->ruleService->method( 'governingRulesForFileIds' )
		                  ->willReturnCallback(
			                  static fn(
				                  array $fileIds,
			                  ): array => array_fill_keys(
				                  $fileIds,
				                  [
					                  'id'   => 'r1',
					                  'type' => 'include',
				                  ],
			                  ),
		                  )
		;

		$userId         = 'testuser';
		$algo           = 'sha1';
		$userFolderPath = '/testuser/files';

		$this->filecacheService->method( 'getUserFolderPath' )
		                       ->with( $userId )
		                       ->willReturn( $userFolderPath )
		;

		$pdf = $this->createMock( File::class );
		$pdf->method( 'getChecksum' )
		    ->willReturn( '' )
		;
		$pdf->method( 'getPath' )
		    ->willReturn( $userFolderPath . '/a.pdf' )
		;
		$pdf->method( 'getId' )
		    ->willReturn( 201 )
		;

		$txt = $this->createMock( File::class );
		$txt->method( 'getChecksum' )
		    ->willReturn( '' )
		;
		$txt->method( 'getPath' )
		    ->willReturn( $userFolderPath . '/b.txt' )
		;
		$txt->method( 'getId' )
		    ->willReturn( 202 )
		;

		$folder = $this->createMock( Folder::class );
		$folder->method( 'get' )
		       ->with( '' )
		       ->willReturn( $folder )
		;
		$folder->method( 'getDirectoryListing' )
		       ->willReturn(
			       [
				       $pdf,
				       $txt,
			       ],
		       )
		;

		$this->filecacheService->method( 'getUserFolder' )
		                       ->with( $userId )
		                       ->willReturn( $folder )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with( $pdf, [ $algo ], true )
		        ->willReturn(
			        [
				        'results' => [
					        $algo => [
						        'success' => true,
						        'hash'    => 'abc',
						        'existed' => false,
					        ],
				        ],
				        'locked'  => false,
			        ],
		        )
		;

		$result = $service->generateMissingHashes( $userId, $algo, '*.pdf', 0 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 0, $result['skipped'] );
	}


	private function createRealService(): HashCalculationService
	{

		return new HashCalculationService(
			$this->filecacheService,
			$this->lockingProvider,
			$this->metadataService,
			$this->ruleService,
			$this->logger,
		);
	}


	public function testRecalcFileHashRejectsUnsupportedAlgo(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;

		$metadata = $this->createMock( IFilesMetadata::class );

		$this->metadataService->method( 'ensureMetadata' )
		                      ->willReturnCallback(
			                      function (
				                      $fileOrId,
				                      &$metadataRef,
			                      ) use
			                      (
				                      $metadata,
			                      ): bool
			                      {

				                      $metadataRef = $metadata;

				                      return false;
			                      },
		                      )
		;
		$this->filecacheService->method( 'getChecksums' )
		                       ->willReturn( [] )
		;

		$result = $this->createRealService()
		               ->recalcFileHash( $file, 'blake2b' )
		;

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Unsupported algorithm', $result['error'] ?? '' );
	}


	public function testRecalcHashesSkipsUpToDateAlgosWithoutLocking(): void
	{

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getMTime' )
		     ->willReturn( 1000 )
		;

		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'hasKey' )
		         ->willReturn( true )
		;
		$metadata->method( 'getString' )
		         ->willReturn( 'abc' )
		;

		$this->filecacheService->method( 'getChecksums' )
		                       ->willReturn( [] )
		;
		$this->metadataService->method( 'getUpdatedAt' )
		                      ->willReturn( 1000 )
		;
		$this->lockingProvider->expects( $this->never() )
		                      ->method( 'acquireLock' )
		;

		$result = $this->createRealService()
		               ->recalcHashes( $file, [ 'sha1' ], true, $metadata )
		;

		$this->assertFalse( $result['locked'] );
		$this->assertTrue( $result['results']['sha1']['success'] );
		$this->assertTrue( $result['results']['sha1']['existed'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGenerateMissingHashesSkipsFilesTheirRuleExcludes(): void
	{

		// Regression: the direct path used to hash every collected file
		// without ever asking for a verdict, so `occ generate` read storage an
		// exclude rule existed to keep it out of — while --mark, the same
		// command's other half, honoured the same rule.
		$service = $this->collectingServiceOverOneFile(
			[
				'id'   => 'metered',
				'type' => 'exclude',
			],
		);
		$service->expects( $this->never() )
		        ->method( 'recalcHashes' )
		;

		$result = $service->generateMissingHashes( 'testuser', [ 'sha1' ], null, 0 );

		$this->assertSame( 0, $result['processed'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGenerateMissingHashesSkipsAFileNoRuleMatches(): void
	{

		$service = $this->collectingServiceOverOneFile( null );
		$service->expects( $this->never() )
		        ->method( 'recalcHashes' )
		;

		$this->assertSame( 0, $service->generateMissingHashes( 'testuser', [ 'sha1' ], null, 0 )['processed'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGenerateMissingHashesProcessesAnIgnoredFileWhenAskedTo(): void
	{

		$service = $this->collectingServiceOverOneFile(
			[
				'id'   => 'quiet',
				'type' => 'ignore',
			],
		);
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->willReturn(
			        [
				        'results' => [
					        'sha1' => [
						        'success' => true,
						        'hash'    => 'abc',
						        'existed' => false,
					        ],
				        ],
				        'locked'  => false,
			        ],
		        )
		;

		$result = $service->generateMissingHashes(
			'testuser',
			[ 'sha1' ],
			null,
			0,
			null,
			new RuleOverrides( withIgnored: true ),
		);

		$this->assertSame( 1, $result['processed'] );
	}


	// ─── effective algorithm set / --mode semantics ─────────────────


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAutoTakesTheAlgorithmsFromTheGoverningRule(): void
	{

		$service = $this->collectingServiceOverOneFile(
			[
				'id'    => 'r1',
				'type'  => 'include',
				'algos' => [ 'sha256' ],
			],
		);
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with( $this->anything(), [ 'sha256' ], true )
		        ->willReturn( $this->oneSuccess( 'sha256' ) )
		;

		$result = $service->generateMissingHashes( 'testuser', [ 'auto' ], null, 0 );

		$this->assertSame( 1, $result['processed'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testExplicitAlgorithmsAreExclusiveOfTheRulesList(): void
	{

		// The rule says sha256; the operator said md5. Explicit means
		// exactly that — the rule's list is not consulted.
		$service = $this->collectingServiceOverOneFile(
			[
				'id'    => 'r1',
				'type'  => 'include',
				'algos' => [ 'sha256' ],
			],
		);
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with( $this->anything(), [ 'md5' ], true )
		        ->willReturn( $this->oneSuccess( 'md5' ) )
		;

		$service->generateMissingHashes( 'testuser', [ 'md5' ], null, 0 );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAutoPlusExplicitFormsTheUnion(): void
	{

		$service = $this->collectingServiceOverOneFile(
			[
				'id'    => 'r1',
				'type'  => 'include',
				'algos' => [ 'sha256' ],
			],
		);
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with(
			        $this->anything(),
			        [
				        'md5',
				        'sha256',
			        ],
			        true,
		        )
		        ->willReturn( $this->oneSuccess( 'sha256' ) )
		;

		$service->generateMissingHashes(
			'testuser',
			[
				'md5',
				'auto',
			],
			null,
			0,
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testMissingModeRefreshesAStaleFileThatHasEveryAlgorithm(): void
	{

		// The file carries the hash, but its content changed after it was
		// computed (updated_at < mtime). "Missing and outdated" is what the
		// missing mode means — presence alone is not done-ness.
		$service = $this->collectingServiceOverOneFile(
			[
				'id'    => 'r1',
				'type'  => 'include',
				'algos' => [ 'sha1' ],
			],
			checksum: 'SHA1:dead',
			mtime: 2000,
			updatedAt: 1000,
		);
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->willReturn( $this->oneSuccess( 'sha1' ) )
		;

		$service->generateMissingHashes( 'testuser', [ 'auto' ], null, 0 );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testMissingModeSkipsAFreshFullyHashedFile(): void
	{

		$service = $this->collectingServiceOverOneFile(
			[
				'id'    => 'r1',
				'type'  => 'include',
				'algos' => [ 'sha1' ],
			],
			checksum: 'SHA1:dead',
			updatedAt: 2000,
		);
		$service->expects( $this->never() )
		        ->method( 'recalcHashes' )
		;

		$result = $service->generateMissingHashes( 'testuser', [ 'auto' ], null, 0 );

		$this->assertSame( 0, $result['processed'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testForceModeRecomputesAFreshFullyHashedFile(): void
	{

		$service = $this->collectingServiceOverOneFile(
			[
				'id'    => 'r1',
				'type'  => 'include',
				'algos' => [ 'sha1' ],
			],
			checksum: 'SHA1:dead',
			updatedAt: 2000,
		);
		// skipExisting=false: force discards the freshness shortcut.
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with( $this->anything(), [ 'sha1' ], false )
		        ->willReturn( $this->oneSuccess( 'sha1' ) )
		;

		$service->generateMissingHashes( 'testuser', [ 'auto' ], null, 0, null, null, 'force' );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testUnmatchedOnlySkipsAMatchedIncludeFile(): void
	{

		// The inverse view: a file an include rule governs is out of scope.
		$service = $this->collectingServiceOverOneFile(
			[
				'id'    => 'r1',
				'type'  => 'include',
				'algos' => [ 'sha1' ],
			],
		);
		$service->expects( $this->never() )
		        ->method( 'recalcHashes' )
		;

		$result = $service->generateMissingHashes(
			'testuser',
			[ 'sha1' ],
			null,
			0,
			null,
			new RuleOverrides( unmatched: RuleOverrides::UNMATCHED_ONLY ),
		);

		$this->assertSame( 0, $result['processed'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testUnmatchedOnlyProcessesAFileNoRuleGoverns(): void
	{

		$service = $this->collectingServiceOverOneFile( null );
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with( $this->anything(), [ 'sha1' ], true )
		        ->willReturn( $this->oneSuccess( 'sha1' ) )
		;

		$result = $service->generateMissingHashes(
			'testuser',
			[ 'sha1' ],
			null,
			0,
			null,
			new RuleOverrides( unmatched: RuleOverrides::UNMATCHED_ONLY ),
		);

		$this->assertSame( 1, $result['processed'] );
	}


	/**
	 * @return array{results: array<string, array{success: bool, hash: string, existed: bool}>, locked: bool}
	 */
	private function oneSuccess( string $algo ): array
	{

		return [
			'results' => [
				$algo => [
					'success' => true,
					'hash'    => 'abc',
					'existed' => false,
				],
			],
			'locked'  => false,
		];
	}


	/**
	 * A collecting service over a single file governed by $rule.
	 *
	 * $checksum is the filecache checksum string (presence source), $mtime
	 * the file's modification time, $updatedAt what the metadata claims —
	 * together they steer the missing/stale/fresh decision.
	 */
	private function collectingServiceOverOneFile(
		?array $rule,
		string $checksum = '',
		int    $mtime = 1000,
		?int   $updatedAt = null,
	): HashCalculationService&MockObject {

		$userFolderPath = '/testuser/files';

		$this->filecacheService->method( 'getUserFolderPath' )
		                       ->willReturn( $userFolderPath )
		;

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getChecksum' )
		     ->willReturn( $checksum )
		;
		$file->method( 'getMTime' )
		     ->willReturn( $mtime )
		;
		$file->method( 'getPath' )
		     ->willReturn( $userFolderPath . '/a.txt' )
		;

		$folder = $this->createMock( Folder::class );
		$folder->method( 'get' )
		       ->willReturn( $folder )
		;
		$folder->method( 'getDirectoryListing' )
		       ->willReturn( [ $file ] )
		;
		$this->filecacheService->method( 'getUserFolder' )
		                       ->willReturn( $folder )
		;

		$this->ruleService->method( 'governingRulesForFileIds' )
		                  ->willReturnCallback(
			                  static fn(
				                  array $fileIds,
			                  ): array => array_fill_keys( $fileIds, $rule ),
		                  )
		;
		$this->metadataService->method( 'getUpdatedAt' )
		                      ->willReturn( $updatedAt )
		;

		return $this->createCollectingServiceMock();
	}


	public function testGenerateMissingHashesCollectsFileMissingAnyAlgo(): void
	{

		// The direct path now honours rule verdicts, so a file needs a rule
		// that says to hash it — as it always did under --mark.
		$this->ruleService->method( 'governingRulesForFileIds' )
		                  ->willReturnCallback(
			                  static fn(
				                  array $fileIds,
			                  ): array => array_fill_keys(
				                  $fileIds,
				                  [
					                  'id'   => 'r1',
					                  'type' => 'include',
				                  ],
			                  ),
		                  )
		;

		$userId         = 'testuser';
		$userFolderPath = '/testuser/files';

		$this->filecacheService->method( 'getUserFolderPath' )
		                       ->with( $userId )
		                       ->willReturn( $userFolderPath )
		;

		// File already has sha1 but not sha256.
		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getChecksum' )
		     ->willReturn( 'SHA1:deadbeef' )
		;
		$file->method( 'getPath' )
		     ->willReturn( $userFolderPath . '/a.txt' )
		;

		$folder = $this->createMock( Folder::class );
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
		        ->method( 'recalcHashes' )
		        ->with(
			        $file,
			        [
				        'sha1',
				        'sha256',
			        ],
			        true,
		        )
		        ->willReturn(
			        [
				        'results' => [
					        'sha1'   => [
						        'success' => true,
						        'hash'    => 'deadbeef',
						        'existed' => true,
					        ],
					        'sha256' => [
						        'success' => true,
						        'hash'    => 'abc',
						        'existed' => false,
					        ],
				        ],
				        'locked'  => false,
			        ],
		        )
		;

		$result = $service->generateMissingHashes(
			$userId,
			[
				'sha1',
				'sha256',
			],
			null,
			0,
		);

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 0, $result['skipped'] );
	}

}
