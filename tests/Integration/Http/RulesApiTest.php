<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Http;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Migration\RepairQuietStart;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Server;

/**
 * The rules API's permission matrix, over real HTTP.
 *
 * Who may read what, whose rule may be changed by whom, and what a
 * non-administrator's selector is turned into — asked of the running
 * server rather than of a mocked controller.
 *
 * The unit tests assert the same status codes against mocks, which can
 * only tell you what the controller returns when it is reached. They
 * cannot tell you whether the middleware reaches it: an endpoint that
 * 401s before the controller sees it, or one whose admin check never
 * runs, passes every one of them. `rules-api.cy.js` covers this ground
 * from the browser; this covers it from inside, where CI runs it without
 * a browser at all.
 *
 * Three accounts, all made for the run and removed after it — one in the
 * admin group, two ordinary — so nothing here depends on an instance
 * having particular users, and no password is written down.
 */
class RulesApiTest
	extends
	DatabaseTestCase
{

	private static string $adminUid;

	private static string $adminPassword;

	private static string $aliceUid;

	private static string $alicePassword;

	private static string $bobUid;

	private static string $bobPassword;

	/** Whether rule editing was granted to everyone before this ran. */
	private static bool   $ruleEditingWasAllowed = false;

	private string        $baseUrl;

	private ?string       $aliceRuleId    = null;

	private ?string       $homeAllRuleId  = null;

	private ?string       $universalRuleId = null;


	public static function setUpBeforeClass(): void
	{

		parent::setUpBeforeClass();

		[
			self::$adminUid,
			self::$adminPassword,
		]
			= self::makeAccount( 'fcias_rules_admin' );

		[
			self::$aliceUid,
			self::$alicePassword,
		]
			= self::makeAccount( 'fcias_rules_alice' );

		[
			self::$bobUid,
			self::$bobPassword,
		]
			= self::makeAccount( 'fcias_rules_bob' );

		$groupManager = Server::get( IGroupManager::class );
		$adminGroup   = $groupManager->get( 'admin' );
		$adminUser    = Server::get( IUserManager::class )
		                      ->get( self::$adminUid )
		;

		if ( $adminGroup === null || $adminUser === null )
		{
			self::markTestSkipped( 'FCIAS integration: no admin group to put the test administrator in.' );
		}

		$adminGroup->addUser( $adminUser );

		// A user may only write a rule if an administrator has said so, and
		// the shipped default is that none may. Half this matrix is about
		// what a permitted user still may not do, which is a different
		// question from whether they are permitted at all.
		$permissions                  = Server::get( PermissionService::class );
		self::$ruleEditingWasAllowed  = $permissions->isAllUsersEnabled(
			PermissionService::PERMISSION_RULE_EDITING,
		);

		$permissions->setAllUsersEnabled( PermissionService::PERMISSION_RULE_EDITING, true );
	}


	public static function tearDownAfterClass(): void
	{

		Server::get( PermissionService::class )
		      ->setAllUsersEnabled(
			      PermissionService::PERMISSION_RULE_EDITING,
			      self::$ruleEditingWasAllowed,
		      )
		;

		parent::tearDownAfterClass();
	}


	protected function setUp(): void
	{

		parent::setUp();

		// 127.0.0.1 rather than the site name: Nextcloud's HTTP client
		// blocks requests to its own hostname as SSRF, and this goes through
		// the same stack.
		$this->baseUrl = 'http://127.0.0.1/ocs/v2.php/apps/file_checksum_search';

		// The shipped state, so band numbers and default ids mean the same
		// thing in every test here whatever ran before: empty the stored
		// rules, then let the repair step put the two disabled defaults
		// back. Through the config key because the write path is private by
		// design — every caller goes through the API, which is what this is
		// testing rather than using.
		Server::get( IAppConfig::class )
		      ->setValueString( Application::APP_ID, 'rule_definitions', '[]' )
		;

		// The decoded list is memoised per process and invalidated only by
		// the write path just gone around.
		Server::get( RuleService::class )
		      ->loadRules( refresh: true )
		;

		Server::get( RepairQuietStart::class )
		      ->runSteps( $this->createMock( IOutput::class ), [ 'selector-model' ] )
		;

		foreach ( $this->request( 'GET', '/api/v1/rules?scope=all', null, 'admin' )['body']['rules'] ?? [] as $rule )
		{
			if ( $rule['selector'] === 'home:*' )
			{
				$this->homeAllRuleId = $rule['id'];
			}

			if ( $rule['selector'] === '*' )
			{
				$this->universalRuleId = $rule['id'];
			}
		}

		// One rule alice owns, for the rows about somebody else's rule.
		$created = $this->request(
			'POST',
			'/api/v1/rules',
			[
				'selector' => 'home:' . self::$aliceUid,
				'path'     => '**',
				'type'     => 'include',
				'algos'    => [ 'sha1' ],
				'mode'     => 'auto',
			],
			'alice',
		);

		$this->assertSame( 200, $created['status'], 'alice may write a rule for her own files.' );

		$this->aliceRuleId = $created['body']['rule']['id'] ?? null;

		$this->assertNotNull( $this->aliceRuleId, 'the created rule reports its id.' );
	}


	// ─── reading ─────────────────────────────────────────────────────

	public function testAnonymousIsRefused(): void
	{

		$this->assertSame( 401, $this->request( 'GET', '/api/v1/rules', null, null )['status'] );
	}


	public function testAUserSeesOnlyTheRulesThatConcernThem(): void
	{

		$response = $this->request( 'GET', '/api/v1/rules', null, 'alice' );

		$this->assertSame( 200, $response['status'] );

		$selectors = array_column( $response['body']['rules'], 'selector' );

		// Her own and the ones covering every home. Another user's rules
		// cannot affect her, so listing them would only show her gaps she
		// cannot act on.
		$this->assertContains( 'home:' . self::$aliceUid, $selectors );
		$this->assertNotContains( 'home:' . self::$bobUid, $selectors );
	}


	public function testTheEveryoneViewIsForAdministrators(): void
	{

		$this->assertSame(
			403,
			$this->request( 'GET', '/api/v1/rules?scope=all', null, 'alice' )['status'],
		);
		$this->assertSame(
			200,
			$this->request( 'GET', '/api/v1/rules?scope=all', null, 'admin' )['status'],
		);
	}


	public function testAnUnknownScopeIsRefused(): void
	{

		$this->assertSame(
			400,
			$this->request( 'GET', '/api/v1/rules?scope=everything', null, 'admin' )['status'],
		);
	}


	// ─── creating ────────────────────────────────────────────────────

	public function testAPathThatCannotReachTheCallersFilesIsRefused(): void
	{

		// Scope answers "does this rule cover me"; this answers "could it
		// ever touch a file I can see". A rule on a folder that is not in
		// her tree can never match anything.
		$response = $this->request(
			'POST',
			'/api/v1/rules',
			[
				'selector' => 'home:' . self::$aliceUid,
				'path'     => '/NoSuchFolder/**',
				'type'     => 'include',
				'algos'    => [ 'sha1' ],
				'mode'     => 'auto',
			],
			'alice',
		);

		$this->assertSame( 403, $response['status'] );
	}


	/**
	 * A non-administrator's selector is not validated and refused — it is
	 * *replaced* with their own home, whatever they asked for. So the
	 * assertion that matters is not the status code but what got stored:
	 * both of these would pass on a 200 alone while a rule over somebody
	 * else's files sat in the database.
	 */
	public function testAUserCannotAimARuleAtSomebodyElse(): void
	{

		$this->assertNeutralisedToOwnHome( 'home:' . self::$bobUid );
	}


	public function testAUserCannotWidenARuleToEveryStorage(): void
	{

		$this->assertNeutralisedToOwnHome( '*' );
	}


	// ─── somebody else's rule ────────────────────────────────────────

	public function testAStrangerMayNotUpdateIt(): void
	{

		$this->assertSame(
			403,
			$this->request(
				'PUT',
				'/api/v1/rules/' . $this->aliceRuleId,
				[ 'enabled' => false ],
				'bob',
			)['status'],
		);
	}


	public function testAStrangerMayNotDeleteIt(): void
	{

		$this->assertSame(
			403,
			$this->request( 'DELETE', '/api/v1/rules/' . $this->aliceRuleId, null, 'bob' )['status'],
		);
	}


	public function testAUserMayNotReapplyAnAdministratorsRule(): void
	{

		$this->assertSame(
			403,
			$this->request( 'POST', '/api/v1/rules/' . $this->homeAllRuleId . '/apply', null, 'alice' )['status'],
		);
	}


	public function testTheOwnerMayDoWhatTheStrangerMayNot(): void
	{

		$this->assertSame(
			200,
			$this->request(
				'PUT',
				'/api/v1/rules/' . $this->aliceRuleId,
				[ 'enabled' => false ],
				'alice',
			)['status'],
		);
	}


	// ─── rules that are not there ────────────────────────────────────

	public function testAMissingRuleIsNotFoundRatherThanForbidden(): void
	{

		foreach (
			[
				[
					'PUT',
					[ 'enabled' => true ],
				],
				[
					'DELETE',
					null,
				],
			] as [ $method, $body ]
		)
		{
			$this->assertSame(
				404,
				$this->request( $method, '/api/v1/rules/nosuchid', $body, 'admin' )['status'],
				"$method on a rule that does not exist.",
			);
		}

		$this->assertSame(
			404,
			$this->request( 'POST', '/api/v1/rules/nosuchid/apply', null, 'admin' )['status'],
		);
	}


	// ─── reordering and re-applying ──────────────────────────────────

	public function testAReorderMustNameItsSegment(): void
	{

		$this->assertSame(
			400,
			$this->request( 'PUT', '/api/v1/rules/order', [], 'admin' )['status'],
			'no selector',
		);
		$this->assertSame(
			400,
			$this->request( 'PUT', '/api/v1/rules/order', [ 'selector' => 'home:*' ], 'admin' )['status'],
			'no ids',
		);
	}


	public function testASegmentGivenInFullIsReordered(): void
	{

		$this->assertSame(
			200,
			$this->request(
				'PUT',
				'/api/v1/rules/order',
				[
					'selector'   => 'home:*',
					'defaults'   => true,
					'orderedIds' => [ $this->homeAllRuleId ],
				],
				'admin',
			)['status'],
		);
	}


	public function testADisabledRuleCannotBeReapplied(): void
	{

		// Re-applying queues an uncapped pass over everything the rule
		// governs. A disabled rule governs nothing, so saying so beats
		// queueing a pass with nothing in it.
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/api/v1/rules/' . $this->universalRuleId . '/apply',
				null,
				'admin',
			)['status'],
		);
	}


	// ─── helpers ─────────────────────────────────────────────────────

	private function assertNeutralisedToOwnHome( string $asked ): void
	{

		$path = '/Neutralised' . substr( md5( $asked ), 0, 6 ) . '/**';

		// The folder has to exist, or the refusal above fires first and this
		// would pass for the wrong reason.
		Server::get( IRootFolder::class )
		      ->getUserFolder( self::$aliceUid )
		      ->newFolder( trim( dirname( $path ), '/' ) )
		;

		$response = $this->request(
			'POST',
			'/api/v1/rules',
			[
				'selector' => $asked,
				'path'     => $path,
				'type'     => 'include',
				'algos'    => [ 'sha1' ],
				'mode'     => 'auto',
			],
			'alice',
		);

		$this->assertSame( 200, $response['status'], "asking for $asked is accepted" );

		$stored = null;

		foreach ( $this->request( 'GET', '/api/v1/rules?scope=all', null, 'admin' )['body']['rules'] as $rule )
		{
			if ( $rule['path'] === $path )
			{
				$stored = $rule;
			}
		}

		$this->assertNotNull( $stored, "the rule alice asked for on $path was stored" );
		$this->assertSame(
			'home:' . self::$aliceUid,
			$stored['selector'],
			"asked for $asked, stored as her own home",
		);
	}


	/**
	 * One request, with its status code.
	 *
	 * `ignore_errors` so a 4xx returns its body instead of false: every row
	 * of this matrix is a status code, and half of them are refusals.
	 *
	 * @param  string|null  $as  'admin', 'alice', 'bob', or null for nobody.
	 *
	 * @return array{status: int, body: array}
	 */
	private function request(
		string  $method,
		string  $path,
		?array  $body,
		?string $as,
	): array {

		// OCS-APIRequest on every call. A plain GET on this app's routes is
		// answered without it, which is what the browser-side probing found
		// — but anything that changes something is refused with 412
		// Precondition Failed, and a 412 names neither the header nor the
		// fact that one is missing.
		$headers = [
			'Accept: application/json',
			'OCS-APIRequest: true',
		];

		if ( $as !== null )
		{
			$credentials = match ( $as )
			{
				'admin' => self::$adminUid . ':' . self::$adminPassword,
				'alice' => self::$aliceUid . ':' . self::$alicePassword,
				'bob'   => self::$bobUid . ':' . self::$bobPassword,
			};

			$headers[] = 'Authorization: Basic ' . base64_encode( $credentials );
		}

		$options = [
			'method'        => $method,
			'ignore_errors' => true,
		];

		if ( $body !== null )
		{
			$headers[]          = 'Content-Type: application/json';
			$options['content'] = json_encode( $body, JSON_THROW_ON_ERROR );
		}

		$options['header'] = implode( "\r\n", $headers );

		$raw = file_get_contents(
			$this->baseUrl . $path,
			false,
			stream_context_create( [ 'http' => $options ] ),
		);

		$status = 0;

		foreach ( $http_response_header ?? [] as $line )
		{
			if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) === 1 )
			{
				$status = (int) $m[1];
			}
		}

		return [
			'status' => $status,
			'body'   => is_string( $raw )
				? ( json_decode( $raw, true ) ?? [] )
				: [],
		];
	}
}
