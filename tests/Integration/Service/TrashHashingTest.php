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
 * A rule that hashes the trash has the queue, and `fcias:hash`, read the
 * files in it that it governs, judged at the places they had, which hold no
 * checksum because they were deleted before any rule governed them; one that
 * does not leaves them alone.
 */
class TrashHashingTest
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

		if ( ! $apps->isEnabledForUser( 'files_trashbin' ) )
		{
			self::markTestSkipped( 'FCIAS integration: the files_trashbin app is not enabled on this instance.' );
		}

		$apps->loadApp( 'files_trashbin' );

		if ( $apps->isEnabledForUser( 'files_versions' ) )
		{
			$apps->loadApp( 'files_versions' );
		}

		[ self::$uid ] = self::makeAccount( 'fcias_thash' );

		if ( $apps->isEnabledForUser( 'groupfolders' ) )
		{
			$apps->loadApp( 'groupfolders' );

			self::$gid = 'fcias_thash_' . bin2hex( random_bytes( 4 ) );
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
	public function testARuleThatHashesTheTrashHasTheQueueReadIt(): void
	{
		$fileId = $this->unhashedInTheTrash( $this->home(), 'Docs', 'a.txt' );
		$rule   = $this->rule( 'home:' . self::$uid, 'Docs/**', trash: true );

		$this->ruleService->processRule( $rule );

		$this->assertSame( MetadataService::PENDING_PREFIX . 'auto', Server::get( MetadataService::class )->getMarker( $fileId ) );

		Server::get( HashCalculationService::class )->processFile( $fileId, MetadataService::PENDING_MODE_AUTO );

		$this->assertSame( sha1( 'content a.txt' ), Server::get( MetadataService::class )->getHashes( $fileId )['sha1'] ?? null );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARuleThatDoesNotLeavesItUnread(): void
	{
		$fileId = $this->unhashedInTheTrash( $this->home(), 'Plain', 'b.txt' );
		$rule   = $this->rule( 'home:' . self::$uid, 'Plain/**', trash: false );

		$this->ruleService->processRule( $rule );

		$this->assertNull( Server::get( MetadataService::class )->getMarker( $fileId ) );
	}

	/**
	 * A team folder's trash lies outside the folder's `files` jail: the queue
	 * opens it all the same.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATeamFoldersTrashIsReadToo(): void
	{
		if ( self::$folderId === null || self::$gid === null )
		{
			$this->markTestSkipped( 'FCIAS integration: the groupfolders app is not enabled on this instance.' );
		}

		$team = $this->home()->get( self::$gid );
		$this->assertInstanceOf( Folder::class, $team );

		$fileId = $this->unhashedInTheTrash( $team, 'plans', 'c.md' );
		$rule   = $this->rule( 'groupfolder:' . self::$folderId, 'plans/**', trash: true );

		$this->ruleService->processRule( $rule );

		$this->assertSame( MetadataService::PENDING_PREFIX . 'auto', Server::get( MetadataService::class )->getMarker( $fileId ) );

		Server::get( HashCalculationService::class )->processFile( $fileId, MetadataService::PENDING_MODE_AUTO );

		$this->assertSame( sha1( 'content c.md' ), Server::get( MetadataService::class )->getHashes( $fileId )['sha1'] ?? null );
	}

	/**
	 * `fcias:hash` hashes a file in the trash after the versions where its
	 * rule says `trash`, and leaves one whose rule does not.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheHashCommandHashesTheTrashWhereTheRuleSaysTrash(): void
	{
		$hashed = $this->unhashedInTheTrash( $this->home(), 'CmdOn', 'd.txt' );
		$left   = $this->unhashedInTheTrash( $this->home(), 'CmdOff', 'e.txt' );
		$this->rule( 'home:' . self::$uid, 'CmdOn/**', trash: true );
		$this->rule( 'home:' . self::$uid, 'CmdOff/**', trash: false );

		$this->hash( [ '--path' => 'Cmd*' ] );

		$metadata = Server::get( MetadataService::class );
		$this->assertSame( sha1( 'content d.txt' ), $metadata->getHashes( $hashed )['sha1'] ?? null );
		$this->assertSame( [], $metadata->getHashes( $left ) );
	}

	/**
	 * `--trash` hashes one whatever its rule says of the trash, but not one
	 * its rule excludes.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheHashCommandWithTrashHashesThemButNotAnExcludedOne(): void
	{
		$hashed = $this->unhashedInTheTrash( $this->home(), 'AllIn', 'f.txt' );
		$left   = $this->unhashedInTheTrash( $this->home(), 'AllOut', 'g.txt' );
		$this->rule( 'home:' . self::$uid, 'AllIn/**', trash: false );
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
				'--path'  => 'All*',
				'--trash' => true,
			],
		);

		$metadata = Server::get( MetadataService::class );
		$this->assertSame( sha1( 'content f.txt' ), $metadata->getHashes( $hashed )['sha1'] ?? null );
		$this->assertSame( [], $metadata->getHashes( $left ) );
	}

	/**
	 * `fcias:hash` hashes a version the trash keeps where its file's rule
	 * says `versions` as well as `trash`, and leaves one whose rule says only
	 * `trash`.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheHashCommandHashesATrashedVersionWhereTheRuleSaysBoth(): void
	{
		$hashed = $this->unhashedVersionInTheTrash( $this->home(), 'VerOn', 'h.txt' );
		$left   = $this->unhashedVersionInTheTrash( $this->home(), 'VerOff', 'i.txt' );
		$this->rule( 'home:' . self::$uid, 'VerOn/**', trash: true, versions: true );
		$this->rule( 'home:' . self::$uid, 'VerOff/**', trash: true );

		$this->hash( [ '--path' => 'Ver*' ] );

		$metadata = Server::get( MetadataService::class );
		$this->assertSame( sha1( 'one h.txt' ), $metadata->getHashes( $hashed )['sha1'] ?? null );
		$this->assertSame( [], $metadata->getHashes( $left ) );
	}

	/**
	 * A team folder's version stays beside its trashed file, outside the
	 * trash: the queue opens it through the versions app, as the account
	 * that deleted the file.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATeamFoldersVersionOfATrashedFileIsReadByTheQueue(): void
	{
		if ( self::$folderId === null || self::$gid === null )
		{
			$this->markTestSkipped( 'FCIAS integration: the groupfolders app is not enabled on this instance.' );
		}

		$team = $this->home()->get( self::$gid );
		$this->assertInstanceOf( Folder::class, $team );

		$versionId = $this->unhashedVersionInTheTrash( $team, 'kept', 'j.txt' );
		$rule      = $this->rule( 'groupfolder:' . self::$folderId, 'kept/**', trash: true, versions: true );

		$this->ruleService->processRule( $rule );

		$this->assertSame( MetadataService::PENDING_PREFIX . 'auto', Server::get( MetadataService::class )->getMarker( $versionId ) );

		Server::get( HashCalculationService::class )->processFile( $versionId, MetadataService::PENDING_MODE_AUTO );

		$this->assertSame( sha1( 'one j.txt' ), Server::get( MetadataService::class )->getHashes( $versionId )['sha1'] ?? null );
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
	 * A file deleted from $folder/$sub before any rule governed it, so that
	 * nothing handed it checksums.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function unhashedInTheTrash(
		Folder $folder,
		string $sub,
		string $name,
	): int
	{
		$file   = self::folderAt( $folder, $sub )->newFile( $name, 'content ' . $name );
		$fileId = $file->getId();

		$file->delete();

		$this->assertSame( [], Server::get( MetadataService::class )->getHashes( $fileId ), 'nothing hashed it' );

		return $fileId;
	}

	/**
	 * The version of `one <name>` in the trash, of a file in $folder/$sub
	 * written over and deleted before any rule governed it, so that nothing
	 * handed it checksums: a home's trash keeps it beside the trashed file,
	 * a team folder's version stays where it was.
	 *
	 * @return int  the version's file id
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function unhashedVersionInTheTrash(
		Folder $folder,
		string $sub,
		string $name,
	): int
	{
		$file = self::folderAt( $folder, $sub )->newFile( $name, 'one ' . $name );
		$file->touch( time() - 3600 );

		$file = Server::get( IRootFolder::class )->getFirstNodeById( $file->getId() );
		$this->assertInstanceOf( File::class, $file );

		$fileId = $file->getId();
		$mtime  = $file->getMTime();
		$file->putContent( 'two ' . $name );
		$file->delete();

		// Where the version is now: beside the trashed file in a home's
		// trash, named after it, or by the file's id in a team folder's.
		$filecache = Server::get( FilecacheService::class );
		$trashed   = $filecache->locate( $fileId );
		$this->assertNotNull( $trashed?->trash, 'the file is in the trash' );

		$path    = $trashed->namespace === FileLocation::NS_HOME
			? 'files_trashbin/versions/' . $name . '.v' . $mtime . '.d' . explode( '.d', basename( $trashed->internalPath ) )[1]
			: 'versions/' . $fileId . '/' . $mtime;
		$version = $filecache->locateAllByPath( [ $trashed->storageId => [ $path ] ] )
			[ FilecacheService::identityKey( $trashed->storageId, $path ) ] ?? null;

		$this->assertNotNull( $version, 'the trash kept its version' );
		$this->assertSame( [], Server::get( MetadataService::class )->getHashes( $version->fileId ), 'and nothing hashed it' );

		return $version->fileId;
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
		bool   $trash,
		bool   $versions = false,
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
				'trash'    => $trash,
				'versions' => $versions,
			],
		);

		$rule = $this->ruleService->findRuleById( $id );
		$this->assertNotNull( $rule );

		return $rule;
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function home(): Folder
	{
		return Server::get( IRootFolder::class )->getUserFolder( self::$uid );
	}
}
