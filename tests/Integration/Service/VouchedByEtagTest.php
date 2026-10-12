<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Public\ChecksumApi;
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
 * A file's hashes are found while its stamp vouches for them: the ETag they
 * were read against is the filecache's, or, stamped before the app kept the
 * ETag, the stamp is no older than the storage's mtime. A change the app
 * did not hear of takes them out at once, and the walk does what the write
 * would have done.
 *
 * Against real files and the real tables; the filecache is changed by hand,
 * as a write the app missed changes it.
 */
class VouchedByEtagTest
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
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileWhoseEtagMovedOnLeavesTheLookupTheListingAndItsHashes(): void
	{
		$file = $this->hashed( 'fcias_vouch_moved', 'as hashed' );

		$this->assertTrue( $this->found( $file ), 'Found while its stamp vouches for it.' );

		$this->etagMovesOn( $file );

		$this->assertFalse( $this->found( $file ) );
	}

	/**
	 * A rule maintains the file: the walk takes its hashes out and queues it
	 * in the rule's mode, and the drain makes them current again, against
	 * the ETag the filecache holds now.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheWalkQueuesAChangedFileAndTheDrainBringsItBack(): void
	{
		$file = $this->hashed( 'fcias_vouch_queued', 'as hashed' );
		$this->ruleFor( $file );
		$this->etagMovesOn( $file );

		$this->assertContains( $file->getId(), $this->metadataService->fetchUnvouchedFileIds( $file->getId() - 1, 1 ) );

		$walked = $this->ruleService->queueChangedAfter( $file->getId() - 1, 1, static fn(): bool => false );

		$this->assertSame( 1, $walked['queued'] );
		$this->assertSame( MetadataService::PENDING_AUTO, $this->metadataService->getMarker( $file->getId() ) );
		$this->assertNotContains( $file->getId(), $this->metadataService->fetchUnvouchedFileIds( $file->getId() - 1, 1 ) );

		$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_AUTO );

		$this->assertTrue( $this->found( $file ), 'Computed again, against the ETag the filecache holds.' );
	}

	/**
	 * No rule maintains the file: its hashes are eroded, as a write would
	 * have left them.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheWalkErodesAChangedFileNoRuleMaintains(): void
	{
		$file = $this->hashed( 'fcias_vouch_eroded', 'as hashed' );
		$this->etagMovesOn( $file );

		$walked = $this->ruleService->queueChangedAfter( $file->getId() - 1, 1, static fn(): bool => false );

		$this->assertSame( 1, $walked['eroded'] );
		$this->assertSame( MetadataService::STATE_ERODED, $this->metadataService->getMarker( $file->getId() ) );
	}

	/**
	 * Stamped before the app kept the ETag: found while the stamp is no
	 * older than the storage's mtime, and not once it is.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testALegacyStampIsHeldToTheStoragesMtime(): void
	{
		$file = $this->hashed( 'fcias_vouch_legacy', 'as hashed' );

		$manager  = Server::get( IFilesMetadataManager::class );
		$document = $manager->getMetadata( $file->getId(), true );
		$stamp    = $document->getInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT );
		$document->unset( MetadataService::KEY_FILE_CHECKSUM_ETAG );
		$document->setInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $stamp, true );
		$manager->saveMetadata( $document );

		$this->assertTrue( $this->found( $file ), 'Its stamp is no older than the storage\'s mtime.' );

		$this->getRawConnection()
		     ->executeStatement(
			     'UPDATE `*PREFIX*filecache` SET `storage_mtime` = ? WHERE `fileid` = ?',
			     [ $stamp + 3600, $file->getId() ],
		     )
		;

		$this->assertFalse( $this->found( $file ) );
	}

	/**
	 * A file hashed in sha1 on demand, which stamps it with the ETag read.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function hashed(
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

		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha1' )['success'] );

		return $file;
	}

	/**
	 * The only rule: `auto`, sha1, for this one file.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function ruleFor( File $file ): void
	{
		$this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_INCLUDE,
				'path'     => $file->getName(),
				'mode'     => MetadataService::PENDING_MODE_AUTO,
				'selector' => '*',
				'algos'    => [ 'sha1' ],
			],
		);
	}

	/**
	 * What a write the app missed leaves: the filecache's ETag moved on.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function etagMovesOn( File $file ): void
	{
		$this->getRawConnection()
		     ->executeStatement(
			     'UPDATE `*PREFIX*filecache` SET `etag` = ? WHERE `fileid` = ?',
			     [ 'moved' . bin2hex( random_bytes( 4 ) ), $file->getId() ],
		     )
		;
	}

	/**
	 * Whether the lookup, the listing and the file's hashes all have the
	 * file's sha1 — asserted to agree, then returned.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function found( File $file ): bool
	{
		$api  = Server::get( ChecksumApi::class );
		$sha1 = sha1( (string) $file->getContent() );

		$lookup = in_array(
			$file->getId(),
			array_map( static fn( array $row ): int => (int) $row['fileid'], $api->findByHash( $sha1, null, 'sha1' )['results'] ),
			true,
		);

		$listed = false;

		foreach ( $api->iterateHashes( [ 'admin' ], 'sha1', null, false, $file->getId() - 1 ) as $fileId => $entry )
		{
			$listed = $fileId === $file->getId() && ( $entry['hashes']['sha1']['hash'] ?? null ) === $sha1;

			break;
		}

		$perFile = ( $api->getHashesByFileId( $file->getId(), null )['hashes']['sha1']['hash'] ?? null ) === $sha1;

		$this->assertSame( $lookup, $listed, 'The lookup and the listing agree.' );
		$this->assertSame( $lookup, $perFile, 'The lookup and the file\'s hashes agree.' );

		return $lookup;
	}
}
