<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\Server;
use Throwable;

/**
 * A file's stamp carries the ETag its hashes were read against, in a row
 * this app owns: written by hashing, given by adoption where the filecache
 * vouches, kept through another app's save, and rebuilt from the document.
 *
 * Against real files and the real metadata tables, since what is asserted
 * is what Nextcloud's own save does to the row.
 */
class EtagStampTest
    extends
    DatabaseTestCase
{

//  private properties

	private HashCalculationService $hashCalc;

	private MetadataService        $metadataService;

	private FilecacheService       $filecacheService;

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

		$this->hashCalc         = Server::get( HashCalculationService::class );
		$this->metadataService  = Server::get( MetadataService::class );
		$this->filecacheService = Server::get( FilecacheService::class );
		$this->ruleService      = Server::get( RuleService::class );

		// No rule queues the files made here.
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
	 * Hashing writes down the ETag the read was against, in the document
	 * and in the stamp row, and saves the stamp unindexed.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testHashingStampsTheEtagTheReadWasAgainst(): void
	{
		$file = $this->createTestFile( 'fcias_etag_hash', 'read me' );

		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha1' )['success'] );

		$etag = $this->filecacheService->etagOf( $file->getId() );

		$this->assertNotNull( $etag );
		$this->assertSame( $etag, $this->documentEtag( $file ) );
		$this->assertSame( $etag, $this->stampRow( $file )[0] );
		$this->assertFalse( $this->stampIsIndexed( $file ), 'The stamp is saved unindexed.' );
	}

	/**
	 * Nextcloud rewrites an indexed key's row on any app's save; the stamp
	 * is not one any more, and its row keeps the ETag.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnotherAppsSaveLeavesTheEtagInTheRow(): void
	{
		$file = $this->createTestFile( 'fcias_etag_other_app', 'read me' );
		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha1' )['success'] );
		$row = $this->stampRow( $file );

		$this->anotherAppSaves( $file );

		$this->assertSame( $row, $this->stampRow( $file ) );
	}

	/**
	 * The filecache copy takes the filecache's ETag with the hashes its
	 * checksum column holds.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheFilecacheCopyTakesTheEtagBesideTheChecksums(): void
	{
		$file  = $this->createTestFile( 'fcias_etag_copy', 'copy me' );
		$state = $this->filecacheService->stateOf( $file->getId() );

		$this->assertSame(
			1,
			$this->metadataService->backfillHashes( $file->getId(), [ 'sha1' => sha1( 'copy me' ) ], $state['mtime'], $state['etag'] ),
		);
		$this->assertSame( $state['etag'], $this->documentEtag( $file ) );
		$this->assertSame( $state['etag'], $this->stampRow( $file )[0] );
	}

	/**
	 * Hashes saved without a stamp are given one by the filecache where its
	 * checksum column still holds them, with its ETag; where it does not,
	 * a stamp of 0 and no ETag.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testMissingStampsTakeTheEtagOnlyWhereTheFilecacheVouches(): void
	{
		$vouched = $this->savedWithoutAStamp( 'fcias_etag_vouched', 'vouched', true );
		$unknown = $this->savedWithoutAStamp( 'fcias_etag_unknown', 'unknown', false );

		$this->metadataService->stampUnstampedAfter( min( $vouched->getId(), $unknown->getId() ) - 1, 500 );

		$this->assertSame( $this->filecacheService->etagOf( $vouched->getId() ), $this->documentEtag( $vouched ) );
		$this->assertNull( $this->documentEtag( $unknown ) );
		$this->assertSame( [ null, 0 ], $this->stampRow( $unknown ) );
	}

	/**
	 * A stamp made before the app kept ETags gets the filecache's where its
	 * stamp is no older than the storage's mtime, and the document its
	 * stamp unindexed; read again, the file is not among those without one.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheRepairGivesALegacyStampItsEtag(): void
	{
		$file = $this->legacyHashed( 'fcias_etag_legacy', 'legacy' );

		$this->assertContains( $file->getId(), $this->metadataService->fetchEtaglessStampIds( $file->getId() - 1, 1 ) );

		$this->assertSame( MetadataService::ETAG_ADOPTED, $this->metadataService->adoptEtag( $file->getId() ) );

		$etag = $this->filecacheService->etagOf( $file->getId() );
		$this->assertSame( $etag, $this->documentEtag( $file ) );
		$this->assertSame( $etag, $this->stampRow( $file )[0] );
		$this->assertFalse( $this->stampIsIndexed( $file ) );
		$this->assertNotContains( $file->getId(), $this->metadataService->fetchEtaglessStampIds( $file->getId() - 1, 1 ) );
	}

	/**
	 * A legacy stamp older than the storage's own mtime, which the
	 * filecache's checksum column does not vouch for either: the hashes are
	 * outdated, and the file queued to have them computed again.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheRepairQueuesALegacyStampTheStorageOutdates(): void
	{
		$file = $this->legacyHashed( 'fcias_etag_outdated', 'outdated' );

		$this->getRawConnection()
		     ->executeStatement(
			     "UPDATE `*PREFIX*filecache` SET `storage_mtime` = `storage_mtime` + 3600, `checksum` = '' WHERE `fileid` = ?",
			     [ $file->getId() ],
		     )
		;

		$this->assertSame( MetadataService::ETAG_QUEUED, $this->metadataService->adoptEtag( $file->getId() ) );
		$this->assertSame( MetadataService::PENDING_AUTO, $this->metadataService->getMarker( $file->getId() ) );
		$this->assertSame( [ null, 0 ], $this->stampRow( $file ) );
	}

	/**
	 * A row that lost its ETag gets it back from the document.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAWipedRowIsRebuiltFromTheDocument(): void
	{
		$file = $this->createTestFile( 'fcias_etag_wiped', 'read me' );
		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha1' )['success'] );
		$row = $this->stampRow( $file );

		$this->getRawConnection()
		     ->executeStatement(
			     'UPDATE `*PREFIX*files_metadata_index` SET `meta_value_string` = NULL WHERE `file_id` = ? AND `meta_key` = ?',
			     [ $file->getId(), MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT ],
		     )
		;

		$this->assertContains( $file->getId(), $this->metadataService->fetchEtaglessStampIds( $file->getId() - 1, 1 ) );
		$this->metadataService->adoptEtag( $file->getId() );

		$this->assertSame( $row, $this->stampRow( $file ) );
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
	 * A file hashed, then put back as a release before the ETag left it: no
	 * ETag in its document, the stamp flagged indexed, and the row as
	 * Nextcloud writes it from that.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function legacyHashed(
		string $base,
		string $content,
	): File
	{
		$file = $this->createTestFile( $base, $content );
		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha1' )['success'] );

		$manager  = Server::get( IFilesMetadataManager::class );
		$document = $manager->getMetadata( $file->getId(), true );
		$stamp    = $document->getInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT );
		$document->unset( MetadataService::KEY_FILE_CHECKSUM_ETAG );
		$document->setInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $stamp, true );
		$manager->saveMetadata( $document );

		$this->assertTrue( $this->stampIsIndexed( $file ) );
		$this->assertNull( $this->stampRow( $file )[0], 'Nextcloud wrote the row without the ETag.' );

		return $file;
	}

	/**
	 * A file whose sha1 was saved without a stamp, the filecache's checksum
	 * column holding it, or not.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function savedWithoutAStamp(
		string $base,
		string $content,
		bool   $inTheFilecache,
	): File
	{
		$file   = $this->createTestFile( $base, $content );
		$hashes = [ 'sha1' => sha1( $content ) ];

		$metadata = $this->metadataService->getMetadata( $file->getId() );
		$metadata->setString( MetadataService::getHashKey( 'sha1' ), $hashes['sha1'], false );
		Server::get( IFilesMetadataManager::class )->saveMetadata( $metadata );
		$this->metadataService->syncHashIndex( $file->getId(), $hashes );

		$this->filecacheService->setHashes( $file->getId(), $inTheFilecache ? $hashes : [ 'sha1' => sha1( 'something else' ) ] );

		return $file;
	}

	private function anotherAppSaves( File $file ): void
	{
		$manager  = Server::get( IFilesMetadataManager::class );
		$document = $manager->getMetadata( $file->getId(), true );
		$document->setString( 'fcias-test-other-app', 'x', false );
		$manager->saveMetadata( $document );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function documentEtag( File $file ): ?string
	{
		return $this->metadataService->getEtag( $this->metadataService->getMetadata( $file->getId() ) );
	}

	/**
	 * The file's stamp row, as [ETag, stamp].
	 *
	 * @return array{0: ?string, 1: ?int}
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function stampRow( File $file ): array
	{
		$row = $this->getRawConnection()
		            ->fetchAssociative(
			            'SELECT `meta_value_string`, `meta_value_int` FROM `*PREFIX*files_metadata_index` WHERE `file_id` = ? AND `meta_key` = ?',
			            [ $file->getId(), MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT ],
		            )
		;

		$this->assertIsArray( $row, 'The file has a stamp row.' );

		return [
			$row['meta_value_string'] === null || $row['meta_value_string'] === ''
				? null
				: (string) $row['meta_value_string'],
			$row['meta_value_int'] === null ? null : (int) $row['meta_value_int'],
		];
	}

	/**
	 * Whether the file's metadata document flags the stamp indexed.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function stampIsIndexed( File $file ): bool
	{
		$json = $this->getRawConnection()
		             ->fetchOne( 'SELECT `json` FROM `*PREFIX*files_metadata` WHERE `file_id` = ?', [ $file->getId() ] )
		;

		return (bool) ( json_decode( (string) $json, true )[ MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT ]['indexed'] ?? false );
	}
}
