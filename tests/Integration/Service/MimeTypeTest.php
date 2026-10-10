<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\MimeTypeDelete;
use OCA\FileChecksumSearch\BackgroundJob\MimeTypeRefresh;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\MimeTypeService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\Server;
use Throwable;
use ZipArchive;

/**
 * A file's MIME type, detected from its content while it is hashed:
 * stored in the metadata document whole, in the index by its id, and
 * following the hashes as the content changes.
 *
 * Against real files, the real metadata tables and the server's own
 * libmagic: what is asserted is what the next reader finds.
 */
class MimeTypeTest
    extends
    DatabaseTestCase
{

//  constants

	private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

	private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';


//  private properties

	private HashCalculationService $hashCalc;

	private MetadataService        $metadataService;

	private RuleService            $ruleService;

	private IAppConfig             $appConfig;

	private ?bool $detectionBefore = null;

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
		$this->appConfig       = Server::get( IAppConfig::class );

		// The instance's rules and switch are put back afterwards.
		$this->preserveStoredRules();

		$this->detectionBefore = $this->appConfig->hasKey( Application::APP_ID, ConfigLexicon::MIMETYPE_DETECTION )
			? $this->appConfig->getValueBool( Application::APP_ID, ConfigLexicon::MIMETYPE_DETECTION )
			: null;

		$this->detection( true );
		$this->rule( MetadataService::PENDING_MODE_MISSING, [ 'sha1' ] );
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

		if ( $this->detectionBefore === null )
		{
			$this->appConfig->deleteKey( Application::APP_ID, ConfigLexicon::MIMETYPE_DETECTION );
		}
		else
		{
			$this->detection( $this->detectionBefore );
		}

		// Nothing a test requested is left for the instance's cron.
		$this->appConfig->deleteKey( Application::APP_ID, ConfigLexicon::MIMETYPE_DELETE_REQUESTED_AT );
		Server::get( IJobList::class )->remove( MimeTypeDelete::class );
		Server::get( IJobList::class )->remove( MimeTypeRefresh::class );

		parent::tearDown();
	}

	/**
	 * A refresh queues the hashed files without a type, and the drain reads
	 * them for it; a file with a type is passed, and so is one queued for
	 * its hashes, which detects the type as well. With all, the typed one
	 * too.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARefreshQueuesTheFilesWithoutATypeAndTheDrainReadsThem(): void
	{
		$this->detection( false );
		$untyped = $this->hashed( 'fcias_mime_refresh_untyped', self::PDF );
		$this->detection( true );
		$typed = $this->hashed( 'fcias_mime_refresh_typed', self::PDF );
		$this->detection( false );
		$queued = $this->hashed( 'fcias_mime_refresh_queued', self::PDF );
		$this->metadataService->markPending( $queued->getId(), MetadataService::PENDING_AUTO );
		$this->detection( true );

		$this->assertSame( 1, $this->refreshOnly( $untyped, 3, false ) );

		$this->assertSame( MetadataService::PENDING_MIME, $this->metadataService->getMarker( $untyped->getId() ) );
		$this->assertNull( $this->metadataService->getMarker( $typed->getId() ) );
		$this->assertSame( MetadataService::PENDING_AUTO, $this->metadataService->getMarker( $queued->getId() ) );

		$stamp = $this->metadataService->getUpdatedAt( $untyped->getId() );

		$this->assertTrue( $this->hashCalc->processFile( $untyped->getId(), MetadataService::PENDING_MODE_MIME ) );

		$this->assertSame( 'application/pdf', $this->documentTypeOf( $untyped ) );
		$this->assertCount( 1, $this->typeRowsOf( $untyped ) );
		$this->assertNull( $this->metadataService->getMarker( $untyped->getId() ) );
		$this->assertSame( $stamp, $this->metadataService->getUpdatedAt( $untyped->getId() ), 'The stamp is the hashes\'.' );

		$this->assertSame( 2, $this->refreshOnly( $untyped, 3, true ), 'All: both typed files, not the queued one.' );
		$this->assertSame( MetadataService::PENDING_MIME, $this->metadataService->getMarker( $typed->getId() ) );
	}

	/**
	 * Cut short, a deletion leaves every file whole or gone, and goes on
	 * from the rows that are left; the hashes stay.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testADeletionCutShortGoesOnWhereItStopped(): void
	{
		$first  = $this->hashed( 'fcias_mime_delete_first', self::PDF );
		$second = $this->hashed( 'fcias_mime_delete_second', "plain text\n" );
		$this->detection( false );

		$cut = $this->metadataService->deleteMimeTypes( 1, static fn (): bool => false );

		$this->assertSame( [ 'deleted' => 1, 'done' => false ], $cut );
		$this->assertNull( $this->documentTypeOf( $first ) );
		$this->assertSame( [], $this->typeRowsOf( $first ) );
		$this->assertSame( 'text/plain', $this->documentTypeOf( $second ) );
		$this->assertCount( 1, $this->typeRowsOf( $second ) );

		$this->assertTrue( $this->metadataService->deleteMimeTypes()['done'] );
		$this->assertNull( $this->documentTypeOf( $second ) );
		$this->assertSame( [], $this->typeRowsOf( $second ) );
		$this->assertSame( 0, $this->metadataService->countMimeTypeRows() );
		$this->assertSame( 1, $this->metadataService->countByFileId( $second->getId() ), 'Its hash stays.' );
	}

	/**
	 * While a deletion runs, detection stays off: the types it detected
	 * meanwhile would be deleted with the rest.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testSwitchingOnIsRefusedWhileADeletionRuns(): void
	{
		$mimeTypes = Server::get( MimeTypeService::class );

		$this->detection( false );
		$mimeTypes->requestDelete();

		$this->assertTrue( Server::get( IJobList::class )->has( MimeTypeDelete::class, null ) );
		$this->assertSame( MimeTypeService::STATE_DELETING, $mimeTypes->getState() );
		$this->assertSame( MimeTypeService::STATE_DELETING, $mimeTypes->refusalToEnable() );

		$mimeTypes->setDeleteRequestedAt( null );

		$this->assertNull( $mimeTypes->refusalToEnable() );
	}

	/**
	 * The types are counted with the checksums, a row to a typed file.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheTypesAreCountedWithTheChecksums(): void
	{
		$before = $this->metadataService->countMimeTypeRows();

		$this->hashed( 'fcias_mime_count', self::PDF );

		$this->assertSame( $before + 1, $this->metadataService->countMimeTypeRows() );
		$this->assertSame( $before + 1, Server::get( StatusService::class )->recountHashRows()['mimeTypes'] );
	}

	/**
	 * Nextcloud names the file by its extension; the content says what it
	 * is.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAPdfNamedJpgIsAPdf(): void
	{
		$file = $this->createTestFile( 'fcias_mime_pdf', 'jpg', self::PDF );

		$this->assertSame( 'image/jpeg', $file->getMimeType(), 'Nextcloud\'s type is the name\'s.' );
		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha1' )['success'] );

		$this->assertSame( 'application/pdf', $this->documentTypeOf( $file ) );
		$this->assertSame(
			[ [ Server::get( IMimeTypeLoader::class )->getId( 'application/pdf' ), 'application/pdf' ] ],
			$this->typeRowsOf( $file ),
		);
		$this->assertSame( [ $file->getId() => 'application/pdf' ], $this->metadataService->mimeTypesOf( [ $file->getId() ] ) );
	}

	/**
	 * A type past the column's 63 characters is whole in the document and
	 * named by its id; the row's string is cut.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnOfficeDocumentKeepsItsWholeType(): void
	{
		$file = $this->createTestFile( 'fcias_mime_docx', 'bin', self::docx() );

		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha1' )['success'] );

		$this->assertSame( self::DOCX, $this->documentTypeOf( $file ) );
		$this->assertSame(
			[ [ Server::get( IMimeTypeLoader::class )->getId( self::DOCX ), substr( self::DOCX, 0, 63 ) ] ],
			$this->typeRowsOf( $file ),
		);
		$this->assertSame( [ $file->getId() => self::DOCX ], $this->metadataService->mimeTypesOf( [ $file->getId() ] ) );
	}

	/**
	 * A write takes the type's row away with the hashes'; the queue brings
	 * the new content's type back.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAWriteHidesTheTypeUntilTheQueueDetectsItAgain(): void
	{
		$file = $this->hashed( 'fcias_mime_write', self::PDF );

		$file->putContent( "plain text now\n" );

		$this->assertSame( [], $this->typeRowsOf( $file ) );
		$this->assertSame( [], $this->metadataService->mimeTypesOf( [ $file->getId() ] ) );

		$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_MISSING );

		$this->assertSame( 'text/plain', $this->documentTypeOf( $file ) );
		$this->assertSame( [ $file->getId() => 'text/plain' ], $this->metadataService->mimeTypesOf( [ $file->getId() ] ) );
	}

	/**
	 * Off, the hashes of changed content are computed and its old type is
	 * not carried over: the new stamp would vouch for it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAChangedFileHashedWhileOffLosesItsType(): void
	{
		$file = $this->hashed( 'fcias_mime_off_changed', self::PDF );

		$this->detection( false );
		$file->putContent( "plain text now\n" );
		$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_MISSING );

		$this->assertNull( $this->documentTypeOf( $file ) );
		$this->assertSame( [], $this->typeRowsOf( $file ) );
	}

	/**
	 * Off, a hash added to content that did not change keeps the type: it
	 * is still true.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAHashAddedWhileOffKeepsACurrentType(): void
	{
		$file = $this->hashed( 'fcias_mime_off_kept', self::PDF );

		$this->detection( false );
		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'md5' )['success'] );

		$this->assertSame( 'application/pdf', $this->documentTypeOf( $file ) );
		$this->assertCount( 1, $this->typeRowsOf( $file ) );
	}

	/**
	 * `force` recomputes everything; off, it cannot recompute the type.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testForceWhileOffRemovesTheType(): void
	{
		$file = $this->hashed( 'fcias_mime_off_force', self::PDF );

		$this->detection( false );
		$this->hashCalc->processFile( $file->getId(), MetadataService::PENDING_MODE_FORCE );

		$this->assertNull( $this->documentTypeOf( $file ) );
		$this->assertSame( [], $this->typeRowsOf( $file ) );
	}

	/**
	 * A file hashed before detection was on is read for its type alone the
	 * next time its hashes are asked for.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileHashedBeforeDetectionGetsItsTypeWhenAskedAgain(): void
	{
		$this->detection( false );
		$file = $this->hashed( 'fcias_mime_later', self::PDF );
		$this->assertNull( $this->documentTypeOf( $file ) );

		$this->detection( true );
		$result = $this->hashCalc->recalcFileHash( $file, 'sha1' );

		$this->assertTrue( $result['existed'], 'The hash stood: nothing was hashed again.' );
		$this->assertSame( 'application/pdf', $this->documentTypeOf( $file ) );
		$this->assertCount( 1, $this->typeRowsOf( $file ) );
	}

	/**
	 * `rebuild-from-metadata` writes a type's row back from the document,
	 * and writes none while detection is off.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheIndexRepairWritesATypeRowOnlyWhileDetectionIsOn(): void
	{
		$file = $this->hashed( 'fcias_mime_repair', self::PDF );

		$this->getRawConnection()
		     ->executeStatement(
			     'DELETE FROM `*PREFIX*files_metadata_index` WHERE `file_id` = ? AND `meta_key` = ?',
			     [
				     $file->getId(),
				     MetadataService::KEY_FILE_CHECKSUM_MIMETYPE,
			     ],
		     )
		;

		$this->detection( false );
		$this->assertSame( 0, $this->repairOnly( $file ) );
		$this->assertSame( [], $this->typeRowsOf( $file ) );

		$this->detection( true );
		$this->assertSame( 1, $this->repairOnly( $file ) );
		$this->assertCount( 1, $this->typeRowsOf( $file ) );
	}

	//  private methods
	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function detection( bool $on ): void
	{
		$this->appConfig->setValueBool( Application::APP_ID, ConfigLexicon::MIMETYPE_DETECTION, $on );
	}

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
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function createTestFile(
		string $base,
		string $extension,
		string $content,
	): File
	{
		$file = Server::get( IRootFolder::class )
		              ->getUserFolder( 'admin' )
		              ->newFile( $base . '_' . bin2hex( random_bytes( 4 ) ) . '.' . $extension, $content )
		;

		$this->cleanupFiles[]   = $file;
		$this->cleanupFileIds[] = $file->getId();

		return $file;
	}

	/**
	 * A file holding $content, hashed in sha1. A write after it is seen by
	 * the write listener, which sets the stamp to 0 whatever the clock's
	 * second.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function hashed(
		string $base,
		string $content,
	): File
	{
		$file = $this->createTestFile( $base, 'dat', $content );

		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha1' )['success'] );

		return $file;
	}

	/**
	 * A refresh over the $files hashed files from $from on alone: one page,
	 * from just before it. The instance this runs against holds other
	 * hashed files, and they are not the test's to queue.
	 *
	 * @return int  Files queued.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function refreshOnly(
		File $from,
		int  $files,
		bool $all,
	): int
	{
		return $this->metadataService->markForMimeTypeAfter( $from->getId() - 1, $files, $all, static fn (): bool => false )['marked'];
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
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function documentTypeOf( File $file ): ?string
	{
		return $this->metadataService->getMimeType( $this->metadataService->getMetadata( $file->getId() ) );
	}

	/**
	 * The file's type rows, as [id, string].
	 *
	 * @return list<array{0: int, 1: string}>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function typeRowsOf( File $file ): array
	{
		$result = $this->getRawConnection()
		               ->executeQuery(
			               'SELECT `meta_value_int`, `meta_value_string` FROM `*PREFIX*files_metadata_index` WHERE `file_id` = ? AND `meta_key` = ? ORDER BY `id`',
			               [
				               $file->getId(),
				               MetadataService::KEY_FILE_CHECKSUM_MIMETYPE,
			               ],
		               )
		;

		$rows = array_map(
			static fn(
				array $row,
			): array => [
				(int) $row['meta_value_int'],
				(string) $row['meta_value_string'],
			],
			$result->fetchAllAssociative(),
		);
		$result->free();

		return $rows;
	}


//  static methods

	/**
	 * The least of a Word document libmagic names as one: its content types
	 * first, then its parts.
	 */
	private static function docx(): string
	{
		$path = (string) tempnam( sys_get_temp_dir(), 'fcias_docx_' );
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::OVERWRITE );
		$zip->addFromString(
			'[Content_Types].xml',
			'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
		);
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>' );
		$zip->addFromString(
			'word/document.xml',
			'<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
		);
		$zip->close();

		$content = (string) file_get_contents( $path );
		unlink( $path );

		return $content;
	}
}
