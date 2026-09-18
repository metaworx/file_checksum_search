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
use OCP\Files\IRootFolder;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * Reach is a mount — a storage and a root — asked over HTTP, with real
 * mounts.
 *
 * Bob shares one folder with alice. Both of bob's duplicate pairs live in
 * the same storage; only one pair lives under the shared folder. Alice's
 * listing must show that pair and not the other — the second half is what a
 * storage-id filter gets wrong, since one shared folder mounts the whole
 * storage. A group leader over alice must see the same, and reach a file in
 * the shared subtree while being refused one beside it.
 *
 * No unit test can make this claim: the query builder is mocked there and
 * the predicate is SQL. The listing is filtered by hash so the assertions do
 * not depend on how many groups the instance already holds.
 */
class ReachTest
	extends
	DatabaseTestCase
{

	private const BASE_URL = 'http://127.0.0.1/ocs/v2.php/apps/file_checksum_search';

	/** The instance's administrator — a sudoer, whose reach is every account. */
	private const ADMIN_UID = 'admin';

	private const ADMIN_PASSWORD = 'admin';

	private static string $ownerUid;

	private static string $ownerPassword;

	private static string $recipientUid;

	private static string $recipientPassword;

	private static string $leaderUid;

	private static string $leaderPassword;

	private static string $groupId;

	/** @var array{in: string, out: string} sha1 of the two contents */
	private static array $hash;

	/** @var array{a: int, b: int, c: int, d: int} */
	private static array $fileId;


	public static function setUpBeforeClass(): void
	{

		parent::setUpBeforeClass();

		[ self::$ownerUid, self::$ownerPassword ]         = self::makeAccount( 'fcias_reach_owner' );
		[ self::$recipientUid, self::$recipientPassword ] = self::makeAccount( 'fcias_reach_recipient' );
		[ self::$leaderUid, self::$leaderPassword ]       = self::makeAccount( 'fcias_reach_leader' );

		$salt   = bin2hex( random_bytes( 4 ) );
		$inside = "fcias reach inside $salt";
		$beside = "fcias reach beside $salt";

		self::$hash = [
			'in'  => sha1( $inside ),
			'out' => sha1( $beside ),
		];

		// Two pairs in one storage: a/b under the folder that will be
		// shared, c/d beside it.
		$ownerFolder = Server::get( IRootFolder::class )->getUserFolder( self::$ownerUid );
		$shared      = $ownerFolder->newFolder( 'shared_' . $salt );
		$other       = $ownerFolder->newFolder( 'other_' . $salt );

		$files = [
			'a' => $shared->newFile( 'a.txt', $inside ),
			'b' => $shared->newFile( 'b.txt', $inside ),
			'c' => $other->newFile( 'c.txt', $beside ),
			'd' => $other->newFile( 'd.txt', $beside ),
		];

		$index = Server::get( HashIndexService::class );

		foreach ( $files as $key => $file )
		{
			/** @var File $file */
			self::$fileId[ $key ] = $file->getId();
			$index->recalcFileHash( $file, 'sha1', false );
		}

		$shareManager = Server::get( IShareManager::class );
		$share        = $shareManager->newShare();
		$share->setNode( $shared )
		      ->setShareType( IShare::TYPE_USER )
		      ->setSharedWith( self::$recipientUid )
		      ->setSharedBy( self::$ownerUid )
		      ->setPermissions( Constants::PERMISSION_READ )
		;
		$shareManager->createShare( $share );

		// The leader administers a group the recipient — and not the owner —
		// is in.
		self::$groupId = 'fcias_reach_' . $salt;
		$users         = Server::get( IUserManager::class );
		$group         = Server::get( IGroupManager::class )->createGroup( self::$groupId );
		$group->addUser( $users->get( self::$recipientUid ) );
		Server::get( ISubAdmin::class )->createSubAdmin( $users->get( self::$leaderUid ), $group );
	}


	public static function tearDownAfterClass(): void
	{

		$users  = Server::get( IUserManager::class );
		$group  = Server::get( IGroupManager::class )->get( self::$groupId );
		$leader = $users->get( self::$leaderUid );

		if ( $group !== null )
		{
			if ( $leader !== null )
			{
				Server::get( ISubAdmin::class )->deleteSubAdmin( $leader, $group );
			}

			$group->delete();
		}

		// The accounts, and with them the files and the share, go with the
		// parent's teardown.
		parent::tearDownAfterClass();
	}


	// ─── the recipient's own listing ─────────────────────────────────

	public function testAReceivedShareAppearsInTheOwnListing(): void
	{

		$ids = $this->listedFileIds( '/api/v1/duplicates', self::$hash['in'], self::$recipientUid, self::$recipientPassword );

		$this->assertSame( [ self::$fileId['a'], self::$fileId['b'] ], $ids, 'the pair under the shared folder' );
	}


	/**
	 * The half that matters. c and d share a *storage* with the shared
	 * folder; a filter on storage ids would list them for the recipient.
	 */
	public function testTheSharersOtherFilesDoNotAppearInTheOwnListing(): void
	{

		$ids = $this->listedFileIds( '/api/v1/duplicates', self::$hash['out'], self::$recipientUid, self::$recipientPassword );

		$this->assertSame( [], $ids, 'the pair beside the shared folder, same storage' );

		// Control: the owner sees it, so its absence above is the filter.
		$owners = $this->listedFileIds( '/api/v1/duplicates', self::$hash['out'], self::$ownerUid, self::$ownerPassword );

		$this->assertSame( [ self::$fileId['c'], self::$fileId['d'] ], $owners );
	}


	// ─── a group leader over the recipient ───────────────────────────

	public function testALeadersCeilingListsWhatTheirMemberCanSeeAndNoMore(): void
	{

		$inside = $this->listedFileIds( '/api/v1/sudo/duplicates', self::$hash['in'], self::$leaderUid, self::$leaderPassword );
		$beside = $this->listedFileIds( '/api/v1/sudo/duplicates', self::$hash['out'], self::$leaderUid, self::$leaderPassword );

		$this->assertSame( [ self::$fileId['a'], self::$fileId['b'] ], $inside, 'what the member received' );
		$this->assertSame( [], $beside, 'not what the sharer kept to themself' );
	}


	/**
	 * The per-file reach is the listing's predicate asked of one file: a
	 * file in the shared subtree is reached, one beside it is not — though
	 * both are the same owner's, in the same storage.
	 */
	public function testALeaderReachesAFileInTheSubtreeAndNotOneBesideIt(): void
	{

		$in  = $this->get( '/api/v1/sudo/file/' . self::$fileId['a'] . '/hashes', self::$leaderUid, self::$leaderPassword );
		$out = $this->get( '/api/v1/sudo/file/' . self::$fileId['c'] . '/hashes', self::$leaderUid, self::$leaderPassword );

		$this->assertSame( 200, $in['status'] );
		$this->assertSame( self::$fileId['a'], $in['body']['fileid'] );
		$this->assertSame( 403, $out['status'] );
		$this->assertSame( 'Not yours to look at.', $out['body']['error'] );
	}


	// ─── the per-file duplicates, across accounts ────────────────────

	/**
	 * The route's twin used to render every duplicate through the session's
	 * own folder, so it answered nothing across accounts whatever reach it
	 * had been granted. A leader over alice asks about a file in the shared
	 * subtree: its duplicate in the same subtree appears, and the pair
	 * beside it — same owner, same storage — is neither the reference they
	 * may name nor a duplicate they are shown.
	 */
	public function testALeaderSeesADuplicateWithinTheirMembersReachAndNotBesideIt(): void
	{

		$inside = $this->get( '/api/v1/sudo/file/' . self::$fileId['a'] . '/duplicates', self::$leaderUid, self::$leaderPassword );

		$this->assertSame( 200, $inside['status'] );
		$this->assertSame( [ self::$fileId['b'] ], $this->duplicateIds( $inside['body'] ), "a's duplicate under the share" );

		$beside = $this->get( '/api/v1/sudo/file/' . self::$fileId['c'] . '/duplicates', self::$leaderUid, self::$leaderPassword );

		$this->assertSame( 403, $beside['status'], 'c is not theirs to ask about' );
	}


	/**
	 * A sudoer's reach is every account: asking about the owner's file
	 * beside the share lists its duplicate, which no account of the
	 * sudoer's own holds — the foreign duplicate the review found missing.
	 */
	public function testASudoerSeesAForeignDuplicate(): void
	{

		$response = $this->get( '/api/v1/sudo/file/' . self::$fileId['c'] . '/duplicates', self::ADMIN_UID, self::ADMIN_PASSWORD );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( [ self::$fileId['d'] ], $this->duplicateIds( $response['body'] ) );
	}


	// ─── helpers ─────────────────────────────────────────────────────

	/**
	 * The file ids a per-file duplicates answer lists, sorted.
	 *
	 * @return list<int>
	 */
	private function duplicateIds( array $body ): array
	{

		$ids = [];

		foreach ( $body['duplicates'] ?? [] as $group )
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
	 * The file ids the listing at $path shows for one hash, sorted.
	 *
	 * @return list<int>
	 */
	private function listedFileIds(
		string $path,
		string $hash,
		string $uid,
		string $password,
	): array {

		$response = $this->get( $path . '?algo=sha1&hash=' . $hash, $uid, $password );

		$this->assertSame( 200, $response['status'], "$path answered " . json_encode( $response['body'] ) );

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
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function get(
		string $path,
		string $uid,
		string $password,
	): array {

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
