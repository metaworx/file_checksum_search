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


	// ─── whose file, and where ───────────────────────────────────────

	/**
	 * The rows alice is shown for the shared pair are bob's files, and say
	 * so: an owner that is not her, and a location in his home. Without it
	 * the listing reads `shared_…/a.txt` — her view of his folder — with
	 * nothing to tell it from a file of her own by that name.
	 */
	public function testARowSaysWhoseFileItIsAndWhereItLives(): void
	{

		$response = $this->get(
			'/api/v1/duplicates?algo=sha1&hash=' . self::$hash['in'],
			self::$recipientUid,
			self::$recipientPassword,
		);

		$this->assertSame( 200, $response['status'] );

		$files = $response['body']['duplicates'][0]['files'] ?? [];

		$this->assertCount( 2, $files );

		foreach ( $files as $file )
		{
			$this->assertSame( self::$ownerUid, $file['owner'] );
			$this->assertStringStartsWith( '/' . self::$ownerUid . '/files/shared_', $file['location'] );
		}
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


	// ─── the set path, as the picker sends it ────────────────────────

	/**
	 * `users[]`/`groups[]` is what the picker actually sends, and until now
	 * only `resolveSet()`'s unit tests had exercised it. A leader naming
	 * their own group is served its members' reach; naming a group they do
	 * not lead refuses the whole request rather than quietly narrowing it.
	 */
	public function testALeaderMayNameTheirOwnGroupAndNoOther(): void
	{

		$own = '/api/v1/sudo/duplicates?groups%5B%5D=' . self::$groupId;

		$inside = $this->listedFileIds( $own, self::$hash['in'], self::$leaderUid, self::$leaderPassword );
		$beside = $this->listedFileIds( $own, self::$hash['out'], self::$leaderUid, self::$leaderPassword );

		$this->assertSame( [ self::$fileId['a'], self::$fileId['b'] ], $inside );
		$this->assertSame( [], $beside );

		$other = $this->get( '/api/v1/sudo/duplicates?groups%5B%5D=admin&algo=sha1', self::$leaderUid, self::$leaderPassword );

		$this->assertSame( 403, $other['status'] );
		$this->assertSame( 'Not yours to look at.', $other['body']['error'] );
	}


	// ─── the write path, as a leader ─────────────────────────────────

	/**
	 * The route the `[SECURITY]` withdrawal was about, over HTTP as the
	 * account it was withdrawn from: a leader recalculates a file in a
	 * member's received subtree and is refused the owner's file beside it,
	 * same owner, same storage.
	 */
	public function testALeaderRecalculatesWithinTheirMembersReachAndNotBesideIt(): void
	{

		$inside = $this->post( '/api/v1/sudo/file/' . self::$fileId['a'] . '/recalc?algo=sha1', self::$leaderUid, self::$leaderPassword );

		$this->assertSame( 200, $inside['status'], json_encode( $inside['body'] ) );
		$this->assertTrue( $inside['body']['success'] );
		$this->assertSame( self::$hash['in'], $inside['body']['hash'] );

		$beside = $this->post( '/api/v1/sudo/file/' . self::$fileId['c'] . '/recalc?algo=sha1', self::$leaderUid, self::$leaderPassword );

		$this->assertSame( 403, $beside['status'] );
		$this->assertSame( 'Not yours to look at.', $beside['body']['error'] );
	}


	// ─── the picker's search, as a leader ────────────────────────────

	public function testALeadersSearchFindsTheirMembersAndNobodyElse(): void
	{

		$member = $this->get( '/api/v1/sudo/selectable?search=' . self::$recipientUid, self::$leaderUid, self::$leaderPassword );

		$this->assertSame( 200, $member['status'] );
		$this->assertSame( [ self::$recipientUid ], array_column( $member['body']['users'], 'id' ) );
		$this->assertTrue( $member['body']['all'], 'the whole reach is always on offer' );
		$this->assertSame( 'groups', $member['body']['reach'], 'and for a leader it is their groups' );

		$stranger = $this->get( '/api/v1/sudo/selectable?search=' . self::$ownerUid, self::$leaderUid, self::$leaderPassword );

		$this->assertSame( 200, $stranger['status'] );
		$this->assertSame( [], $stranger['body']['users'], 'the owner is in none of the groups they lead' );
	}


	// ─── several files in one request ────────────────────────────────

	/**
	 * `many` is a literal where `{fileId}` sits on the route beside it. That
	 * this answers with `results` and not with a single file's verdict is
	 * the proof the `\d+` requirement holds over HTTP, where the router
	 * actually runs.
	 */
	public function testTheOwnerRecalculatesSeveralFilesInOneRequest(): void
	{

		$response = $this->post(
			'/api/v1/file/many/recalc',
			self::$ownerUid,
			self::$ownerPassword,
			[ 'fileIds' => [ self::$fileId['a'], self::$fileId['c'] ], 'algo' => 'sha1' ],
		);

		$this->assertSame( 200, $response['status'], json_encode( $response['body'] ) );
		$this->assertSame( [ self::$fileId['a'], self::$fileId['c'] ], array_column( $response['body']['results'], 'fileid' ) );
		$this->assertSame( [ true, true ], array_column( $response['body']['results'], 'success' ) );
		$this->assertSame( [ self::$hash['in'], self::$hash['out'] ], array_column( $response['body']['results'], 'hash' ) );
		$this->assertSame( [], $response['body']['remaining'] );
	}


	/**
	 * A leader's batch over a member's shared file and the owner's file
	 * beside it: one succeeds, one is refused, in the same answer — per
	 * file, as the AP decided, rather than the whole request failing for
	 * the one id out of reach.
	 */
	public function testALeadersBatchAnswersPerFile(): void
	{

		$response = $this->post(
			'/api/v1/sudo/file/many/recalc',
			self::$leaderUid,
			self::$leaderPassword,
			[ 'fileIds' => [ self::$fileId['a'], self::$fileId['c'] ], 'algo' => 'sha1' ],
		);

		$this->assertSame( 200, $response['status'], json_encode( $response['body'] ) );

		[ $inside, $beside ] = $response['body']['results'];

		$this->assertTrue( $inside['success'] );
		$this->assertSame( self::$hash['in'], $inside['hash'] );
		$this->assertFalse( $beside['success'] );
		$this->assertSame( 'File not found.', $beside['error'] );
	}


	// ─── which rows a link would open ────────────────────────────────

	/**
	 * A file link resolves in the viewer's folder or not at all. The
	 * leader holds none of the member's files, so every row they are shown
	 * says it would not open; the recipient's own rows say nothing, because
	 * the own listing shows only what its viewer holds.
	 */
	public function testACrossAccountRowSaysWhetherItWouldOpenForTheViewer(): void
	{

		$leaders = $this->get( '/api/v1/sudo/duplicates?algo=sha1&hash=' . self::$hash['in'], self::$leaderUid, self::$leaderPassword );

		$this->assertSame( 200, $leaders['status'] );
		$this->assertSame(
			[ false, false ],
			array_column( $leaders['body']['duplicates'][0]['files'], 'openable' ),
			'a leader does not hold what their member received',
		);

		$own = $this->get( '/api/v1/duplicates?algo=sha1&hash=' . self::$hash['in'], self::$recipientUid, self::$recipientPassword );

		$this->assertSame( 200, $own['status'] );
		$this->assertArrayNotHasKey( 'openable', $own['body']['duplicates'][0]['files'][0] );
	}


	// ─── a plain account, on every cross-account route ───────────────

	/**
	 * The recipient is neither an administrator nor a leader of anything:
	 * the account most people have. Every `/sudo/` route — all eight — refuses them, and
	 * with the scope's message rather than a password prompt — permission
	 * is asked before confirmation, so someone who may not cross is told so
	 * without being made to type a password first. The ordinary listing
	 * says the same in advance, which is why the page never offers the tab.
	 */
	public function testAPlainAccountIsRefusedOnEveryCrossAccountRoute(): void
	{

		$listing = $this->get( '/api/v1/duplicates?algo=sha1&hash=' . self::$hash['in'], self::$recipientUid, self::$recipientPassword );

		$this->assertSame( 200, $listing['status'] );
		$this->assertFalse( $listing['body']['canSudo'] );

		$a = self::$fileId['a'];

		$refused = [
			'GET /api/v1/sudo/duplicates',
			'GET /api/v1/sudo/duplicates?users%5B%5D=' . self::$ownerUid,
			'GET /api/v1/sudo/lookup?hash=' . self::$hash['in'],
			'GET /api/v1/sudo/selectable',
			"GET /api/v1/sudo/file/$a/hashes",
			"GET /api/v1/sudo/file/$a/duplicates",
			"POST /api/v1/sudo/file/$a/recalc?algo=sha1",
			'POST /api/v1/sudo/file/many/recalc',
		];

		foreach ( $refused as $request )
		{
			[ $verb, $path ] = explode( ' ', $request, 2 );

			$response = $verb === 'POST'
				? $this->post( $path, self::$recipientUid, self::$recipientPassword )
				: $this->get( $path, self::$recipientUid, self::$recipientPassword );

			$this->assertSame( 403, $response['status'], $request );
			$this->assertSame( 'Not yours to look at.', $response['body']['error'] ?? null, $request );
		}
	}


	// ─── helpers ─────────────────────────────────────────────────────

	/**
	 * The mutating twin of {@see get()}. The recalc routes keep the CSRF
	 * check, and a login password over Basic auth carries no request token —
	 * OCS accepts `OCS-APIRequest` in its place.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function post(
		string $path,
		string $uid,
		string $password,
		?array $json = null,
	): array {

		$content = $json === null ? '' : json_encode( $json, JSON_THROW_ON_ERROR );

		$context = stream_context_create( [
			'http' => [
				'method'        => 'POST',
				'header'        => 'Authorization: Basic ' . base64_encode( $uid . ':' . $password )
				                   . "\r\nOCS-APIRequest: true"
				                   . "\r\nAccept: application/json"
				                   . ( $json === null ? '' : "\r\nContent-Type: application/json" )
				                   . "\r\nContent-Length: " . strlen( $content ),
				'content'       => $content,
				'ignore_errors' => true,
			],
		] );

		$body = file_get_contents( self::BASE_URL . $path, false, $context );

		$this->assertNotFalse( $body, "POST $path answered nothing." );

		$statusLine = $http_response_header[0] ?? '';
		$status     = (int) ( preg_match( '/\s(\d{3})\s/', $statusLine, $m ) ? $m[1] : 0 );
		$decoded    = json_decode( $body, true );

		$this->assertIsArray( $decoded, "POST $path did not answer JSON: " . substr( $body, 0, 200 ) );

		return [
			'status' => $status,
			'body'   => $decoded,
		];
	}


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

		$joiner   = str_contains( $path, '?' ) ? '&' : '?';
		$response = $this->get( $path . $joiner . 'algo=sha1&hash=' . $hash, $uid, $password );

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
