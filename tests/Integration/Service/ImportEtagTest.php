<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\ImportService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\State\Format\CsvFormat;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\HashRecordFormat;
use OCA\FileChecksumSearch\State\Format\JsonFormat;
use OCA\FileChecksumSearch\State\HashRecord;
use OCA\FileChecksumSearch\State\ImportPolicy;
use OCA\FileChecksumSearch\State\ImportReport;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\Server;
use Throwable;

/**
 * A restore weighs a record's ETag: the file's own vouches for the hashes;
 * another, in a backup of this instance, means the file changed since, as
 * the mtime a sync client keeps cannot tell. From elsewhere, another ETag
 * says nothing.
 *
 * Against real files and the real tables; the filecache's ETag is moved by
 * hand, with the mtime kept, as such an upload leaves it.
 */
class ImportEtagTest
    extends
    DatabaseTestCase
{

//  private properties

	private MetadataService $metadataService;

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

		$this->metadataService = Server::get( MetadataService::class );
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
	 * Backed up, cleared, restored onto the unchanged file: stamped with its
	 * ETag, and found.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARestoreOntoTheUnchangedFileIsVouchedForByItsEtag(): void
	{
		$file   = $this->hashed( 'fcias_import_unchanged' );
		$record = $this->recordOf( $file );

		$this->assertNotNull( $record->etag, 'The backup carries the ETag the hash was read against.' );

		$this->metadataService->clearMetadata( $file->getId() );
		$this->assertFalse( $this->found( $file ) );

		$report = $this->restore( new JsonFormat(), $this->backupOf( $record, $this->instanceId() ) );

		$this->assertSame( 1, $report->written );
		$this->assertSame( $record->etag, $this->stampEtagOf( $file ) );
		$this->assertTrue( $this->found( $file ) );
	}

	/**
	 * Changed between backup and restore with its mtime kept: refused, and
	 * counted as changed.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARestoreOntoAFileChangedSinceIsRefused(): void
	{
		$file   = $this->hashed( 'fcias_import_changed' );
		$record = $this->recordOf( $file );

		$this->metadataService->clearMetadata( $file->getId() );
		$this->etagMovesOn( $file );

		$report = $this->restore( new JsonFormat(), $this->backupOf( $record, $this->instanceId() ) );

		$this->assertSame( 1, $report->skippedChanged );
		$this->assertSame( 0, $report->written );
		$this->assertSame( [], $this->metadataService->getHashes( $this->metadataService->getMetadata( $file->getId() ) ) );
	}

	/**
	 * `--allow-stale` stores it under the ETag it was read against, which
	 * keeps it out of search.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAllowStaleStoresAChangedFileOutOfSearch(): void
	{
		$file   = $this->hashed( 'fcias_import_stale' );
		$record = $this->recordOf( $file );

		$this->metadataService->clearMetadata( $file->getId() );
		$this->etagMovesOn( $file );

		$report = $this->restore(
			new JsonFormat(),
			$this->backupOf( $record, $this->instanceId() ),
			new ImportPolicy( merge: false, allowOutdated: true ),
		);

		$this->assertSame( 1, $report->written );
		$this->assertSame( $record->etag, $this->stampEtagOf( $file ) );
		$this->assertFalse( $this->found( $file ) );
	}

	/**
	 * A csv names no instance, so a different ETag in it says nothing: the
	 * timestamp decides, as it did before records carried one.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testACsvsForeignEtagImportsAsBefore(): void
	{
		$file   = $this->hashed( 'fcias_import_csv' );
		$record = $this->recordOf( $file );

		$this->metadataService->clearMetadata( $file->getId() );
		$this->etagMovesOn( $file );

		$stream = fopen( 'php://memory', 'r+' );
		( new CsvFormat() )->write( [ $record ], $stream, new FormatOptions() );
		rewind( $stream );
		$csv = (string) stream_get_contents( $stream );
		fclose( $stream );

		$report = $this->restore( new CsvFormat(), $csv );

		$this->assertSame( 1, $report->written );
		$this->assertSame( 0, $report->skippedChanged );
		$this->assertNull( $this->stampEtagOf( $file ) );
		$this->assertTrue( $this->found( $file ), 'Held by its timestamp, as a record without an ETag is.' );
	}

	/**
	 * A file in the admin's home, hashed in sha1 and stamped with its ETag.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function hashed( string $base ): File
	{
		$file = Server::get( IRootFolder::class )
		              ->getUserFolder( 'admin' )
		              ->newFile( $base . '_' . bin2hex( random_bytes( 4 ) ) . '.dat', $base . ' ' . bin2hex( random_bytes( 8 ) ) )
		;

		$this->cleanupFiles[]   = $file;
		$this->cleanupFileIds[] = $file->getId();

		$this->assertTrue( Server::get( HashCalculationService::class )->recalcFileHash( $file, 'sha1' )['success'] );

		return $file;
	}

	/**
	 * The file's sha1 as a backup holds it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function recordOf( File $file ): HashRecord
	{
		$metadata = $this->metadataService->getMetadata( $file->getId() );
		$location = Server::get( FilecacheService::class )->locateAll( [ $file->getId() ] )[ $file->getId() ];

		return new HashRecord(
			$location->storageId,
			$location->internalPath,
			'sha1',
			sha1( (string) $file->getContent() ),
			$this->metadataService->getUpdatedAt( $metadata ),
			$this->metadataService->getEtag( $metadata ),
		);
	}

	/**
	 * A json backup holding one record, from the instance named.
	 */
	private function backupOf(
		HashRecord $record,
		string     $instanceId,
	): string
	{
		return (string) json_encode(
			[
				'schema'      => JsonFormat::SCHEMA_VERSION,
				'instance_id' => $instanceId,
				'hashes'      => [ $record->toArray() ],
			],
		);
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function restore(
		HashRecordFormat $format,
		string           $document,
		?ImportPolicy    $policy = null,
	): ImportReport
	{
		$stream = fopen( 'php://memory', 'r+' );
		fwrite( $stream, $document );
		rewind( $stream );

		try
		{
			return Server::get( ImportService::class )->import(
				$format,
				$stream,
				new FormatOptions(),
				$policy ?? new ImportPolicy( merge: false ),
				false,
				true,
			);
		}
		finally
		{
			fclose( $stream );
		}
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function instanceId(): string
	{
		return Server::get( IConfig::class )->getSystemValueString( 'instanceid' );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function stampEtagOf( File $file ): ?string
	{
		return $this->metadataService->getEtag( $this->metadataService->getMetadata( $file->getId() ) );
	}

	/**
	 * Whether a lookup by the file's sha1 finds it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function found( File $file ): bool
	{
		$results = Server::get( ChecksumApi::class )->findByHash( sha1( (string) $file->getContent() ), null, 'sha1' )['results'];

		return in_array(
			$file->getId(),
			array_map( static fn( array $row ): int => (int) $row['fileid'], $results ),
			true,
		);
	}
}
