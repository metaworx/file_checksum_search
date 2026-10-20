<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Command\HashFiles;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\HashCalculationService;
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

/**
 * A rule that hashes versions has the queue, and `fcias:hash`, read the
 * older versions of the files it governs, which got no checksums when their
 * files were overwritten; one that does not leaves them alone. The versions
 * are made by the versions app, from files written before they had hashes.
 */
class VersionHashingTest
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

		[ self::$uid ] = self::makeAccount( 'fcias_vhash' );

		if ( $apps->isEnabledForUser( 'groupfolders' ) )
		{
			$apps->loadApp( 'groupfolders' );

			self::$gid = 'fcias_vhash_' . bin2hex( random_bytes( 4 ) );
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
	public function testARuleThatHashesVersionsHasTheQueueReadThem(): void
	{
		$version = $this->unhashedVersion( $this->folder( 'Docs' ), 'a.txt' );
		$rule    = $this->rule( 'home:' . self::$uid, 'Docs/**', versions: true );

		$this->ruleService->processRule( $rule );

		$this->assertSame( MetadataService::PENDING_PREFIX . 'auto', Server::get( MetadataService::class )->getMarker( $version->fileId ) );

		Server::get( HashCalculationService::class )->processFile( $version->fileId, MetadataService::PENDING_MODE_AUTO );

		$this->assertSame( sha1( 'one a.txt' ), Server::get( MetadataService::class )->getHashes( $version->fileId )['sha1'] ?? null );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARuleThatDoesNotLeavesThemUnread(): void
	{
		$version = $this->unhashedVersion( $this->folder( 'Plain' ), 'b.txt' );
		$rule    = $this->rule( 'home:' . self::$uid, 'Plain/**', versions: false );

		$this->ruleService->processRule( $rule );

		$this->assertNull( Server::get( MetadataService::class )->getMarker( $version->fileId ) );
	}

	/**
	 * A team folder's version lies outside the folder's `files` jail: the
	 * queue opens it through the versions app.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATeamFoldersVersionIsReadThroughTheVersionsApp(): void
	{
		if ( self::$folderId === null || self::$gid === null )
		{
			$this->markTestSkipped( 'FCIAS integration: the groupfolders app is not enabled on this instance.' );
		}

		$team = Server::get( IRootFolder::class )->getUserFolder( self::$uid )->get( self::$gid );
		$this->assertInstanceOf( Folder::class, $team );

		$version = $this->unhashedVersion( $team, 'c.txt' );
		$this->assertSame( FileLocation::NS_GROUPFOLDER, $version->namespace );

		$rule = $this->rule( 'groupfolder:' . self::$folderId, '**', versions: true );
		$this->ruleService->processRule( $rule );

		$this->assertSame( MetadataService::PENDING_PREFIX . 'auto', Server::get( MetadataService::class )->getMarker( $version->fileId ) );

		Server::get( HashCalculationService::class )->processFile( $version->fileId, MetadataService::PENDING_MODE_AUTO );

		$this->assertSame( sha1( 'one c.txt' ), Server::get( MetadataService::class )->getHashes( $version->fileId )['sha1'] ?? null );
	}

	/**
	 * `fcias:hash` hashes a version after the files where its rule says
	 * `versions`, and leaves one whose rule does not.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheHashCommandHashesTheVersionsWhereTheRuleSaysVersions(): void
	{
		$hashed = $this->unhashedVersion( $this->folder( 'CmdOn' ), 'd.txt' );
		$left   = $this->unhashedVersion( $this->folder( 'CmdOff' ), 'e.txt' );
		$this->rule( 'home:' . self::$uid, 'CmdOn/**', versions: true );
		$this->rule( 'home:' . self::$uid, 'CmdOff/**', versions: false );

		$this->hash( [ '--path' => 'Cmd*' ] );

		$metadata = Server::get( MetadataService::class );
		$this->assertSame( sha1( 'one d.txt' ), $metadata->getHashes( $hashed->fileId )['sha1'] ?? null );
		$this->assertSame( [], $metadata->getHashes( $left->fileId ) );
	}

	/**
	 * `--versions` hashes one whatever its rule says of versions, but not one
	 * its rule excludes.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheHashCommandWithVersionsHashesThemButNotAnExcludedOne(): void
	{
		$hashed = $this->unhashedVersion( $this->folder( 'AllIn' ), 'f.txt' );
		$left   = $this->unhashedVersion( $this->folder( 'AllOut' ), 'g.txt' );
		$this->rule( 'home:' . self::$uid, 'AllIn/**', versions: false );
		$this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_EXCLUDE,
				'path'     => 'AllOut/**',
				'selector' => 'home:' . self::$uid,
			],
		);

		$this->hash(
			[
				'--path'     => 'All*',
				'--versions' => true,
			],
		);

		$metadata = Server::get( MetadataService::class );
		$this->assertSame( sha1( 'one f.txt' ), $metadata->getHashes( $hashed->fileId )['sha1'] ?? null );
		$this->assertSame( [], $metadata->getHashes( $left->fileId ) );
	}

	/**
	 * Run `fcias:hash` over the test's account.
	 *
	 * @param  array<string, mixed>  $options
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function hash( array $options ): void
	{
		$tester = new CommandTester( Server::get( HashFiles::class ) );

		$this->assertSame( Command::SUCCESS, $tester->execute( [ '--user' => self::$uid ] + $options ), $tester->getDisplay() );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function folder( string $name ): Folder
	{
		return self::folderAt( Server::get( IRootFolder::class )->getUserFolder( self::$uid ), $name );
	}

	/**
	 * A version the versions app made of a file written before any rule
	 * governed it, so that nothing handed it checksums.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function unhashedVersion(
		Folder $folder,
		string $name,
	): FileLocation
	{
		$file = $folder->newFile( $name, 'one ' . $name );
		$file->touch( time() - 3600 );

		$file = Server::get( IRootFolder::class )->getFirstNodeById( $file->getId() );
		$this->assertInstanceOf( File::class, $file );

		$mtime = $file->getMTime();
		$file->putContent( 'two ' . $name );

		$filecache = Server::get( FilecacheService::class );
		$location  = $filecache->locate( $file->getId() );
		$path      = (string) $location?->versionPathAt( $mtime );
		$version   = $filecache->locateAllByPath( [ (string) $location?->storageId => [ $path ] ] )
			[ FilecacheService::identityKey( (string) $location?->storageId, $path ) ] ?? null;

		$this->assertNotNull( $version, 'the versions app made a version' );
		$this->assertSame( [], Server::get( MetadataService::class )->getHashes( $version->fileId ), 'and nothing hashed it' );

		return $version;
	}

	/**
	 * An enabled include rule, auto, sha1, as stored: the instance's own are
	 * set aside for the test.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function rule(
		string $selector,
		string $path,
		bool   $versions,
	): array
	{
		$id = $this->ruleService->ruleAdd(
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

		$rule = $this->ruleService->findRuleById( $id );
		$this->assertNotNull( $rule );

		return $rule;
	}
}
