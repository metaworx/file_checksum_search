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
 * A file's marker — queued, disowned, eroded — survives another app's save
 * of the metadata document.
 *
 * Nextcloud rewrites the index rows of a document's indexed keys whenever
 * any app saves it, and the stamp row is one of them. A marker kept in its
 * string half was lost on the next save by Photos, blurhash or anything
 * else that writes metadata. Against the real tables, since what is
 * asserted is what Nextcloud's own save does.
 */
class MarkerRowTest
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
	 * The defect as it was found: a reset disowns a hash, another app saves
	 * the document, and the hash answered the lookup again. It stays out of
	 * the lookup and the listing, and the drain still clears it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testADisownedHashStaysOutOfTheLookupThroughAnotherAppsSave(): void
	{
		[ $file, $sha1 ] = $this->hashedFile( 'fcias_marker_reset' );

		$this->assertSame( [ $file->getId() ], $this->foundBy( $sha1 ), 'Found while it is vouched for.' );

		$this->metadataService->markStale( [ $file->getId() ] );
		$this->anotherAppSaves( $file );

		$this->assertSame( MetadataService::STATE_RESET, $this->metadataService->getMarker( $file->getId() ) );
		$this->assertSame( [], $this->foundBy( $sha1 ), 'The disowned hash stays out of the lookup.' );
		$this->assertFalse( $this->listed( $file ), 'And out of the listing.' );

		$this->ruleService->clearDisownedFiles( 1000 );

		$this->assertSame( [], $this->stateRowsOf( $file->getId() ), 'The drain cleared the marker.' );
		$this->assertSame( [], $this->metadataService->getHashes( $this->metadataService->getMetadata( $file->getId() ) ) );
	}

	/**
	 * A file queued by `force`, `lazy` or Nextcloud's background event has
	 * nothing else to bring it back to the queue: the rule sweep finds a
	 * changed file by its stamp, not these.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAQueuedFileStaysQueuedThroughAnotherAppsSave(): void
	{
		$this->rule( MetadataService::PENDING_MODE_MISSING, [ 'sha1' ] );

		$file = $this->createTestFile( 'fcias_marker_queued', 'queued ' . microtime( true ) );
		$this->metadataService->markPending( $file->getId(), MetadataService::PENDING_PREFIX . 'force' );
		$this->anotherAppSaves( $file );

		$this->assertSame( MetadataService::PENDING_PREFIX . 'force', $this->metadataService->getMarker( $file->getId() ) );

		$this->hashCalc->processFile( $file->getId(), 'force' );

		$this->assertSame( [], $this->stateRowsOf( $file->getId() ), 'The queue took it off once its hashes were saved.' );
		$this->assertSame( [ 'sha1' ], array_keys( $this->metadataService->getHashes( $this->metadataService->getMetadata( $file->getId() ) ) ) );
	}

	/**
	 * A state row exists only while there is a state; the stamp row stays.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testClearingAMarkerLeavesNoStateRow(): void
	{
		[ $file ] = $this->hashedFile( 'fcias_marker_clear' );

		$this->metadataService->markPending( $file->getId(), MetadataService::PENDING_AUTO );
		$this->metadataService->markPending( $file->getId(), MetadataService::PENDING_PREFIX . 'missing' );

		$this->assertSame( [ MetadataService::PENDING_PREFIX . 'missing' ], $this->stateRowsOf( $file->getId() ), 'Replaced, not added.' );

		$this->metadataService->markPending( $file->getId(), '' );

		$this->assertSame( [], $this->stateRowsOf( $file->getId() ) );
		$this->assertNotNull( $this->metadataService->getUpdatedAt( $file->getId() ), 'The stamp row stays.' );
	}

	/**
	 * Computing a file's hashes ends its erosion, as Nextcloud's save used to
	 * by the way; a disowned file stays disowned until the drain or an
	 * import replaces what it was disowned for.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnOnDemandCalculationEndsErosionAndNotDisowning(): void
	{
		[ $eroded ] = $this->hashedFile( 'fcias_marker_eroded' );
		$this->metadataService->markEroded( $eroded->getId() );

		$this->assertTrue( $this->hashCalc->recalcFileHash( $this->fresh( $eroded ), 'sha256' )['success'] );
		$this->assertNull( $this->metadataService->getMarker( $eroded->getId() ) );

		[ $disowned ] = $this->hashedFile( 'fcias_marker_disowned' );
		$this->metadataService->markStale( [ $disowned->getId() ] );

		$this->assertTrue( $this->hashCalc->recalcFileHash( $this->fresh( $disowned ), 'sha256' )['success'] );
		$this->assertSame( MetadataService::STATE_RESET, $this->metadataService->getMarker( $disowned->getId() ) );
	}

	/**
	 * The `marker-row` repair: a marker an earlier version left in the stamp
	 * row moves to a state row, unless the file has a newer one; the stamp
	 * rows are left with the stamp alone, and a second run finds nothing.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheRepairMovesTheMarkersOnceAndKeepsANewerState(): void
	{
		[ $legacy ] = $this->hashedFile( 'fcias_marker_legacy' );
		[ $newer ]  = $this->hashedFile( 'fcias_marker_newer' );

		$this->leaveInTheStampRow( $legacy->getId(), MetadataService::STATE_RESET );
		$this->leaveInTheStampRow( $newer->getId(), MetadataService::PENDING_AUTO );
		$this->metadataService->markEroded( $newer->getId() );

		$this->assertGreaterThanOrEqual( 1, $this->metadataService->moveMarkersToStateRows() );

		$this->assertSame( [ MetadataService::STATE_RESET ], $this->stateRowsOf( $legacy->getId() ) );
		$this->assertSame( [ MetadataService::STATE_ERODED ], $this->stateRowsOf( $newer->getId() ), 'The newer state stays.' );
		$this->assertNull( $this->stampStringOf( $legacy->getId() ) );
		$this->assertNull( $this->stampStringOf( $newer->getId() ) );

		$this->assertSame( 0, $this->metadataService->moveMarkersToStateRows(), 'Nothing left to move.' );
	}

	/**
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
	 * A file of content no other file has, hashed in sha1 and stamped.
	 *
	 * @return array{File, string}  The file and its sha1.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function hashedFile( string $base ): array
	{
		$content = $base . ' ' . bin2hex( random_bytes( 8 ) );
		$file    = $this->createTestFile( $base, $content );

		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha1' )['success'] );

		return [
			$file,
			sha1( $content ),
		];
	}

	/**
	 * Another app writes a key of its own to the document and saves it, as
	 * Photos or blurhash do.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function anotherAppSaves( File $file ): void
	{
		$manager  = Server::get( IFilesMetadataManager::class );
		$document = $manager->getMetadata( $file->getId(), true );
		$document->setString( 'fcias-test-other-app', 'x', false );
		$manager->saveMetadata( $document );
	}

	/**
	 * The files a lookup by this sha1 finds.
	 *
	 * @return list<int>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function foundBy( string $sha1 ): array
	{
		return array_map(
			static fn( array $result ): int => (int) $result['fileid'],
			Server::get( ChecksumApi::class )->findByHash( $sha1, null, 'sha1' )['results'],
		);
	}

	/**
	 * Whether the listing has the file.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function listed( File $file ): bool
	{
		foreach ( Server::get( ChecksumApi::class )->iterateHashes( [ 'admin' ], null, null, false, $file->getId() - 1 ) as $fileId => $entry )
		{
			return $fileId === $file->getId();
		}

		return false;
	}

	/**
	 * The file's state rows, as the index holds them.
	 *
	 * @return list<string>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function stateRowsOf( int $fileId ): array
	{
		$result = $this->getRawConnection()
		               ->executeQuery(
			               'SELECT `meta_value_string` FROM `*PREFIX*files_metadata_index` WHERE `file_id` = ? AND `meta_key` = ?',
			               [
				               $fileId,
				               MetadataService::KEY_FILE_CHECKSUM_STATE,
			               ],
		               )
		;

		$states = array_map( strval( ... ), $result->fetchFirstColumn() );
		$result->free();

		return $states;
	}

	/**
	 * The string half of the file's stamp row.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function stampStringOf( int $fileId ): ?string
	{
		$result = $this->getRawConnection()
		               ->executeQuery(
			               'SELECT `meta_value_string` FROM `*PREFIX*files_metadata_index` WHERE `file_id` = ? AND `meta_key` = ?',
			               [
				               $fileId,
				               MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT,
			               ],
		               )
		;

		$value = $result->fetchOne();
		$result->free();

		$this->assertNotFalse( $value, 'The file has its stamp row.' );

		return $value === null
			? null
			: (string) $value;
	}

	/**
	 * A marker where an earlier version kept it: the stamp row's string half.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function leaveInTheStampRow(
		int    $fileId,
		string $marker,
	): void
	{
		$this->getRawConnection()
		     ->executeStatement(
			     'UPDATE `*PREFIX*files_metadata_index` SET `meta_value_string` = ? WHERE `file_id` = ? AND `meta_key` = ?',
			     [
				     $marker,
				     $fileId,
				     MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT,
			     ],
		     )
		;
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
}
