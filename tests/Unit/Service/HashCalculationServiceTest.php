<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleOverrides;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Unit\EnglishL10n;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Storage\IStorage;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

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
	use EnglishL10n;


//  private properties

	private FilecacheService&MockObject $filecacheService;

	private ILockingProvider&MockObject $lockingProvider;

	private MetadataService&MockObject  $metadataService;

	private RuleService&MockObject      $ruleService;

	private LoggerInterface&MockObject  $logger;

	private AlgorithmCatalogue $catalogue;

	/** @var HashCalculationService&MockObject */
	private HashCalculationService $service;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->filecacheService = $this->createMock( FilecacheService::class );
		$this->lockingProvider  = $this->createMock( ILockingProvider::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->ruleService      = $this->createMock( RuleService::class );
		$this->logger           = $this->createMock( LoggerInterface::class );
		// Real catalogue over a mocked app config: [] stored, so the shipped
		// default list is in force — what an untouched instance has.
		$this->catalogue = new AlgorithmCatalogue( $this->createMock( IAppConfig::class ) );

		$this->service = $this->getMockBuilder( HashCalculationService::class )
		                      ->onlyMethods( [ 'recalcHashes' ] )
		                      ->setConstructorArgs(
			                      [
				                      $this->filecacheService,
				                      $this->lockingProvider,
				                      $this->metadataService,
				                      $this->ruleService,
				                      $this->logger,
					$this->catalogue,
					$this->englishL10n(),
			                      ],
		                      )
		                      ->getMock()
		;
	}


//  other non-static methods

	// isValidAlgo
	public function testIsValidAlgoAcceptsSupportedAlgo(): void
	{
		$this->assertTrue( $this->service->isValidAlgo( 'sha256' ) );
	}

	public function testIsValidAlgoRejectsUnsupportedString(): void
	{
		$this->assertFalse( $this->service->isValidAlgo( 'bogus' ) );
	}

	public function testIsValidAlgoRejectsNonString(): void
	{
		$this->assertFalse( $this->service->isValidAlgo( 42 ) );
		$this->assertFalse( $this->service->isValidAlgo( null ) );
		$this->assertFalse( $this->service->isValidAlgo( [ 'sha256' ] ) );
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
			                      function(
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
			                      function() use
			                      (
				                      &
				                      $order,
			                      ): bool
			                      {
				                      $order[] = 'save';

				                      return true;
			                      },
		                      )
		;
		$this->metadataService->method( 'clearComputedMarker' )
		                      ->willReturnCallback(
			                      function() use
			                      (
				                      &
				                      $order,
			                      ): bool
			                      {
				                      $order[] = 'clear';

				                      return false;
			                      },
		                      )
		;
		$this->lockingProvider->method( 'releaseLock' )
		                      ->willReturnCallback(
			                      function() use
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
					'clear',
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
	 * Regression: on local storage, a file hash_file() could not read made
	 * it return false, which met the string return type as a TypeError that
	 * named neither the file nor the reason. It is now the refusal an
	 * unreadable stream gets, and the algorithm's result says it failed.
	 */
	public function testAnUnreadableLocalFileFailsItsAlgorithmRatherThanTheCall(): void
	{
		$storage = $this->createMock( IStorage::class );
		$storage->method( 'isLocal' )
		        ->willReturn( true )
		;
		$storage->method( 'getLocalFile' )
		        ->willReturn( false )
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
		     ->willReturn( 'files/gone.txt' )
		;

		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'hasKey' )
		         ->willReturn( false )
		;

		$this->filecacheService->method( 'getChecksums' )
		                       ->willReturn( [] )
		;

		$result = $this->createRealService()
		               ->recalcHashes( $file, [ 'sha1' ], true, $metadata )
		;

		$this->assertFalse( $result['results']['sha1']['success'] );
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
	 * The md5 case: a hash computed once by hand, outside the rule's list,
	 * is dropped when the content changed, not kept and vouched for by the
	 * new stamp.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileAutoDropsOutdatedHashesItsRuleDoesNotName(): void
	{
		$metadata = $this->documentStamped(
			[
				'sha256' => 'old',
				'md5'    => 'old',
			],
			0,
		);

		$dropped = [];
		$metadata->method( 'unset' )
		         ->willReturnCallback(
			         function(
				         string $key,
			         ) use
			         (
				         &
				         $dropped,
				         $metadata,
			         ): IFilesMetadata
			         {
				         $dropped[] = $key;

				         return $metadata;
			         },
		         )
		;

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn(
			                  [
				                  'id'    => 'r1',
				                  'type'  => 'include',
				                  'mode'  => 'auto',
				                  'algos' => [ 'sha256' ],
			                  ],
		                  )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with( $this->anything(), [ 'sha256' ], true, $metadata )
		        ->willReturn(
			        [
				        'results' => [
					        'sha256' => [
						        'success' => true,
						        'hash'    => 'new',
						        'existed' => false,
					        ],
				        ],
				        'locked'  => false,
			        ],
		        )
		;

		$service->processFile( 42, 'auto' );

		$this->assertSame( [ MetadataService::getHashKey( 'md5' ) ], $dropped );
	}

	/**
	 * A hash computed by hand on a file that has not changed stays: the
	 * drop is for content the stamp no longer covers.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileKeepsACurrentHashItsRuleDoesNotName(): void
	{
		$metadata = $this->documentStamped(
			[
				'sha256' => 'current',
				'md5'    => 'current',
			],
			2000,
		);
		$metadata->expects( $this->never() )
		         ->method( 'unset' )
		;

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn(
			                  [
				                  'id'    => 'r1',
				                  'type'  => 'include',
				                  'mode'  => 'auto',
				                  'algos' => [ 'sha256' ],
			                  ],
		                  )
		;

		$service = $this->createCollectingServiceMock();
		$service->method( 'recalcHashes' )
		        ->willReturn(
			        [
				        'results' => [
					        'sha256' => [
						        'success' => true,
						        'hash'    => 'current',
						        'existed' => true,
					        ],
				        ],
				        'locked'  => false,
			        ],
		        )
		;

		$service->processFile( 42, 'auto' );
	}

	/**
	 * Outdated hashes with nothing left to recompute them are eroded, as a
	 * write to a file no rule maintains erodes them — not left behind with
	 * the mark dropped.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileErodesOutdatedHashesWhenNoIncludeRuleGoverns(): void
	{
		$this->documentStamped( [ 'sha1' => 'old' ], 0 );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( null )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'markEroded' )
		                      ->with( 42 )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
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

	/**
	 * Lazy computes nothing: the hashes go, saved with the marker cleared,
	 * and the file leaves the queue. Not through the save that asks whether
	 * the document is current, which a stamp of 0 never is: that kept every
	 * lazy file queued.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
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
		                      ->with( $metadata )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'saveMetadata' )
		;

		$this->service->expects( $this->never() )
		              ->method( 'recalcHashes' )
		;

		$this->assertTrue(
			$this->service->processFile(
				42,
				'lazy',
				[
					'sha1',
					'sha256',
				],
			),
		);
	}

	/**
	 * The file leaves the queue once what it was queued for is saved
	 * current, and not before: Nextcloud's save no longer clears the marker,
	 * and a save that is not current means a write landed meanwhile and
	 * queued it again.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileLeavesTheQueueWhenSavedCurrent(): void
	{
		$this->processAndSave( true );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileStaysQueuedWhenAWriteLandedMeanwhile(): void
	{
		$this->processAndSave( false );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function processAndSave( bool $current ): void
	{
		$metadata = $this->createMock( IFilesMetadata::class );
		$this->metadataService->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;
		$this->service->method( 'recalcHashes' )
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
		$this->metadataService->method( 'saveMetadata' )
		                      ->willReturn( $current )
		;

		$this->metadataService->expects( $current ? $this->once() : $this->never() )
		                      ->method( 'clearComputedMarker' )
		                      ->with( 42 )
		;

		// And says which, for the drains to count.
		$this->assertSame( $current, $this->service->processFile( 42, 'force', [ 'sha1' ] ) );
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
		         ->willReturnCallback( function(
			         string $key,
		         ) use
		         (
			         &
			         $written,
			         $metadata,
		         )
		         {
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
	 * A failing algorithm leaves the file queued with a failed attempt
	 * counted, in place of the re-mark that set the count back to 0; and
	 * processFile() says the file is still queued.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileFailureCountsAFailedAttempt(): void
	{
		$this->failOneOfTwoAlgos( attempts: 1, level: LogLevel::WARNING );
	}

	/**
	 * A file that keeps failing is retried on every pass of the drain, so
	 * only its first failure warns.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARetriedFailureLogsAtDebug(): void
	{
		$this->failOneOfTwoAlgos( attempts: 4, level: LogLevel::DEBUG );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function failOneOfTwoAlgos(
		int    $attempts,
		string $level,
	): void
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

		// The failed attempt counted for the mode it was made for; the row
		// is not rewritten.
		$this->metadataService->expects( $this->once() )
		                      ->method( 'recordFailedAttempt' )
		                      ->with( 42, MetadataService::PENDING_PREFIX . 'missing' )
		                      ->willReturn( $attempts )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'log' )
		             ->with( $level, $this->anything(), $this->anything() )
		;

		// saveMetadata NOT called (early return)
		$this->metadataService->expects( $this->never() )
		                      ->method( 'saveMetadata' )
		;

		$this->assertFalse(
			$this->service->processFile(
				42,
				'missing',
				[
					'sha1',
					'sha256',
				],
			),
		);
	}

	/**
	 * The mock here is the point. `getHashes()` answers `algo => hash`, and
	 * this test used to stub it with a plain list — so the assertion below
	 * passed while the production path fed the *hashes* to `recalcHashes()`
	 * as algorithm names and recomputed nothing.
	 */
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
				                      'sha1'   => 'aaa',
				                      'sha256' => 'bbb',
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
					$this->catalogue,
					$this->englishL10n(),
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
			$this->catalogue,
			$this->englishL10n(),
		);
	}

	/**
	 * File 42, mtime 1000, on local storage, holding $content in a temporary
	 * file that both read paths reach: `hash_file()` for one algorithm, the
	 * stream for several. The caller unlinks the path.
	 *
	 * @return array{0: File&MockObject, 1: string}
	 */
	private function streamedFile(
		string $content,
		string $etag = 'etag',
	): array
	{
		$path = (string) tempnam( sys_get_temp_dir(), 'fcias_test_' );
		file_put_contents( $path, $content );

		$storage = $this->createMock( IStorage::class );
		$storage->method( 'isLocal' )
		        ->willReturn( true )
		;
		$storage->method( 'getLocalFile' )
		        ->willReturn( $path )
		;

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getMTime' )
		     ->willReturn( 1000 )
		;
		$file->method( 'getEtag' )
		     ->willReturn( $etag )
		;
		$file->method( 'getStorage' )
		     ->willReturn( $storage )
		;
		$file->method( 'getInternalPath' )
		     ->willReturn( 'files/test.txt' )
		;
		$file->method( 'fopen' )
		     ->willReturnCallback(
			     static fn() => fopen( $path, 'rb' ),
		     )
		;

		return [
			$file,
			$path,
		];
	}

	/**
	 * File 42, mtime 1000, whose metadata document holds $hashes under
	 * $stamp: outdated below 1000, current from it on.
	 *
	 * @param  array<string, string>  $hashes
	 *
	 * @return IFilesMetadata&MockObject
	 */
	private function documentStamped(
		array $hashes,
		int   $stamp,
	): IFilesMetadata
	{
		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'hasKey' )
		         ->willReturnCallback(
			         static fn(
				         string $key,
			         ): bool => in_array(
				         $key,
				         array_map( MetadataService::getHashKey( ... ), array_keys( $hashes ) ),
				         true,
			         ),
		         )
		;
		$metadata->method( 'setString' )
		         ->willReturnSelf()
		;
		$metadata->method( 'setInt' )
		         ->willReturnSelf()
		;

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getMTime' )
		     ->willReturn( 1000 )
		;

		$this->metadataService->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;
		$this->metadataService->method( 'getHashes' )
		                      ->willReturn( $hashes )
		;
		$this->metadataService->method( 'getUpdatedAt' )
		                      ->willReturn( $stamp )
		;
		$this->filecacheService->method( 'getFile' )
		                       ->willReturn( $file )
		;

		return $metadata;
	}

	/**
	 * The call loads the document itself — an on-demand caller's — and is
	 * handed $metadata.
	 */
	private function ownTheDocument( IFilesMetadata $metadata ): void
	{
		$this->metadataService->method( 'ensureMetadata' )
		                      ->willReturnCallback(
			                      function(
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
			                      function(
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
		$this->assertStringContainsString( 'Algorithm not allowed on this server', $result['error'] ?? '' );
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
	 * Regression: only the queue stamped. A file hashed by `occ fcias:hash`,
	 * the sidebar or the API carried hashes and no stamp, counted as never
	 * hashed, and was hashed again by every `missing` run.
	 */
	public function testRecalcHashesStampsTheTimeTheReadBegan(): void
	{
		[ $file, $path ] = $this->streamedFile( 'hello world' );

		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'hasKey' )
		         ->willReturn( false )
		;
		$metadata->method( 'setString' )
		         ->willReturnSelf()
		;

		$stamps = [];
		$metadata->method( 'setInt' )
		         ->willReturnCallback(
			         function(
				         string $key,
				         int    $value,
			         ) use
			         (
				         &
				         $stamps,
				         $metadata,
			         ): IFilesMetadata
			         {
				         $stamps[ $key ] = $value;

				         return $metadata;
			         },
		         )
		;

		$this->filecacheService->method( 'getChecksums' )
		                       ->willReturn( [] )
		;

		$before = time();

		try
		{
			$result = $this->createRealService()
			               ->recalcHashes( $file, [ 'sha1' ], true, $metadata )
			;
		}
		finally
		{
			@unlink( $path );
		}

		$this->assertTrue( $result['results']['sha1']['success'] );
		$this->assertArrayHasKey( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $stamps );
		$this->assertGreaterThanOrEqual( $before, $stamps[ MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT ] );
		$this->assertLessThanOrEqual( time(), $stamps[ MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT ] );
	}

	/**
	 * The app's lock keeps out only the app: a write takes Nextcloud's own.
	 * A file whose etag moved while it was read gets nothing written, stamp
	 * included, and is reported busy, as a locked one is.
	 */
	public function testAResultIsDiscardedWhenTheFileIsWrittenToDuringTheRead(): void
	{
		[ $file, $path ] = $this->streamedFile( 'hello world', 'before' );

		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'hasKey' )
		         ->willReturn( false )
		;
		$metadata->expects( $this->never() )
		         ->method( 'setString' )
		;
		$metadata->expects( $this->never() )
		         ->method( 'setInt' )
		;

		$this->ownTheDocument( $metadata );
		$this->filecacheService->method( 'getChecksums' )
		                       ->willReturn( [] )
		;
		$this->filecacheService->method( 'etagOf' )
		                       ->with( 42 )
		                       ->willReturn( 'after' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'saveMetadata' )
		;

		try
		{
			$result = $this->createRealService()
			               ->recalcHashes( $file, [ 'sha1' ] )
			;
		}
		finally
		{
			@unlink( $path );
		}

		$this->assertTrue( $result['locked'] );
		$this->assertFalse( $result['results']['sha1']['success'] );
	}

	/**
	 * The sidebar asking for sha1 on a file queued after a change: the
	 * rule's sha256 is recomputed in the same read, and the md5 computed
	 * once by hand is dropped rather than vouched for by the new stamp.
	 */
	public function testAnOnDemandCalculationOfAQueuedFileDoesWhatTheQueueWouldBesides(): void
	{
		[ $file, $path ] = $this->streamedFile( 'new content' );

		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'hasKey' )
		         ->willReturnCallback(
			         static fn(
				         string $key,
			         ): bool => in_array(
				         $key,
				         [
					         MetadataService::getHashKey( 'sha256' ),
					         MetadataService::getHashKey( 'md5' ),
				         ],
				         true,
			         ),
		         )
		;
		$metadata->method( 'setString' )
		         ->willReturnSelf()
		;
		$metadata->method( 'setInt' )
		         ->willReturnSelf()
		;

		$dropped = [];
		$metadata->method( 'unset' )
		         ->willReturnCallback(
			         function(
				         string $key,
			         ) use
			         (
				         &
				         $dropped,
				         $metadata,
			         ): IFilesMetadata
			         {
				         $dropped[] = $key;

				         return $metadata;
			         },
		         )
		;

		$this->ownTheDocument( $metadata );
		$this->metadataService->method( 'getHashes' )
		                      ->willReturn(
			                      [
				                      'sha256' => 'old',
				                      'md5'    => 'old',
			                      ],
		                      )
		;
		$this->metadataService->method( 'getUpdatedAt' )
		                      ->willReturn( 0 )
		;
		$this->metadataService->method( 'getMarker' )
		                      ->with( 42 )
		                      ->willReturn( MetadataService::PENDING_AUTO )
		;
		$this->filecacheService->method( 'getChecksums' )
		                       ->willReturn( [] )
		;
		$this->filecacheService->method( 'getFile' )
		                       ->willReturn( $file )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->with( 42 )
		                  ->willReturn(
			                  [
				                  'id'    => 'r1',
				                  'type'  => 'include',
				                  'mode'  => 'auto',
				                  'algos' => [ 'sha256' ],
			                  ],
		                  )
		;

		try
		{
			$result = $this->createRealService()
			               ->recalcHashes( $file, [ 'sha1' ] )
			;
		}
		finally
		{
			@unlink( $path );
		}

		$this->assertSame( [ MetadataService::getHashKey( 'md5' ) ], $dropped );
		$this->assertTrue( $result['results']['sha1']['success'] );
		$this->assertSame( sha1( 'new content' ), $result['results']['sha1']['hash'] );
		$this->assertTrue( $result['results']['sha256']['success'] );
		$this->assertSame( hash( 'sha256', 'new content' ), $result['results']['sha256']['hash'] );
	}

	/**
	 * A file merely queued, its hashes current, keeps them: nothing is
	 * dropped, and the queue's algorithms are added to the one asked.
	 */
	public function testAnOnDemandCalculationOfAQueuedButCurrentFileDropsNothing(): void
	{
		[ $file, $path ] = $this->streamedFile( 'same content' );

		$metadata = $this->createMock( IFilesMetadata::class );
		$metadata->method( 'hasKey' )
		         ->willReturn( false )
		;
		$metadata->method( 'setString' )
		         ->willReturnSelf()
		;
		$metadata->method( 'setInt' )
		         ->willReturnSelf()
		;
		$metadata->expects( $this->never() )
		         ->method( 'unset' )
		;

		$this->ownTheDocument( $metadata );
		$this->metadataService->method( 'getHashes' )
		                      ->willReturn( [ 'md5' => 'current' ] )
		;
		$this->metadataService->method( 'getUpdatedAt' )
		                      ->willReturn( 2000 )
		;
		$this->metadataService->method( 'getMarker' )
		                      ->willReturn( MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_MISSING )
		;
		$this->filecacheService->method( 'getChecksums' )
		                       ->willReturn( [] )
		;
		$this->filecacheService->method( 'getFile' )
		                       ->willReturn( $file )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn(
			                  [
				                  'id'    => 'r1',
				                  'type'  => 'include',
				                  'mode'  => 'missing',
				                  'algos' => [ 'sha256' ],
			                  ],
		                  )
		;

		try
		{
			$result = $this->createRealService()
			               ->recalcHashes( $file, [ 'sha1' ] )
			;
		}
		finally
		{
			@unlink( $path );
		}

		$this->assertSame(
			[
				'sha1',
				'sha256',
			],
			array_keys( $result['results'] ),
		);
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
	 * Regression: a file whose hashing threw was reported through
	 * `$output->warning()`, which the plain console output `occ hash-files`
	 * hands over does not have — so one failing file ended the whole run
	 * with "Call to undefined method", and its own error was never shown.
	 * A real BufferedOutput, not a mock: a mock of OutputInterface would
	 * have accepted the call.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGenerateMissingHashesReportsAFailingFileAndCarriesOn(): void
	{
		$service = $this->collectingServiceOverOneFile(
			[
				'id'   => 'all',
				'type' => 'include',
			],
		);
		$service->method( 'recalcHashes' )
		        ->willThrowException( new RuntimeException( 'storage <gone>' ) )
		;
		$output = new BufferedOutput();

		$result = $service->generateMissingHashes( 'testuser', [ 'sha1' ], null, 0, $output );

		$this->assertSame( 0, $result['processed'] );
		$this->assertStringContainsString(
			'WARNING: recalcHashes failed for fileId 42: storage <gone>',
			$output->fetch(),
		);
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
	): HashCalculationService&MockObject
	{
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

	/**
	 * The algorithm list and the default are part of this app's contract:
	 * dropping one silently narrows what a stored rule can ask for, and
	 * changing the default changes what `--algo auto` computes.
	 *
	 * Here rather than in the integration suite, where these sat until the
	 * audit pointed out that asserting a constant does not need a booted
	 * server.
	 */
	public function testTheSupportedAlgorithmsIncludeTheOnesRulesRelyOn(): void
	{
		foreach (
			[
				'sha1',
				'md5',
				'sha256',
				'sha512',
			] as $algo
		)
		{
			$this->assertContains( $algo, MetadataService::LEGACY_ALGOS );
		}
	}

	public function testTheDefaultAlgorithmIsSha1(): void
	{
		$this->assertSame( 'sha1', $this->service->getDefaultAlgo() );
	}
}
