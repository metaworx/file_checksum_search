<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Service\DuplicateActionService;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\Files_Versions\Versions\IVersionManager;
use OCA\GroupFolders\Folder\FolderManager;
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Throwable;

/**
 * Deleting the rows of a duplicate group, against a real instance: each kind
 * of row, to the trash and for good, in a home and in a team folder, and
 * each refusal — a changed file, another account's, a read-only share and
 * a share's root.
 */
class DuplicateActionsTest
    extends
    DatabaseTestCase
{

//  private properties

	private static string $uid;

	private static string $other;

	private static ?int $folderId = null;

	private static ?string $gid = null;

	private RuleService $ruleService;

	private DuplicateActionService $service;


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

		[ self::$uid ]   = self::makeAccount( 'fcias_dact' );
		[ self::$other ] = self::makeAccount( 'fcias_dact_other' );

		if ( $apps->isEnabledForUser( 'groupfolders' ) )
		{
			$apps->loadApp( 'groupfolders' );

			self::$gid = 'fcias_dact_' . bin2hex( random_bytes( 4 ) );
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

		$this->service = Server::get( DuplicateActionService::class );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		self::actAs( null );

		parent::tearDown();
	}

	/**
	 * A dry run deletes nothing, and says what would happen: a file to the
	 * trash, with the versions that go along, a named one labelled.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testADryRunActsOnNothingAndListsTheVersionsThatGoAlong(): void
	{
		[ $file, , $revision ] = $this->versioned( $this->home(), 'dry.txt' );
		$hash                  = $this->hashed( $file, 'two dry.txt' );

		Server::get( IVersionManager::class )->setMetadataValue( $file, $revision, 'label', 'Draft' );

		$result = $this->delete( [ $file->getId() ], $hash, dryRun: true )[ $file->getId() ];

		$this->assertSame( DuplicateActionService::STATUS_READY, $result['status'] );
		$this->assertSame( DuplicateActionService::KIND_FILE, $result['kind'] );
		$this->assertSame( DuplicateActionService::ACTION_TRASH, $result['action'] );
		$this->assertSame( [ $revision ], array_column( $result['versions'], 'timestamp' ) );
		$this->assertSame( [ 'Draft' ], array_column( $result['versions'], 'label' ) );
		$this->assertInstanceOf( File::class, $this->home()->getFirstNodeById( $file->getId() ), 'nothing was deleted' );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileGoesToTheTrash(): void
	{
		$file = $this->home()->newFile( 'trashed.txt', 'fcias dact trashed' );
		$hash = $this->hashed( $file, 'fcias dact trashed' );

		$result = $this->delete( [ $file->getId() ], $hash )[ $file->getId() ];

		$this->assertSame( DuplicateActionService::STATUS_TRASHED, $result['status'] );
		$this->assertNull( $this->home()->getFirstNodeById( $file->getId() ) );
		$this->assertNotNull( Server::get( ITrashManager::class )->getTrashNodeById( $this->user(), $file->getId() ) );
	}

	/**
	 * For good: past the trash, and its versions with it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileDeletedForGoodLeavesNothingInTheTrash(): void
	{
		[ $file, $versionId ] = $this->versioned( $this->home(), 'gone.txt' );
		$hash                 = $this->hashed( $file, 'two gone.txt' );

		$result = $this->delete( [ $file->getId() ], $hash, forGood: true )[ $file->getId() ];

		$this->assertSame( DuplicateActionService::STATUS_DELETED, $result['status'] );
		$this->assertSame( DuplicateActionService::ACTION_DELETE, $result['action'] );
		$this->assertNull( $this->home()->getFirstNodeById( $file->getId() ) );
		$this->assertNull( Server::get( ITrashManager::class )->getTrashNodeById( $this->user(), $file->getId() ) );
		$this->assertNull( Server::get( FilecacheService::class )->locate( $versionId ), 'its version went with it' );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAVersionIsDeletedAndItsFileStays(): void
	{
		[ $file, $versionId, $revision ] = $this->versioned( $this->home(), 'version.txt' );

		$result = $this->delete( [ $versionId ], sha1( 'one version.txt' ) )[ $versionId ];

		$this->assertSame( DuplicateActionService::KIND_VERSION, $result['kind'] );
		$this->assertSame( DuplicateActionService::STATUS_DELETED, $result['status'] );
		$this->assertNotContains( (string) $revision, $this->revisionsOf( $file ) );
		$this->assertInstanceOf( File::class, $this->home()->getFirstNodeById( $file->getId() ) );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileInTheTrashIsRemovedForGood(): void
	{
		$file = $this->home()->newFile( 'removed.txt', 'fcias dact removed' );
		$hash = $this->hashed( $file, 'fcias dact removed' );
		$id   = $file->getId();
		$file->delete();

		$result = $this->delete( [ $id ], $hash )[ $id ];

		$this->assertSame( DuplicateActionService::KIND_TRASH, $result['kind'] );
		$this->assertSame( DuplicateActionService::ACTION_DELETE, $result['action'] ?? null, (string) json_encode( $result ) );
		$this->assertSame( DuplicateActionService::STATUS_DELETED, $result['status'] );
		$this->assertNull( Server::get( ITrashManager::class )->getTrashNodeById( $this->user(), $id ) );
	}

	/**
	 * A file inside a trashed folder is its own item of the trash.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileInATrashedFolderIsRemovedForGood(): void
	{
		$folder = $this->home()->newFolder( 'folder-' . bin2hex( random_bytes( 3 ) ) );
		$file   = $folder->newFile( 'inside.txt', 'fcias dact inside' );
		$hash   = $this->hashed( $file, 'fcias dact inside' );
		$folder->delete();

		$result = $this->delete( [ $file->getId() ], $hash )[ $file->getId() ];

		$this->assertSame( DuplicateActionService::STATUS_DELETED, $result['status'] );
		$this->assertNull( Server::get( ITrashManager::class )->getTrashNodeById( $this->user(), $file->getId() ) );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAHomeVersionOfATrashedFileIsDeletedForGood(): void
	{
		[ $file, $versionId ] = $this->versioned( $this->home(), 'trashed-version.txt' );
		$id                   = $file->getId();
		$file->delete();

		$result = $this->delete( [ $versionId ], sha1( 'one trashed-version.txt' ) )[ $versionId ];

		$this->assertSame( DuplicateActionService::KIND_TRASHED_VERSION, $result['kind'] );
		$this->assertSame( DuplicateActionService::STATUS_DELETED, $result['status'], (string) json_encode( $result ) );
		$this->assertNull( Server::get( FilecacheService::class )->locate( $versionId ) );
		$this->assertNotNull( Server::get( ITrashManager::class )->getTrashNodeById( $this->user(), $id ), 'its file stays in the trash' );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileWhoseContentChangedIsRefused(): void
	{
		$file = $this->home()->newFile( 'changed.txt', 'fcias dact changed' );
		$this->hashed( $file, 'fcias dact changed' );

		$result = $this->delete( [ $file->getId() ], sha1( 'something else' ) )[ $file->getId() ];

		$this->assertSame( DuplicateActionService::STATUS_REFUSED, $result['status'] );
		$this->assertSame( 'Its content changed after the list was loaded.', $result['reason'] );
		$this->assertInstanceOf( File::class, $this->home()->getFirstNodeById( $file->getId() ) );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnotherAccountsFileIsRefused(): void
	{
		self::actAs( self::$other );
		$file = Server::get( IRootFolder::class )->getUserFolder( self::$other )->newFile( 'theirs.txt', 'fcias dact theirs' );
		$hash = $this->hashed( $file, 'fcias dact theirs' );
		self::actAs( self::$uid );

		$result = $this->delete( [ $file->getId() ], $hash )[ $file->getId() ];

		$this->assertSame( DuplicateActionService::STATUS_REFUSED, $result['status'] );
		$this->assertSame( 'It is not among your files.', $result['reason'] );
		$this->assertInstanceOf( File::class, Server::get( IRootFolder::class )->getFirstNodeById( $file->getId() ) );
	}

	/**
	 * A file in a folder shared read-only: there, but not the account's to
	 * delete, and the listing offers nothing for it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileSharedReadOnlyIsNeitherDeletedNorOffered(): void
	{
		$file = $this->sharedWithMe( 'readonly', Constants::PERMISSION_READ, inFolder: true );
		$hash = sha1( 'fcias dact shared readonly' );

		$result = $this->delete( [ $file->getId() ], $hash )[ $file->getId() ];

		$this->assertSame( DuplicateActionService::STATUS_REFUSED, $result['status'] );
		$this->assertSame( 'You may not delete it.', $result['reason'] );
		$this->assertSame( [], $this->actionsFor( [ $file->getId() ] )[ $file->getId() ] );
	}

	/**
	 * A file shared alone: deleting it in Files leaves the share and frees
	 * nothing, so it is refused, and offered nothing.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAShareRootIsNeitherDeletedNorOffered(): void
	{
		$file = $this->sharedWithMe( 'root', Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE, inFolder: false );
		$hash = sha1( 'fcias dact shared root' );

		$result = $this->delete( [ $file->getId() ], $hash )[ $file->getId() ];

		$this->assertSame( DuplicateActionService::STATUS_REFUSED, $result['status'] );
		$this->assertSame( 'It is shared with you. Leave the share in Files instead.', $result['reason'] );
		$this->assertSame( [], $this->actionsFor( [ $file->getId() ] )[ $file->getId() ] );
	}

	/**
	 * The listing's actions: a file may go to the trash or for good; a
	 * version and a trashed file only for good.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheListingOffersWhatMayBeDone(): void
	{
		[ $file, $versionId ] = $this->versioned( $this->home(), 'offered.txt' );
		$trashed              = $this->home()->newFile( 'offered-trashed.txt', 'fcias dact offered' );
		$trashedId            = $trashed->getId();
		$this->hashed( $trashed, 'fcias dact offered' );
		$trashed->delete();

		$actions = $this->actionsFor( [ $file->getId(), $versionId, $trashedId ] );

		$this->assertSame( [ DuplicateActionService::ACTION_TRASH, DuplicateActionService::ACTION_DELETE ], $actions[ $file->getId() ] );
		$this->assertSame( [ DuplicateActionService::ACTION_DELETE ], $actions[ $versionId ] );
		$this->assertSame( [ DuplicateActionService::ACTION_DELETE ], $actions[ $trashedId ] );
	}

	/**
	 * In a team folder: a version, then the file to the trash, then a
	 * version of the trashed file and the trashed file itself, for good.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATeamFoldersRowsAreDeletedEachAsTheirKind(): void
	{
		[ $file, $versionId, $revision ] = $this->versioned( $this->team(), 'team.txt' );

		// A second version, holding `two team.txt`, kept for the trash.
		$keptId = $this->kept( $file, 'two team.txt', 'three team.txt', 3600 );
		$file   = $this->refetched( $file );
		$hash   = $this->hashed( $file, 'three team.txt' );

		$version = $this->delete( [ $versionId ], sha1( 'one team.txt' ) )[ $versionId ];
		$this->assertSame( DuplicateActionService::KIND_VERSION, $version['kind'] );
		$this->assertSame( DuplicateActionService::STATUS_DELETED, $version['status'] );
		$this->assertNotContains( (string) $revision, $this->revisionsOf( $file ) );

		$trashed = $this->delete( [ $file->getId() ], $hash )[ $file->getId() ];
		$this->assertSame( DuplicateActionService::STATUS_TRASHED, $trashed['status'] );

		// The team folders app touches a version as its file goes to the
		// trash, and the stamp vouches no more; the queue hashes it again
		// where its rule says so, and only then is it listed.
		$this->hashedRow( $keptId, 'two team.txt' );

		$trashedVersion = $this->delete( [ $keptId ], sha1( 'two team.txt' ) )[ $keptId ];
		$this->assertSame( DuplicateActionService::KIND_TRASHED_VERSION, $trashedVersion['kind'] );
		$this->assertSame( DuplicateActionService::STATUS_DELETED, $trashedVersion['status'], (string) json_encode( $trashedVersion ) );

		$removed = $this->delete( [ $file->getId() ], $hash )[ $file->getId() ];
		$this->assertSame( DuplicateActionService::KIND_TRASH, $removed['kind'] );
		$this->assertSame( DuplicateActionService::STATUS_DELETED, $removed['status'] );
		$this->assertNull( Server::get( ITrashManager::class )->getTrashNodeById( $this->user(), $file->getId() ) );
	}

	/**
	 * The service's answer, keyed by file id.
	 *
	 * @param  list<int>  $fileIds
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function delete(
		array  $fileIds,
		string $hash,
		bool   $forGood = false,
		bool   $dryRun = false,
	): array
	{
		return array_column( $this->service->delete( $this->user(), $fileIds, 'sha1', $hash, $forGood, $dryRun ), null, 'fileid' );
	}

	/**
	 * The listing's actions for these rows, keyed by file id.
	 *
	 * @param  list<int>  $fileIds
	 *
	 * @return array<int, list<string>>
	 */
	private function actionsFor( array $fileIds ): array
	{
		$groups = $this->service->withActions(
			$this->user(),
			[ [ 'files' => array_map( static fn ( int $fileId ): array => [ 'fileid' => $fileId ], $fileIds ) ] ],
		);

		return array_column( $groups[0]['files'], 'actions', 'fileid' );
	}

	/**
	 * $file hashed as holding $content, against its own ETag.
	 *
	 * @return string  the hash
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function hashed(
		File   $file,
		string $content,
	): string
	{
		return $this->hashedRow( $file->getId(), $content );
	}

	/**
	 * The row $fileId hashed as holding $content, against its own ETag.
	 *
	 * @return string  the hash
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function hashedRow(
		int    $fileId,
		string $content,
	): string
	{
		$hash = sha1( $content );

		Server::get( MetadataService::class )->writeHashes(
			$fileId,
			[ 'sha1' => $hash ],
			time(),
			false,
			Server::get( FilecacheService::class )->etagOf( $fileId ),
		);

		return $hash;
	}

	/**
	 * A file written as `one <name>`, then written over as `two <name>`,
	 * so that the versions app keeps a version of it that carries its
	 * checksum.
	 *
	 * @return array{0: File, 1: int, 2: int}  the file, the version's id
	 *                                         and its revision
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function versioned(
		Folder $folder,
		string $name,
	): array
	{
		$file      = $folder->newFile( $name, 'one ' . $name );
		$versionId = $this->kept( $file, 'one ' . $name, 'two ' . $name, 7200 );
		$version   = Server::get( FilecacheService::class )->locate( $versionId );

		return [ $this->refetched( $file ), $versionId, (int) $version?->version?->timestamp ];
	}

	/**
	 * $file, holding $held, made $age seconds old and hashed as holding it,
	 * then written over with $next: the id of the version the versions app
	 * kept, which carries the checksum. Aged before the write, never after,
	 * since a touch re-dates the version the file is; and each version
	 * older than the next, as an edit history is.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function kept(
		File   $file,
		string $held,
		string $next,
		int    $age,
	): int
	{
		$filecache = Server::get( FilecacheService::class );

		$file->touch( time() - $age );
		$file = $this->refetched( $file );
		$this->hashed( $file, $held );
		$mtime = $file->getMTime();

		$file->putContent( $next );

		$location = $filecache->locate( $file->getId() );
		$path     = (string) $location?->versionPathAt( $mtime );
		$version  = $filecache->locateAllByPath( [ (string) $location?->storageId => [ $path ] ] )
			[ FilecacheService::identityKey( (string) $location?->storageId, $path ) ] ?? null;

		$this->assertInstanceOf( FileLocation::class, $version, 'the versions app made a version' );
		$this->assertSame( sha1( $held ), Server::get( MetadataService::class )->getHashes( $version->fileId )['sha1'] ?? null, 'which carries the checksum' );

		return $version->fileId;
	}

	/**
	 * $file read afresh, its time and ETag as they are now.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function refetched( File $file ): File
	{
		$node = Server::get( IRootFolder::class )->getFirstNodeById( $file->getId() );
		$this->assertInstanceOf( File::class, $node );

		return $node;
	}

	/**
	 * The revisions the versions app lists for $file.
	 *
	 * @return list<string>
	 */
	private function revisionsOf( File $file ): array
	{
		return array_map(
			static fn ( object $version ): string => (string) $version->getRevisionId(),
			Server::get( IVersionManager::class )->getVersionsForFile( $this->user(), $file ),
		);
	}

	/**
	 * A file of the other account's, holding `fcias dact shared <name>`,
	 * hashed, and shared with this one with $permissions: alone, or in a
	 * folder shared so. This account's node for it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function sharedWithMe(
		string $name,
		int    $permissions,
		bool   $inFolder,
	): File
	{
		self::actAs( self::$other );

		$root    = Server::get( IRootFolder::class )->getUserFolder( self::$other );
		$folder  = $root->newFolder( "shared-$name-" . bin2hex( random_bytes( 3 ) ) );
		$file    = $folder->newFile( "$name.txt", "fcias dact shared $name" );
		$this->hashed( $file, "fcias dact shared $name" );

		$share = Server::get( IShareManager::class )->newShare();
		$share->setNode( $inFolder ? $folder : $file )
		      ->setShareType( IShare::TYPE_USER )
		      ->setSharedWith( self::$uid )
		      ->setSharedBy( self::$other )
		      ->setPermissions( $permissions )
		;
		Server::get( IShareManager::class )->createShare( $share );

		self::actAs( self::$uid );

		$mine = $this->home()->getFirstNodeById( $file->getId() );
		$this->assertInstanceOf( File::class, $mine, 'the share reached this account' );

		return $mine;
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function team(): Folder
	{
		if ( self::$gid === null )
		{
			$this->markTestSkipped( 'FCIAS integration: the team folders app is not enabled on this instance.' );
		}

		$team = $this->home()->get( self::$gid );
		$this->assertInstanceOf( Folder::class, $team );

		return $team;
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function home(): Folder
	{
		return Server::get( IRootFolder::class )->getUserFolder( self::$uid );
	}

	private function user(): IUser
	{
		$user = Server::get( IUserManager::class )->get( self::$uid );
		$this->assertNotNull( $user );

		return $user;
	}
}
