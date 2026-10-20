<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCA\GroupFolders\Folder\FolderManager;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use Throwable;

/**
 * A trashed file is governed by the rule of the place it had before the
 * deletion, read from the trash's own records: a home's, a team folder's,
 * and a file inside a trashed folder. Queued, the drain leaves it unread.
 */
class TrashGovernanceTest
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

		[ self::$uid ] = self::makeAccount( 'fcias_trashrule' );

		if ( $apps->isEnabledForUser( 'groupfolders' ) )
		{
			$apps->loadApp( 'groupfolders' );

			self::$gid = 'fcias_trashrule_' . bin2hex( random_bytes( 4 ) );
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
	public function testAHomeFileInTheTrashIsGovernedByTheRuleOfItsPlace(): void
	{
		$file   = self::folderAt( $this->home(), 'Docs' )->newFile( 'a.txt', 'a' );
		$fileId = $file->getId();
		$id     = $this->rule( 'home:' . self::$uid, 'Docs/**' );

		$file->delete();

		$this->assertStringStartsWith( 'files_trashbin/files/a.txt.d', (string) Server::get( FilecacheService::class )->locate( $fileId )?->internalPath );
		$this->assertSame( $id, $this->ruleService->findFirstMatchingRule( $fileId )['id'] ?? null );
		$this->assertSame( $id, $this->ruleService->governingRulesForFileIds( [ $fileId ] )[ $fileId ]['id'] ?? null );
	}

	/**
	 * Inside a trashed folder, a file's place is the folder's, and its path
	 * below it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileInsideATrashedFolderIsGovernedByTheRuleOfItsPlace(): void
	{
		$folder = self::folderAt( $this->home(), 'Proj' );
		$fileId = self::folderAt( $folder, 'sub' )->newFile( 'b.txt', 'b' )->getId();
		$this->rule( 'home:' . self::$uid, 'Other/**' );
		$id     = $this->rule( 'home:' . self::$uid, 'Proj/sub/*.txt' );

		$folder->delete();

		$location = Server::get( FilecacheService::class )->locate( $fileId );
		$this->assertNotNull( $location );
		$this->assertSame( 'files/Proj/sub/b.txt', Server::get( FilecacheService::class )->originsOfTrash( [ $location ] )[ $fileId ]->internalPath ?? null );
		$this->assertSame( $id, $this->ruleService->findFirstMatchingRule( $fileId )['id'] ?? null );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATeamFolderFileInTheTrashIsGovernedByTheRuleOfItsPlace(): void
	{
		if ( self::$folderId === null || self::$gid === null )
		{
			$this->markTestSkipped( 'FCIAS integration: the groupfolders app is not enabled on this instance.' );
		}

		$team = $this->home()->get( self::$gid );
		$this->assertInstanceOf( Folder::class, $team );

		$file   = self::folderAt( $team, 'plans' )->newFile( 'c.md', 'c' );
		$fileId = $file->getId();
		$id     = $this->rule( 'groupfolder:' . self::$folderId, 'plans/**' );

		$file->delete();

		$this->assertStringStartsWith( 'trash/c.md.d', (string) Server::get( FilecacheService::class )->locate( $fileId )?->internalPath );
		$this->assertSame( $id, $this->ruleService->findFirstMatchingRule( $fileId )['id'] ?? null );
	}

	/**
	 * Queued before its deletion, a trashed file is dropped from the queue
	 * unread, and keeps the hashes it has: a forced read would have replaced
	 * this one, which is not its content's.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheDrainLeavesATrashedFileUnread(): void
	{
		$this->rule( 'home:' . self::$uid, 'Queued/**' );
		$file     = self::folderAt( $this->home(), 'Queued' )->newFile( 'q.txt', 'q' );
		$fileId   = $file->getId();
		$metadata = Server::get( MetadataService::class );
		$metadata->writeHashes( $fileId, [ 'sha1' => sha1( 'written' ) ], time(), false );
		$metadata->markPending( $fileId, MetadataService::PENDING_FORCE );

		$file->delete();

		$outcome = Server::get( HashCalculationService::class )->processFile( $fileId, MetadataService::PENDING_MODE_FORCE );

		$this->assertTrue( $outcome->hasLeftTheQueue() );
		$this->assertSame( sha1( 'written' ), $metadata->getHashes( $fileId )['sha1'] ?? null );
	}

	/**
	 * An enabled include rule over $path, as stored.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function rule(
		string $selector,
		string $path,
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
