<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\PublicApiController;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lockdown\ILockdownManager;
use OCA\FileChecksumSearch\Service\SudoScope;
use OCA\FileChecksumSearch\Service\SudoConfirmation;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCP\ISession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PublicApiControllerTest
	extends
	TestCase
{

// private properties
	private MockObject|ChecksumApi     $api;

	private MockObject|IUserSession    $userSession;

	private MockObject|IGroupManager   $groupManager;

	private MockObject|LoggerInterface $logger;

	private IUserConfig&MockObject $userConfig;

	private ILockdownManager&MockObject $lockdown;

	private SudoScope&MockObject $sudo;

	private ISession&MockObject $session;

	private PermissionService&MockObject $permissions;

	private SudoConfirmation&MockObject $confirmation;

	private PublicApiController        $controller;


	protected function setUp(): void
	{

		parent::setUp();

		$this->api          = $this->createMock( ChecksumApi::class );
		$this->userSession  = $this->createMock( IUserSession::class );
		$this->groupManager = $this->createMock( IGroupManager::class );
		$this->logger       = $this->createMock( LoggerInterface::class );
		$this->userConfig = $this->createMock( IUserConfig::class );
		$request            = $this->createMock( IRequest::class );

		// The default caller is a member of the admin group, which grants
		// nothing on the read side: every test below expects the caller's own
		// uid as the scope, administrator or not. Tests about somebody else
		// override the session per test.
		$this->lockdown = $this->createMock( ILockdownManager::class );
		$this->lockdown->method( 'canAccessFilesystem' )
		               ->willReturn( true )
		;
		// A browser session, and an account allowed the API, unless a test says so.
		$this->session = $this->createMock( ISession::class );
		$this->permissions = $this->createMock( PermissionService::class );
		$this->permissions->method( 'isAllowed' )
		                  ->willReturn( true )
		;
		// Nobody may look across accounts unless a test says so.
		$this->sudo = $this->createMock( SudoScope::class );
		$this->sudo->method( 'resolve' )
		           ->willReturn( false )
		;
		// Confirmed, unless a test says so: the confirmation rule has its own
		// tests, and these are about what the routes do with its answer.
		$this->confirmation = $this->createMock( SudoConfirmation::class );
		$this->confirmation->method( 'isConfirmed' )
		                   ->willReturn( true )
		;

		$adminUser = $this->createMock( IUser::class );
		$adminUser->method( 'getUID' )
		          ->willReturn( 'admin' )
		;
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $adminUser )
		;
		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'admin' )
		                   ->willReturn( true )
		;

		$this->controller = new PublicApiController(
			'file_checksum_search',
			$request,
			$this->api,
			$this->userSession,
			$this->groupManager,
			$this->logger,
			$this->createMock( AlgorithmCatalogue::class ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->session,
			$this->permissions,
			$this->confirmation,
		);
	}


	// ─── scope ──────────────────────────────────────────────────────

	/**
	 * Permission first, confirmation second: a sudoer who has not confirmed
	 * gets core's own message, which the pages' dialog recognises, and the
	 * API is never asked.
	 */
	public function testAnUnconfirmedSudoerIsAskedToConfirm(): void
	{

		$this->sudo = $this->createMock( SudoScope::class );
		$this->sudo->method( 'resolve' )
		           ->willReturn( null )
		;
		$this->confirmation = $this->createMock( SudoConfirmation::class );
		$this->confirmation->method( 'isConfirmed' )
		                   ->with( 'admin' )
		                   ->willReturn( false )
		;
		$controller = new PublicApiController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
			$this->api,
			$this->userSession,
			$this->groupManager,
			$this->logger,
			$this->createMock( AlgorithmCatalogue::class ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->session,
			$this->permissions,
			$this->confirmation,
		);
		$this->api->expects( $this->never() )
		          ->method( 'getHashesByFileId' )
		;

		$response = $controller->sudoGetHashes( 42 );

		$this->assertSame( Http::STATUS_FORBIDDEN, $response->getStatus() );
		$this->assertSame( 'Password confirmation required', $response->getData()['message'] );
	}


	/**
	 * The API permission gates requests that arrive with an app password —
	 * core leaves `app_password` in such a session — and not the browser
	 * session the bundled pages use to reach the very same routes.
	 */
	public function testAnAccountDeniedTheApiIsRefusedWithAnAppPasswordButNotFromThePages(): void
	{

		// An ordinary account: the administrator is never locked out, so the
		// refusal can only be seen on somebody who is not one.
		[ $session, $groups ] = $this->signedInAs( 'bob' );
		$this->permissions = $this->createMock( PermissionService::class );
		$this->permissions->method( 'isAllowed' )
		                  ->with( PermissionService::PERMISSION_API_ACCESS, 'bob' )
		                  ->willReturn( false )
		;
		$this->session = $this->createMock( ISession::class );
		$this->session->method( 'get' )
		              ->with( 'app_password' )
		              ->willReturn( 'a-token' )
		;
		$viaToken = new PublicApiController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
			$this->api,
			$session,
			$groups,
			$this->logger,
			$this->createMock( AlgorithmCatalogue::class ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->session,
			$this->permissions,
			$this->confirmation,
		);

		$this->assertSame( Http::STATUS_FORBIDDEN, $viaToken->getHashes( 42 )->getStatus() );

		// The same account over a plain browser session: nothing in the
		// session, no Authorization header — the app, not the API.
		$this->api->method( 'getHashesByFileId' )
		          ->with( 42, 'bob' )
		          ->willReturn( [ 'fileid' => 42, 'hashes' => [] ] )
		;
		$viaPage = new PublicApiController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
			$this->api,
			$session,
			$groups,
			$this->logger,
			$this->createMock( AlgorithmCatalogue::class ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->createMock( ISession::class ),
			$this->permissions,
			$this->confirmation,
		);

		$this->assertSame( Http::STATUS_OK, $viaPage->getHashes( 42 )->getStatus() );
	}


	/**
	 * The permission is the administrator's to set, and a setting that could
	 * cut off the account that fixes settings is a trap: an administrator is
	 * served whatever the permission says.
	 */
	public function testAnAdministratorIsNeverLockedOutOfTheApi(): void
	{

		$this->permissions = $this->createMock( PermissionService::class );
		$this->permissions->expects( $this->never() )
		                  ->method( 'isAllowed' )
		;
		$this->session = $this->createMock( ISession::class );
		$this->session->method( 'get' )
		              ->with( 'app_password' )
		              ->willReturn( 'a-token' )
		;
		$this->api->method( 'getHashesByFileId' )
		          ->with( 42, 'admin' )
		          ->willReturn( [ 'fileid' => 42, 'hashes' => [] ] )
		;
		$controller = new PublicApiController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
			$this->api,
			$this->userSession,
			$this->groupManager,
			$this->logger,
			$this->createMock( AlgorithmCatalogue::class ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->session,
			$this->permissions,
			$this->confirmation,
		);

		$this->assertSame( Http::STATUS_OK, $controller->getHashes( 42 )->getStatus() );
	}


	/**
	 * A session and a group manager for an ordinary account, for the tests
	 * whose point is that the caller is not an administrator.
	 *
	 * @return array{0: IUserSession&MockObject, 1: IGroupManager&MockObject}
	 */
	private function signedInAs( string $uid ): array
	{

		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( $uid )
		;
		$session = $this->createMock( IUserSession::class );
		$session->method( 'getUser' )
		        ->willReturn( $user )
		;
		$groups = $this->createMock( IGroupManager::class );
		$groups->method( 'isAdmin' )
		       ->willReturn( false )
		;

		return [ $session, $groups ];
	}


	/**
	 * A script with the account password over Basic auth is the API too:
	 * core does not mark that session, so the header is the tell.
	 */
	public function testCredentialsInTheAuthorizationHeaderCountAsTheApi(): void
	{

		[ $session, $groups ] = $this->signedInAs( 'bob' );
		$this->permissions = $this->createMock( PermissionService::class );
		$this->permissions->method( 'isAllowed' )
		                  ->willReturn( false )
		;
		$request = $this->createMock( IRequest::class );
		$request->method( 'getHeader' )
		        ->with( 'Authorization' )
		        ->willReturn( 'Basic Ym9iOnNlY3JldA==' )
		;
		$controller = new PublicApiController(
			'file_checksum_search',
			$request,
			$this->api,
			$session,
			$groups,
			$this->logger,
			$this->createMock( AlgorithmCatalogue::class ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->createMock( ISession::class ),
			$this->permissions,
			$this->confirmation,
		);

		$this->assertSame( Http::STATUS_FORBIDDEN, $controller->lookup( 'abc123' )->getStatus() );
	}


	/**
	 * The listing the Duplicates page loads says whether its viewer may
	 * switch to the instance-wide view, so the page asks nothing else.
	 */
	public function testTheListingSaysWhetherTheCallerMaySudo(): void
	{

		$this->sudo->method( 'isSudoer' )
		           ->with( 'admin' )
		           ->willReturn( true )
		;
		$this->api->method( 'findDuplicatesFor' )
		          ->willReturn( [ 'duplicates' => [], 'total_groups' => 0, 'pagination' => [ 'offset' => 0, 'limit' => 50 ] ] )
		;

		$data = $this->controller->findAllDuplicates()->getData();

		$this->assertTrue( $data['canSudo'] );
	}


	/**
	 * The sudo twin resolves the caller through SudoScope and passes what it
	 * answers — null for every account — straight down. The password
	 * confirmation the route carries is core's middleware and is not here.
	 */
	public function testTheSudoTwinReadsWhateverScopeTheResolverGrants(): void
	{

		$this->sudo = $this->createMock( SudoScope::class );
		$this->sudo->method( 'resolve' )
		           ->with( 'admin', null )
		           ->willReturn( null )
		;
		$controller = new PublicApiController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
			$this->api,
			$this->userSession,
			$this->groupManager,
			$this->logger,
			$this->createMock( AlgorithmCatalogue::class ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->session,
			$this->permissions,
			$this->confirmation,
		);
		$this->api->expects( $this->once() )
		          ->method( 'getHashesByFileId' )
		          ->with( 42, null )
		          ->willReturn( [ 'fileid' => 42, 'hashes' => [] ] )
		;

		$this->assertSame( Http::STATUS_OK, $controller->sudoGetHashes( 42 )->getStatus() );
	}


	/**
	 * The picker names a set, and this is the route the page actually calls —
	 * so the set has to reach the listing here, not only on the page's own
	 * twin. It did not, once: `users[]` was ignored, the route fell through
	 * to "every account", and every selection looked identical.
	 */
	public function testTheSudoTwinListsTheAccountsTheSetResolvesTo(): void
	{

		$this->sudo->expects( $this->once() )
		           ->method( 'resolveSet' )
		           ->with( 'admin', [ 'alice' ], [ 'team' ] )
		           ->willReturn( [ 'alice', 'bob' ] )
		;
		$this->api->expects( $this->once() )
		          ->method( 'findDuplicatesFor' )
		          ->with( [ 'alice', 'bob' ] )
		          ->willReturn( [ 'duplicates' => [] ] )
		;

		$response = $this->controller->sudoFindAllDuplicates(
			users: [ 'alice' ],
			groups: [ 'team' ],
		);

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
	}


	public function testTheSudoTwinRefusesASetTheResolverRefuses(): void
	{

		$this->sudo->method( 'resolveSet' )
		           ->willReturn( false )
		;
		$this->api->expects( $this->never() )
		          ->method( 'findDuplicatesFor' )
		;

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->sudoFindAllDuplicates( users: [ 'stranger' ] )->getStatus(),
		);
	}


	public function testTheSudoTwinRefusesWhoeverTheResolverRefuses(): void
	{

		$this->api->expects( $this->never() )
		          ->method( 'getHashesByFileId' )
		;
		$this->api->expects( $this->never() )
		          ->method( 'findByHash' )
		;

		$this->assertSame( Http::STATUS_FORBIDDEN, $this->controller->sudoGetHashes( 42 )->getStatus() );
		$this->assertSame( Http::STATUS_FORBIDDEN, $this->controller->sudoLookup( 'abc123' )->getStatus() );
		$this->assertSame( Http::STATUS_FORBIDDEN, $this->controller->sudoFindAllDuplicates( 'alice' )->getStatus() );
	}


	/**
	 * The ordinary route never crosses accounts, whatever the resolver would
	 * grant: it does not ask.
	 */
	public function testTheOrdinaryRouteNeverAsksTheResolver(): void
	{

		$this->sudo->expects( $this->never() )
		           ->method( 'resolve' )
		;
		$this->api->method( 'getHashesByFileId' )
		          ->with( 42, 'admin' )
		          ->willReturn( [ 'fileid' => 42, 'hashes' => [] ] )
		;

		$this->assertSame( Http::STATUS_OK, $this->controller->getHashes( 42 )->getStatus() );
	}


	/**
	 * An app password whose owner switched off "allow filesystem access"
	 * gets nothing from any route. Core enforces that scope by mounting
	 * nothing, which already empties every path that resolves a file; the
	 * lookup and the duplicate listing never touch a mount, so the refusal
	 * is made once, here, where it covers all of them.
	 */
	public function testATokenKeptOutOfTheFilesystemIsRefusedOnEveryRoute(): void
	{

		$this->lockdown = $this->createMock( ILockdownManager::class );
		$this->lockdown->method( 'canAccessFilesystem' )
		               ->willReturn( false )
		;
		$controller = new PublicApiController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
			$this->api,
			$this->userSession,
			$this->groupManager,
			$this->logger,
			$this->createMock( AlgorithmCatalogue::class ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->session,
			$this->permissions,
			$this->confirmation,
		);
		$this->api->expects( $this->never() )
		          ->method( 'getHashesByFileId' )
		;
		$this->api->expects( $this->never() )
		          ->method( 'findByHash' )
		;

		$this->assertSame( Http::STATUS_FORBIDDEN, $controller->getHashes( 42 )->getStatus() );
		$this->assertSame( Http::STATUS_FORBIDDEN, $controller->lookup( 'abc123' )->getStatus() );
	}


	// ─── lookup ─────────────────────────────────────────────────────

	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testFindAllDuplicatesPassesAllParams(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findDuplicatesFor' )
		          ->with( 'admin', 'sha256', 3, 10, 20 )
		          ->willReturn( [
			          'duplicates'   => [],
			          'total_groups' => 0,
			          'pagination'   => [
				          'offset' => 20,
				          'limit'  => 10,
			          ],
		          ] )
		;

		$response = $this->controller->findAllDuplicates( 'sha256', 3, 10, 20 );

		$this->assertInstanceOf( DataResponse::class, $response );
	}


	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testFindAllDuplicatesReturnsGroups(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findDuplicatesFor' )
		          ->with( 'admin', null, 2, 50, 0 )
		          ->willReturn( [
			          'duplicates'   => [],
			          'total_groups' => 0,
			          'pagination'   => [
				          'offset' => 0,
				          'limit'  => 50,
			          ],
		          ] )
		;

		$response = $this->controller->findAllDuplicates();

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertSame( 0, $data['total_groups'] );
	}


	public function testFindAllDuplicatesReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findDuplicatesFor' )
		          ->willThrowException( new \RuntimeException( 'DB error' ) )
		;

		$response = $this->controller->findAllDuplicates();

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testFindDuplicatesReturnsGroups(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findSameHash' )
		          ->with( 42 )
		          ->willReturn( [
			          'duplicates' => [
				          [
					          'algo'       => 'sha1',
					          'hash_value' => 'abc',
					          'files'      => [
						          [
							          'fileid' => 108,
							          'path'   => 'Backup',
							          'name'   => 'copy.jpg',
						          ],
					          ],
				          ],
			          ],
		          ] )
		;

		$response = $this->controller->findDuplicates( 42 );

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertCount( 1, $data['duplicates'] );
	}


	// ─── getHashes ──────────────────────────────────────────────────

	public function testFindDuplicatesReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findSameHash' )
		          ->willThrowException( new \RuntimeException( 'DB error' ) )
		;

		$response = $this->controller->findDuplicates( 42 );

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testGetHashesReturnsFileHashes(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'getHashesByFileId' )
		          ->with( 42, 'admin' )
		          ->willReturn( [
			          'fileid' => 42,
			          'hashes' => [
				          [
					          'algo' => 'sha1',
					          'hash' => 'abc',
				          ],
			          ],
		          ] )
		;

		$response = $this->controller->getHashes( 42 );

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertSame( 42, $data['fileid'] );
		$this->assertCount( 1, $data['hashes'] );
	}


	public function testGetHashesReturnsUnauthorizedWhenNoUser(): void
	{

		// Regression test for FCIAS Review §6, Finding 1.
		$this->userSession = $this->createMock( IUserSession::class );
		$this->userSession->method( 'getUser' )
		                  ->willReturn( null )
		;
		$this->controller = new PublicApiController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
			$this->api,
			$this->userSession,
			$this->groupManager,
			$this->logger,
			$this->createMock( AlgorithmCatalogue::class ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->session,
			$this->permissions,
			$this->confirmation,
		);

		$this->api->expects( $this->never() )
		          ->method( 'getHashesByFileId' )
		;

		$response = $this->controller->getHashes( 42 );

		$this->assertSame( Http::STATUS_UNAUTHORIZED, $response->getStatus() );
	}


	public function testGetHashesScopesNonAdminCallerToOwnFiles(): void
	{

		// Regression test for FCIAS Review §6, Finding 1: a non-admin
		// caller must be scoped to their own UID, not passed through
		// unrestricted.
		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( 'alice' )
		;
		$this->userSession = $this->createMock( IUserSession::class );
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;
		$this->groupManager = $this->createMock( IGroupManager::class );
		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'alice' )
		                   ->willReturn( false )
		;
		$this->controller = new PublicApiController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
			$this->api,
			$this->userSession,
			$this->groupManager,
			$this->logger,
			$this->createMock( AlgorithmCatalogue::class ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->session,
			$this->permissions,
			$this->confirmation,
		);

		$this->api->expects( $this->once() )
		          ->method( 'getHashesByFileId' )
		          ->with( 42, 'alice' )
		          ->willReturn( [
			          'fileid' => 42,
			          'hashes' => [],
		          ] )
		;

		$this->controller->getHashes( 42 );
	}


	public function testGetHashesReturnsNotFoundWhenFileInaccessibleToCaller(): void
	{

		// Regression test for FCIAS Review §6, Finding 1.
		$this->api->method( 'getHashesByFileId' )
		          ->willThrowException( new NotFoundException( 'Invalid file ID: 42' ) )
		;

		$response = $this->controller->getHashes( 42 );

		$this->assertSame( Http::STATUS_NOT_FOUND, $response->getStatus() );
	}


	// ─── findDuplicates (per-file) ──────────────────────────────────

	public function testGetHashesReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'getHashesByFileId' )
		          ->willThrowException( new \RuntimeException( 'DB error' ) )
		;

		$response = $this->controller->getHashes( 42 );

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testGetStatusReturnsHealthInfo(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'getStatus' )
		          ->willReturn( [
			          'version'     => '1.0.0',
			          'dbVersion'   => '10.11.6-MariaDB',
			          'rowCount'    => 5000,
			          'pendingRows' => 3,
		          ] )
		;

		$response = $this->controller->getStatus();

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertSame( '1.0.0', $data['version'] );
		$this->assertSame( 5000, $data['rowCount'] );
		$this->assertSame( 3, $data['pendingRows'] );
	}


	// ─── recalcHash ─────────────────────────────────────────────────

	public function testGetStatusReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'getStatus' )
		          ->willThrowException( new \RuntimeException( 'DB error' ) )
		;

		$response = $this->controller->getStatus();

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testLookupPassesAlgoParameter(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->with( 'abc123', 'md5', 100, 'admin' )
		          ->willReturn( [ 'results' => [] ] )
		;

		$response = $this->controller->lookup( 'abc123', 'md5' );

		$this->assertInstanceOf( DataResponse::class, $response );
	}


	public function testLookupReturnsBadRequestOnInvalidArgument(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->willThrowException( new \InvalidArgumentException( 'Hash parameter is required.' ) )
		;

		$response = $this->controller->lookup( '' );

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
		$this->assertArrayHasKey( 'error', $response->getData() );
	}


	// ─── findAllDuplicates ──────────────────────────────────────────

	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testLookupReturnsResults(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->with( 'abc123', null, 100, 'admin' )
		          ->willReturn( [
			          'results' => [
				          [
					          'fileid' => 42,
					          'algo'   => 'sha1',
					          'hash'   => 'abc123',
					          'path'   => 'Docs',
					          'name'   => 'report.pdf',
				          ],
			          ],
		          ] )
		;

		$response = $this->controller->lookup( 'abc123' );

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertArrayHasKey( 'results', $data );
		$this->assertCount( 1, $data['results'] );
	}


	public function testLookupScopesNonAdminCallerToOwnFiles(): void
	{

		// Regression test for FCIAS Review §6, Finding 1.
		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( 'alice' )
		;
		$this->userSession = $this->createMock( IUserSession::class );
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;
		$this->groupManager = $this->createMock( IGroupManager::class );
		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'alice' )
		                   ->willReturn( false )
		;
		$this->controller = new PublicApiController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
			$this->api,
			$this->userSession,
			$this->groupManager,
			$this->logger,
			$this->createMock( AlgorithmCatalogue::class ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->session,
			$this->permissions,
			$this->confirmation,
		);

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->with( 'abc123', null, 100, 'alice' )
		          ->willReturn( [ 'results' => [] ] )
		;

		$this->controller->lookup( 'abc123' );
	}


	public function testLookupReturnsServerErrorOnRuntimeException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->willThrowException( new \RuntimeException( 'DB failure' ) )
		;

		$response = $this->controller->lookup( 'abc123' );

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	public function testRecalcHashReturnsBadRequestOnFailure(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'recalcHash' )
		          ->with( 99999, null, 'admin' )
		          ->willReturn( [
			          'success' => false,
			          'error'   => 'File not found.',
		          ] )
		;

		$response = $this->controller->recalcHash( 99999 );

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
	}


	/**
	 * A permission refusal is policy, like an exclude rule: 403, so a client
	 * knows retrying will not help, and the reason travels with it.
	 */
	public function testRecalcHashAnswers403WhenThePermissionRefuses(): void
	{

		$this->api->method( 'recalcHash' )
		          ->willReturn( [
			          'success'   => false,
			          'error'     => 'This account may not calculate by hand.',
			          'forbidden' => true,
		          ] )
		;

		$response = $this->controller->recalcHash( 42 );

		$this->assertSame( Http::STATUS_FORBIDDEN, $response->getStatus() );
		$this->assertTrue( $response->getData()['forbidden'] );
	}


	// ─── getStatus ──────────────────────────────────────────────────

	public function testRecalcHashReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'recalcHash' )
		          ->willThrowException( new \RuntimeException( 'IO error' ) )
		;

		$response = $this->controller->recalcHash( 42 );

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	/**
	 * @noinspection PhpConditionAlreadyCheckedInspection
	 */
	public function testRecalcHashReturnsSuccess(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'recalcHash' )
		          ->with( 42, null, 'admin' )
		          ->willReturn( [
			          'success' => true,
			          'algo'    => 'sha1',
			          'hash'    => 'abc',
			          'fileid'  => 42,
		          ] )
		;

		$response = $this->controller->recalcHash( 42 );

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertTrue( $data['success'] );
	}


	public function testRecalcHashScopesNonAdminCallerToOwnFiles(): void
	{

		// Regression test for FCIAS Review §6, Finding 1.
		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( 'alice' )
		;
		$this->userSession = $this->createMock( IUserSession::class );
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;
		$this->groupManager = $this->createMock( IGroupManager::class );
		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'alice' )
		                   ->willReturn( false )
		;
		$this->controller = new PublicApiController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
			$this->api,
			$this->userSession,
			$this->groupManager,
			$this->logger,
			$this->createMock( AlgorithmCatalogue::class ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->session,
			$this->permissions,
			$this->confirmation,
		);

		$this->api->expects( $this->once() )
		          ->method( 'recalcHash' )
		          ->with( 42, null, 'alice' )
		          ->willReturn( [ 'success' => true ] )
		;

		$this->controller->recalcHash( 42 );
	}


	// ── preferences ─────────────────────────────────────────────────────

	/**
	 * A controller of its own for these: setUp() pins the session to an
	 * administrator and PHPUnit keeps the first stub it is given, so the
	 * anonymous case and the body under test need fresh mocks. The catalogue
	 * is real — its default is what `active` falls back to.
	 */
	private function preferenceController( ?string $uid, mixed $value = '' ): PublicApiController
	{

		$session = $this->createMock( IUserSession::class );
		if ( $uid !== null )
		{
			$user = $this->createMock( IUser::class );
			$user->method( 'getUID' )
			     ->willReturn( $uid )
			;
			$session->method( 'getUser' )
			        ->willReturn( $user )
			;
		}

		$request = $this->createMock( IRequest::class );
		$request->method( 'getParam' )
		        ->willReturnCallback( static fn( string $key, mixed $default = null ): mixed => $key === 'value' ? $value : $default )
		;

		return new PublicApiController(
			'file_checksum_search',
			$request,
			$this->api,
			$session,
			$this->groupManager,
			$this->logger,
			new AlgorithmCatalogue( $this->createMock( IAppConfig::class ) ),
			$this->userConfig,
			$this->lockdown,
			$this->sudo,
			$this->session,
			$this->permissions,
			$this->confirmation,
		);
	}


	public function testAPreferenceNeedsASession(): void
	{

		$controller = $this->preferenceController( null );

		$this->assertSame( Http::STATUS_UNAUTHORIZED, $controller->getPreference( 'preferred_algorithm' )->getStatus() );
		$this->assertSame( Http::STATUS_UNAUTHORIZED, $controller->setPreference( 'preferred_algorithm' )->getStatus() );
	}


	public function testAnUnknownPreferenceIsNotFound(): void
	{

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$this->preferenceController( 'alice' )->getPreference( 'favourite_colour' )->getStatus(),
		);
	}


	/**
	 * `value` is what was stored, `default` the instance's, `active` which of
	 * the two applies.
	 */
	public function testReadingThePreferenceSaysWhichOneIsActive(): void
	{

		$this->userConfig->method( 'getValueString' )
		                 ->willReturn( 'sha256' )
		;

		$data = $this->preferenceController( 'alice' )->getPreference( 'preferred_algorithm' )->getData();

		$this->assertSame( 'preferred_algorithm', $data['key'] );
		$this->assertSame( 'sha256', $data['value'] );
		$this->assertSame( 'sha1', $data['default'] );
		$this->assertSame( 'sha256', $data['active'] );
	}


	/**
	 * A stored preference the administrator has since disallowed is kept but
	 * not applied: the default is active until the user picks again.
	 */
	public function testAStoredPreferenceNoLongerInForceYieldsToTheDefault(): void
	{

		$this->userConfig->method( 'getValueString' )
		                 ->willReturn( 'whirlpool' )
		;

		$data = $this->preferenceController( 'alice' )->getPreference( 'preferred_algorithm' )->getData();

		$this->assertSame( 'whirlpool', $data['value'] );
		$this->assertSame( 'sha1', $data['active'] );
	}


	public function testAnEmptyValueReturnsToTheDefault(): void
	{

		$this->userConfig->expects( $this->once() )
		                 ->method( 'deleteUserConfig' )
		                 ->with( 'alice', 'file_checksum_search', 'preferred_algorithm' )
		;
		$this->userConfig->expects( $this->never() )
		                 ->method( 'setValueString' )
		;

		$response = $this->preferenceController( 'alice', '' )
		                 ->setPreference( 'preferred_algorithm' )
		;

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
	}


	public function testAnAlgorithmNotInForceIsRefused(): void
	{

		$this->userConfig->expects( $this->never() )
		                 ->method( 'setValueString' )
		;

		$response = $this->preferenceController( 'alice', 'whirlpool' )
		                 ->setPreference( 'preferred_algorithm' )
		;

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
	}


	public function testAnAlgorithmInForceIsStoredLowerCased(): void
	{

		$this->userConfig->expects( $this->once() )
		                 ->method( 'setValueString' )
		                 ->with( 'alice', 'file_checksum_search', 'preferred_algorithm', 'sha256' )
		;

		$response = $this->preferenceController( 'alice', ' SHA256 ' )
		                 ->setPreference( 'preferred_algorithm' )
		;

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
	}

}
