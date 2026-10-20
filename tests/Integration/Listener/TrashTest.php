<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Listener;

use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCA\Files_Trashbin\Trash\ITrashManager;
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
 * A file keeps its checksums in the trash and comes back with them: the
 * trash keeps its id and its ETag, in a home and in a team folder, so they
 * are still proved after a restore. A file restored without them is queued
 * where a rule maintains it, as a written one is.
 */
class TrashTest
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

		[ self::$uid ] = self::makeAccount( 'fcias_trash' );

		if ( $apps->isEnabledForUser( 'groupfolders' ) )
		{
			$apps->loadApp( 'groupfolders' );

			self::$gid = 'fcias_trash_' . bin2hex( random_bytes( 4 ) );
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
	public function testAHomeFileKeepsItsChecksumsThroughTheTrashAndBack(): void
	{
		$this->keepsItsChecksumsThroughTheTrashAndBack( $this->home(), 'home.txt' );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATeamFolderFileKeepsItsChecksumsThroughTheTrashAndBack(): void
	{
		if ( self::$gid === null )
		{
			$this->markTestSkipped( 'FCIAS integration: the groupfolders app is not enabled on this instance.' );
		}

		$team = $this->home()->get( self::$gid );
		$this->assertInstanceOf( Folder::class, $team );

		$this->keepsItsChecksumsThroughTheTrashAndBack( $team, 'team.txt' );
	}

	/**
	 * Trashed while no rule maintained it, restored under one that does:
	 * queued at once, where the sweep would come to it only later.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARestoredFileWithoutChecksumsIsQueued(): void
	{
		$file   = $this->home()->newFile( 'plain.txt', 'plain' );
		$fileId = $file->getId();
		$file->delete();

		$this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_INCLUDE,
				'path'     => '**',
				'mode'     => MetadataService::PENDING_MODE_AUTO,
				'selector' => 'home:' . self::$uid,
				'algos'    => [ 'sha1' ],
			],
		);

		$this->restore( 'plain.txt' );

		$this->assertSame( MetadataService::PENDING_PREFIX . 'auto', Server::get( MetadataService::class )->getMarker( $fileId ) );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function keepsItsChecksumsThroughTheTrashAndBack(
		Folder $folder,
		string $name,
	): void
	{
		$file   = $folder->newFile( $name, 'content of ' . $name );
		$fileId = $file->getId();
		Server::get( HashCalculationService::class )->recalcHashes( $fileId, [ 'sha1' ] );
		$this->assertCurrent( $fileId, $name, 'hashed' );

		$file->delete();

		$this->assertStringStartsNotWith( 'files/', (string) Server::get( FilecacheService::class )->locate( $fileId )?->internalPath, 'in the trash' );
		$this->assertCurrent( $fileId, $name, 'in the trash' );

		$this->restore( $name );

		$this->assertInstanceOf( File::class, Server::get( IRootFolder::class )->getFirstNodeById( $fileId ), 'restored' );
		$this->assertCurrent( $fileId, $name, 'restored' );
		$this->assertNull( Server::get( MetadataService::class )->getMarker( $fileId ), 'and not queued' );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function assertCurrent(
		int    $fileId,
		string $name,
		string $when,
	): void
	{
		$metadata = Server::get( MetadataService::class );

		$this->assertSame( sha1( 'content of ' . $name ), $metadata->getHashes( $fileId )['sha1'] ?? null, 'the checksum, ' . $when );
		$this->assertTrue( $metadata->fileStatesOf( [ $fileId ] )[ $fileId ]['vouched'] ?? false, 'still proved, ' . $when );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function restore( string $name ): void
	{
		$trash = Server::get( ITrashManager::class );
		$user  = Server::get( IUserManager::class )->get( self::$uid );
		$this->assertNotNull( $user );

		foreach ( $trash->listTrashRoot( $user ) as $item )
		{
			if ( $item->getName() === $name )
			{
				$trash->restoreItem( $item );

				return;
			}
		}

		$this->fail( $name . ' is not in the trash' );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function home(): Folder
	{
		return Server::get( IRootFolder::class )->getUserFolder( self::$uid );
	}
}
