<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\HashListingService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCA\GroupFolders\Folder\FolderManager;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use Throwable;

/**
 * A version of a file in the trash is placed through its file, where the
 * file was before the deletion, under `versions://trash://`, governed as the
 * file is, and left unread by the drain: a home's, which the trash moves
 * beside its file, and a team folder's, which stays by its file's id.
 */
class TrashVersionsTest
    extends
    DatabaseTestCase
{

//  private properties

	private static string $uid;

	private static ?int $folderId = null;

	private static ?string $gid = null;

	private RuleService $ruleService;


//  static methods

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$apps = Server::get( IAppManager::class );

		if ( ! $apps->isEnabledForUser( 'files_trashbin' ) || ! $apps->isEnabledForUser( 'files_versions' ) )
		{
			self::markTestSkipped( 'FCIAS integration: the trash and the versions apps are not both enabled on this instance.' );
		}

		$apps->loadApp( 'files_trashbin' );
		$apps->loadApp( 'files_versions' );

		[ self::$uid ] = self::makeAccount( 'fcias_tver' );

		if ( $apps->isEnabledForUser( 'groupfolders' ) )
		{
			$apps->loadApp( 'groupfolders' );

			self::$gid = 'fcias_tver_' . bin2hex( random_bytes( 4 ) );
			Server::get( IGroupManager::class )
			      ->createGroup( self::$gid )
			      ?->addUser( Server::get( IUserManager::class )->get( self::$uid ) )
			;

			$folders        = Server::get( FolderManager::class );
			self::$folderId = $folders->createFolder( self::$gid );
			$folders->addApplicableGroup( self::$folderId, self::$gid );
		}
	}

	public static function tearDownAfterClass(): void
	{
		if ( self::$folderId !== null )
		{
			try
			{
				Server::get( FolderManager::class )->removeFolder( self::$folderId );
			}
			catch ( Throwable )
			{
				// Left for the instance's own cleanup.
			}

			self::$folderId = null;
		}

		if ( self::$gid !== null )
		{
			Server::get( IGroupManager::class )->get( self::$gid )?->delete();
			self::$gid = null;
		}

		parent::tearDownAfterClass();
	}


//  getters / setters / is* / has*

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->ruleService = Server::get( RuleService::class );

		$this->preserveStoredRules();

		foreach ( $this->ruleService->loadRules() as $rule )
		{
			$this->ruleService->ruleDelete( (string) $rule['id'] );
		}

		self::actAs( self::$uid );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		self::actAs( null );

		parent::tearDown();
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAHomeVersionInTheTrashIsPlacedAndGovernedThroughItsFile(): void
	{
		[ $fileId, $versionId ] = $this->trashedVersion( self::folderAt( $this->home(), 'Docs' ), 'v.txt' );
		$id                     = $this->rule( 'home:' . self::$uid, 'Docs/**' );

		$this->assertStringStartsWith( 'files_trashbin/versions/v.txt.v', (string) Server::get( FilecacheService::class )->locate( $versionId )?->internalPath );

		$entry = iterator_to_array( Server::get( HashListingService::class )->iterate( [ self::$uid ] ) )[ $versionId ] ?? null;

		$this->assertNotNull( $entry, 'the version is listed' );
		$this->assertSame( 'versions://trash:///Docs/v.txt', $entry['path'] );
		$this->assertSame( $fileId, $entry['version']['fileid'] ?? null );
		$this->assertArrayHasKey( 'deletedAt', $entry['trash'] ?? [] );
		$this->assertSame( sha1( 'one v.txt' ), $entry['hashes']['sha1']['hash'] ?? null );

		$this->assertSame( $id, $this->ruleService->findFirstMatchingRule( $versionId )['id'] ?? null );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATeamFoldersVersionOfATrashedFileIsPlacedThroughIt(): void
	{
		if ( self::$gid === null )
		{
			$this->markTestSkipped( 'FCIAS integration: the groupfolders app is not enabled on this instance.' );
		}

		$team = $this->home()->get( self::$gid );
		$this->assertInstanceOf( Folder::class, $team );

		[ $fileId, $versionId ] = $this->trashedVersion( $team, 't.txt' );

		$this->assertSame( 'versions/' . $fileId, dirname( (string) Server::get( FilecacheService::class )->locate( $versionId )?->internalPath ) );

		$entry = iterator_to_array( Server::get( HashListingService::class )->iterate( [ self::$uid ] ) )[ $versionId ] ?? null;

		$this->assertSame( 'versions://trash:///' . self::$gid . '/t.txt', $entry['path'] ?? null );
		$this->assertArrayHasKey( 'trash', $entry ?? [] );
	}

	/**
	 * Queued, a version of a trashed file is dropped unread: a forced read
	 * would have replaced the checksum it holds.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheDrainLeavesAVersionInTheTrashUnread(): void
	{
		$this->rule( 'home:' . self::$uid, 'Queued/**', versions: true );
		[ , $versionId ] = $this->trashedVersion( self::folderAt( $this->home(), 'Queued' ), 'q.txt' );
		$metadata        = Server::get( MetadataService::class );
		$metadata->writeHashes( $versionId, [ 'sha1' => sha1( 'written' ) ], time(), false, Server::get( FilecacheService::class )->etagOf( $versionId ) );
		$metadata->markPending( $versionId, MetadataService::PENDING_FORCE );

		$outcome = Server::get( HashCalculationService::class )->processFile( $versionId, MetadataService::PENDING_MODE_FORCE );

		$this->assertTrue( $outcome->hasLeftTheQueue() );
		$this->assertSame( sha1( 'written' ), $metadata->getHashes( $versionId )['sha1'] ?? null );
	}

	/**
	 * A file written as `one <name>`, hashed, then written over, so that the
	 * versions app keeps a version of it with its checksum; then the file
	 * moved to the trash.
	 *
	 * @return array{0: int, 1: int}  the file's id and the version's
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function trashedVersion(
		Folder $folder,
		string $name,
	): array
	{
		$file = $folder->newFile( $name, 'one ' . $name );
		$file->touch( time() - 3600 );

		$file = Server::get( IRootFolder::class )->getFirstNodeById( $file->getId() );
		$this->assertInstanceOf( File::class, $file );

		$filecache = Server::get( FilecacheService::class );
		$fileId    = $file->getId();
		$mtime     = $file->getMTime();
		Server::get( MetadataService::class )->writeHashes( $fileId, [ 'sha1' => sha1( 'one ' . $name ) ], time(), false, $filecache->etagOf( $fileId ) );

		$file->putContent( 'two ' . $name );

		$location = $filecache->locate( $fileId );
		$path     = (string) $location?->versionPathAt( $mtime );
		$version  = $filecache->locateAllByPath( [ (string) $location?->storageId => [ $path ] ] )
			[ FilecacheService::identityKey( (string) $location?->storageId, $path ) ] ?? null;

		$this->assertInstanceOf( FileLocation::class, $version, 'the versions app made a version' );
		$this->assertSame( sha1( 'one ' . $name ), Server::get( MetadataService::class )->getHashes( $version->fileId )['sha1'] ?? null, 'which holds the checksum' );

		$file->delete();

		return [ $fileId, $version->fileId ];
	}

	/**
	 * An enabled include rule over $path, as stored.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function rule(
		string $selector,
		string $path,
		bool   $versions = false,
	): string
	{
		return $this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_INCLUDE,
				'path'     => $path,
				'mode'     => MetadataService::PENDING_MODE_AUTO,
				'selector' => $selector,
				'algos'    => [ 'sha1' ],
				'versions' => $versions,
			],
		);
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function home(): Folder
	{
		return Server::get( IRootFolder::class )->getUserFolder( self::$uid );
	}
}
