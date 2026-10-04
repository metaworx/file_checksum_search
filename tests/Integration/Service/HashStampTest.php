<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\File;
use OCP\Files\IRootFolder;
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
	 * Hashes a recalculation saved without a stamp, which the filecache
	 * still holds: they describe the content as of the file's mtime.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testHashesSavedWithoutAStampAreStampedWithTheMtimeWhereTheFilecacheHoldsThem(): void
	{
		$file = $this->savedWithoutAStamp( 'fcias_stamp_repair', 'stamped by the repair' );

		$this->stampOnly( $file );

		$mtime = $this->fresh( $file )
		              ->getMTime()
		;

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

		$this->stampOnly( $file );

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
		$file = $this->createTestFile( $base, $content );

		$metadata = $this->metadataService->getMetadata( $file->getId() );
		$metadata->setString( MetadataService::getHashKey( 'sha1' ), sha1( $content ), false );
		$this->metadataService->saveMetadata( $metadata );

		$this->assertContains( $file->getId(), $this->metadataService->fetchUnstampedFileIds( $file->getId() - 1, 1 ) );

		return $file;
	}

	/**
	 * Stamp this file and no other: one page of one, from just before it.
	 * The instance this runs against may hold other unstamped files, and
	 * they are not the test's to stamp.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function stampOnly( File $file ): void
	{
		$result = $this->metadataService->stampUnstampedAfter(
			$file->getId() - 1,
			1,
			static fn (): bool => false,
		);

		$this->assertSame( 1, $result['stamped'] );
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
