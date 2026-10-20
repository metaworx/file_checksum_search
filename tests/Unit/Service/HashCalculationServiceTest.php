<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use ArrayObject;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCA\FileChecksumSearch\Service\DrainOutcome;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\MimeTypeService;
use OCA\FileChecksumSearch\Service\RuleOverrides;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Unit\EnglishL10n;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Storage\IStorage;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

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


//  constants

	/** A rule that hashes its files, and their older versions, with sha1. */
	private const VERSIONS_RULE
		 = [
			'id'       => 'v',
			'type'     => 'include',
			'algos'    => [ 'sha1' ],
			'versions' => true,
		];

	/** A rule that hashes its files with sha1, and not their versions. */
	private const INCLUDE_RULE
		 = [
			'id'    => 'i',
			'type'  => 'include',
			'algos' => [ 'sha1' ],
		];

	/** The files {@see collectingServiceOverVersions()} gives versions 11 to 13. */
	private const VERSIONED_FILES
		 = [
			11 => 'a.txt',
			12 => 'Docs/b.txt',
			13 => 'c.txt',
		];


//  private properties

	private FilecacheService&MockObject $filecacheService;

	private ILockingProvider&MockObject $lockingProvider;

	private MetadataService&MockObject  $metadataService;

	private RuleService&MockObject      $ruleService;

	private LoggerInterface&MockObject  $logger;

	private AlgorithmCatalogue $catalogue;

	/** Detection off unless a test says otherwise: what an install has. */
	private MimeTypeService&MockObject  $mimeTypes;

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
		$this->mimeTypes = $this->createMock( MimeTypeService::class );

		// These tests stub a document's stamp and the file's mtime, not the
		// rule: the filecache holds the mtime of the File they hand out, and
		// freshness is MetadataService::isCurrent()'s for a stamp without an
		// ETag. A test of the ETag stubs both itself.
		$this->filecacheService->method( 'stateOf' )
		                       ->willReturnCallback(
			                       function(
				                       int $fileId,
			                       ): ?array
			                       {
				                       try
				                       {
					                       $mtime = $this->filecacheService->getFile( $fileId )
					                                                       ->getMTime()
					                       ;
				                       }
				                       catch ( \Throwable )
				                       {
					                       return null;
				                       }

				                       return [
					                       'etag'         => 'etag',
					                       'mtime'        => $mtime,
					                       'storageMtime' => $mtime,
				                       ];
			                       },
		                       )
		;
		$this->metadataService->method( 'isCurrent' )
		                      ->willReturnCallback(
			                      function(
				                      IFilesMetadata $metadata,
				                      ?array         $state,
			                      ): bool
			                      {
				                      $stamp = $this->metadataService->getUpdatedAt( $metadata );

				                      return $state !== null && $stamp !== null && $stamp > 0 && $stamp >= $state['mtime'];
			                      },
		                      )
		;

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
					$this->mimeTypes,
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
	 * A file on a storage Nextcloud holds unavailable, which would fail every
	 * read at once: its attempt is counted without the metadata, the lock or
	 * the read, and it stays queued.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileOnAStorageHeldDownIsCountedWithoutBeingRead(): void
	{
		$this->onAStorage( available: false, checkedSecondsAgo: 30 );

		$this->metadataService->expects( $this->never() )
		                      ->method( 'getMetadata' )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'recordFailedAttempt' )
		                      ->with( 42, 'pending:missing' )
		                      ->willReturn( 1 )
		;
		$this->logger->expects( $this->once() )
		             ->method( 'log' )
		             ->with( LogLevel::WARNING, $this->stringContains( 'unavailable' ), $this->anything() )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->never() )
		        ->method( 'recalcHashes' )
		;

		$this->assertEquals( DrainOutcome::failed( 'its storage is unavailable' ), $service->processFile( 42, 'missing' ) );
	}

	/**
	 * Past Nextcloud's own wait the read goes through, and Nextcloud's check
	 * of the storage with it: a flag nothing else touched would else stand
	 * for good.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAStorageHeldDownLongerThanNextcloudWaitsIsTriedAgain(): void
	{
		$this->onAStorage( available: false, checkedSecondsAgo: 601 );
		$this->metadataService->method( 'getMetadata' )
		                      ->willReturn( $this->createMock( IFilesMetadata::class ) )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'recordFailedAttempt' )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
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
	 * Lazy reads nothing, so a storage held down does not stop it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testALazyFileOnAStorageHeldDownGoesOn(): void
	{
		$this->onAStorage( available: false, checkedSecondsAgo: 30 );
		$this->metadataService->method( 'getMetadata' )
		                      ->willReturn( $this->createMock( IFilesMetadata::class ) )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearMetadata' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'recordFailedAttempt' )
		;

		$this->assertEquals( DrainOutcome::emptied(), $this->service->processFile( 42, 'lazy' ) );
	}

	/**
	 * File 42, governed by an include rule for sha256, on a storage whose
	 * availability Nextcloud recorded $checkedSecondsAgo.
	 */
	private function onAStorage(
		bool $available,
		int  $checkedSecondsAgo,
	): void
	{
		$storage = $this->createMock( IStorage::class );
		$storage->method( 'getAvailability' )
		        ->willReturn(
			        [
				        'available'    => $available,
				        'last_checked' => time() - $checkedSecondsAgo,
			        ],
		        )
		;
		$file = $this->createMock( File::class );
		$file->method( 'getStorage' )
		     ->willReturn( $storage )
		;
		$this->filecacheService->method( 'getFile' )
		                       ->willReturn( $file )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn(
			                  [
				                  'id'    => 'r1',
				                  'type'  => 'include',
				                  'algos' => [ 'sha256' ],
				                  'mode'  => 'missing',
			                  ],
		                  )
		;
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
	 * A version is read only where its rule asks for its file's versions:
	 * queued under one that does not — revoked, or found changed — it is
	 * dropped unread, as a file no include rule governs is.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileDropsAVersionItsRuleDoesNotHash(): void
	{
		$metadata = $this->createMock( IFilesMetadata::class );
		$this->metadataService->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;

		$this->filecacheService->method( 'getFile' )
		                       ->willReturn( $this->createMock( File::class ) )
		;
		$this->filecacheService->method( 'locate' )
		                       ->with( 42 )
		                       ->willReturn( FileLocation::fromRow( 42, 'home::alice', 'files_versions/a.txt.v1700000000', 1 ) )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'    => 'docs',
			                  'type'  => 'include',
			                  'algos' => [ 'sha1' ],
		                  ] )
		;

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
	 * A trashed file keeps what it had: queued — before its deletion, or
	 * found changed — it is dropped unread, whatever its place's rule says.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileDropsATrashedFile(): void
	{
		$this->metadataService->method( 'getMetadata' )
		                      ->willReturn( $this->createMock( IFilesMetadata::class ) )
		;
		$this->filecacheService->method( 'getFile' )
		                       ->willReturn( $this->createMock( File::class ) )
		;
		$this->filecacheService->method( 'locate' )
		                       ->with( 42 )
		                       ->willReturn( FileLocation::fromRow( 42, 'home::alice', 'files_trashbin/files/a.txt.d1700000000', 1 ) )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'    => 'docs',
			                  'type'  => 'include',
			                  'algos' => [ 'sha1' ],
		                  ] )
		;

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
	 * Where its place's rule hashes the trash, a trashed file is read as a
	 * file is, with the rule's algorithms.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileHashesATrashedFileWhereItsRuleHashesTheTrash(): void
	{
		$this->metadataService->method( 'getMetadata' )
		                      ->willReturn( $this->createMock( IFilesMetadata::class ) )
		;
		$this->filecacheService->method( 'getFile' )
		                       ->willReturn( $this->createMock( File::class ) )
		;
		$this->filecacheService->method( 'locate' )
		                       ->with( 42 )
		                       ->willReturn( FileLocation::fromRow( 42, 'home::alice', 'files_trashbin/files/a.txt.d1700000000', 1 ) )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'    => 'docs',
			                  'type'  => 'include',
			                  'algos' => [ 'sha1' ],
			                  'trash' => true,
		                  ] )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->willReturn( $this->oneSuccess( 'sha1' ) )
		;

		$service->processFile( 42, 'force' );
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
	/**
	 * A rule algorithm the server no longer computes is left out: the file
	 * is read for the others, rather than failed on that one for ever.
	 */
	public function testProcessFileLeavesOutAnAlgorithmTheServerDoesNotCompute(): void
	{
		$metadata = $this->documentStamped( [], 0 );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn(
			                  [
				                  'id'    => 'r1',
				                  'type'  => 'include',
				                  'mode'  => 'missing',
				                  'algos' => [ 'SHA256', 'nonesuch' ],
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

		$this->assertNotEquals(
			DrainOutcome::dropped( 'none of its rule\'s algorithms is computed on this server' ),
			$service->processFile( 42, 'missing' ),
		);
	}

	/**
	 * A batch whose every hash existed read nothing: the file is `stamped`,
	 * not `hashed`.
	 */
	public function testProcessFileSaysStampedWhereNothingWasRead(): void
	{
		$metadata = $this->documentStamped( [ 'sha256' => 'current' ], 2000 );

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
		$this->metadataService->method( 'saveMetadata' )
		                      ->willReturn( true )
		;

		$service = $this->createCollectingServiceMock();
		$service->method( 'recalcHashes' )
		        ->with( $this->anything(), [ 'sha256' ], true, $metadata )
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

		$this->assertEquals( DrainOutcome::stamped(), $service->processFile( 42, 'missing' ) );
	}

	/**
	 * A rule left with no algorithm the server computes hashes nothing: the
	 * file leaves the queue unread, as one no rule maintains does.
	 */
	public function testProcessFileDropsAFileWhoseRuleNamesNothingTheServerComputes(): void
	{
		$this->documentStamped( [], 0 );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn(
			                  [
				                  'id'    => 'r1',
				                  'type'  => 'include',
				                  'mode'  => 'auto',
				                  'algos' => [ 'nonesuch' ],
			                  ],
		                  )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, '' )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->never() )
		        ->method( 'recalcHashes' )
		;

		$this->assertEquals(
			DrainOutcome::dropped( 'none of its rule\'s algorithms is computed on this server' ),
			$service->processFile( 42, 'auto' ),
		);
	}

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
	 * The MIME type of content the stamp no longer covers goes too, with
	 * detection off as well: the stamp written after the read must not
	 * vouch for it. The read detects it again where detection is on.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileDropsTheTypeOfAnOutdatedDocument(): void
	{
		$this->documentStamped( [ 'sha256' => 'old' ], 0 );
		$types = $this->capturedTypes();

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
						        'hash'    => 'new',
						        'existed' => false,
					        ],
				        ],
				        'locked'  => false,
			        ],
		        )
		;

		$service->processFile( 42, 'auto' );

		$this->assertSame( [ null ], $types->getArrayCopy() );
	}

	/**
	 * `pending:mime` reads the first 64 KiB of a hashed file its stamp still
	 * covers, saves the type with the stamp untouched, and takes the file
	 * off the queue.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheMimeModeReadsTheTypeAloneAndTakesTheFileOffTheQueue(): void
	{
		[ $file, $path ] = $this->streamedFile( '%PDF-1.4' );
		$metadata        = $this->queuedForItsType( $file, 1000 );
		$types           = $this->capturedTypes();

		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( true )
		;
		$this->mimeTypes->method( 'detect' )
		                ->with( '%PDF-1.4' )
		                ->willReturn( 'application/pdf' )
		;
		$metadata->expects( $this->never() )
		         ->method( 'setInt' )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->willReturn( true )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearComputedMarker' )
		                      ->with( 42 )
		;

		try
		{
			$left = $this->createRealService()
			             ->processFile( 42, MetadataService::PENDING_MODE_MIME )
			;
		}
		finally
		{
			@unlink( $path );
		}

		$this->assertEquals( DrainOutcome::typed(), $left );
		$this->assertSame( [ 'application/pdf' ], $types->getArrayCopy() );
	}

	/** Off, what the mark asked for is not wanted any more: dropped unread. */
	public function testTheMimeModeDropsTheMarkUnreadWhileDetectionIsOff(): void
	{
		[ $file, $path ] = $this->streamedFile( '%PDF-1.4' );
		$this->queuedForItsType( $file, 1000 );

		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( false )
		;
		$this->mimeTypes->expects( $this->never() )
		                ->method( 'detect' )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, '' )
		;

		try
		{
			$this->assertEquals(
				DrainOutcome::dropped( 'MIME type detection is off' ),
				$this->createRealService()
				     ->processFile( 42, MetadataService::PENDING_MODE_MIME ),
			);
		}
		finally
		{
			@unlink( $path );
		}
	}

	/**
	 * Changed content is the hash modes' to read, which the write or the
	 * sweep queues the file for: the mark is dropped unread.
	 */
	public function testTheMimeModeDropsTheMarkOfAFileItsStampNoLongerCovers(): void
	{
		[ $file, $path ] = $this->streamedFile( '%PDF-1.4' );
		$this->queuedForItsType( $file, 999 );

		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( true )
		;
		$this->mimeTypes->expects( $this->never() )
		                ->method( 'detect' )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, '' )
		;

		try
		{
			$this->assertEquals(
				DrainOutcome::dropped( 'it has no current hashes, and the read for them detects the type' ),
				$this->createRealService()
				     ->processFile( 42, MetadataService::PENDING_MODE_MIME ),
			);
		}
		finally
		{
			@unlink( $path );
		}
	}

	/** A busy file is a failed attempt, as under a hash mode, and stays queued. */
	public function testTheMimeModeCountsABusyFileAsAFailedAttempt(): void
	{
		[ $file, $path ] = $this->streamedFile( '%PDF-1.4' );
		$this->queuedForItsType( $file, 1000 );

		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( true )
		;
		$this->lockingProvider->method( 'acquireLock' )
		                      ->willThrowException( new LockedException( 'files/42' ) )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'recordFailedAttempt' )
		                      ->with( 42, MetadataService::PENDING_MIME )
		                      ->willReturn( 1 )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'saveMetadata' )
		;

		try
		{
			$this->assertEquals(
				DrainOutcome::failed( 'busy' ),
				$this->createRealService()
				     ->processFile( 42, MetadataService::PENDING_MODE_MIME ),
			);
		}
		finally
		{
			@unlink( $path );
		}
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

		$this->assertEquals(
			DrainOutcome::emptied(),
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
		$this->assertEquals(
			$current
				? DrainOutcome::hashed()
				: DrainOutcome::failed( 'written to while it was hashed, and queued again' ),
			$this->service->processFile( 42, 'force', [ 'sha1' ] ),
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

		// Stamped through the one way a stamp is set, which saves it
		// unindexed; the ETag stays the one the read stamped.
		$this->metadataService->expects( $this->once() )
		                      ->method( 'stamp' )
		                      ->with( $metadata, $this->anything() )
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
	 * `auto` gives a file without a current hash, none yet or only outdated
	 * ones, every algorithm of its rule, since the file is read anyway; it
	 * never reads a file whose hashes are current only to add one.
	 *
	 * @param  array<string, string>  $hashes  The document's, under a stamp
	 *                                          of $stamp; the file's mtime is
	 *                                          1000.
	 * @param  list<string>           $computed
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	#[DataProvider( 'autoDocuments' )]
	public function testProcessFileAutoComputesTheRulesAlgorithmsForAFileWithoutACurrentHash(
		array $hashes,
		int   $stamp,
		array $computed,
	): void
	{
		$metadata = $this->documentStamped( $hashes, $stamp );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn(
			                  [
				                  'id'    => 'r1',
				                  'type'  => 'include',
				                  'mode'  => 'auto',
				                  'algos' => [
					                  'sha1',
					                  'sha256',
				                  ],
			                  ],
		                  )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with( $this->anything(), $computed, true, $metadata )
		        ->willReturn(
			        [
				        'results' => array_fill_keys(
					        $computed,
					        [
						        'success' => true,
						        'hash'    => 'new',
						        'existed' => false,
					        ],
				        ),
				        'locked'  => false,
			        ],
		        )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'saveMetadata' )
		                      ->with( $metadata )
		                      ->willReturn( true )
		;

		$this->assertEquals( DrainOutcome::hashed(), $service->processFile( 42, 'auto' ) );
	}

	/**
	 * A read that fails for a file its storage does not have leaves the queue
	 * as `stale:gone` on the second failed attempt, not the first: one
	 * negative answer from a remote storage can be a passing fault. A file
	 * the storage has stays queued, however often its read fails.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	#[DataProvider( 'goneReads' )]
	public function testAFileGoneFromItsStorageLeavesTheQueueOnItsSecondFailedRead(
		int    $absent,
		bool   $exists,
		bool   $rootAnswers,
		bool   $etagMoved,
		string $kind,
	): void
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
						              'success' => false,
						              'hash'    => '',
						              'existed' => false,
						              'error'   => 'Could not open the file for reading.',
						              'reason'  => 'Unable to open file for reading.',
					              ],
				              ],
				              'locked'  => false,
			              ],
		              )
		;
		// Failed attempts of every kind are counted apart, and decide nothing.
		$this->metadataService->method( 'recordFailedAttempt' )
		                      ->willReturn( 5 )
		;
		$this->metadataService->method( 'recordAbsent' )
		                      ->willReturn( $absent )
		;

		$storage = $this->createMock( IStorage::class );
		$storage->method( 'file_exists' )
		        ->willReturnCallback(
			        static fn(
				        string $path,
			        ): bool => $path === ''
				        ? $rootAnswers
				        : $exists,
		        )
		;
		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getMTime' )
		     ->willReturn( 1000 )
		;
		$file->method( 'getEtag' )
		     ->willReturn( 'read' )
		;
		$file->method( 'getInternalPath' )
		     ->willReturn( 'files/gone.txt' )
		;
		$file->method( 'getStorage' )
		     ->willReturn( $storage )
		;
		$this->filecacheService->method( 'getFile' )
		                       ->willReturn( $file )
		;
		$this->filecacheService->method( 'etagOf' )
		                       ->willReturn( $etagMoved ? 'written meanwhile' : 'read' )
		;

		$this->metadataService->expects( $exists || ! $rootAnswers ? $this->once() : $this->never() )
		                      ->method( 'forgetAbsent' )
		                      ->with( 42 )
		;
		$this->metadataService->expects( $kind === DrainOutcome::GONE ? $this->once() : $this->never() )
		                      ->method( 'markGone' )
		                      ->with( 42, $this->greaterThanOrEqual( 1000 ), 'read' )
		;

		$outcome = $this->service->processFile( 42, 'missing', [ 'sha1' ] );

		$this->assertSame( $kind, $outcome->kind );
		$this->assertSame( $kind === DrainOutcome::GONE, $outcome->hasLeftTheQueue() );
	}

	/**
	 * A current file holding none of the rule's algorithms: `auto` reads
	 * nothing to add one, and the drain says it had nothing to compute.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileAutoStampsACurrentFileItHasNothingToComputeFor(): void
	{
		$this->documentStamped( [ 'md5' => 'abc' ], 2000 );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn(
			                  [
				                  'id'    => 'r1',
				                  'type'  => 'include',
				                  'mode'  => 'auto',
				                  'algos' => [ 'sha1' ],
			                  ],
		                  )
		;
		$this->metadataService->method( 'saveMetadata' )
		                      ->willReturn( true )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->never() )
		        ->method( 'recalcHashes' )
		;

		$this->assertEquals( DrainOutcome::stamped(), $service->processFile( 42, 'auto' ) );
	}

	/**
	 * No include rule governs the file any more: its mark is dropped unread,
	 * and the drain says why.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testProcessFileDropsTheMarkOfAFileNoIncludeRuleGoverns(): void
	{
		$this->documentStamped( [], 0 );

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( null )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, '' )
		;

		$service = $this->createCollectingServiceMock();
		$service->expects( $this->never() )
		        ->method( 'recalcHashes' )
		;

		$this->assertEquals( DrainOutcome::dropped( 'no include rule governs it' ), $service->processFile( 42, 'auto' ) );
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

		// Stamped through the one way a stamp is set, which saves it
		// unindexed; the ETag stays the one the read stamped.
		$this->metadataService->expects( $this->once() )
		                      ->method( 'stamp' )
		                      ->with( $metadata, $this->anything() )
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

		// The algorithm's error is the reason the drain gives.
		$this->assertEquals(
			DrainOutcome::failed( 'hash failed' ),
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
					$this->mimeTypes,
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
			$this->mimeTypes,
		);
	}

	/**
	 * File 42, mtime 1000, on local storage, holding $content in a temporary
	 * file that both read paths reach: `hash_file()` for one algorithm, the
	 * stream for several. Not $local, its storage is remote, and one
	 * algorithm streams too. The caller unlinks the path.
	 *
	 * @return array{0: File&MockObject, 1: string}
	 */
	private function streamedFile(
		string $content,
		string $etag = 'etag',
		bool   $local = true,
	): array
	{
		$path = (string) tempnam( sys_get_temp_dir(), 'fcias_test_' );
		file_put_contents( $path, $content );

		$storage = $this->createMock( IStorage::class );
		$storage->method( 'isLocal' )
		        ->willReturn( $local )
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
	 * $file, queued `pending:mime` under an include rule, its document
	 * holding a sha1 under $stamp: current from the file's mtime of 1000.
	 *
	 * @return IFilesMetadata&MockObject
	 */
	private function queuedForItsType(
		File $file,
		int  $stamp,
	): IFilesMetadata
	{
		$metadata = $this->createMock( IFilesMetadata::class );

		$this->filecacheService->method( 'getFile' )
		                       ->willReturn( $file )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn(
			                  [
				                  'id'    => 'r1',
				                  'type'  => 'include',
				                  'mode'  => 'auto',
				                  'algos' => [ 'sha1' ],
			                  ],
		                  )
		;
		$this->metadataService->method( 'getMetadata' )
		                      ->willReturn( $metadata )
		;
		$this->metadataService->method( 'getHashes' )
		                      ->willReturn( [ 'sha1' => str_repeat( 'a', 40 ) ] )
		;
		$this->metadataService->method( 'getUpdatedAt' )
		                      ->willReturn( $stamp )
		;

		return $metadata;
	}

	/**
	 * A document holding nothing yet, which takes whatever is set on it.
	 *
	 * @return IFilesMetadata&MockObject
	 */
	private function blankDocument(): IFilesMetadata
	{
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

		$this->filecacheService->method( 'getChecksums' )
		                       ->willReturn( [] )
		;

		return $metadata;
	}

	/**
	 * Every MIME type put into the document, in order; null for one taken
	 * out.
	 *
	 * @return ArrayObject<int, string|null>
	 */
	private function capturedTypes(): ArrayObject
	{
		/** @var ArrayObject<int, string|null> $types */
		$types = new ArrayObject();

		$this->metadataService->method( 'setMimeType' )
		                      ->willReturnCallback(
			                      static function(
				                      IFilesMetadata $metadata,
				                      ?string        $mimeType,
			                      ) use
			                      (
				                      $types,
			                      ): void
			                      {
				                      $types[] = $mimeType;
			                      },
		                      )
		;

		return $types;
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
		$this->metadataService->method( 'stamp' )
		                      ->willReturnCallback(
			                      static function(
				                      IFilesMetadata $stamped,
				                      int            $time,
				                      mixed          $etag = null,
			                      ) use
			                      (
				                      &
				                      $stamps,
			                      ): void
			                      {
				                      $stamps[] = [ $time, $etag ];
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
		$this->assertCount( 1, $stamps );
		$this->assertGreaterThanOrEqual( $before, $stamps[0][0] );
		$this->assertLessThanOrEqual( time(), $stamps[0][0] );
		$this->assertSame( $file->getEtag(), $stamps[0][1], 'against the ETag the read began with' );
	}

	/**
	 * The read that hashes a file keeps its first 64 KiB, and the MIME type
	 * is detected from those and no more; the hashes are of the whole file.
	 * Each of the read paths ({@see readPaths()}).
	 *
	 * @param  list<string>  $algos
	 */
	#[DataProvider( 'readPaths' )]
	public function testTheReadThatHashesAFileDetectsItsTypeFromTheFirst64KiB(
		array $algos,
		bool  $local,
	): void
	{
		$content = str_repeat( 'a', MimeTypeService::HEAD_BYTES - 100 ) . str_repeat( 'b', 200_000 );

		[ $file, $path ] = $this->streamedFile( $content, 'etag', $local );
		$metadata        = $this->blankDocument();
		$types           = $this->capturedTypes();

		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( true )
		;
		$this->mimeTypes->expects( $this->once() )
		                ->method( 'detect' )
		                ->with( substr( $content, 0, MimeTypeService::HEAD_BYTES ) )
		                ->willReturn( 'application/x-test' )
		;

		try
		{
			$result = $this->createRealService()
			               ->recalcHashes( $file, $algos, true, $metadata )
			;
		}
		finally
		{
			@unlink( $path );
		}

		$this->assertSame( sha1( $content ), $result['results']['sha1']['hash'] );
		$this->assertSame( [ 'application/x-test' ], $types->getArrayCopy() );
	}

	/** A file under 64 KiB is its own head. */
	public function testAShortFileIsReadWholeForItsType(): void
	{
		[ $file, $path ] = $this->streamedFile( '%PDF-1.4' );
		$metadata        = $this->blankDocument();

		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( true )
		;
		$this->mimeTypes->expects( $this->once() )
		                ->method( 'detect' )
		                ->with( '%PDF-1.4' )
		                ->willReturn( 'application/pdf' )
		;

		try
		{
			$this->createRealService()
			     ->recalcHashes( $file, [ 'sha1', 'md5' ], true, $metadata )
			;
		}
		finally
		{
			@unlink( $path );
		}
	}

	/**
	 * Content read and no answer is no type: the type of the content as it
	 * was would be wrong.
	 */
	public function testContentLibmagicCannotNameTakesTheTypeAway(): void
	{
		[ $file, $path ] = $this->streamedFile( 'unnamed' );
		$metadata        = $this->blankDocument();
		$types           = $this->capturedTypes();

		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( true )
		;
		$this->mimeTypes->method( 'detect' )
		                ->willReturn( null )
		;

		try
		{
			$this->createRealService()
			     ->recalcHashes( $file, [ 'sha1' ], true, $metadata )
			;
		}
		finally
		{
			@unlink( $path );
		}

		$this->assertSame( [ null ], $types->getArrayCopy() );
	}

	/** Off, nothing is detected, and a type already stored is left alone. */
	public function testNoTypeIsDetectedWhileDetectionIsOff(): void
	{
		[ $file, $path ] = $this->streamedFile( 'hello world' );
		$metadata        = $this->blankDocument();

		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( false )
		;
		$this->mimeTypes->expects( $this->never() )
		                ->method( 'detect' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'setMimeType' )
		;

		try
		{
			$result = $this->createRealService()
			               ->recalcHashes( $file, [ 'sha1', 'md5' ], true, $metadata )
			;
		}
		finally
		{
			@unlink( $path );
		}

		$this->assertTrue( $result['results']['sha1']['success'] );
	}

	/**
	 * Every hash held and current, and no type: a file hashed before
	 * detection was on. The first 64 KiB are read for the type alone, and
	 * the hashes stand as they are.
	 */
	public function testACurrentDocumentWithoutATypeIsReadForItsTypeAlone(): void
	{
		[ $file, $path ] = $this->streamedFile( '%PDF-1.4' );
		$metadata        = $this->documentStamped( [ 'sha1' => sha1( '%PDF-1.4' ) ], 1000 );
		$metadata->method( 'getString' )
		         ->willReturn( sha1( '%PDF-1.4' ) )
		;
		$types = $this->capturedTypes();

		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( true )
		;
		$this->mimeTypes->method( 'detect' )
		                ->with( '%PDF-1.4' )
		                ->willReturn( 'application/pdf' )
		;
		$this->lockingProvider->expects( $this->once() )
		                      ->method( 'acquireLock' )
		;
		$this->lockingProvider->expects( $this->once() )
		                      ->method( 'releaseLock' )
		;
		// The caller's document: the caller saves it.
		$this->metadataService->expects( $this->never() )
		                      ->method( 'saveMetadata' )
		;

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

		$this->assertTrue( $result['results']['sha1']['existed'] );
		$this->assertFalse( $result['locked'] );
		$this->assertSame( [ 'application/pdf' ], $types->getArrayCopy() );
	}

	/** A file written to while its head was read keeps no type. */
	public function testATypeReadAloneIsDiscardedWhenTheFileChangedMeanwhile(): void
	{
		[ $file, $path ] = $this->streamedFile( '%PDF-1.4', 'before' );
		$metadata        = $this->documentStamped( [ 'sha1' => sha1( '%PDF-1.4' ) ], 1000 );
		$metadata->method( 'getString' )
		         ->willReturn( sha1( '%PDF-1.4' ) )
		;

		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( true )
		;
		$this->filecacheService->method( 'etagOf' )
		                       ->willReturn( 'after' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'setMimeType' )
		;

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

		$this->assertFalse( $result['locked'], 'Its hashes stand: nobody failed.' );
	}

	/** A document that has its type is not read again. */
	public function testACurrentDocumentWithItsTypeIsNotRead(): void
	{
		$metadata = $this->documentStamped( [ 'sha1' => sha1( 'x' ) ], 1000 );
		$metadata->method( 'getString' )
		         ->willReturn( sha1( 'x' ) )
		;

		$file = $this->createMock( File::class );
		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getMTime' )
		     ->willReturn( 1000 )
		;
		$file->expects( $this->never() )
		     ->method( 'fopen' )
		;

		$this->mimeTypes->method( 'isActive' )
		                ->willReturn( true )
		;
		$this->metadataService->method( 'getMimeType' )
		                      ->willReturn( 'application/pdf' )
		;

		$this->createRealService()
		     ->recalcHashes( $file, [ 'sha1' ], true, $metadata )
		;
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

	/**
	 * A collecting service over alice's home, one page of versions: each of
	 * $rulesByVersion's ids a version of its file in {@see VERSIONED_FILES},
	 * governed by its rule. $currentHashes are the hashes a version holds
	 * that its stamp vouches for; the files of $gone are not there.
	 *
	 * @param  array<int, array|null>                 $rulesByVersion
	 * @param  array<int, array<string, string>>      $currentHashes
	 * @param  list<int>                              $gone
	 */
	private function collectingServiceOverVersions(
		array $rulesByVersion,
		array $currentHashes = [],
		array $gone = [],
	): HashCalculationService&MockObject
	{
		$versions = [];
		$files    = [];

		foreach ( array_keys( $rulesByVersion ) as $versionId )
		{
			$path       = self::VERSIONED_FILES[ $versionId ];
			$versions[] = FileLocation::fromRow( $versionId, 'home::alice', 'files_versions/' . $path . '.v100', 100 );

			if ( ! in_array( $versionId, $gone, true ) )
			{
				$files[ $versionId ] = FileLocation::fromRow( $versionId * 10, 'home::alice', 'files/' . $path, 200 );
			}
		}

		$this->filecacheService->method( 'homeStorageNumericIds' )
		                       ->with( [ 'alice' ] )
		                       ->willReturn( [ 5 ] )
		;
		$this->filecacheService->method( 'pageStorageFiles' )
		                       ->willReturnCallback(
			                       static fn(
				                       int $storage,
				                       int $lastFileId,
			                       ): array => $lastFileId === 0
				                       ? $versions
				                       : [],
		                       )
		;
		$this->filecacheService->method( 'filesOfVersions' )
		                       ->willReturn( $files )
		;
		$this->ruleService->method( 'governingRuleForLocation' )
		                  ->willReturnCallback(
			                  static fn(
				                  FileLocation $location,
			                  ): ?array => $rulesByVersion[ $location->fileId ],
		                  )
		;
		$this->metadataService->method( 'fileStatesOf' )
		                      ->willReturnCallback(
			                      static fn(
				                      array $fileIds,
			                      ): array => array_map(
				                      static fn(
					                      int $fileId,
				                      ): array => [ 'vouched' => isset( $currentHashes[ $fileId ] ) ],
				                      array_combine( $fileIds, $fileIds ),
			                      ),
		                      )
		;
		$this->metadataService->method( 'listedHashes' )
		                      ->willReturn( $currentHashes )
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

	public function testVersionHashesTakeOnlyTheVersionsWhoseRuleSaysVersions(): void
	{
		$service = $this->collectingServiceOverVersions(
			[
				11 => self::VERSIONS_RULE,
				12 => self::INCLUDE_RULE,
			],
		);
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with( 11, [ 'sha1' ], true )
		        ->willReturn( $this->oneSuccess( 'sha1' ) )
		;

		$result = $service->generateVersionHashes( 'alice', [ 'auto' ], null, 0 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 0, $result['skipped'] );
	}

	public function testVersionHashesWithAllTakeEveryVersionTheRunMayButNotAnExcludedOne(): void
	{
		$service = $this->collectingServiceOverVersions(
			[
				11 => self::VERSIONS_RULE,
				12 => self::INCLUDE_RULE,
				13 => [
					'id'   => 'x',
					'type' => 'exclude',
				],
			],
		);
		$hashed  = [];
		$service->method( 'recalcHashes' )
		        ->willReturnCallback(
			        function( int $fileId ) use ( &$hashed ): array
			        {
				        $hashed[] = $fileId;

				        return $this->oneSuccess( 'sha1' );
			        },
		        )
		;

		$result = $service->generateVersionHashes( 'alice', [ 'auto' ], null, 0, null, null, 'missing', true );

		$this->assertSame( [ 11, 12 ], $hashed );
		$this->assertSame( 2, $result['processed'] );
	}

	public function testVersionHashesLeaveACurrentVersionUnderMissingAndNotUnderForce(): void
	{
		$service = $this->collectingServiceOverVersions(
			[ 11 => self::VERSIONS_RULE ],
			[ 11 => [ 'sha1' => 'abc' ] ],
		);
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with( 11, [ 'sha1' ], false )
		        ->willReturn( $this->oneSuccess( 'sha1' ) )
		;

		$this->assertSame( 0, $service->generateVersionHashes( 'alice', [ 'auto' ], null, 0 )['processed'] );
		$this->assertSame( 1, $service->generateVersionHashes( 'alice', [ 'auto' ], null, 0, null, null, 'force' )['processed'] );
	}

	public function testVersionHashesTakeAVersionThatLacksAnAlgorithm(): void
	{
		$service = $this->collectingServiceOverVersions(
			[ 11 => self::VERSIONS_RULE ],
			[ 11 => [ 'md5' => 'abc' ] ],
		);
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with( 11, [ 'sha1' ], true )
		        ->willReturn( $this->oneSuccess( 'sha1' ) )
		;

		$service->generateVersionHashes( 'alice', [ 'auto' ], null, 0 );
	}

	public function testVersionHashesMatchThePathGlobAgainstTheirFile(): void
	{
		$service = $this->collectingServiceOverVersions(
			[
				11 => self::VERSIONS_RULE,
				12 => self::VERSIONS_RULE,
			],
		);
		$service->expects( $this->once() )
		        ->method( 'recalcHashes' )
		        ->with( 12, [ 'sha1' ], true )
		        ->willReturn( $this->oneSuccess( 'sha1' ) )
		;

		$service->generateVersionHashes( 'alice', [ 'auto' ], 'Docs/*', 0 );
	}

	public function testVersionHashesStopAtTheBatchSize(): void
	{
		$service = $this->collectingServiceOverVersions(
			[
				11 => self::VERSIONS_RULE,
				12 => self::VERSIONS_RULE,
				13 => self::VERSIONS_RULE,
			],
		);
		$service->expects( $this->exactly( 2 ) )
		        ->method( 'recalcHashes' )
		        ->willReturn( $this->oneSuccess( 'sha1' ) )
		;

		$this->assertSame( 2, $service->generateVersionHashes( 'alice', [ 'auto' ], null, 2 )['processed'] );
	}

	public function testAVersionWhoseFileIsGoneIsLeft(): void
	{
		$service = $this->collectingServiceOverVersions( [ 11 => null ], [], [ 11 ] );
		$service->expects( $this->never() )
		        ->method( 'recalcHashes' )
		;

		$service->generateVersionHashes(
			'alice',
			[ 'sha1' ],
			null,
			0,
			null,
			new RuleOverrides( unmatched: RuleOverrides::UNMATCHED_INCLUDE ),
			'missing',
			true,
		);
	}

	public function testMarkVersionsQueuesOnlyTheVersionsWhoseRuleSaysVersions(): void
	{
		$service = $this->collectingServiceOverVersions(
			[
				11 => self::VERSIONS_RULE,
				12 => self::INCLUDE_RULE,
			],
		);
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 11, 'pending:force' )
		;

		$this->assertSame( 1, $service->markVersions( 'alice', null, 0, new RuleOverrides(), 'force' ) );
	}

	public function testMarkVersionsLeavesAQueuedVersion(): void
	{
		$service = $this->collectingServiceOverVersions(
			[
				11 => self::VERSIONS_RULE,
				12 => self::VERSIONS_RULE,
			],
		);
		$this->metadataService->method( 'queuedAmong' )
		                      ->willReturn( [ 11 => true ] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 12, 'pending:force' )
		;

		$this->assertSame( 1, $service->markVersions( 'alice', null, 0, new RuleOverrides(), 'force' ) );
	}

	public function testMarkVersionsLeavesACurrentVersionUnderMissing(): void
	{
		$service = $this->collectingServiceOverVersions(
			[
				11 => self::VERSIONS_RULE,
				12 => self::VERSIONS_RULE,
			],
			[ 11 => [ 'sha1' => 'abc' ] ],
		);
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 12, 'pending:missing' )
		;

		$this->assertSame( 1, $service->markVersions( 'alice', null, 0, new RuleOverrides(), 'missing' ) );
	}

	public function testMarkMissingHashesQueuesAFileThatLacksItsRulesAlgorithm(): void
	{
		$service = $this->collectingServiceOverOneFile( self::INCLUDE_RULE );
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, 'pending:missing' )
		;

		$this->assertSame(
			[
				'marked'  => 1,
				'skipped' => 0,
			],
			$service->markMissingHashes( 'testuser', null, 0, new RuleOverrides(), 'missing' ),
		);
	}

	/**
	 * The drain would compute nothing for a file holding its rule's
	 * algorithms, fresh: under `missing` it is not queued, under `force` it is.
	 */
	public function testMarkMissingHashesLeavesAFreshFileUnderMissingButNotUnderForce(): void
	{
		$service = $this->collectingServiceOverOneFile( self::INCLUDE_RULE, 'SHA1:aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d', 1000, 2000 );
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 42, 'pending:force' )
		;

		$this->assertSame( 0, $service->markMissingHashes( 'testuser', null, 0, new RuleOverrides(), 'missing' )['marked'] );
		$this->assertSame( 1, $service->markMissingHashes( 'testuser', null, 0, new RuleOverrides(), 'force' )['marked'] );
	}

	/**
	 * A queued file is left, under `force` too, and does not count toward
	 * the batch: else every run would queue the same first files again.
	 */
	public function testMarkMissingHashesLeavesAQueuedFile(): void
	{
		$service = $this->collectingServiceOverOneFile( self::INCLUDE_RULE );
		$this->metadataService->method( 'queuedAmong' )
		                      ->with( [ 42 ] )
		                      ->willReturn( [ 42 => true ] )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$this->assertSame( 0, $service->markMissingHashes( 'testuser', null, 0, new RuleOverrides(), 'force' )['marked'] );
	}

	/**
	 * A file a rule leaves is counted, and at -v named with the rule.
	 */
	public function testMarkMissingHashesCountsAndReportsAFileARuleLeaves(): void
	{
		$service = $this->collectingServiceOverOneFile(
			[
				'id'   => 'metered',
				'type' => 'exclude',
			],
		);
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;
		$output = new BufferedOutput( OutputInterface::VERBOSITY_VERBOSE );

		$this->assertSame(
			[
				'marked'  => 0,
				'skipped' => 1,
			],
			$service->markMissingHashes( 'testuser', null, 0, new RuleOverrides(), 'missing', $output ),
		);
		$this->assertStringContainsString( 'skip /testuser/files/a.txt [metered: band', $output->fetch() );
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


//  static methods

	/**
	 * One algorithm: `hash_file()` on local storage, and the head read apart.
	 * Two: the stream in 8 KiB chunks, the head kept from the first eight.
	 * On a remote storage, one algorithm streams too, the head taken first.
	 *
	 * @return array<string, array{0: list<string>, 1: bool}>
	 */
	public static function readPaths(): array
	{
		return [
			'one algorithm, local'  => [ [ 'sha1' ], true ],
			'two algorithms, local' => [ [ 'sha1', 'md5' ], true ],
			'one algorithm, remote' => [ [ 'sha1' ], false ],
		];
	}

	/**
	 * The storage's "not found" answers in a row, this one included; whether
	 * it has the file; whether its root answers; whether the file's ETag
	 * moved since the read; and what the drain makes of it.
	 *
	 * @return array<string, array{0: int, 1: bool, 2: bool, 3: bool, 4: string}>
	 */
	public static function goneReads(): array
	{
		return [
			'not found, the first time'          => [ 1, false, true, false, DrainOutcome::FAILED ],
			'not found, twice in a row'          => [ 2, false, true, false, DrainOutcome::GONE ],
			'there'                              => [ 2, true, true, false, DrainOutcome::FAILED ],
			'not found, and the root neither'    => [ 2, false, false, false, DrainOutcome::FAILED ],
			'not found twice, but written since' => [ 2, false, true, true, DrainOutcome::FAILED ],
		];
	}

	/**
	 * A document under `auto` with a rule of sha1 and sha256, the file's
	 * mtime 1000: its hashes, its stamp, and what the drain computes.
	 *
	 * @return array<string, array{0: array<string, string>, 1: int, 2: list<string>}>
	 */
	public static function autoDocuments(): array
	{
		return [
			'none yet'                  => [ [], 0, [ 'sha1', 'sha256' ] ],
			'a stamp over none'         => [ [], 2000, [ 'sha1', 'sha256' ] ],
			'outdated by a change'      => [ [ 'sha1' => 'old' ], 0, [ 'sha1', 'sha256' ] ],
			'current, with one of them' => [ [ 'sha1' => 'abc' ], 2000, [ 'sha1' ] ],
		];
	}
}
