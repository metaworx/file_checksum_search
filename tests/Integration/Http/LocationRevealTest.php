<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Http;

use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * No route shows a share's recipient the sharer's folders above the share.
 *
 * Bob shares `Clients_…/Acme/x` with alice, directly and through her group,
 * and one file of `Acme` on its own; alice moves the folder's share to
 * `My Projects/Bob-x`. Every route alice asks names the files by her path
 * and locates them by the share — `share:<id>//…` — never by `Clients` or
 * `Acme`; bob reads his own address; a leader over alice reads what she
 * reads; a sudoer reads the file's own address. Over HTTP, with real
 * shares: the share's id is the one Nextcloud mounts it by, which only a
 * real mount shows.
 */
class LocationRevealTest
    extends
    DatabaseTestCase
{

//  constants

	private const BASE_URL = 'http://127.0.0.1/ocs/v2.php/apps/file_checksum_search';

	/** The instance's administrator — a sudoer, whose reach is every account. */
	private const ADMIN_UID = 'admin';

	private const ADMIN_PASSWORD = 'admin';


//  private properties

	private static string $ownerUid;

	private static string $ownerPassword;

	private static string $recipientUid;

	private static string $recipientPassword;

	private static string $leaderUid;

	private static string $leaderPassword;

	private static string $groupId;

	private static string $clients;

	/** The pair's content's sha1. */
	private static string $hash;

	/** @var array{a: int, b: int, solo: int} */
	private static array $fileId;

	/** The share alice holds `x` through: the user share, older than the group's. */
	private static string $folderShareId;

	private static string $soloShareId;


//  static methods

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		[ self::$ownerUid, self::$ownerPassword ]         = self::makeAccount( 'fcias_reveal_owner' );
		[ self::$recipientUid, self::$recipientPassword ] = self::makeAccount( 'fcias_reveal_recipient' );
		[ self::$leaderUid, self::$leaderPassword ]       = self::makeAccount( 'fcias_reveal_leader' );

		$salt          = bin2hex( random_bytes( 4 ) );
		$pair          = "fcias reveal pair $salt";
		self::$hash    = sha1( $pair );
		self::$clients = 'Clients_' . $salt;

		$root  = Server::get( IRootFolder::class );
		$owner = $root->getUserFolder( self::$ownerUid );
		$acme  = $owner->newFolder( self::$clients . '/Acme' );
		$x     = $acme->newFolder( 'x' );

		$files = [
			'a'    => $x->newFile( 'a.txt', $pair ),
			'b'    => $x->newFile( 'b.txt', $pair ),
			'solo' => $acme->newFile( 'solo.txt', "fcias reveal solo $salt" ),
		];

		$index = Server::get( HashIndexService::class );

		foreach ( $files as $key => $file )
		{
			/** @var File $file */
			self::$fileId[ $key ] = $file->getId();
			$index->recalcFileHash( $file, 'sha1', false );
		}

		// Alice is in a group the leader administers, and the group receives
		// the folder as well: Nextcloud mounts the two shares as one, by the
		// older.
		self::$groupId = 'fcias_reveal_' . $salt;
		$users         = Server::get( IUserManager::class );
		$group         = Server::get( IGroupManager::class )->createGroup( self::$groupId );
		$group->addUser( $users->get( self::$recipientUid ) );
		Server::get( ISubAdmin::class )->createSubAdmin( $users->get( self::$leaderUid ), $group );

		self::$folderShareId = self::share( $x, IShare::TYPE_USER, self::$recipientUid );
		self::share( $x, IShare::TYPE_GROUP, self::$groupId );
		self::$soloShareId = self::share( $files['solo'], IShare::TYPE_USER, self::$recipientUid );

		// Alice files the share away under a name of her own.
		$recipient = $root->getUserFolder( self::$recipientUid );
		$projects  = $recipient->newFolder( 'My Projects' );
		$recipient->get( 'x' )
		          ->move( $projects->getPath() . '/Bob-x' )
		;
	}

	public static function tearDownAfterClass(): void
	{
		$group  = Server::get( IGroupManager::class )->get( self::$groupId );
		$leader = Server::get( IUserManager::class )->get( self::$leaderUid );

		if ( $group !== null )
		{
			if ( $leader !== null )
			{
				Server::get( ISubAdmin::class )->deleteSubAdmin( $leader, $group );
			}

			$group->delete();
		}

		// The accounts, and with them the files and the shares, go with the
		// parent's teardown.
		parent::tearDownAfterClass();
	}

	/**
	 * @return string  The share's id.
	 */
	private static function share(
		Folder|File $node,
		int         $type,
		string      $with,
	): string
	{
		$manager = Server::get( IShareManager::class );
		$share   = $manager->newShare();
		$share->setNode( $node )
		      ->setShareType( $type )
		      ->setSharedWith( $with )
		      ->setSharedBy( self::$ownerUid )
		      ->setPermissions( Constants::PERMISSION_READ )
		;

		return $manager->createShare( $share )->getId();
	}


//  other non-static methods

	public function testTheHashListingLocatesSharedFilesByTheirShare(): void
	{
		$response = $this->asRecipient( '/api/v1/hashes?algo=sha1' );

		$this->assertSame( 200, $response['status'] );
		$this->assertSharersFoldersAreNotIn( $response['body'] );

		$byId = array_column( $response['body']['files'], null, 'fileid' );

		$this->assertSame( '/My Projects/Bob-x/a.txt', $byId[ self::$fileId['a'] ]['path'] );
		$this->assertSame( 'share:' . self::$folderShareId . '//a.txt', $byId[ self::$fileId['a'] ]['location'] );
		$this->assertSame( self::$ownerUid, $byId[ self::$fileId['a'] ]['owner'] );

		// A file shared on its own is its share's top.
		$this->assertSame( '/solo.txt', $byId[ self::$fileId['solo'] ]['path'] );
		$this->assertSame( 'share:' . self::$soloShareId . '//', $byId[ self::$fileId['solo'] ]['location'] );
	}

	public function testTheDuplicatesListingNamesThemAsTheRecipientDoes(): void
	{
		$response = $this->asRecipient( '/api/v1/duplicates?algo=sha1&hash=' . self::$hash );
		$files    = $this->duplicateFiles( $response );

		$this->assertSharersFoldersAreNotIn( $response['body'] );
		$this->assertSame(
			[ '/My Projects/Bob-x/a.txt', '/My Projects/Bob-x/b.txt' ],
			array_column( $files, 'path' ),
			'the recipient\'s path, without files/',
		);
		$this->assertSame(
			[ 'share:' . self::$folderShareId . '//a.txt', 'share:' . self::$folderShareId . '//b.txt' ],
			array_column( $files, 'location' ),
		);
	}

	public function testTheLookupLocatesThemByTheShare(): void
	{
		$response = $this->asRecipient( '/api/v1/lookup?algo=sha1&hash=' . self::$hash );

		$this->assertSame( 200, $response['status'] );
		$this->assertSharersFoldersAreNotIn( $response['body'] );
		$this->assertSame(
			[ 'share:' . self::$folderShareId . '//a.txt', 'share:' . self::$folderShareId . '//b.txt' ],
			$this->sorted( array_column( $response['body']['results'], 'location' ) ),
		);
	}

	public function testAFilesDuplicatesAreLocatedByTheShare(): void
	{
		$response = $this->asRecipient( '/api/v1/file/' . self::$fileId['a'] . '/duplicates' );

		$this->assertSame( 200, $response['status'] );
		$this->assertSharersFoldersAreNotIn( $response['body'] );
		$this->assertSame(
			[ 'share:' . self::$folderShareId . '//b.txt' ],
			array_column( $response['body']['duplicates'][0]['files'] ?? [], 'location' ),
		);
	}

	public function testTheOwnerReadsTheirOwnAddress(): void
	{
		$response = $this->get( '/api/v1/lookup?algo=sha1&hash=' . self::$hash, self::$ownerUid, self::$ownerPassword );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame(
			[
				'home:' . self::$ownerUid . '//' . self::$clients . '/Acme/x/a.txt',
				'home:' . self::$ownerUid . '//' . self::$clients . '/Acme/x/b.txt',
			],
			$this->sorted( array_column( $response['body']['results'], 'location' ) ),
		);
	}

	public function testALeaderReadsWhatTheMemberReads(): void
	{
		$response = $this->get(
			'/api/v1/sudo/duplicates?algo=sha1&hash=' . self::$hash,
			self::$leaderUid,
			self::$leaderPassword,
		);
		$files    = $this->duplicateFiles( $response );

		$this->assertSharersFoldersAreNotIn( $response['body'] );
		$this->assertSame(
			[ 'share:' . self::$folderShareId . '//a.txt', 'share:' . self::$folderShareId . '//b.txt' ],
			array_column( $files, 'location' ),
		);
	}

	public function testASudoerReadsTheFilesOwnAddress(): void
	{
		$files = $this->duplicateFiles( $this->get(
			'/api/v1/sudo/duplicates?algo=sha1&hash=' . self::$hash . '&users%5B%5D=' . self::$recipientUid,
			self::ADMIN_UID,
			self::ADMIN_PASSWORD,
		) );

		$this->assertSame( [ '/My Projects/Bob-x/a.txt', '/My Projects/Bob-x/b.txt' ], array_column( $files, 'path' ), 'the named account\'s path' );
		$this->assertSame(
			[
				'home:' . self::$ownerUid . '//' . self::$clients . '/Acme/x/a.txt',
				'home:' . self::$ownerUid . '//' . self::$clients . '/Acme/x/b.txt',
			],
			array_column( $files, 'location' ),
		);
	}

	/**
	 * @param  array<string, mixed>  $body
	 */
	private function assertSharersFoldersAreNotIn( array $body ): void
	{
		$json = (string) json_encode( $body );

		$this->assertStringNotContainsString( self::$clients, $json, 'nothing of the sharer\'s above the share' );
		$this->assertStringNotContainsString( 'Acme', $json, 'nothing of the sharer\'s above the share' );
	}

	/**
	 * The one group's files, in file id order.
	 *
	 * @param  array{status: int, body: array<string, mixed>}  $response
	 *
	 * @return list<array<string, mixed>>
	 */
	private function duplicateFiles( array $response ): array
	{
		$this->assertSame( 200, $response['status'] );
		$this->assertCount( 1, $response['body']['duplicates'] ?? [], 'one group' );

		$files = $response['body']['duplicates'][0]['files'];
		usort( $files, static fn ( array $x, array $y ): int => $x['fileid'] <=> $y['fileid'] );

		return $files;
	}

	/**
	 * @param  list<string>  $values
	 *
	 * @return list<string>
	 */
	private function sorted( array $values ): array
	{
		sort( $values );

		return $values;
	}

	/**
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function asRecipient( string $path ): array
	{
		return $this->get( $path, self::$recipientUid, self::$recipientPassword );
	}

	/**
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function get(
		string $path,
		string $uid,
		string $password,
	): array
	{
		$context = stream_context_create( [
			'http' => [
				'header'        => 'Authorization: Basic ' . base64_encode( $uid . ':' . $password )
				                   . "\r\nOCS-APIRequest: true"
				                   . "\r\nAccept: application/json",
				'ignore_errors' => true,
			],
		] );

		$body = file_get_contents( self::BASE_URL . $path, false, $context );

		$this->assertNotFalse( $body, "GET $path answered nothing." );

		$statusLine = $http_response_header[0] ?? '';
		$status     = (int) ( preg_match( '/\s(\d{3})\s/', $statusLine, $m ) ? $m[1] : 0 );
		$decoded    = json_decode( $body, true );

		$this->assertIsArray( $decoded, "GET $path did not answer JSON: " . substr( $body, 0, 200 ) );

		return [
			'status' => $status,
			'body'   => $decoded,
		];
	}
}
