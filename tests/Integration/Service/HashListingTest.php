<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * The hash listing on a real database, with real mounts.
 *
 * The selection is SQL — the index rows, the filecache, the mounts and the
 * stamp joined — and the paths come from the mounts' points, so neither
 * can be claimed from a unit test, where the query builder is mocked.
 *
 * Bob shares one folder with alice; a file beside it stays his. Alice's
 * listing holds her own file and the shared ones, by her names for them,
 * and nothing else of bob's.
 */
class HashListingTest
    extends
    DatabaseTestCase
{

//  private properties

	private static string $ownerUid;

	private static string $recipientUid;

	private static string $salt;

	/** @var array{a: int, b: int, c: int, r: int} */
	private static array $fileId;

	/** @var array{a: string, b: string, c: string, r: string} */
	private static array $content;

	private ChecksumApi $api;


//  static methods

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		[ self::$ownerUid ]     = self::makeAccount( 'fcias_list_owner' );
		[ self::$recipientUid ] = self::makeAccount( 'fcias_list_recipient' );

		self::$salt    = bin2hex( random_bytes( 4 ) );
		self::$content = [
			'a' => 'fcias list a ' . self::$salt,
			'b' => 'fcias list b ' . self::$salt,
			'c' => 'fcias list c ' . self::$salt,
			'r' => 'fcias list r ' . self::$salt,
		];

		$root   = Server::get( IRootFolder::class );
		$owner  = $root->getUserFolder( self::$ownerUid );
		$shared = $owner->newFolder( 'shared_' . self::$salt );
		$other  = $owner->newFolder( 'other_' . self::$salt );

		$files = [
			'a' => $shared->newFile( 'a.txt', self::$content['a'] ),
			'b' => $shared->newFile( 'b.txt', self::$content['b'] ),
			'c' => $other->newFile( 'c.txt', self::$content['c'] ),
		];

		$shareManager = Server::get( IShareManager::class );
		$share        = $shareManager->newShare();
		$share->setNode( $shared )
		      ->setShareType( IShare::TYPE_USER )
		      ->setSharedWith( self::$recipientUid )
		      ->setSharedBy( self::$ownerUid )
		      ->setPermissions( Constants::PERMISSION_READ )
		;
		$shareManager->createShare( $share );

		// After the share: setting the recipient's files up is what records
		// their mounts, the received share among them, where the reach
		// reads them.
		$files['r'] = $root->getUserFolder( self::$recipientUid )->newFile( 'r.txt', self::$content['r'] );

		foreach ( $files as $key => $file )
		{
			/** @var File $file */
			self::$fileId[ $key ] = $file->getId();
			self::stateHashes( $file->getId(), [ 'sha256' => hash( 'sha256', self::$content[ $key ] ) ] );
		}

		// One file with a second algorithm, for the filter.
		self::stateHashes( self::$fileId['c'], [ 'sha1' => sha1( self::$content['c'] ) ] );
	}

	/**
	 * Hashes written as an import writes them, stamped: a recalculation by
	 * hand stamps nothing, since one algorithm recomputed says nothing of
	 * the file's others.
	 *
	 * @param  array<string, string>  $hashes
	 */
	private static function stateHashes(
		int   $fileId,
		array $hashes,
	): void
	{
		Server::get( MetadataService::class )->writeHashes( $fileId, $hashes, time(), true );
	}


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->api = Server::get( ChecksumApi::class );
	}


//  other non-static methods

	/**
	 * The recipient's reach is their own files and the shared subtree:
	 * bob's file beside it is in the same storage, and not listed.
	 */
	public function testTheRecipientListsTheShareAndNothingBesideIt(): void
	{
		$page = $this->api->listHashes( [ self::$recipientUid ] );

		$this->assertSame(
			[ self::$fileId['a'], self::$fileId['b'], self::$fileId['r'] ],
			array_column( $page['files'], 'fileid' ),
		);
		$this->assertNull( $page['next'] );
		$this->assertSame( 3, $page['estimated_total'] );

		$byId = array_column( $page['files'], null, 'fileid' );

		$this->assertSame( '/shared_' . self::$salt . '/a.txt', $byId[ self::$fileId['a'] ]['path'], 'the recipient\'s name for it' );
		$this->assertSame( 'a.txt', $byId[ self::$fileId['a'] ]['name'] );
		$this->assertSame( self::$ownerUid, $byId[ self::$fileId['a'] ]['owner'], 'whose it is' );
		$this->assertSame( '/r.txt', $byId[ self::$fileId['r'] ]['path'] );
		$this->assertSame( self::$recipientUid, $byId[ self::$fileId['r'] ]['owner'] );
	}

	/**
	 * The index holds 63 characters of a SHA-256; the listing gives all 64,
	 * from the metadata document.
	 */
	public function testAHashIsWholeFromTheDocument(): void
	{
		$entry = $this->entryFor( $this->api->listHashes( [ self::$ownerUid ] ), 'a' );

		$this->assertSame(
			[ 'sha256' => [ 'algo' => 'sha256', 'hash' => hash( 'sha256', self::$content['a'] ) ] ],
			$entry['hashes'],
		);
		$this->assertNotNull( $entry['updated_at'] );
	}

	public function testTheAlgorithmNarrowsTheFilesAndTheirHashes(): void
	{
		$page = $this->api->listHashes( [ self::$ownerUid ], 'sha1' );

		$this->assertSame( [ self::$fileId['c'] ], array_column( $page['files'], 'fileid' ) );
		$this->assertSame( [ 'sha1' ], array_keys( $page['files'][0]['hashes'] ) );
		$this->assertSame( 1, $page['estimated_total'] );
	}

	public function testSinceKeepsTheFilesStampedAtOrAfterIt(): void
	{
		$stamp = Server::get( MetadataService::class )->getUpdatedAt( self::$fileId['a'] );

		$this->assertNotNull( $stamp );

		$from = $this->api->listHashes( [ self::$ownerUid ], null, 500, 0, $stamp );
		$this->assertContains( self::$fileId['a'], array_column( $from['files'], 'fileid' ), 'at the stamp' );

		$later = $this->api->listHashes( [ self::$ownerUid ], null, 500, 0, time() + 3600 );
		$this->assertSame( [], $later['files'], 'after every stamp' );
		$this->assertSame( 0, $later['estimated_total'] );
	}

	/**
	 * Every account in reach: the owner's view of the file, and where it is
	 * on the server's disk when asked.
	 */
	public function testWithEveryAccountTheOwnerNamesTheFile(): void
	{
		$after = min( self::$fileId ) - 1;
		$entry = $this->entryFor( $this->api->listHashes( null, null, 1000, $after, null, true ), 'a' );

		$this->assertSame( '/shared_' . self::$salt . '/a.txt', $entry['path'] );
		$this->assertSame( '/' . self::$ownerUid . '/files/shared_' . self::$salt . '/a.txt', $entry['location'] );
		$this->assertArrayHasKey( 'localPath', $entry );

		if ( $entry['localPath'] !== null )
		{
			$this->assertStringEndsWith( '/' . self::$ownerUid . '/files/shared_' . self::$salt . '/a.txt', $entry['localPath'] );
		}
	}

	/**
	 * Keyset paging: a file deleted after its page was read moves nothing,
	 * and the next page starts after it all the same.
	 */
	public function testAFileDeletedBetweenPagesSkipsNothing(): void
	{
		[ $uid ] = self::makeAccount( 'fcias_list_pages' );
		$folder  = Server::get( IRootFolder::class )->getUserFolder( $uid );
		$ids     = $this->hashedFiles( $folder, 3 );

		$first = $this->api->listHashes( [ $uid ], null, 1 );

		$this->assertSame( [ $ids[0] ], array_column( $first['files'], 'fileid' ) );
		$this->assertSame( $ids[0], $first['next'] );
		$this->assertSame( 3, $first['estimated_total'] );

		foreach ( $folder->getById( $ids[0] ) as $node )
		{
			$node->delete();
		}

		$rest = $this->api->listHashes( [ $uid ], null, 500, $first['next'] );

		$this->assertSame( [ $ids[1], $ids[2] ], array_column( $rest['files'], 'fileid' ) );
		$this->assertNull( $rest['next'] );
		$this->assertArrayNotHasKey( 'estimated_total', $rest );
	}

	/**
	 * A reset disowns a file's hashes: a lookup no longer finds them, and
	 * the listing no longer lists them.
	 */
	public function testADisownedFileIsLeftOut(): void
	{
		[ $uid ] = self::makeAccount( 'fcias_list_reset' );
		$ids     = $this->hashedFiles( Server::get( IRootFolder::class )->getUserFolder( $uid ), 2 );

		Server::get( MetadataService::class )->markStale( [ $ids[0] ] );

		$this->assertSame( [ $ids[1] ], array_column( $this->api->listHashes( [ $uid ] )['files'], 'fileid' ) );
	}

	/**
	 * @return list<int>  the new files' ids, in order
	 */
	private function hashedFiles(
		Folder $folder,
		int    $count,
	): array
	{
		$ids = [];

		for ( $n = 1; $n <= $count; $n ++ )
		{
			$content = "fcias list $n " . bin2hex( random_bytes( 4 ) );
			$ids[]   = $folder->newFile( "f$n.txt", $content )->getId();
			self::stateHashes( $ids[ $n - 1 ], [ 'sha256' => hash( 'sha256', $content ) ] );
		}

		return $ids;
	}

	/**
	 * @param  array{files: list<array<string, mixed>>}  $page
	 *
	 * @return array<string, mixed>
	 */
	private function entryFor(
		array  $page,
		string $key,
	): array
	{
		foreach ( $page['files'] as $entry )
		{
			if ( $entry['fileid'] === self::$fileId[ $key ] )
			{
				return $entry;
			}
		}

		$this->fail( "File $key is not listed." );
	}
}
