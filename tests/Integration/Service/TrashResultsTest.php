<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Search\HashSearchProvider;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashListingService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCA\GroupFolders\Folder\FolderManager;
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Search\ISearchQuery;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Throwable;

/**
 * A file in the trash is a result like a file, held where it was before the
 * deletion and named after that place, under `trash://`: in the listing, the
 * duplicates, the lookup, the files sharing a file's hash, the missing files
 * and the unified search. Its owner holds it; a share's recipient does not.
 *
 * - `Docs/a.txt` is A, and `Docs/a2.txt`, A, is in the trash: no duplicate.
 * - `Docs/b.txt` and `Docs/b2.txt` are B, `Docs/b3.txt`, B, is in the trash:
 *   a duplicate pair, the trashed copy shown marked.
 * - `Docs/c.txt`, C, is in the trash alone.
 * - `Docs/d.txt` is in the trash without a checksum.
 *
 * `Docs` is shared with the recipient; where the groupfolders app is
 * enabled, a team folder of the owner's held `t.txt`, T, now in its trash.
 */
class TrashResultsTest
    extends
    DatabaseTestCase
{

//  private properties

	private static string $owner;

	private static string $recipient;

	private static ?int $folderId = null;

	private static ?string $gid = null;

	/** @var array<string, string> */
	private static array $content = [];

	/** @var array<string, int> */
	private static array $id = [];

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

		[ self::$owner ]     = self::makeAccount( 'fcias_tres_owner' );
		[ self::$recipient ] = self::makeAccount( 'fcias_tres_recipient' );

		if ( $apps->isEnabledForUser( 'groupfolders' ) )
		{
			$apps->loadApp( 'groupfolders' );

			self::$gid = 'fcias_tres_' . bin2hex( random_bytes( 4 ) );
			Server::get( IGroupManager::class )
			      ->createGroup( self::$gid )
			      ?->addUser( Server::get( IUserManager::class )->get( self::$owner ) )
			;

			$folders        = Server::get( FolderManager::class );
			self::$folderId = $folders->createFolder( self::$gid );
			$folders->addApplicableGroup( self::$folderId, self::$gid );
		}

		$salt          = bin2hex( random_bytes( 4 ) );
		self::$content = [
			'A' => "fcias tres A $salt",
			'B' => "fcias tres B $salt",
			'C' => "fcias tres C $salt",
			'D' => "fcias tres D $salt",
			'T' => "fcias tres T $salt",
		];

		self::actAs( self::$owner );

		try
		{
			$root = Server::get( IRootFolder::class )->getUserFolder( self::$owner );
			$docs = $root->newFolder( 'Docs' );

			self::file( 'a', $docs, 'a.txt', 'A' );
			self::file( 'a2', $docs, 'a2.txt', 'A', trash: true );
			self::file( 'b', $docs, 'b.txt', 'B' );
			self::file( 'b2', $docs, 'b2.txt', 'B' );
			self::file( 'b3', $docs, 'b3.txt', 'B', trash: true );
			self::file( 'c', $docs, 'c.txt', 'C', trash: true );
			self::file( 'd', $docs, 'd.txt', 'D', trash: true, hashed: false );

			if ( self::$gid !== null )
			{
				$team = $root->get( self::$gid );
				self::assertInstanceOf( Folder::class, $team );
				self::file( 't', $team, 't.txt', 'T', trash: true );
			}

			$share = Server::get( IShareManager::class )->newShare();
			$share->setNode( $docs )
			      ->setShareType( IShare::TYPE_USER )
			      ->setSharedWith( self::$recipient )
			      ->setSharedBy( self::$owner )
			      ->setPermissions( Constants::PERMISSION_READ )
			;
			Server::get( IShareManager::class )->createShare( $share );

			// The recipient's files set up, as their first request does.
			self::actAs( self::$recipient );
			self::assertInstanceOf( Folder::class, Server::get( IRootFolder::class )->getUserFolder( self::$recipient )->get( 'Docs' ) );
		}
		finally
		{
			self::actAs( null );
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

	/**
	 * A file holding content $key, its id under $key, hashed against its own
	 * ETag unless asked not to, and moved to the trash where asked.
	 */
	private static function file(
		string $key,
		Folder $folder,
		string $name,
		string $content,
		bool   $trash = false,
		bool   $hashed = true,
	): void
	{
		$file             = $folder->newFile( $name, self::$content[ $content ] );
		self::$id[ $key ] = $file->getId();

		if ( $hashed )
		{
			Server::get( MetadataService::class )->writeHashes(
				$file->getId(),
				[ 'sha1' => sha1( self::$content[ $content ] ) ],
				time(),
				false,
				Server::get( FilecacheService::class )->etagOf( $file->getId() ),
			);
		}

		if ( $trash )
		{
			$file->delete();
		}
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
	}


//  other non-static methods

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheListingNamesATrashedFileAfterItsPlace(): void
	{
		$entries = iterator_to_array( Server::get( HashListingService::class )->iterate( [ self::$owner ] ) );
		$trashed = $entries[ self::$id['c'] ] ?? null;

		$this->assertNotNull( $trashed, 'the trashed file is listed' );
		$this->assertSame( 'trash:///Docs/c.txt', $trashed['path'] );
		$this->assertSame( 'c.txt', $trashed['name'] );
		$this->assertSame( 'trash://home:' . self::$owner . '//Docs/c.txt', $trashed['location'] );
		$this->assertArrayHasKey( 'deletedAt', $trashed['trash'] ?? [] );
		$this->assertSame( sha1( self::$content['C'] ), $trashed['hashes']['sha1']['hash'] ?? null );
		$this->assertArrayNotHasKey( 'trash', $entries[ self::$id['b'] ] ?? [], 'a live file is in no trash' );
	}

	/**
	 * What its owner deletes from a shared folder is the owner's: the
	 * recipient holds none of it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAShareRecipientHoldsNoneOfTheOwnersTrash(): void
	{
		$entries = iterator_to_array( Server::get( HashListingService::class )->iterate( [ self::$recipient ] ) );

		$this->assertArrayHasKey( self::$id['b'], $entries, 'the shared file' );
		$this->assertArrayNotHasKey( self::$id['c'], $entries, 'not the owner\'s trashed one' );
		$this->assertSame( [], Server::get( ChecksumApi::class )->findByHash( sha1( self::$content['C'] ), [ self::$recipient ], 'sha1' )['results'] );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheLookupFindsATrashedFile(): void
	{
		$found = array_column(
			Server::get( ChecksumApi::class )->findByHash( sha1( self::$content['C'] ), [ self::$owner ], 'sha1' )['results'],
			null,
			'fileid',
		);

		$this->assertSame( 'trash:///Docs/c.txt', $found[ self::$id['c'] ]['path'] ?? null );
		$this->assertSame( 'trash://home:' . self::$owner . '//Docs/c.txt', $found[ self::$id['c'] ]['location'] ?? null );
		$this->assertArrayHasKey( 'trash', $found[ self::$id['c'] ] ?? [] );
	}

	/**
	 * A copy in the trash is shown in a group that is a duplicate, marked;
	 * one file with only a trashed copy is none.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATrashedCopyIsMarkedInAGroupAndMakesNoneAlone(): void
	{
		$api    = Server::get( ChecksumApi::class );
		$groups = $api->findDuplicatesFor( [ self::$owner ], 'sha1', 2, 50, 0, sha1( self::$content['B'] ) )['duplicates'];

		$this->assertCount( 1, $groups );

		$files    = array_column( $groups[0]['files'], null, 'fileid' );
		$ids      = array_keys( $files );
		$expected = [ self::$id['b'], self::$id['b2'], self::$id['b3'] ];
		sort( $ids );
		sort( $expected );

		$this->assertSame( $expected, $ids );
		$this->assertArrayHasKey( 'trash', $files[ self::$id['b3'] ] );
		$this->assertSame( 'trash:///Docs/b3.txt', $files[ self::$id['b3'] ]['path'] );

		$this->assertSame( [], $api->findDuplicatesFor( [ self::$owner ], 'sha1', 2, 50, 0, sha1( self::$content['A'] ) )['duplicates'] );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheFilesSharingAHashNameATrashedCopy(): void
	{
		$same  = Server::get( ChecksumApi::class )->findSameHash( self::$id['a'], [ self::$owner ] )['duplicates'];
		$files = array_column( $same[0]['files'] ?? [], null, 'fileid' );

		$this->assertSame( [ self::$id['a2'] ], array_keys( $files ) );
		$this->assertSame( 'trash:///Docs/a2.txt', $files[ self::$id['a2'] ]['path'] );
		$this->assertArrayHasKey( 'trash', $files[ self::$id['a2'] ] );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATeamFoldersTrashIsTheMembers(): void
	{
		if ( self::$gid === null )
		{
			$this->markTestSkipped( 'FCIAS integration: the groupfolders app is not enabled on this instance.' );
		}

		$entries = iterator_to_array( Server::get( HashListingService::class )->iterate( [ self::$owner ] ) );

		$this->assertSame( 'trash:///' . self::$gid . '/t.txt', $entries[ self::$id['t'] ]['path'] ?? null );
		$this->assertArrayNotHasKey( self::$id['t'], iterator_to_array( Server::get( HashListingService::class )->iterate( [ self::$recipient ] ) ) );
	}

	/**
	 * A trashed file without a checksum is not missing one: nothing hashes
	 * the trash unasked, as an ignore rule hashes nothing.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATrashedFileWithoutAChecksumIsIgnoredNotMissing(): void
	{
		$this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_INCLUDE,
				'path'     => '**',
				'mode'     => MetadataService::PENDING_MODE_AUTO,
				'selector' => 'home:' . self::$owner,
				'algos'    => [ 'sha1' ],
			],
		);

		$this->assertNotContains( self::$id['d'], $this->missing() );
		$this->assertContains( self::$id['d'], $this->missing( withIgnored: true ) );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheUnifiedSearchOffersATrashedFileAndOpensTheTrash(): void
	{
		$query = $this->createMock( ISearchQuery::class );
		$query->method( 'getTerm' )->willReturn( sha1( self::$content['C'] ) );
		$query->method( 'getLimit' )->willReturn( 10 );

		$owner   = Server::get( IUserManager::class )->get( self::$owner );
		$this->assertNotNull( $owner );
		$entries = Server::get( HashSearchProvider::class )->search( $owner, $query )->jsonSerialize()['entries'];

		$this->assertCount( 1, $entries );
		$this->assertStringStartsWith( 'c.txt (deleted ', $entries[0]->jsonSerialize()['title'] );
		$this->assertStringContainsString( 'trash:///Docs/c.txt', $entries[0]->jsonSerialize()['subline'] );
		$this->assertStringContainsString( '/trashbin/' . self::$id['c'], $entries[0]->jsonSerialize()['resourceUrl'], 'it opens the trash' );
	}

	/**
	 * The ids the missing listing gives the owner, from the first file the
	 * class made.
	 *
	 * @return list<int>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function missing( bool $withIgnored = false ): array
	{
		$ids   = [];
		$after = min( self::$id ) - 1;

		do
		{
			$page  = Server::get( HashListingService::class )->missingPage( [ self::$owner ], 'sha1', 1000, $after, withIgnored: $withIgnored );
			$ids   = array_merge( $ids, array_column( $page['files'], 'fileid' ) );
			$after = $page['next'];
		}
		while ( $after !== null );

		return $ids;
	}
}
