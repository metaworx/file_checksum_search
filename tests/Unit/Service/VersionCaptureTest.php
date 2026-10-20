<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\VersionCapture;
use OCP\FilesMetadata\Model\IFilesMetadata;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The hashes a file holds before a write go to the version Nextcloud makes
 * of the replaced content, found by the name it gives it, and only where
 * they are current.
 */
class VersionCaptureTest
    extends
    TestCase
{

//  private properties

	private MockObject|FilecacheService $filecacheService;

	private MockObject|MetadataService  $metadataService;

	private VersionCapture              $capture;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->filecacheService = $this->createMock( FilecacheService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );

		$this->capture = new VersionCapture(
			$this->filecacheService,
			$this->metadataService,
			$this->createMock( LoggerInterface::class ),
		);
	}


//  other non-static methods

	public function testCurrentHashesGoToTheVersionNamedAfterTheReplacedMtime(): void
	{
		$this->fileAt( 'home::alice', 'files/Docs/a.txt', current: true );
		$this->versionFound( 'home::alice', 'files_versions/Docs/a.txt.v1700000000', 70, 'etag-v' );

		$this->metadataService->expects( $this->once() )
		                      ->method( 'writeHashes' )
		                      ->with( 70, [ 'sha1' => 'aaa' ], $this->greaterThanOrEqual( 1700000000 ), false, 'etag-v' )
		;

		$this->capture->beforeWrite( 7 );

		$this->assertSame( 70, $this->capture->afterWrite( 7 ) );
	}

	public function testOutdatedHashesAreKeptForNoVersion(): void
	{
		$this->fileAt( 'home::alice', 'files/Docs/a.txt', current: false );

		$this->filecacheService->expects( $this->never() )->method( 'locateAllByPath' );
		$this->metadataService->expects( $this->never() )->method( 'writeHashes' );

		$this->capture->beforeWrite( 7 );

		$this->assertNull( $this->capture->afterWrite( 7 ) );
	}

	/**
	 * Versioning off, or an empty file: no version is made, and nothing is
	 * saved.
	 */
	public function testNoVersionMadeSavesNothing(): void
	{
		$this->fileAt( 'home::alice', 'files/a.txt', current: true );
		$this->filecacheService->method( 'locateAllByPath' )->willReturn( [] );

		$this->metadataService->expects( $this->never() )->method( 'writeHashes' );

		$this->capture->beforeWrite( 7 );

		$this->assertNull( $this->capture->afterWrite( 7 ) );
	}

	/**
	 * An external storage keeps no versions beside its files, so there is
	 * no name to look for.
	 */
	public function testAFileWithoutVersionsBesideItKeepsNothing(): void
	{
		$this->fileAt( 'smb::u@host//share//', '2024/x.pdf', current: true );

		$this->metadataService->expects( $this->never() )->method( 'getMetadata' );

		$this->capture->beforeWrite( 7 );

		$this->assertNull( $this->capture->afterWrite( 7 ) );
	}

	/**
	 * Each write hands on once: a second write without its own before-write
	 * finds nothing kept.
	 */
	public function testWhatIsKeptIsHandedOnOnce(): void
	{
		$this->fileAt( 'home::alice', 'files/a.txt', current: true );
		$this->versionFound( 'home::alice', 'files_versions/a.txt.v1700000000', 70, 'etag-v' );

		$this->metadataService->expects( $this->once() )->method( 'writeHashes' );

		$this->capture->beforeWrite( 7 );
		$this->capture->afterWrite( 7 );

		$this->assertNull( $this->capture->afterWrite( 7 ) );
	}

	/**
	 * A failure saving is the version's loss, never the write's.
	 */
	public function testAFailureSavingIsSwallowed(): void
	{
		$this->fileAt( 'home::alice', 'files/a.txt', current: true );
		$this->versionFound( 'home::alice', 'files_versions/a.txt.v1700000000', 70, 'etag-v' );
		$this->metadataService->method( 'writeHashes' )->willThrowException( new RuntimeException( 'db gone' ) );

		$this->capture->beforeWrite( 7 );

		$this->assertNull( $this->capture->afterWrite( 7 ) );
	}

	/**
	 * File 7 at $path on $storageId, modified at 1700000000, holding a sha1
	 * that is current or not.
	 */
	private function fileAt(
		string $storageId,
		string $path,
		bool   $current,
	): void
	{
		$this->filecacheService->method( 'stateOf' )
		                       ->with( 7 )
		                       ->willReturn( [
			                       'etag'         => 'etag-f',
			                       'size'         => 3,
			                       'mtime'        => 1700000000,
			                       'storageMtime' => 1700000000,
		                       ] )
		;
		$this->filecacheService->method( 'locate' )
		                       ->with( 7 )
		                       ->willReturn( FileLocation::fromRow( 7, $storageId, $path, 1700000000 ) )
		;

		$metadata = $this->createMock( IFilesMetadata::class );
		$this->metadataService->method( 'getMetadata' )->willReturn( $metadata );
		$this->metadataService->method( 'getHashes' )->willReturn( [ 'sha1' => 'aaa' ] );
		$this->metadataService->method( 'isCurrent' )->willReturn( $current );
	}

	private function versionFound(
		string $storageId,
		string $path,
		int    $versionId,
		string $etag,
	): void
	{
		$this->filecacheService->method( 'locateAllByPath' )
		                       ->with( [ $storageId => [ $path ] ] )
		                       ->willReturn( [
			                       FilecacheService::identityKey( $storageId, $path ) => FileLocation::fromRow( $versionId, $storageId, $path, 1700000000 ),
		                       ] )
		;
		$this->filecacheService->method( 'etagOf' )
		                       ->with( $versionId )
		                       ->willReturn( $etag )
		;
	}
}
