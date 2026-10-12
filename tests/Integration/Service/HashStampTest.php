<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Listener\MetadataListener;
use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCA\FileChecksumSearch\Service\DrainOutcome;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\Event\MetadataBackgroundEvent;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IAppConfig;
use OCP\Server;
use Throwable;

/**
 * Every hash this app saves carries a stamp, and a stamp vouches only for
 * hashes that describe the content as it is.
 *
 * Against real files and the real metadata tables: what is asserted is
 * what the next reader finds — the metadata document, its stamp, and the
 * index rows a lookup goes through.
 */
class HashStampTest
    extends
    DatabaseTestCase
{

//  private properties

	private HashCalculationService $hashCalc;

	private MetadataService        $metadataService;

	private RuleService            $ruleService;

	/** @var list<File> */
	private array $cleanupFiles = [];

	/** @var list<int> */
	private array $cleanupFileIds = [];


//  getters / setters / is* / has*

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->hashCalc        = Server::get( HashCalculationService::class );
		$this->metadataService = Server::get( MetadataService::class );
		$this->ruleService     = Server::get( RuleService::class );

		// The instance's rules are put back afterwards; the tests here need
		// to know which one governs their files.
		$this->preserveStoredRules();

		foreach ( $this->ruleService->loadRules() as $rule )
		{
			$this->ruleService->ruleDelete( (string) $rule['id'] );
		}
	}


//  other non-static methods

	protected function tearDown(): void
	{
		foreach ( $this->cleanupFiles as $file )
		{
			try
			{
				$file->delete();
			}
			catch ( Throwable )
			{
			}
		}

		foreach ( $this->cleanupFileIds as $fileId )
		{
			foreach ( [ 'files_metadata_index', 'files_metadata' ] as $table )
			{
				try
				{
					$this->getRawConnection()
					     ->executeStatement( "DELETE FROM `*PREFIX*$table` WHERE `file_id` = ?", [ $fileId ] )
					;
				}
				catch ( Throwable )
				{
				}
			}
		}

		parent::tearDown();
	}

	/**
	 * `occ fcias:hash` runs this. It saved hashes without a stamp, and every
	 * `missing` run after counted the file as never hashed.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testOccHashStampsWhatItComputes(): void
	{
		$this->rule( MetadataService::PENDING_MODE_MISSING, [ 'sha1' ] );

		$file   = $this->createTestFile( 'fcias_stamp_occ', 'stamped by occ' );
		$before = time();

		$this->hashCalc->generateMissingHashes( 'admin', [ 'sha1' ], $file->getName(), 0 );

		$this->assertSame( [ 'sha1' => sha1( 'stamped by occ' ) ], $this->hashesOf( $file->getId() ) );
		$this->assertStampedSince( $before, $file->getId() );
	}

	/**
	 * The sidebar, the REST route and `ChecksumApi` run this.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARecalculationOnDemandStampsWhatItComputes(): void
	{
		$file   = $this->createTestFile( 'fcias_stamp_demand', 'stamped on demand' );
		$before = time();

		$result = $this->hashCalc->recalcFileHash( $file, 'sha1' );

		$this->assertTrue( $result['success'] );
		$this->assertStampedSince( $before, $file->getId() );
	}

	/**
	 * The md5 case: computed once by hand beside the rule's sha256, then the
	 * content changes. The queue recomputes the sha256 and drops the md5,
	 * which no longer describes the file, from the document and the index.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAChangeUnderAutoDropsAHashItsRuleDoesNotName(): void
	{
		$this->rule( MetadataService::PENDING_MODE_AUTO, [ 'sha256' ] );

		$file = $this->hashedThenChanged( 'fcias_stamp_auto', [ 'sha256', 'md5' ], 'changed by auto' );

		$before = time();

		$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_AUTO );

		$this->assertSame(
			[ 'sha256' => hash( 'sha256', 'changed by auto' ) ],
			$this->hashesOf( $file->getId() ),
		);
		$this->assertSame( [ MetadataService::getHashKey( 'sha256' ) ], $this->hashRowsOf( $file->getId() ) );
		$this->assertStampedSince( $before, $file->getId() );
	}

	/**
	 * Asking for sha1 on a file queued after a change: the queue's sha256 is
	 * recomputed in the same read, the md5 dropped, and the file leaves the
	 * queue with the save.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnOnDemandRecalculationOfAQueuedFileDoesWhatTheQueueWould(): void
	{
		$this->rule( MetadataService::PENDING_MODE_AUTO, [ 'sha256' ] );

		$file = $this->hashedThenChanged( 'fcias_stamp_queued', [ 'sha256', 'md5' ], 'changed, then asked' );
		$this->metadataService->markPending( $file->getId(), MetadataService::PENDING_AUTO );

		$before = time();
		$result = $this->hashCalc->recalcFileHash( $this->fresh( $file ), 'sha1' );

		$this->assertTrue( $result['success'] );
		$this->assertSame(
			[
				'sha1'   => sha1( 'changed, then asked' ),
				'sha256' => hash( 'sha256', 'changed, then asked' ),
			],
			$this->hashesOf( $file->getId() ),
		);
		$this->assertSame(
			[
				MetadataService::getHashKey( 'sha1' ),
				MetadataService::getHashKey( 'sha256' ),
			],
			$this->hashRowsOf( $file->getId() ),
		);
		$this->assertStampedSince( $before, $file->getId() );
		$this->assertNull( $this->metadataService->getMarker( $file->getId() ), 'The file has left the queue.' );
	}

	/**
	 * A write takes the hashes out of the lookup, the listing and the
	 * per-file route at once, under `auto` and `missing` alike; the queue
	 * brings the new ones back to all three.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAWriteHidesTheHashesUntilTheQueueRecomputesThem(): void
	{
		foreach ( [ MetadataService::PENDING_MODE_AUTO, MetadataService::PENDING_MODE_MISSING ] as $mode )
		{
			$this->rule( $mode, [ 'sha256' ] );

			$file   = $this->createTestFile( 'fcias_stamp_hidden_' . $mode, 'before the write' );
			$before = hash( 'sha256', 'before the write' );
			$after  = hash( 'sha256', 'after the write' );

			$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha256' )['success'] );
			$this->assertSame( [ $before ], $this->seenBy( $file ), "$mode: listed before the write" );

			$file->putContent( 'after the write' );

			$this->assertSame( 0, $this->metadataService->getUpdatedAt( $file->getId() ), "$mode: the write set the stamp to 0" );
			$this->assertSame( [], $this->seenBy( $file ), "$mode: hidden after the write" );
			$this->assertSame(
				[],
				Server::get( ChecksumApi::class )->findByHash( $before, null, 'sha256' )['results'],
				"$mode: the old hash finds nothing",
			);

			$this->hashCalc->processFile( $file->getId(), $mode );

			$this->assertSame( [ $after ], $this->seenBy( $file ), "$mode: listed again once recomputed" );
		}
	}

	/**
	 * A sync client keeps the file's own mtime, which can be older than the
	 * stamp: the write hides the hashes all the same, since it sets the
	 * stamp to 0 rather than relying on `stamp < mtime`.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAWriteWithAnOlderMtimeIsHiddenToo(): void
	{
		$this->rule( MetadataService::PENDING_MODE_AUTO, [ 'sha256' ] );

		$file = $this->createTestFile( 'fcias_stamp_oldmtime', 'as uploaded first' );
		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha256' )['success'] );

		$file->putContent( 'edited offline' );
		$file->touch( time() - 86400 );

		$this->assertSame( [], $this->seenBy( $file ) );

		$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_AUTO );

		$this->assertSame( [ hash( 'sha256', 'edited offline' ) ], $this->seenBy( $file ) );
	}

	/**
	 * A change the write listener did not see — found by a scan, the stamp
	 * older than the mtime — reaches the rule sweep, which hides the hashes
	 * as it queues the file.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheRuleSweepHidesTheHashesOfAFileItFindsChanged(): void
	{
		$file = $this->createTestFile( 'fcias_stamp_sweep', 'seen by the sweep' );

		// Only this file: the sweep reads every storage the selector names.
		$this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_INCLUDE,
				'path'     => $file->getName(),
				'mode'     => MetadataService::PENDING_MODE_AUTO,
				'selector' => '*',
				'algos'    => [ 'sha256' ],
			],
		);

		$this->metadataService->writeHashes( $file->getId(), [ 'sha256' => hash( 'sha256', 'seen by the sweep' ) ], time(), false );
		$this->assertSame( 1, $this->metadataService->countByFileId( $file->getId() ) );

		// What a scan leaves: the content changed on disk, the stamp row
		// older than the mtime, no write event.
		$this->getRawConnection()
		     ->executeStatement(
			     'UPDATE `*PREFIX*files_metadata_index` SET `meta_value_int` = ? WHERE `file_id` = ? AND `meta_key` = ?',
			     [
				     $this->fresh( $file )->getMTime() - 60,
				     $file->getId(),
				     MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT,
			     ],
		     )
		;

		$this->ruleService->processRule( $this->ruleService->loadRules()[0] );

		$this->assertSame( 0, $this->metadataService->countByFileId( $file->getId() ) );
		$this->assertSame( MetadataService::PENDING_AUTO, $this->metadataService->getMarker( $file->getId() ) );
	}

	/**
	 * A file created under an `auto` rule is queued, and the queue gives it
	 * the rule's hashes: `auto` used to leave a new file to a metadata event
	 * that Nextcloud fires only where another app asks for one.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testANewFileUnderAutoIsQueuedAndHashed(): void
	{
		$this->rule( MetadataService::PENDING_MODE_AUTO, [ 'sha256' ] );

		$file = $this->createTestFile( 'fcias_auto_new', 'new under auto' );

		$this->assertSame( MetadataService::PENDING_AUTO, $this->metadataService->getMarker( $file->getId() ) );

		$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_AUTO );

		$this->assertSame( [ 'sha256' => hash( 'sha256', 'new under auto' ) ], $this->hashesOf( $file->getId() ) );
		$this->assertNull( $this->metadataService->getMarker( $file->getId() ), 'The file has left the queue.' );
	}

	/**
	 * An `auto` rule enabled after the fact reaches the files it now governs,
	 * through the sweep and through Reapply alike, and the queue hashes them.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnAutoRuleEnabledAfterTheFactHashesExistingFiles(): void
	{
		foreach ( [ 'sweep', 'reapply' ] as $route )
		{
			$file = $this->createTestFile( 'fcias_auto_after_' . $route, 'there before the rule: ' . $route );
			$rule = $this->ruleFor( $file, MetadataService::PENDING_MODE_AUTO );

			$this->assertNull( $this->metadataService->getMarker( $file->getId() ), "$route: nothing queued it before" );

			$route === 'sweep'
				? $this->ruleService->processRule( $rule )
				: $this->ruleService->applyRule( $rule );

			$this->assertSame( MetadataService::PENDING_AUTO, $this->metadataService->getMarker( $file->getId() ), "$route: queued" );

			$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_AUTO );

			$this->assertSame(
				[ 'sha256' => hash( 'sha256', 'there before the rule: ' . $route ) ],
				$this->hashesOf( $file->getId() ),
				"$route: hashed",
			);
		}
	}

	/**
	 * An algorithm added to a `missing` rule reaches a file hashed before,
	 * through the sweep and Reapply alike; under `auto` the file is left as
	 * it is, never read only to add an algorithm.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnAlgorithmAddedToAMissingRuleReachesTheFilesHashedBefore(): void
	{
		foreach ( [ 'sweep', 'reapply' ] as $route )
		{
			foreach ( [ MetadataService::PENDING_MODE_MISSING, MetadataService::PENDING_MODE_AUTO ] as $mode )
			{
				$content = "hashed before the rule grew: $route, $mode";
				$file    = $this->createTestFile( 'fcias_' . $mode . '_grown_' . $route, $content );

				$this->ruleFor( $file, $mode );
				$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha256' )['success'] );

				$rule = $this->ruleFor(
					$file,
					$mode,
					[
						'sha256',
						'md5',
					],
				);

				$route === 'sweep'
					? $this->ruleService->processRule( $rule )
					: $this->ruleService->applyRule( $rule );

				if ( $mode === MetadataService::PENDING_MODE_AUTO )
				{
					$this->assertNull( $this->metadataService->getMarker( $file->getId() ), "$route, auto: left as it is" );

					continue;
				}

				$this->assertSame( MetadataService::PENDING_PREFIX . $mode, $this->metadataService->getMarker( $file->getId() ), "$route, $mode: queued" );

				$this->hashCalc->processFile( $file->getId(), $mode );

				$this->assertSame(
					[
						'md5'    => md5( $content ),
						'sha256' => hash( 'sha256', $content ),
					],
					$this->hashesOf( $file->getId() ),
					"$route, $mode: the algorithm added",
				);
			}
		}
	}

	/**
	 * A file deleted on disk behind Nextcloud's back keeps its filecache row,
	 * and every read of it fails. The second failed read finds the storage
	 * without it, and the file leaves the queue as `stale:gone`, its hashes
	 * gone with it; the sweep and Reapply leave it alone; written again, it
	 * is queued as any written file is.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileGoneFromItsStorageLeavesTheQueueOnItsSecondFailedRead(): void
	{
		$file = $this->createTestFile( 'fcias_gone', 'there, then not' );
		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha256' )['success'] );

		// An algorithm the file lacks, so that the drain has to read it.
		$rule = $this->ruleFor(
			$file,
			MetadataService::PENDING_MODE_MISSING,
			[
				'sha256',
				'md5',
			],
		);
		$this->metadataService->markPending( $file->getId(), MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_MISSING );

		$path = $file->getStorage()->getLocalFile( $file->getInternalPath() );
		$this->assertIsString( $path );
		$this->assertTrue( unlink( $path ) );

		$this->assertSame(
			DrainOutcome::FAILED,
			$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_MISSING )->kind,
			'One failed read is not enough.',
		);
		$this->assertSame(
			DrainOutcome::GONE,
			$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_MISSING )->kind,
		);
		$this->assertSame( MetadataService::STATE_GONE, $this->metadataService->getMarker( $file->getId() ) );
		$this->assertSame( [], $this->hashesOf( $file->getId() ) );
		$this->assertSame( [], $this->hashRowsOf( $file->getId() ) );
		$this->assertSame( [], Server::get( FilecacheService::class )->checksumsOf( $file->getId() ), 'Its checksum column emptied too.' );

		Server::get( HashIndexService::class )->backfillFromFilecache();
		$this->assertSame( [], $this->hashRowsOf( $file->getId() ), 'The backfill copies nothing back.' );

		$this->ruleService->processRule( $rule );
		$this->ruleService->applyRule( $rule );

		$this->assertSame( MetadataService::STATE_GONE, $this->metadataService->getMarker( $file->getId() ), 'The sweep and Reapply leave it alone.' );

		$this->fresh( $file )->putContent( 'back again' );

		$this->assertSame(
			MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_MISSING,
			$this->metadataService->getMarker( $file->getId() ),
			'Written again, it is queued.',
		);
	}

	/**
	 * An algorithm the allowlist no longer has, still named by the rule, is
	 * left out: the file is hashed for the others and leaves the queue,
	 * rather than read and failed on it for ever.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARuleAlgorithmTheServerNoLongerComputesIsLeftOut(): void
	{
		$catalogue = Server::get( AlgorithmCatalogue::class );
		$appConfig = Server::get( IAppConfig::class );
		$effective = $catalogue->algorithms();
		$hadList   = $appConfig->hasKey( Application::APP_ID, AlgorithmCatalogue::CONFIG_KEY );
		$list      = $appConfig->getValueArray( Application::APP_ID, AlgorithmCatalogue::CONFIG_KEY, [] );
		$default   = $appConfig->getValueString( Application::APP_ID, AlgorithmCatalogue::DEFAULT_KEY, '' );

		$file = $this->createTestFile( 'fcias_allowlist', 'narrowed after the rule' );
		$this->ruleFor( $file, MetadataService::PENDING_MODE_MISSING, [ 'sha256', 'md5' ] );
		$catalogue->setAllowlist( [ 'sha256' ] );

		try
		{
			$this->metadataService->markPending( $file->getId(), MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_MISSING );

			$this->assertSame( DrainOutcome::HASHED, $this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_MISSING )->kind );
			$this->assertSame( [ 'sha256' => hash( 'sha256', 'narrowed after the rule' ) ], $this->hashesOf( $file->getId() ) );
			$this->assertNull( $this->metadataService->getMarker( $file->getId() ), 'The file has left the queue.' );
		}
		finally
		{
			$catalogue->setAllowlist( $effective );

			$hadList
				? $appConfig->setValueArray( Application::APP_ID, AlgorithmCatalogue::CONFIG_KEY, $list )
				: $appConfig->deleteKey( Application::APP_ID, AlgorithmCatalogue::CONFIG_KEY );
			$default !== ''
				? $appConfig->setValueString( Application::APP_ID, AlgorithmCatalogue::DEFAULT_KEY, $default )
				: $appConfig->deleteKey( Application::APP_ID, AlgorithmCatalogue::DEFAULT_KEY );
		}
	}

	/**
	 * The sweep leaves a queued file to the drain: marked again, it would
	 * count toward the sweep's cap, and files that keep failing would hold
	 * every other back.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheSweepLeavesAQueuedFileAlone(): void
	{
		$file = $this->createTestFile( 'fcias_sweep_queued', 'queued and failing' );
		$rule = $this->ruleFor( $file, MetadataService::PENDING_MODE_AUTO );

		$this->metadataService->recordFailedAttempt( $file->getId(), MetadataService::PENDING_AUTO );
		$this->metadataService->recordFailedAttempt( $file->getId(), MetadataService::PENDING_AUTO );

		$this->assertSame( 0, $this->ruleService->processRule( $rule )['marked'] );
		$this->assertSame( MetadataService::PENDING_AUTO, $this->metadataService->getMarker( $file->getId() ) );
	}

	/**
	 * `lazy` is low priority: a write empties the hashes at once, and the
	 * sweep queues the file to be hashed later, as `auto` — not as `lazy`,
	 * which would empty it again, and hash it never.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testALazyRuleHashesAtLowPriority(): void
	{
		$file = $this->createTestFile( 'fcias_lazy', 'lazy, not never' );
		$rule = $this->ruleFor( $file, MetadataService::PENDING_MODE_LAZY );
		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha256' )['success'] );

		$this->metadataService->markPending( $file->getId(), MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_LAZY );
		$this->assertSame( DrainOutcome::EMPTIED, $this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_LAZY )->kind );
		$this->assertSame( [], $this->hashesOf( $file->getId() ) );

		$this->ruleService->processRule( $rule );
		$this->assertSame( MetadataService::PENDING_AUTO, $this->metadataService->getMarker( $file->getId() ) );

		$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_AUTO );
		$this->assertSame( [ 'sha256' => hash( 'sha256', 'lazy, not never' ) ], $this->hashesOf( $file->getId() ) );
	}

	/**
	 * Nextcloud's metadata generation is no write: a revoked file stays
	 * revoked through it, and one never hashed is queued in its rule's
	 * mode.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testMetadataGenerationKeepsAFilesState(): void
	{
		$listener = Server::get( MetadataListener::class );
		$manager  = Server::get( IFilesMetadataManager::class );

		$revoked = $this->createTestFile( 'fcias_meta_revoked', 'revoked before the event' );
		$this->ruleFor( $revoked, MetadataService::PENDING_MODE_AUTO );
		$this->assertTrue( $this->hashCalc->recalcFileHash( $revoked, 'sha256' )['success'] );
		$this->metadataService->markPending( $revoked->getId(), MetadataService::STATE_REVOKED );

		$listener->handle( new MetadataBackgroundEvent( $revoked, $manager->getMetadata( $revoked->getId(), true ) ) );

		$this->assertSame( MetadataService::STATE_REVOKED, $this->metadataService->getMarker( $revoked->getId() ) );

		$fresh = $this->createTestFile( 'fcias_meta_fresh', 'never hashed' );
		$this->ruleFor( $fresh, MetadataService::PENDING_MODE_MISSING );

		$listener->handle( new MetadataBackgroundEvent( $fresh, $manager->getMetadata( $fresh->getId(), true ) ) );

		$this->assertSame(
			MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_MISSING,
			$this->metadataService->getMarker( $fresh->getId() ),
		);
	}

	/**
	 * A second row of one algorithm does not stand in for another's missing
	 * one: under `missing`, the sweep still finds the algorithm lacking.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testADuplicateRowOfOneAlgorithmHidesNoMissingOne(): void
	{
		$file = $this->createTestFile( 'fcias_sweep_held', 'one row twice' );
		$this->ruleFor( $file, MetadataService::PENDING_MODE_MISSING );
		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha256' )['success'] );
		$rule = $this->ruleFor( $file, MetadataService::PENDING_MODE_MISSING, [ 'sha256', 'md5' ] );

		$row = $this->getRawConnection()
		            ->fetchAssociative(
			            'SELECT `meta_value_string`, `meta_value_int` FROM `*PREFIX*files_metadata_index` WHERE `file_id` = ? AND `meta_key` = ?',
			            [ $file->getId(), MetadataService::getHashKey( 'sha256' ) ],
		            )
		;
		$this->assertIsArray( $row );
		$this->getRawConnection()
		     ->executeStatement(
			     'INSERT INTO `*PREFIX*files_metadata_index` (`file_id`, `meta_key`, `meta_value_string`, `meta_value_int`) VALUES (?, ?, ?, ?)',
			     [ $file->getId(), MetadataService::getHashKey( 'sha256' ), $row['meta_value_string'], $row['meta_value_int'] ],
		     )
		;

		$this->assertSame( 1, $this->ruleService->processRule( $rule )['marked'] );
	}

	/**
	 * A gone file put back under its old mtime — a remount, a restore — is
	 * one a scan takes for unchanged, its ETag kept: the daily walk asks the
	 * storage about each gone file, and queues one it has again.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheDailyWalkQueuesAGoneFileFoundAgain(): void
	{
		$file = $this->createTestFile( 'fcias_gone_back', 'there, then not, then back' );
		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha256' )['success'] );
		$this->ruleFor( $file, MetadataService::PENDING_MODE_MISSING, [ 'sha256', 'md5' ] );
		$this->metadataService->markPending( $file->getId(), MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_MISSING );

		$path  = $file->getStorage()->getLocalFile( $file->getInternalPath() );
		$mtime = $file->getMTime();
		$this->assertIsString( $path );
		$this->assertTrue( unlink( $path ) );

		$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_MISSING );
		$this->assertSame( DrainOutcome::GONE, $this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_MISSING )->kind );

		$walked = $this->ruleService->queueChangedAfter( $file->getId() - 1, 1, static fn(): bool => false );
		$this->assertSame( 0, $walked['queued'], 'Still not there: left gone.' );
		$this->assertSame( MetadataService::STATE_GONE, $this->metadataService->getMarker( $file->getId() ) );

		file_put_contents( $path, 'back, under its old mtime' );
		touch( $path, $mtime );

		$walked = $this->ruleService->queueChangedAfter( $file->getId() - 1, 1, static fn(): bool => false );

		$this->assertSame( 1, $walked['queued'] );
		$this->assertSame(
			MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_MISSING,
			$this->metadataService->getMarker( $file->getId() ),
		);
	}

	/**
	 * A gone mark carries the file's ETag, and the sweep leaves it while the
	 * ETag stays; one made before the mark carried it is tried once more.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAGoneMarkWithoutAnEtagIsTriedOnceMore(): void
	{
		$file = $this->createTestFile( 'fcias_gone_etag', 'there, then not' );
		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha256' )['success'] );
		$rule = $this->ruleFor( $file, MetadataService::PENDING_MODE_MISSING, [ 'sha256', 'md5' ] );
		$this->metadataService->markPending( $file->getId(), MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_MISSING );

		$path = $file->getStorage()->getLocalFile( $file->getInternalPath() );
		$this->assertIsString( $path );
		$this->assertTrue( unlink( $path ) );

		$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_MISSING );
		$this->assertSame( DrainOutcome::GONE, $this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_MISSING )->kind );

		$this->ruleService->processRule( $rule );
		$this->assertSame( MetadataService::STATE_GONE, $this->metadataService->getMarker( $file->getId() ), 'Its ETag stays: left alone.' );

		// What 0.25.0 left: the gone stamp without an ETag.
		$document = Server::get( IFilesMetadataManager::class )->getMetadata( $file->getId(), true );
		$this->metadataService->stamp( $document, $document->getInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT ), null );
		$this->metadataService->saveMetadata( $document );

		$this->ruleService->processRule( $rule );

		$this->assertSame(
			MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_MISSING,
			$this->metadataService->getMarker( $file->getId() ),
		);
	}

	/**
	 * A stamp over no hash, which `auto` once wrote for a file it computed
	 * nothing for, no longer passes for fresh: the sweep and Reapply queue
	 * the file again, and the queue hashes it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileStampedOverNoHashIsQueuedAgain(): void
	{
		foreach ( [ 'sweep', 'reapply' ] as $route )
		{
			$file = $this->createTestFile( 'fcias_auto_bare_' . $route, 'stamped over nothing: ' . $route );

			// What the old `auto` left: a stamp no earlier than the mtime, and
			// no hash, in the document and its index row.
			$metadata = $this->metadataService->getMetadata( $file->getId() );
			$metadata->setInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $this->fresh( $file )->getMTime() + 60, true );
			Server::get( IFilesMetadataManager::class )->saveMetadata( $metadata );

			$rule = $this->ruleFor( $file, MetadataService::PENDING_MODE_AUTO );

			$route === 'sweep'
				? $this->ruleService->processRule( $rule )
				: $this->ruleService->applyRule( $rule );

			$this->assertSame( MetadataService::PENDING_AUTO, $this->metadataService->getMarker( $file->getId() ), "$route: queued again" );

			$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_AUTO );

			$this->assertSame(
				[ 'sha256' => hash( 'sha256', 'stamped over nothing: ' . $route ) ],
				$this->hashesOf( $file->getId() ),
				"$route: hashed",
			);
		}
	}

	/**
	 * `rebuild-from-metadata` removes the rows of a document whose stamp no
	 * longer covers the file, and leaves a queued one alone.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheIndexRepairNeitherRefillsAQueuedFileNorKeepsAnOutdatedOnesRows(): void
	{
		$outdated = $this->createTestFile( 'fcias_stamp_rebuild_outdated', 'outdated' );
		$this->metadataService->writeHashes( $outdated->getId(), [ 'sha1' => sha1( 'outdated' ) ], time(), false );
		$outdated->touch( time() + 3600 );

		$this->assertSame( 1, $this->repairOnly( $outdated ), 'its rows are rewritten — as none' );
		$this->assertSame( 0, $this->metadataService->countByFileId( $outdated->getId() ) );

		$queued = $this->createTestFile( 'fcias_stamp_rebuild_queued', 'queued' );
		$this->metadataService->writeHashes( $queued->getId(), [ 'sha1' => sha1( 'queued' ) ], time(), false );
		$this->metadataService->hideHashes( $queued->getId() );
		$this->metadataService->markPending( $queued->getId(), MetadataService::PENDING_AUTO );

		$this->assertSame( 0, $this->repairOnly( $queued ), 'the queue is on its way' );
		$this->assertSame( 0, $this->metadataService->countByFileId( $queued->getId() ) );
	}

	/**
	 * Hashes a recalculation saved without a stamp, which the filecache
	 * still holds: they describe the content as of the file's mtime.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testHashesSavedWithoutAStampAreStampedWithTheMtimeWhereTheFilecacheHoldsThem(): void
	{
		$file = $this->savedWithoutAStamp( 'fcias_stamp_repair', 'stamped by the repair' );

		$outcome = $this->stampOnly( $file );

		$mtime = $this->fresh( $file )
		              ->getMTime()
		;

		$this->assertSame(
			[
				'current' => true,
				'stamp'   => $mtime,
				'hashes'  => [ 'sha1' => sha1( 'stamped by the repair' ) ],
			],
			$outcome,
			'What a verbose run reports of the file.',
		);
		$this->assertSame( $mtime, $this->stampOf( $file->getId() ) );
		$this->assertSame( [ MetadataService::getHashKey( 'sha1' ) ], $this->hashRowsOf( $file->getId() ) );
		$this->assertNull( $this->metadataService->getMarker( $file->getId() ) );
		$this->assertNotContains( $file->getId(), $this->metadataService->fetchUnstampedFileIds( $file->getId() - 1, 1 ) );
	}

	/**
	 * Where the filecache holds something else, nobody vouches for the
	 * hashes: stamped 0, out of the index, and queued to be computed again.
	 * The document keeps them, so the queue knows which the file had.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testHashesTheFilecacheDoesNotHoldAreHiddenAndQueued(): void
	{
		$file = $this->savedWithoutAStamp( 'fcias_stamp_hidden', 'hidden by the repair' );

		$this->getRawConnection()
		     ->executeStatement(
			     'UPDATE `*PREFIX*filecache` SET `checksum` = ? WHERE `fileid` = ?',
			     [
				     'SHA1:' . str_repeat( '0', 40 ),
				     $file->getId(),
			     ],
		     )
		;

		$this->assertFalse( $this->stampOnly( $file )['current'] );
		$this->assertSame( 0, $this->stampOf( $file->getId() ) );
		$this->assertSame( [], $this->hashRowsOf( $file->getId() ) );
		$this->assertSame( MetadataService::PENDING_AUTO, $this->metadataService->getMarker( $file->getId() ) );
		$this->assertSame( [ 'sha1' => sha1( 'hidden by the repair' ) ], $this->hashesOf( $file->getId() ) );
	}

	//  private methods
	/**
	 * The only rule while the test runs, for every file.
	 *
	 * @param  list<string>  $algos
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function rule(
		string $mode,
		array  $algos,
	): void
	{
		foreach ( $this->ruleService->loadRules() as $rule )
		{
			$this->ruleService->ruleDelete( (string) $rule['id'] );
		}

		$this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_INCLUDE,
				'path'     => '**',
				'mode'     => $mode,
				'selector' => '*',
				'algos'    => $algos,
			],
		);
	}

	/**
	 * The only rule: $mode, $algos, for this one file — the sweep reads every
	 * storage the selector names, and the instance's other files are not the
	 * test's to queue. Added after the file, so its creation queued nothing.
	 *
	 * @return array<string, mixed>  The rule as stored.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function ruleFor(
		File   $file,
		string $mode,
		array  $algos = [ 'sha256' ],
	): array
	{
		foreach ( $this->ruleService->loadRules() as $rule )
		{
			$this->ruleService->ruleDelete( (string) $rule['id'] );
		}

		$this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_INCLUDE,
				'path'     => $file->getName(),
				'mode'     => $mode,
				'selector' => '*',
				'algos'    => $algos,
			],
		);

		return $this->ruleService->loadRules()[0];
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function createTestFile(
		string $base,
		string $content,
	): File
	{
		$file = Server::get( IRootFolder::class )
		              ->getUserFolder( 'admin' )
		              ->newFile( $base . '_' . bin2hex( random_bytes( 4 ) ) . '.dat', $content )
		;

		$this->cleanupFiles[]   = $file;
		$this->cleanupFileIds[] = $file->getId();

		return $file;
	}

	/**
	 * A file hashed in $algos, its stamp put back a minute so that the write
	 * that follows lands after it whatever the clock's second, and then
	 * written with $content.
	 *
	 * @param  list<string>  $algos
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function hashedThenChanged(
		string $base,
		array  $algos,
		string $content,
	): File
	{
		$file = $this->createTestFile( $base, 'as it was' );

		foreach ( $algos as $algo )
		{
			$this->assertTrue( $this->hashCalc->recalcFileHash( $file, $algo )['success'] );
		}

		$metadata = $this->metadataService->getMetadata( $file->getId() );
		$metadata->setInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, time() - 60, true );
		$this->metadataService->saveMetadata( $metadata );

		$file->putContent( $content );

		return $file;
	}

	/**
	 * A file whose sha1 was saved the way a recalculation saved it before
	 * every calculation stamped: in the document, the filecache's column and
	 * the index, with no stamp anywhere.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function savedWithoutAStamp(
		string $base,
		string $content,
	): File
	{
		$file   = $this->createTestFile( $base, $content );
		$hashes = [ 'sha1' => sha1( $content ) ];

		// Each part as it was written then: saveMetadata() itself no longer
		// publishes hashes without a stamp.
		$metadata = $this->metadataService->getMetadata( $file->getId() );
		$metadata->setString( MetadataService::getHashKey( 'sha1' ), $hashes['sha1'], false );
		Server::get( IFilesMetadataManager::class )->saveMetadata( $metadata );
		Server::get( FilecacheService::class )->setHashes( $file->getId(), $hashes );
		$this->metadataService->syncHashIndex( $file->getId(), $hashes );

		$this->assertContains( $file->getId(), $this->metadataService->fetchUnstampedFileIds( $file->getId() - 1, 1 ) );

		return $file;
	}

	/**
	 * The hashes a lookup finds the file by, as the listing and the per-file
	 * route give them — asserted to agree, then returned.
	 *
	 * @return list<string>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function seenBy( File $file ): array
	{
		$api     = Server::get( ChecksumApi::class );
		$perFile = array_values( $api->getHashesByFileId( $file->getId(), null )['hashes'] );
		$listed  = [];

		foreach ( $api->iterateHashes( [ 'admin' ], null, null, false, $file->getId() - 1 ) as $fileId => $entry )
		{
			if ( $fileId === $file->getId() )
			{
				$listed = array_values( $entry['hashes'] );
			}

			break;
		}

		$this->assertSame( $perFile, $listed, 'The listing and the per-file route agree.' );

		return array_column( $perFile, 'hash' );
	}

	/**
	 * `rebuild-from-metadata`'s walk over this file alone: one page of one,
	 * from just before it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function repairOnly( File $file ): int
	{
		return $this->metadataService->reindexHashesAfter( $file->getId() - 1, 1, static fn (): bool => false )['fixed'];
	}

	/**
	 * Stamp this file and no other: one page of one, from just before it.
	 * The instance this runs against may hold other unstamped files, and
	 * they are not the test's to stamp.
	 *
	 * @return array{current: bool, stamp: int, hashes: array<string, string>}  What the stamping reported of it.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function stampOnly( File $file ): array
	{
		$outcomes = [];
		$result   = $this->metadataService->stampUnstampedAfter(
			$file->getId() - 1,
			1,
			static fn (): bool => false,
			static function( int $fileId, array $outcome ) use ( &$outcomes ): void
			{
				$outcomes[ $fileId ] = $outcome;
			},
		);

		$this->assertSame( 1, $result['stamped'] );
		$this->assertArrayHasKey( $file->getId(), $outcomes, 'The file is reported as it is stamped.' );

		return $outcomes[ $file->getId() ];
	}

	/**
	 * The stamp in the document, after checking that its index row carries
	 * the same.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function stampOf( int $fileId ): int
	{
		$document = $this->metadataService->getMetadata( $fileId )
		                                  ->getInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT )
		;

		$result = $this->getRawConnection()
		               ->executeQuery(
			               'SELECT `meta_value_int` FROM `*PREFIX*files_metadata_index` WHERE `file_id` = ? AND `meta_key` = ?',
			               [
				               $fileId,
				               MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT,
			               ],
		               )
		;

		$row = $result->fetchOne();
		$result->free();

		$this->assertSame( $document, (int) $row, 'The index row carries the document\'s stamp.' );

		return $document;
	}

	/**
	 * The file as the filecache holds it now: a node keeps what it was
	 * resolved with.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function fresh( File $file ): File
	{
		$node = Server::get( IRootFolder::class )
		              ->getUserFolder( 'admin' )
		              ->getFirstNodeById( $file->getId() )
		;

		$this->assertInstanceOf( File::class, $node );

		return $node;
	}

	/**
	 * The hashes the metadata document holds, by algorithm, sorted.
	 *
	 * @return array<string, string>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function hashesOf( int $fileId ): array
	{
		$hashes = $this->metadataService->getHashes( $this->metadataService->getMetadata( $fileId ) );
		ksort( $hashes );

		return $hashes;
	}

	/**
	 * The hash keys the index has rows for, sorted.
	 *
	 * @return list<string>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function hashRowsOf( int $fileId ): array
	{
		$result = $this->getRawConnection()
		               ->executeQuery(
			               'SELECT `meta_key` FROM `*PREFIX*files_metadata_index` WHERE `file_id` = ? AND `meta_key` LIKE ? ORDER BY `meta_key`',
			               [
				               $fileId,
				               MetadataService::KEY_FILE_CHECKSUM_HASH_PREFIX . '%',
			               ],
		               )
		;

		$keys = array_map( strval( ... ), $result->fetchFirstColumn() );
		$result->free();

		return $keys;
	}

	/**
	 * The stamp, in the document and in its index row alike, taken no
	 * earlier than $before and no later than now.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function assertStampedSince(
		int $before,
		int $fileId,
	): void
	{
		$document = $this->metadataService->getMetadata( $fileId )
		                                  ->getInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT )
		;

		$result = $this->getRawConnection()
		               ->executeQuery(
			               'SELECT `meta_value_int` FROM `*PREFIX*files_metadata_index` WHERE `file_id` = ? AND `meta_key` = ?',
			               [
				               $fileId,
				               MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT,
			               ],
		               )
		;

		$row = $result->fetchOne();
		$result->free();

		$this->assertGreaterThanOrEqual( $before, $document, 'The document carries the stamp.' );
		$this->assertLessThanOrEqual( time(), $document );
		$this->assertSame( $document, (int) $row, 'Its index row carries the same.' );
	}
}
