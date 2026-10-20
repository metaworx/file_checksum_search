<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Listener;

use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCA\Files_Versions\Versions\IVersionManager;
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
 * An overwritten file's checksums pass to the version Nextcloud keeps of
 * the content they describe, through the versions app itself.
 *
 * The app is booted here, as cron and the web boot it: a test process
 * registers its listeners but not its backends, and without one no
 * version is made.
 */
class VersionCaptureTest
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

		if ( ! $apps->isEnabledForUser( 'files_versions' ) )
		{
			self::markTestSkipped( 'FCIAS integration: the files_versions app is not enabled on this instance.' );
		}

		$apps->loadApp( 'files_versions' );

		[ self::$uid ] = self::makeAccount( 'fcias_capture' );

		// The team folder before the account's files are first set up, which
		// fixes the mounts it sees for the process.
		if ( $apps->isEnabledForUser( 'groupfolders' ) )
		{
			$apps->loadApp( 'groupfolders' );

			$salt      = bin2hex( random_bytes( 4 ) );
			self::$gid = 'fcias_capture_' . $salt;
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

		$this->rule( 'home:' . self::$uid, 'Docs/**' );

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
	public function testAnOverwriteHandsTheHashesToTheVersion(): void
	{
		$file   = $this->hashedFile( $this->docs(), 'a.txt', 'one' );
		$mtime  = $file->getMTime();
		$before = sha1( 'one' );

		$file->putContent( 'two' );

		$version = $this->versionAt( $file, $mtime );

		$this->assertNotNull( $version, 'the versions app made a version' );
		$this->assertSame( $before, $this->hashesOf( $version->fileId )['sha1'] ?? null );
		$this->assertTrue( $this->isCurrent( $version->fileId ), 'stamped against the version\'s own ETag' );
	}

	/**
	 * Hashes that no longer describe the file describe no version either.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testOutdatedHashesAreNotHandedOn(): void
	{
		$file  = $this->hashedFile( $this->docs(), 'b.txt', 'one' );
		$mtime = $file->getMTime();

		Server::get( MetadataService::class )->markOutdated( $file->getId(), MetadataService::PENDING_MODE_AUTO );

		$file->putContent( 'two' );

		$version = $this->versionAt( $file, $mtime );

		$this->assertNotNull( $version, 'the versions app made a version' );
		$this->assertSame( [], $this->hashesOf( $version->fileId ) );
	}

	/**
	 * A file no rule maintains hands nothing on, whatever it holds.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileNoRuleGovernsHandsItsHashesOn(): void
	{
		$root  = Server::get( IRootFolder::class )->getUserFolder( self::$uid );
		$file  = $this->hashedFile( self::folderAt( $root, 'Elsewhere' ), 'c.txt', 'one' );
		$mtime = $file->getMTime();

		$file->putContent( 'two' );

		$version = $this->versionAt( $file, $mtime );

		$this->assertNotNull( $version, 'the versions app made a version' );
		$this->assertSame( sha1( 'one' ), $this->hashesOf( $version->fileId )['sha1'] ?? null, 'hashed by hand, and right for the content' );
		$this->assertSame( [], $this->hashesOf( $file->getId() ), 'the file\'s own, nothing maintains, are eroded by the write' );
	}

	/**
	 * Copied, a file's versions are copied beside the copy, ETag and all;
	 * their hashes follow them.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testACopiedFilesVersionsKeepTheirHashes(): void
	{
		$file  = $this->hashedFile( $this->docs(), 'f.txt', 'one' );
		$mtime = $file->getMTime();

		$file->putContent( 'two' );

		$version = $this->versionAt( $file, $mtime );
		$this->assertSame( sha1( 'one' ), $this->hashesOf( (int) $version?->fileId )['sha1'] ?? null, 'the version got the hashes at the write' );

		$copy = $file->copy( $this->docs()->getPath() . '/f-copy.txt' );
		$this->assertInstanceOf( File::class, $copy );

		$copied = $this->versionAt( $copy, $mtime );

		$this->assertNotNull( $copied, 'the versions app copied the version' );
		$this->assertNotSame( $version?->fileId, $copied->fileId );
		$this->assertSame( sha1( 'one' ), $this->hashesOf( $copied->fileId )['sha1'] ?? null );
		$this->assertTrue( $this->isCurrent( $copied->fileId ) );
	}

	/**
	 * Restored, a version hands its hashes back to the file, and the content
	 * the restore replaced keeps the file's on the version made of it: no
	 * read, and none waiting for the daily ETag walk.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRestoringAVersionRestoresItsHashes(): void
	{
		$file   = $this->hashedFile( $this->docs(), 'r.txt', 'one' );
		$before = $file->getMTime();

		$file->putContent( 'two' );
		$file  = $this->rehashed( $file );
		$after = $file->getMTime();

		$this->restore( $file, $before );

		$this->assertSame( 'one', $file->getContent(), 'the versions app restored the content' );
		$this->assertSame( sha1( 'one' ), $this->hashesOf( $file->getId() )['sha1'] ?? null, 'the file has the restored version\'s hashes' );
		$this->assertTrue( $this->isCurrent( $file->getId() ), 'stamped against the ETag the restore left' );

		$replaced = $this->versionAt( $file, $after );
		$this->assertNotNull( $replaced, 'the restore made a version of the replaced content' );
		$this->assertSame( sha1( 'two' ), $this->hashesOf( $replaced->fileId )['sha1'] ?? null );
		$this->assertTrue( $this->isCurrent( $replaced->fileId ) );
	}

	/**
	 * A version that holds no hashes has none to hand back: the file is as
	 * written, its hashes out of every search and queued again at once.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRestoringAVersionWithoutHashesQueuesTheFile(): void
	{
		$file = $this->docs()->newFile( 'q.txt', 'one' );
		$file->touch( time() - 3600 );
		$file   = Server::get( IRootFolder::class )->getFirstNodeById( $file->getId() );
		$this->assertInstanceOf( File::class, $file );
		$before = $file->getMTime();

		$file->putContent( 'two' );
		$file = $this->rehashed( $file );

		$this->restore( $file, $before );

		$this->assertSame( 'one', $file->getContent() );
		$this->assertSame( MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_AUTO, Server::get( MetadataService::class )->getMarker( $file->getId() ) );
		$this->assertFalse( $this->isCurrent( $file->getId() ), 'the hashes of what it no longer holds are out' );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRestoringATeamFoldersVersionRestoresItsHashes(): void
	{
		if ( self::$folderId === null || self::$gid === null )
		{
			$this->markTestSkipped( 'FCIAS integration: the groupfolders app is not enabled on this instance.' );
		}

		$this->rule( 'groupfolder:' . self::$folderId, '**' );

		$team = Server::get( IRootFolder::class )->getUserFolder( self::$uid )->get( self::$gid );
		$this->assertInstanceOf( Folder::class, $team );

		$file   = $this->hashedFile( $team, 'r.txt', 'one' );
		$before = $file->getMTime();

		$file->putContent( 'two' );
		$file = $this->rehashed( $file );

		$this->restore( $file, $before );

		$this->assertSame( sha1( 'one' ), $this->hashesOf( $file->getId() )['sha1'] ?? null );
		$this->assertTrue( $this->isCurrent( $file->getId() ) );
	}

	/**
	 * A file an exclude rule governs may not be hashed by any route, and
	 * hands nothing on, whatever it holds.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnExcludedFileHandsNothingOn(): void
	{
		$root  = Server::get( IRootFolder::class )->getUserFolder( self::$uid );
		$file  = $this->hashedFile( self::folderAt( $root, 'Metered' ), 'e.txt', 'one' );
		$mtime = $file->getMTime();

		$this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_EXCLUDE,
				'path'     => 'Metered/**',
				'selector' => 'home:' . self::$uid,
			],
		);

		$file->putContent( 'two' );

		$version = $this->versionAt( $file, $mtime );

		$this->assertNotNull( $version, 'the versions app made a version' );
		$this->assertSame( [], $this->hashesOf( $version->fileId ) );
	}

	/**
	 * A team folder keeps its versions in its own storage, by file id.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATeamFoldersVersionGetsTheHashes(): void
	{
		if ( self::$folderId === null || self::$gid === null )
		{
			$this->markTestSkipped( 'FCIAS integration: the groupfolders app is not enabled on this instance.' );
		}

		$this->rule( 'groupfolder:' . self::$folderId, '**' );

		$team = Server::get( IRootFolder::class )->getUserFolder( self::$uid )->get( self::$gid );
		$this->assertInstanceOf( Folder::class, $team );

		$file  = $this->hashedFile( $team, 'd.txt', 'one' );
		$mtime = $file->getMTime();

		$file->putContent( 'two' );

		$version = $this->versionAt( $file, $mtime );

		$this->assertNotNull( $version, 'groupfolders made a version' );
		$this->assertSame( FileLocation::NS_GROUPFOLDER, $version->namespace );
		$this->assertSame( $file->getId(), $version->version?->fileId );
		$this->assertSame( sha1( 'one' ), $this->hashesOf( $version->fileId )['sha1'] ?? null );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function docs(): Folder
	{
		return self::folderAt( Server::get( IRootFolder::class )->getUserFolder( self::$uid ), 'Docs' );
	}

	/**
	 * A file with a current sha1, its mtime set back first: a version is
	 * named after it, and a touch after hashing would leave the hash
	 * outdated.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function hashedFile(
		Folder $folder,
		string $name,
		string $content,
	): File
	{
		$file = $folder->newFile( $name, $content );
		$file->touch( time() - 3600 );

		$file = Server::get( IRootFolder::class )->getFirstNodeById( $file->getId() );
		$this->assertInstanceOf( File::class, $file );

		$result = Server::get( HashIndexService::class )->recalcFileHash( $file, 'sha1', false );
		$this->assertTrue( $result['success'] ?? false, 'hashing ' . $name );

		return $file;
	}

	/**
	 * $file as written, hashed again, as the queue would.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function rehashed( File $file ): File
	{
		$file = Server::get( IRootFolder::class )->getFirstNodeById( $file->getId() );
		$this->assertInstanceOf( File::class, $file );

		$result = Server::get( HashIndexService::class )->recalcFileHash( $file, 'sha1', false );
		$this->assertTrue( $result['success'] ?? false, 'hashing ' . $file->getName() );
		$this->assertTrue( $this->isCurrent( $file->getId() ) );

		return $file;
	}

	/**
	 * Restore $file's version of $revision, as the Versions tab does.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function restore(
		File $file,
		int  $revision,
	): void
	{
		$manager = Server::get( IVersionManager::class );
		$user    = Server::get( IUserManager::class )->get( self::$uid );
		$this->assertNotNull( $user );

		foreach ( $manager->getVersionsForFile( $user, $file ) as $version )
		{
			if ( (int) $version->getRevisionId() === $revision )
			{
				$manager->rollback( $version );

				return;
			}
		}

		$this->fail( 'no version of ' . $revision );
	}

	/**
	 * The version Nextcloud keeps of $file as it was at $mtime, or null.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function versionAt(
		File $file,
		int  $mtime,
	): ?FileLocation
	{
		$filecache = Server::get( FilecacheService::class );
		$location  = $filecache->locate( $file->getId() );
		$path      = $location?->versionPathAt( $mtime );

		$this->assertNotNull( $path );

		return $filecache->locateAllByPath( [ $location->storageId => [ $path ] ] )
			[ FilecacheService::identityKey( $location->storageId, $path ) ] ?? null;
	}

	/**
	 * @return array<string, string>
	 */
	private function hashesOf( int $fileId ): array
	{
		return Server::get( MetadataService::class )->getHashes( $fileId );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function isCurrent( int $fileId ): bool
	{
		$metadata = Server::get( MetadataService::class );

		return $metadata->isCurrent(
			$metadata->getMetadata( $fileId ),
			Server::get( FilecacheService::class )->stateOf( $fileId ),
		);
	}

	/**
	 * An enabled include rule, auto, sha1: the instance's own are set aside
	 * for the test.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function rule(
		string $selector,
		string $path,
	): void
	{
		$this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_INCLUDE,
				'path'     => $path,
				'mode'     => MetadataService::PENDING_MODE_AUTO,
				'selector' => $selector,
				'algos'    => [ 'sha1' ],
			],
		);
	}
}
