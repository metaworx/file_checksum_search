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
use OCP\Files\File;
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
 * A version is a result like a file, held where its file is and named
 * after it: in the listing, the duplicates, the lookup, the files sharing
 * a file's hash, the missing files and the unified search.
 *
 * The versions are the versions app's own, made by overwriting files as
 * their owner; their checksums are written straight onto them, so that no
 * rule needs to be in place while they are made.
 *
 * - `Docs/f.txt` was X and is Y, and `Docs/g.txt` is X: X is a duplicate
 *   pair of a file and another file's version.
 * - `Docs/h.txt` was Z and is Z again: a file and its own version.
 * - `Private/p.txt` was W: a version nobody but its owner reaches.
 * - `Docs/m.txt`'s version has no checksum: a version missing one.
 *
 * `Docs` is shared with the recipient; where the groupfolders app is
 * enabled, a team folder of the owner's holds `t.txt`, which was T.
 */
class VersionResultsTest
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

	/** @var array<string, int> the files' and the versions' ids */
	private static array $id = [];

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

		[ self::$owner ]     = self::makeAccount( 'fcias_vres_owner' );
		[ self::$recipient ] = self::makeAccount( 'fcias_vres_recipient' );

		if ( $apps->isEnabledForUser( 'groupfolders' ) )
		{
			$apps->loadApp( 'groupfolders' );

			self::$gid = 'fcias_vres_' . bin2hex( random_bytes( 4 ) );
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
			'X'  => "fcias vres X $salt",
			'Y'  => "fcias vres Y $salt",
			'Z'  => "fcias vres Z $salt",
			'W'  => "fcias vres W $salt",
			'W2' => "fcias vres W2 $salt",
			'M'  => "fcias vres M $salt",
			'M2' => "fcias vres M2 $salt",
			'T'  => "fcias vres T $salt",
			'T2' => "fcias vres T2 $salt",
		];

		self::actAs( self::$owner );

		try
		{
			$root = Server::get( IRootFolder::class )->getUserFolder( self::$owner );
			$docs = $root->newFolder( 'Docs' );

			self::versioned( 'f', $docs, 'f.txt', 'X', 'Y' );
			self::versioned( 'h', $docs, 'h.txt', 'Z', 'Z' );
			self::versioned( 'p', $root->newFolder( 'Private' ), 'p.txt', 'W', 'W2' );
			self::versioned( 'm', $docs, 'm.txt', 'M', 'M2', hashVersion: false );
			self::$id['g'] = $docs->newFile( 'g.txt', self::$content['X'] )->getId();
			self::hash( self::$id['g'], 'X' );

			if ( self::$gid !== null )
			{
				$team = $root->get( self::$gid );
				self::assertInstanceOf( Folder::class, $team );
				self::versioned( 't', $team, 't.txt', 'T', 'T2' );
			}

			$share = Server::get( IShareManager::class )->newShare();
			$share->setNode( $docs )
			      ->setShareType( IShare::TYPE_USER )
			      ->setSharedWith( self::$recipient )
			      ->setSharedBy( self::$owner )
			      ->setPermissions( Constants::PERMISSION_READ )
			;
			Server::get( IShareManager::class )->createShare( $share );

			// The recipient's files set up, as their first request does: the
			// share is in the mounts recorded for them from then on.
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

		// The accounts, and with them the files and the share, go with the
		// parent's teardown.
		parent::tearDownAfterClass();
	}

	/**
	 * A file written as $before, then as $after, so that the versions app
	 * keeps a version of $before: the file's id under $key, the version's
	 * under `$key@v`; both hashed, the version unless asked not to.
	 */
	private static function versioned(
		string $key,
		Folder $folder,
		string $name,
		string $before,
		string $after,
		bool   $hashVersion = true,
	): void
	{
		$file = $folder->newFile( $name, self::$content[ $before ] );
		$file->touch( time() - 3600 );

		$file = Server::get( IRootFolder::class )->getFirstNodeById( $file->getId() );
		self::assertInstanceOf( File::class, $file );

		$mtime = $file->getMTime();
		$file->putContent( self::$content[ $after ] );

		$filecache = Server::get( FilecacheService::class );
		$location  = $filecache->locate( $file->getId() );
		$path      = (string) $location?->versionPathAt( $mtime );
		$version   = $filecache->locateAllByPath( [ (string) $location?->storageId => [ $path ] ] )
			[ FilecacheService::identityKey( (string) $location?->storageId, $path ) ] ?? null;

		self::assertNotNull( $version, "the versions app made a version of $name" );

		self::$id[ $key ]        = $file->getId();
		self::$id[ $key . '@v' ] = $version->fileId;

		self::hash( $file->getId(), $after );

		if ( $hashVersion )
		{
			self::hash( $version->fileId, $before );
		}
	}

	/**
	 * Hash $fileId as holding content $key, current: written as an import
	 * writes, against its own ETag.
	 */
	private static function hash(
		int    $fileId,
		string $key,
	): void
	{
		Server::get( MetadataService::class )->writeHashes(
			$fileId,
			[ 'sha1' => sha1( self::$content[ $key ] ) ],
			time(),
			false,
			Server::get( FilecacheService::class )->etagOf( $fileId ),
		);
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
	public function testTheListingNamesAVersionAfterItsFile(): void
	{
		$entries = iterator_to_array( Server::get( HashListingService::class )->iterate( [ self::$owner ] ) );
		$version = $entries[ self::$id['f@v'] ] ?? null;

		$this->assertNotNull( $version, 'the version is listed' );
		$this->assertSame( 'versions:///Docs/f.txt', $version['path'] );
		$this->assertSame( 'f.txt', $version['name'] );
		$this->assertSame( 'versions://home:' . self::$owner . '//Docs/f.txt', $version['location'] );
		$this->assertSame( self::$id['f'], $version['version']['fileid'] ?? null );
		$this->assertSame( sha1( self::$content['X'] ), $version['hashes']['sha1']['hash'] ?? null );
		$this->assertArrayNotHasKey( 'version', $entries[ self::$id['f'] ] ?? [], 'the file is no version' );
	}

	/**
	 * Through a share, the versions of what is shared, under the share; not
	 * those of what is not.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAShareRecipientHoldsTheVersionsOfWhatIsShared(): void
	{
		$entries = iterator_to_array( Server::get( HashListingService::class )->iterate( [ self::$recipient ] ) );

		$this->assertArrayHasKey( self::$id['f@v'], $entries, 'the shared file\'s version' );
		$this->assertSame( 'versions:///Docs/f.txt', $entries[ self::$id['f@v'] ]['path'] );
		$this->assertStringStartsWith( 'versions://share:', $entries[ self::$id['f@v'] ]['location'] );
		$this->assertArrayNotHasKey( self::$id['p@v'], $entries, 'not the version of a file not shared' );

		$found = array_column(
			Server::get( ChecksumApi::class )->findByHash( sha1( self::$content['X'] ), [ self::$recipient ], 'sha1' )['results'],
			null,
			'fileid',
		);

		$this->assertSame( 'versions:///Docs/f.txt', $found[ self::$id['f@v'] ]['path'] ?? null );
		$this->assertSame( [], Server::get( ChecksumApi::class )->findByHash( sha1( self::$content['W'] ), [ self::$recipient ], 'sha1' )['results'] );
	}

	/**
	 * A version and another file are a duplicate pair; a file and its own
	 * version are its history.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAVersionIsADuplicateOfAnotherFileNotOfItsOwn(): void
	{
		$api   = Server::get( ChecksumApi::class );
		$pairs = $api->findDuplicatesFor( [ self::$owner ], 'sha1', 2, 50, 0, sha1( self::$content['X'] ) )['duplicates'];

		$this->assertCount( 1, $pairs );

		$files    = array_column( $pairs[0]['files'], null, 'fileid' );
		$ids      = array_keys( $files );
		$expected = [ self::$id['f@v'], self::$id['g'] ];
		sort( $ids );
		sort( $expected );

		$this->assertSame( $expected, $ids );
		$this->assertSame( self::$id['f'], $files[ self::$id['f@v'] ]['version']['fileid'] ?? null );

		$this->assertSame( [], $api->findDuplicatesFor( [ self::$owner ], 'sha1', 2, 50, 0, sha1( self::$content['Z'] ) )['duplicates'] );

		$same = $api->findSameHash( self::$id['g'], [ self::$owner ] )['duplicates'];
		$this->assertSame( [ self::$id['f@v'] ], array_column( $same[0]['files'] ?? [], 'fileid' ), 'the other file\'s version shares the hash' );
		$this->assertSame( [], $api->findSameHash( self::$id['h'], [ self::$owner ] )['duplicates'], 'the file\'s own version is its history' );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATeamFoldersVersionIsTheMembersToo(): void
	{
		if ( self::$gid === null )
		{
			$this->markTestSkipped( 'FCIAS integration: the groupfolders app is not enabled on this instance.' );
		}

		$entries = iterator_to_array( Server::get( HashListingService::class )->iterate( [ self::$owner ] ) );

		$this->assertSame( 'versions:///' . self::$gid . '/t.txt', $entries[ self::$id['t@v'] ]['path'] ?? null );
		$this->assertSame( self::$id['t'], $entries[ self::$id['t@v'] ]['version']['fileid'] ?? null );
	}

	/**
	 * A version without a checksum is missing one where its rule hashes
	 * versions; under one that does not, it is as an ignored file is.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAVersionIsMissingAChecksumOnlyWhereItsRuleHashesVersions(): void
	{
		$id = $this->rule( versions: false );

		$this->assertNotContains( self::$id['m@v'], $this->missing() );
		$this->assertContains( self::$id['m@v'], $this->missing( withIgnored: true ) );

		$this->ruleService->ruleDelete( $id );
		$this->rule( versions: true );

		$this->assertContains( self::$id['m@v'], $this->missing() );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheUnifiedSearchOffersAVersionAsItsFile(): void
	{
		$query = $this->createMock( ISearchQuery::class );
		$query->method( 'getTerm' )->willReturn( sha1( self::$content['W'] ) );
		$query->method( 'getLimit' )->willReturn( 10 );

		$owner   = Server::get( IUserManager::class )->get( self::$owner );
		$this->assertNotNull( $owner );
		$entries = Server::get( HashSearchProvider::class )->search( $owner, $query )->jsonSerialize()['entries'];

		$this->assertCount( 1, $entries );
		$this->assertSame( 'p.txt', $entries[0]->jsonSerialize()['title'] );
		$this->assertStringContainsString( 'Version of', $entries[0]->jsonSerialize()['subline'] );
		$this->assertStringContainsString( '/f/' . self::$id['p'] . '?', $entries[0]->jsonSerialize()['resourceUrl'], 'it opens the file' );
	}

	/**
	 * The ids the missing listing gives the owner, from the first file the
	 * class made: the instance's ids before it are other tests'.
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

	/**
	 * An include rule over the owner's home, the instance's own set aside.
	 *
	 * @return string  Its id.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function rule( bool $versions ): string
	{
		return $this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_INCLUDE,
				'path'     => '**',
				'mode'     => MetadataService::PENDING_MODE_AUTO,
				'selector' => 'home:' . self::$owner,
				'algos'    => [ 'sha1' ],
				'versions' => $versions,
			],
		);
	}
}
