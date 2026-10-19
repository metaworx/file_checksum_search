<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Http;

use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCA\GroupFolders\ACL\Rule;
use OCA\GroupFolders\ACL\RuleManager;
use OCA\GroupFolders\ACL\UserMapping\UserMapping;
use OCA\GroupFolders\Folder\FolderManager;
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use Throwable;

/**
 * Reach honours a team folder's advanced permissions, asked over HTTP of a
 * real team folder.
 *
 * Two members of the folder's group: `allowed`, who may read all of it, and
 * `denied`, whom the folder's permissions deny its `Secret` subfolder. Both
 * hold the folder as a mount, and `Secret` lies inside that mount, so a
 * query of the filecache alone puts its files in both reaches. A file there
 * is out of `denied`'s reach, and in the reach of anyone who may count
 * `allowed`'s. Two group admins: one over `denied` alone, one over both.
 * The instance's administrator is a sudoer, whose reach those permissions
 * do not narrow.
 *
 * Skipped where the groupfolders app is not enabled.
 */
class TeamFolderAclTest
    extends
    DatabaseTestCase
{

//  constants

	private const BASE_URL = 'http://127.0.0.1/ocs/v2.php/apps/file_checksum_search';

	/** The instance's administrator — a sudoer, whose reach is every account. */
	private const ADMIN_UID = 'admin';

	private const ADMIN_PASSWORD = 'admin';

	/** @var array<string, array{0: string, 1: string}> uid and password, by role */
	private static array $account = [];

	/** @var list<string> the groups the class made */
	private static array $groups = [];


//  private properties

	private static ?int $folderId = null;

	private static string $hash;

	/** @var array{secret: int, open: int, home: int} */
	private static array $fileId;


//  static methods

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$apps = Server::get( IAppManager::class );

		if ( ! $apps->isEnabledForUser( 'groupfolders' ) )
		{
			self::markTestSkipped( 'FCIAS integration: the groupfolders app is not enabled on this instance.' );
		}

		$apps->loadApp( 'groupfolders' );

		foreach ( [ 'allowed', 'denied', 'leadsDenied', 'leadsBoth' ] as $role )
		{
			self::$account[ $role ] = self::makeAccount( 'fcias_acl_' . strtolower( $role ) );
		}

		$salt       = bin2hex( random_bytes( 4 ) );
		$content    = "fcias team folder acl $salt";
		self::$hash = sha1( $content );

		$users    = Server::get( IUserManager::class );
		$subAdmin = Server::get( ISubAdmin::class );

		$team = self::group( 'fcias_acl_team_' . $salt, [ 'allowed', 'denied' ] );

		$subAdmin->createSubAdmin( $users->get( self::uid( 'leadsDenied' ) ), self::group( 'fcias_acl_d_' . $salt, [ 'denied' ] ) );
		$subAdmin->createSubAdmin( $users->get( self::uid( 'leadsBoth' ) ), self::group( 'fcias_acl_ad_' . $salt, [ 'allowed', 'denied' ] ) );

		$folders        = Server::get( FolderManager::class );
		$mountPoint     = 'fcias_acl_' . $salt;
		self::$folderId = $folders->createFolder( $mountPoint );
		$folders->addApplicableGroup( self::$folderId, $team->getGID() );
		$folders->setFolderACL( self::$folderId, true );

		// The secret copy first, so it has the lower file id: a listing page
		// of one reads it before the open copy.
		$root = Server::get( IRootFolder::class );
		$tf   = $root->getUserFolder( self::uid( 'allowed' ) )->get( $mountPoint );

		self::assertInstanceOf( Folder::class, $tf );

		$secret = $tf->newFolder( 'Secret' );
		$files  = [
			'secret' => $secret->newFile( 's.txt', $content ),
			'open'   => $tf->newFolder( 'Open' )->newFile( 'a.txt', $content ),
			'home'   => $root->getUserFolder( self::uid( 'denied' ) )->newFile( 'fcias_acl_' . $salt . '.txt', $content ),
		];

		$index = Server::get( HashIndexService::class );

		foreach ( $files as $key => $file )
		{
			/** @var File $file */
			self::$fileId[ $key ] = $file->getId();
			$index->recalcFileHash( $file, 'sha1', false );
		}

		// Read denied to `denied`, below `Secret`; nothing else changes.
		Server::get( RuleManager::class )->saveRule( new Rule(
			new UserMapping( 'user', self::uid( 'denied' ) ),
			$secret->getId(),
			Constants::PERMISSION_READ,
			0,
		) );
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
				// Left for the instance's own cleanup; the accounts go regardless.
			}

			self::$folderId = null;
		}

		$groups = Server::get( IGroupManager::class );

		foreach ( self::$groups as $gid )
		{
			$groups->get( $gid )?->delete();
		}

		self::$groups = [];

		// The accounts, and with them their files and sub-admin rows, go
		// with the parent's teardown.
		parent::tearDownAfterClass();
	}

	/**
	 * A group of the given roles' accounts, made for the class.
	 *
	 * @param  list<string>  $roles
	 */
	private static function group(
		string $gid,
		array  $roles,
	): \OCP\IGroup
	{
		$users = Server::get( IUserManager::class );
		$group = Server::get( IGroupManager::class )->createGroup( $gid );

		self::assertNotNull( $group );

		foreach ( $roles as $role )
		{
			$group->addUser( $users->get( self::uid( $role ) ) );
		}

		self::$groups[] = $gid;

		return $group;
	}

	private static function uid( string $role ): string
	{
		return self::$account[ $role ][0];
	}


//  other non-static methods

	// ─── the per-file routes ─────────────────────────────────────────
	/**
	 * The member the folder denies `Secret` is not found the file there on
	 * any own per-file route; the member it does not deny is. The open copy
	 * beside it is both members'.
	 */
	public function testADeniedFileIsNotFoundOnTheOwnPerFileRoutes(): void
	{
		$secret = self::$fileId['secret'];

		$this->assertSame( 404, $this->as( 'denied', "GET /api/v1/file/$secret/hashes" )['status'] );
		$this->assertSame( 404, $this->as( 'denied', "GET /api/v1/file/$secret/duplicates" )['status'] );

		$recalc = $this->as( 'denied', "POST /api/v1/file/$secret/recalc?algo=sha1" );

		$this->assertSame( 400, $recalc['status'] );
		$this->assertSame( 'File not found.', $recalc['body']['error'] ?? null );

		$open = $this->as( 'denied', 'GET /api/v1/file/' . self::$fileId['open'] . '/hashes' );

		$this->assertSame( 200, $open['status'] );
		$this->assertSame( self::$hash, $open['body']['hashes']['sha1']['hash'] ?? null );

		$allowed = $this->as( 'allowed', "GET /api/v1/file/$secret/hashes" );

		$this->assertSame( 200, $allowed['status'], 'control: the file is there, and hashed' );
		$this->assertSame( self::$hash, $allowed['body']['hashes']['sha1']['hash'] ?? null );
	}

	/**
	 * Asking about the open copy, the denied member is shown its duplicate
	 * in their home and not the one in `Secret`; the allowed member, whose
	 * home holds none, is shown the one in `Secret`.
	 */
	public function testTheDuplicatesOfAnOpenFileLeaveTheDeniedCopyOut(): void
	{
		$open = '/api/v1/file/' . self::$fileId['open'] . '/duplicates';

		$this->assertSame( [ self::$fileId['home'] ], $this->duplicateIds( $this->as( 'denied', "GET $open" ) ) );
		$this->assertSame( [ self::$fileId['secret'] ], $this->duplicateIds( $this->as( 'allowed', "GET $open" ) ) );
	}

	/**
	 * A group admin over the denied member alone is refused the file, as
	 * one outside the mounts; one over both members reaches it through the
	 * allowed one; a sudoer reaches it, the folder's permissions aside.
	 */
	public function testAcrossAccountsOneMemberThatMayReadItIsEnough(): void
	{
		$secret = self::$fileId['secret'];

		$alone = $this->as( 'leadsDenied', "GET /api/v1/sudo/file/$secret/hashes" );

		$this->assertSame( 403, $alone['status'] );
		$this->assertSame( 'Not yours to look at.', $alone['body']['error'] ?? null );
		$this->assertSame( 200, $this->as( 'leadsDenied', 'GET /api/v1/sudo/file/' . self::$fileId['open'] . '/hashes' )['status'], 'control: the open copy is theirs' );

		$both = $this->as( 'leadsBoth', "GET /api/v1/sudo/file/$secret/hashes" );

		$this->assertSame( 200, $both['status'], json_encode( $both['body'] ) );
		$this->assertSame( self::$hash, $both['body']['hashes']['sha1']['hash'] ?? null );

		$sudoer = $this->get( "/api/v1/sudo/file/$secret/hashes", self::ADMIN_UID, self::ADMIN_PASSWORD );

		$this->assertSame( 200, $sudoer['status'] );
	}

	/**
	 * The write path, across accounts: the admin over the denied member
	 * alone may not recalculate the file; one over both may.
	 */
	public function testALeaderRecalculatesTheFileOnlyWhereAMemberMayReadIt(): void
	{
		$path = '/api/v1/sudo/file/' . self::$fileId['secret'] . '/recalc?algo=sha1';

		$this->assertSame( 403, $this->as( 'leadsDenied', "POST $path" )['status'] );

		$both = $this->as( 'leadsBoth', "POST $path" );

		$this->assertSame( 200, $both['status'], json_encode( $both['body'] ) );
		$this->assertSame( self::$hash, $both['body']['hash'] ?? null );
	}

	// ─── helpers ─────────────────────────────────────────────────────
	/**
	 * `VERB /path` as the account of $role.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function as(
		string $role,
		string $request,
	): array
	{
		[ $verb, $path ]    = explode( ' ', $request, 2 );
		[ $uid, $password ] = self::$account[ $role ];

		return $verb === 'POST'
			? $this->post( $path, $uid, $password )
			: $this->get( $path, $uid, $password );
	}

	/**
	 * The file ids a per-file duplicates answer lists, sorted.
	 *
	 * @param  array{status: int, body: array<string, mixed>}  $response
	 *
	 * @return list<int>
	 */
	private function duplicateIds( array $response ): array
	{
		$this->assertSame( 200, $response['status'], json_encode( $response['body'] ) );

		$ids = [];

		foreach ( $response['body']['duplicates'] ?? [] as $group )
		{
			foreach ( $group['files'] as $file )
			{
				$ids[] = (int) $file['fileid'];
			}
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * The mutating twin of {@see get()}: OCS accepts `OCS-APIRequest` in
	 * place of the request token a login over Basic auth does not carry.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function post(
		string $path,
		string $uid,
		string $password,
	): array
	{
		$context = stream_context_create( [
			'http' => [
				'method'        => 'POST',
				'header'        => 'Authorization: Basic ' . base64_encode( $uid . ':' . $password )
				                   . "\r\nOCS-APIRequest: true"
				                   . "\r\nAccept: application/json"
				                   . "\r\nContent-Length: 0",
				'content'       => '',
				'ignore_errors' => true,
			],
		] );

		return $this->answer( "POST $path", file_get_contents( self::BASE_URL . $path, false, $context ), $http_response_header ?? [] );
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

		return $this->answer( "GET $path", file_get_contents( self::BASE_URL . $path, false, $context ), $http_response_header ?? [] );
	}

	/**
	 * @param  list<string>  $headers
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function answer(
		string       $request,
		string|false $body,
		array        $headers,
	): array
	{
		$this->assertNotFalse( $body, "$request answered nothing." );

		$status  = (int) ( preg_match( '/\s(\d{3})\s/', $headers[0] ?? '', $m ) ? $m[1] : 0 );
		$decoded = json_decode( $body, true );

		$this->assertIsArray( $decoded, "$request did not answer JSON: " . substr( $body, 0, 200 ) );

		return [
			'status' => $status,
			'body'   => $decoded,
		];
	}
}
