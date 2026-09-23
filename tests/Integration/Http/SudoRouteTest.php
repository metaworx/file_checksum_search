<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Http;

use OCA\FileChecksumSearch\Service\AuthTokenRepository;
use OCA\FileChecksumSearch\Service\SudoTokens;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

/**
 * The cross-account routes over HTTP, with the credentials a script has.
 *
 * The rule — a password confirmed within thirty minutes, or an app
 * password that has been granted — is unit-tested in SudoConfirmation.
 * What that cannot show is the wiring: that a request authenticated with
 * a real app password reaches the check with the token core resolved for
 * it, and that the grant stored under that token's id is the one read.
 * Nothing exercised that path end to end until the sudo-token listings
 * turned out never to have loaded from a browser (629b95c), which is the
 * kind of gap this class is for.
 *
 * The account is made for the run and deleted after it, and is put in
 * the admin group so that it is a sudoer without touching the instance's
 * permission settings. The app password is minted by occ, as a user's
 * would be on the Security page; its id is read back through the app's
 * own repository, never the secret.
 */
class SudoRouteTest
	extends
	DatabaseTestCase
{

	private const TEST_USER_PREFIX = 'fcias_sudo_test';

	private const APP_PASSWORD_NAME = 'fcias sudo route test';

	/** A group of the test account's own to lead; created and deleted per case. */
	private const TEST_GROUP = 'fcias_sudo_test_group';

	private const BASE_URL = 'http://127.0.0.1/ocs/v2.php/apps/file_checksum_search';

	/** Any well-formed hash nobody stored: the route's answer is the point, not its contents. */
	private const HASH = 'abc123abc123abc123abc123abc123abc123abc1';

	private static string $uid;

	private static string $password;

	private string $appPassword;

	private int    $tokenId;


	public static function setUpBeforeClass(): void
	{

		parent::setUpBeforeClass();

		[
			self::$uid,
			self::$password,
		]
			= self::makeAccount( self::TEST_USER_PREFIX );

		self::adminGroup( true );
	}


	public static function tearDownAfterClass(): void
	{

		// Deleting the account drops the membership too; done here as well
		// so a deletion that fails does not leave a dead admin behind.
		self::adminGroup( false );

		parent::tearDownAfterClass();
	}


	protected function setUp(): void
	{

		parent::setUp();

		$this->appPassword = $this->mintAppPassword();
		$this->tokenId     = $this->tokenIdByName( self::APP_PASSWORD_NAME );
	}


	protected function tearDown(): void
	{

		Server::get( SudoTokens::class )->revoke( self::$uid, $this->tokenId );
		$this->occ( 'user:auth-tokens:delete', self::$uid, (string) $this->tokenId );
		self::adminGroup( true );

		parent::tearDown();
	}


	/**
	 * The login password over Basic auth is a login, and a login is a
	 * confirmation — the same thirty-minute rule core applies.
	 */
	public function testTheAccountPasswordCountsAsAConfirmation(): void
	{

		$response = $this->get( '/api/v1/sudo/lookup?hash=' . self::HASH, self::$password );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( [], $response['body']['results'] );
	}


	public function testAnAppPasswordWithoutAGrantIsRefusedWithTheMessageTheDialogKnows(): void
	{

		$response = $this->get( '/api/v1/sudo/lookup?hash=' . self::HASH, $this->appPassword );

		$this->assertSame( 403, $response['status'] );
		$this->assertSame( 'Password confirmation required', $response['body']['message'] );
	}


	public function testAGrantedAppPasswordPasses(): void
	{

		Server::get( SudoTokens::class )->grant( self::$uid, $this->tokenId, self::$uid );

		$response = $this->get( '/api/v1/sudo/lookup?hash=' . self::HASH, $this->appPassword );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( [], $response['body']['results'] );
	}


	public function testAGrantRevokedIsAGrantGone(): void
	{

		$sudoTokens = Server::get( SudoTokens::class );
		$sudoTokens->grant( self::$uid, $this->tokenId, self::$uid );
		$sudoTokens->revoke( self::$uid, $this->tokenId );

		$response = $this->get( '/api/v1/sudo/lookup?hash=' . self::HASH, $this->appPassword );

		$this->assertSame( 403, $response['status'] );
	}


	/**
	 * A grant replaces the prompt, not the permission: an account nobody
	 * named as a sudoer is refused with the grant in place, and by the
	 * scope rule rather than the confirmation one.
	 */
	public function testAGrantDoesNotMakeASudoer(): void
	{

		Server::get( SudoTokens::class )->grant( self::$uid, $this->tokenId, self::$uid );
		self::adminGroup( false );

		$response = $this->get( '/api/v1/sudo/lookup?hash=' . self::HASH, $this->appPassword );

		$this->assertSame( 403, $response['status'] );
		$this->assertSame( 'Not yours to look at.', $response['body']['error'] );
	}


	/**
	 * The ordinary routes never ask: an ungranted app password reads its
	 * own account as before.
	 */
	public function testTheOrdinaryRouteAsksForNoGrant(): void
	{

		$response = $this->get( '/api/v1/lookup?hash=' . self::HASH, $this->appPassword );

		$this->assertSame( 200, $response['status'] );
	}


	// ─── may they cross at all ───────────────────────────────────────

	/**
	 * The first HTTP case in this repository to run as a sub-admin: every
	 * other test here is an administrator, which is how the tab stayed
	 * hidden from group leaders unnoticed. The fixture mints the group and
	 * the delegation itself rather than borrowing an account whose password
	 * it does not know.
	 *
	 * The listing says a leader may cross, and the picker offers them their
	 * own group but not "everyone" — the entry question and the ceiling
	 * question answered differently for the same account.
	 */
	public function testAGroupLeaderIsToldTheyMayCrossButNotSeeEveryone(): void
	{

		self::adminGroup( false );
		self::leaderOfTestGroup( true );

		try
		{
			$listing = $this->get( '/api/v1/duplicates', self::$password );

			$this->assertSame( 200, $listing['status'] );
			$this->assertTrue( $listing['body']['canSudo'], 'a group leader may cross' );

			$offer = $this->get( '/api/v1/sudo/selectable', self::$password );

			$this->assertSame( 200, $offer['status'] );
			// "All" is offered to a leader too — as their whole reach, which
			// the routes below answer when nothing is named — and `reach`
			// says it is their groups, not everyone.
			$this->assertTrue( $offer['body']['all'], 'a leader may ask for their whole reach at once' );
			$this->assertSame( 'groups', $offer['body']['reach'] );
			$this->assertSame( [ self::TEST_GROUP ], array_column( $offer['body']['groups'], 'id' ) );

			// The two routes that take no target serve a leader their
			// ceiling — their groups' members — where they answered 403
			// "Not yours to look at." for as long as no target meant
			// "everyone".
			$lookup = $this->get( '/api/v1/sudo/lookup?hash=' . self::HASH, self::$password );

			$this->assertSame( 200, $lookup['status'], 'the lookup serves a leader their ceiling' );
			$this->assertSame( [], $lookup['body']['results'] );

			$bare = $this->get( '/api/v1/sudo/duplicates', self::$password );

			$this->assertSame( 200, $bare['status'], 'and so does the listing that names nothing' );
			$this->assertSame( [], $bare['body']['duplicates'] );
		}
		finally
		{
			self::leaderOfTestGroup( false );
		}
	}


	public function testAnAccountNobodyNamedIsToldTheyMayNotCross(): void
	{

		self::adminGroup( false );

		$listing = $this->get( '/api/v1/duplicates', self::$password );

		$this->assertSame( 200, $listing['status'] );
		$this->assertFalse( $listing['body']['canSudo'] );
	}


	// ─── recalculating a file that is not one's own ──────────────────

	/**
	 * A file id nothing knows about is the point: it proves the request got
	 * *past* the gate, since only the body can answer "File not found." A
	 * 403 here would mean the reach check or the confirmation refused, and
	 * those are what the two cases below assert instead.
	 */
	public function testASudoerReachesTheRecalcBodyForAFileThatIsNotTheirs(): void
	{

		Server::get( SudoTokens::class )->grant( self::$uid, $this->tokenId, self::$uid );

		$response = $this->post( '/api/v1/sudo/file/999999999/recalc?algo=sha1', $this->appPassword );

		$this->assertSame( 400, $response['status'], 'the gate passed and the body answered' );
		$this->assertSame( 'File not found.', $response['body']['error'] );
	}


	public function testTheRecalcRouteAsksForTheSameConfirmationTheOthersDo(): void
	{

		$response = $this->post( '/api/v1/sudo/file/999999999/recalc?algo=sha1', $this->appPassword );

		$this->assertSame( 403, $response['status'] );
		$this->assertSame( 'Password confirmation required', $response['body']['message'] );
	}


	/**
	 * And a grant is still not a permission: an account that reaches no
	 * account but its own is refused by the reach rule, holding a grant.
	 */
	public function testAGrantDoesNotLetOneRecalculateAnotherAccountsFile(): void
	{

		Server::get( SudoTokens::class )->grant( self::$uid, $this->tokenId, self::$uid );
		self::adminGroup( false );

		$response = $this->post( '/api/v1/sudo/file/999999999/recalc?algo=sha1', $this->appPassword );

		$this->assertSame( 403, $response['status'] );
		$this->assertSame( 'Not yours to look at.', $response['body']['error'] );
	}


	// ─── helpers ─────────────────────────────────────────────────────

	/**
	 * The mutating twin of {@see get()}. The route deliberately keeps the
	 * CSRF check, and an app password carries no request token — OCS accepts
	 * `OCS-APIRequest` in its place, which is what a script sends.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function post(
		string $path,
		string $secret,
	): array {

		$context = stream_context_create( [
			'http' => [
				'method'        => 'POST',
				'header'        => 'Authorization: Basic ' . base64_encode( self::$uid . ':' . $secret )
				                   . "\r\nAccept: application/json"
				                   . "\r\nOCS-APIRequest: true"
				                   . "\r\nContent-Length: 0",
				'ignore_errors' => true,
			],
		] );

		$body = file_get_contents( self::BASE_URL . $path, false, $context );

		$this->assertNotFalse( $body, "POST $path answered nothing." );

		$statusLine = $http_response_header[0] ?? '';
		$status     = (int) ( preg_match( '/\s(\d{3})\s/', $statusLine, $m ) ? $m[1] : 0 );

		$decoded = json_decode( $body, true );

		$this->assertIsArray( $decoded, "POST $path did not answer JSON: " . substr( $body, 0, 200 ) );

		return [
			'status' => $status,
			'body'   => $decoded,
		];
	}


	/**
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function get(
		string $path,
		string $secret,
	): array {

		$context = stream_context_create( [
			'http' => [
				'header'        => 'Authorization: Basic ' . base64_encode( self::$uid . ':' . $secret )
				                   . "\r\nAccept: application/json",
				'ignore_errors' => true,
			],
		] );

		$body = file_get_contents( self::BASE_URL . $path, false, $context );

		$this->assertNotFalse( $body, "GET $path answered nothing." );

		// file_get_contents leaves the status line in this variable.
		$statusLine = $http_response_header[0] ?? '';
		$status     = (int) ( preg_match( '/\s(\d{3})\s/', $statusLine, $m ) ? $m[1] : 0 );

		$decoded = json_decode( $body, true );

		$this->assertIsArray( $decoded, "GET $path did not answer JSON: " . substr( $body, 0, 200 ) );

		return [
			'status' => $status,
			'body'   => $decoded,
		];
	}


	/**
	 * An app password the way a user gets one: from occ, non-interactively,
	 * which mints one without the login password — all a listing or a
	 * read needs. The secret is the last line occ prints.
	 */
	private function mintAppPassword(): string
	{

		$lines = $this->occ( 'user:auth-tokens:add', self::$uid, '--name=' . self::APP_PASSWORD_NAME, '-n' );
		$last  = trim( (string) end( $lines ) );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{40,}$/', $last, 'occ printed the app password last: ' . implode( ' | ', $lines ) );

		return $last;
	}


	private function tokenIdByName( string $name ): int
	{

		foreach ( Server::get( AuthTokenRepository::class )->listForUser( self::$uid ) as $token )
		{
			if ( $token['name'] === $name )
			{
				return $token['id'];
			}
		}

		$this->fail( "No token named \"$name\" on " . self::$uid );
	}


	/**
	 * @return list<string>  What occ printed.
	 */
	private function occ( string ...$args ): array
	{

		$command = PHP_BINARY . ' ' . escapeshellarg( \OC::$SERVERROOT . '/occ' )
		           . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';

		exec( $command, $lines, $code );

		$this->assertSame( 0, $code, "occ failed: $command\n" . implode( "\n", $lines ) );

		return $lines;
	}


	/**
	 * In or out of the admin group — the one thing that decides whether
	 * the account is a sudoer here.
	 */
	/**
	 * Make the account the leader of a group of its own, or undo that.
	 * Core's delegation is set through ISubAdmin — no occ command exposes
	 * it — and the group is created and deleted with it so nothing is left
	 * behind for the next run to trip on.
	 */
	private static function leaderOfTestGroup( bool $leader ): void
	{

		$user     = Server::get( IUserManager::class )->get( self::$uid );
		$groups   = Server::get( IGroupManager::class );
		$subAdmin = Server::get( ISubAdmin::class );

		if ( $user === null )
		{
			return;
		}

		$group = $groups->get( self::TEST_GROUP ) ?? $groups->createGroup( self::TEST_GROUP );

		if ( $group === null )
		{
			return;
		}

		if ( $leader )
		{
			$subAdmin->createSubAdmin( $user, $group );

			return;
		}

		if ( $subAdmin->isSubAdminOfGroup( $user, $group ) )
		{
			$subAdmin->deleteSubAdmin( $user, $group );
		}

		$group->delete();
	}


	private static function adminGroup( bool $member ): void
	{

		$user  = Server::get( IUserManager::class )->get( self::$uid );
		$group = Server::get( IGroupManager::class )->get( 'admin' );

		if ( $user === null || $group === null )
		{
			return;
		}

		if ( $member )
		{
			$group->addUser( $user );
		}
		else
		{
			$group->removeUser( $user );
		}
	}

}
