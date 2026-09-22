<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCP\Config\IUserConfig;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCA\FileChecksumSearch\Service\DuplicateService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lockdown\ILockdownManager;
use OCP\ISession;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\SudoScope;
use OCA\FileChecksumSearch\Service\SudoConfirmation;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public REST API v1 controller.
 *
 * Thin HTTP adapter over ChecksumApi. All endpoints are public
 * (NoAdminRequired) and CSRF-exempt (NoCSRFRequired) for API access.
 * Non-admin callers are scoped to their own files — see
 * {@see scopeOrRefusal()}.
 *
 * @noinspection PhpUnused
 */
class PublicApiController
	extends
	ApiController
{

	public function __construct(
		string                           $appName,
		IRequest                         $request,
		private readonly ChecksumApi     $api,
		private readonly IUserSession    $userSession,
		private readonly IGroupManager   $groupManager,
		private readonly LoggerInterface $logger,
		private readonly AlgorithmCatalogue $catalogue,
		private readonly IUserConfig     $userConfig,
		private readonly ILockdownManager $lockdown,
		private readonly SudoScope       $sudo,
		private readonly ISession        $session,
		private readonly PermissionService $permissions,
		private readonly SudoConfirmation $confirmation,
		private readonly IAppConfig      $appConfig,
	) {

		parent::__construct( $appName, $request );
	}


	/**
	 * Whose files a request may read: the caller's own, always.
	 *
	 * This used to answer `null` — unrestricted — for a member of the admin
	 * group, so an administrator who merely opened a file was reading across
	 * every account, unasked and unseen. Membership grants nothing here now.
	 * Looking across accounts is a separate set of routes, named for it and
	 * behind a password confirmation or an explicit grant.
	 *
	 * A token its owner kept out of the filesystem is refused outright. Core
	 * enforces that scope by mounting nothing, which already empties every
	 * path that resolves a file through the user's folder; the lookup and
	 * the duplicate listing never touch a mount, and a hash is still a fact
	 * about a file, so the refusal is made here where it covers all of them.
	 *
	 * @return string|DataResponse  The caller's uid, or the response to send
	 *                              instead: 401 with no session, 403 for a
	 *                              token kept out of files or for an account
	 *                              the API permission does not name.
	 */
	private function scopeOrRefusal(): string|DataResponse
	{

		$user = $this->userSession->getUser();

		if ( $user === null )
		{
			return new DataResponse(
				[ 'success' => false, 'error' => 'Not authenticated.' ],
				Http::STATUS_UNAUTHORIZED,
			);
		}

		if ( ! $this->lockdown->canAccessFilesystem() )
		{
			return new DataResponse(
				[ 'success' => false, 'error' => 'This app password may not access files.' ],
				Http::STATUS_FORBIDDEN,
			);
		}

		// The API permission gates the API, not the app: the bundled pages
		// reach these same routes over the browser session and keep working
		// for everyone. What makes a request "the API" is how it
		// authenticated — see isApiRequest(). An administrator is never
		// locked out: the permission is theirs to set, and a setting that
		// could cut off the account that fixes settings is a trap.
		if ( $this->isApiRequest()
		     && ! $this->groupManager->isAdmin( $user->getUID() )
		     && ! $this->permissions->isAllowed( PermissionService::PERMISSION_API_ACCESS, $user->getUID() ) )
		{
			return new DataResponse(
				[ 'success' => false, 'error' => 'This account may not use the API.' ],
				Http::STATUS_FORBIDDEN,
			);
		}

		return $user->getUID();
	}


	/**
	 * Whether this request came from outside the app's own pages.
	 *
	 * Core marks a session that was opened with an app password by leaving
	 * `app_password` in it (Session::logClientIn); a login with the account
	 * password over Basic auth is not marked, so the Authorization header
	 * is the other half of the test. A browser session sends neither. The
	 * session key is a core-private name in a public store — the same kind
	 * of coupling as reading a core table, and worth this sentence.
	 */
	private function isApiRequest(): bool
	{

		return $this->session->get( 'app_password' ) !== null
		       || $this->request->getHeader( 'Authorization' ) !== '';
	}


	/**
	 * The scope a cross-account route may read, or the response to send
	 * instead.
	 *
	 * Everything {@see scopeOrRefusal()} refuses, this refuses too. On top
	 * of that, two things, in this order: the caller must be someone who may
	 * look across accounts at all ({@see SudoScope}), and the request must be
	 * confirmed ({@see SudoConfirmation}). Permission first, so that someone
	 * who may not ask is told so without being made to type a password first.
	 *
	 * The scope is the caller's ceiling: every account for a sudoer, the
	 * members of their groups for a sub-admin. A route that names accounts
	 * goes through {@see sudoSetOrRefusal()} instead.
	 *
	 * @return list<string>|null|DataResponse  The scope to pass down — a list
	 *         of accounts, or null for every account — or the refusal.
	 */
	private function sudoScopeOrRefusal(): array|null|DataResponse
	{

		$own = $this->scopeOrRefusal();

		if ( $own instanceof DataResponse )
		{
			return $own;
		}

		$scope = $this->sudo->resolve( $own );

		if ( $scope !== false && ! $this->confirmation->isConfirmed( $own ) )
		{
			// The message core's own middleware uses, so the confirmation
			// dialog the pages already run recognises it.
			return new DataResponse(
				[ 'success' => false, 'message' => 'Password confirmation required' ],
				Http::STATUS_FORBIDDEN,
			);
		}

		if ( $scope === false )
		{
			return new DataResponse(
				[ 'success' => false, 'error' => 'Not yours to look at.' ],
				Http::STATUS_FORBIDDEN,
			);
		}

		return $scope;
	}


	/**
	 * The caller's own uid when they may act on one file that need not be
	 * theirs, or the response to send instead.
	 *
	 * The per-file twin of {@see sudoScopeOrRefusal()}. That one asks
	 * {@see SudoScope::resolve()} about an account, and a per-file route has
	 * none to name — passing null means "every account", which only a sudoer
	 * may have, so a sub-admin was refused for files their own listing shows
	 * them. {@see SudoScope::mayReachFile()} answers the question actually
	 * being asked.
	 *
	 * Returns the *caller*, not a scope: the reach is settled here, and what
	 * the route still needs downstream is who is acting.
	 */
	private function sudoFileOrRefusal( int $fileId ): string|DataResponse
	{

		$own = $this->scopeOrRefusal();

		if ( $own instanceof DataResponse )
		{
			return $own;
		}

		$mayReach = $this->sudo->mayReachFile( $own, $fileId );

		if ( $mayReach && ! $this->confirmation->isConfirmed( $own ) )
		{
			return new DataResponse(
				[ 'success' => false, 'message' => 'Password confirmation required' ],
				Http::STATUS_FORBIDDEN,
			);
		}

		if ( ! $mayReach )
		{
			return new DataResponse(
				[ 'success' => false, 'error' => 'Not yours to look at.' ],
				Http::STATUS_FORBIDDEN,
			);
		}

		return $own;
	}


	/**
	 * The most $own may reach with nothing named: null for a sudoer (every
	 * account), a group leader's members otherwise. Called only after a
	 * refusal helper has admitted the caller, so a refusal here would be a
	 * contradiction; it is answered as an empty reach rather than trusted.
	 *
	 * @return list<string>|null
	 */
	private function ceilingOf( string $own ): ?array
	{

		$ceiling = $this->sudo->resolve( $own );

		return $ceiling === false ? [] : $ceiling;
	}


	/**
	 * A scope as the routes carry it — one account, several, or null — in
	 * the shape the API takes: a list, or null for every account.
	 *
	 * @param  string|list<string>|null  $scope
	 *
	 * @return list<string>|null
	 */
	private function reachOf( string|array|null $scope ): ?array
	{

		return match ( true ) {
			$scope === null       => null,
			is_string( $scope )   => [ $scope ],
			default               => array_values( $scope ),
		};
	}


	/**
	 * The accounts a cross-account route may read when the caller names a
	 * set, or the response to send instead.
	 *
	 * The set twin of {@see sudoScopeOrRefusal()}, and it refuses in the same
	 * order: everything {@see scopeOrRefusal()} refuses, then whether the
	 * caller may read every named target ({@see SudoScope::resolveSet()},
	 * which expands the groups), then whether the request is confirmed.
	 *
	 * @param  list<string>  $users
	 * @param  list<string>  $groups
	 *
	 * @return list<string>|DataResponse
	 */
	private function sudoSetOrRefusal(
		array $users,
		array $groups,
	): array|DataResponse {

		$own = $this->scopeOrRefusal();

		if ( $own instanceof DataResponse )
		{
			return $own;
		}

		$scope = $this->sudo->resolveSet( $own, $users, $groups );

		if ( $scope !== false && ! $this->confirmation->isConfirmed( $own ) )
		{
			return new DataResponse(
				[ 'success' => false, 'message' => 'Password confirmation required' ],
				Http::STATUS_FORBIDDEN,
			);
		}

		if ( $scope === false )
		{
			return new DataResponse(
				[ 'success' => false, 'error' => 'Not yours to look at.' ],
				Http::STATUS_FORBIDDEN,
			);
		}

		return $scope;
	}


	/**
	 * Get all checksums for a file by filecache ID.
	 *
	 * @noinspection PhpUnused
	 */
	/**
	 * The algorithms this instance computes, and the one used when none is
	 * named.
	 *
	 * Neither is fixed: the set is what PHP offers narrowed to what the
	 * administrator allows, and every picker in the app reads it from here
	 * rather than carrying its own copy — which is what lets an
	 * administrator enable `sha384` without a release.
	 *
	 * @noinspection PhpUnused
	 */
	/**
	 * One of the caller's own preferences.
	 *
	 * `/api/v1/preferences/{key}` was reserved by AP RuleBands for exactly
	 * this: per-user settings that belong to the API rather than to a page.
	 * The first key is `preferred_algorithm` — the algorithm the sidebar
	 * offers first. `value` is what the user stored (empty when nothing),
	 * `default` is the instance's, and `active` is which of the two applies.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/api/v1/preferences/{key}' )]
	public function getPreference( string $key ): DataResponse
	{

		$uid = $this->userSession->getUser()?->getUID();

		if ( $uid === null )
		{
			return new DataResponse( [ 'error' => 'Not authenticated.' ], Http::STATUS_UNAUTHORIZED );
		}

		if ( $key !== ConfigLexicon::USER_PREFERRED_ALGORITHM )
		{
			return new DataResponse( [ 'error' => 'Unknown preference.' ], Http::STATUS_NOT_FOUND );
		}

		return new DataResponse( $this->preferredAlgorithm( $uid ) );
	}


	/**
	 * Set one of the caller's own preferences. The body is `{"value": …}`;
	 * an empty value returns to the instance default.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[ApiRoute( verb: 'PUT', url: '/api/v1/preferences/{key}' )]
	public function setPreference( string $key ): DataResponse
	{

		$uid = $this->userSession->getUser()?->getUID();

		if ( $uid === null )
		{
			return new DataResponse( [ 'error' => 'Not authenticated.' ], Http::STATUS_UNAUTHORIZED );
		}

		if ( $key !== ConfigLexicon::USER_PREFERRED_ALGORITHM )
		{
			return new DataResponse( [ 'error' => 'Unknown preference.' ], Http::STATUS_NOT_FOUND );
		}

		// Nextcloud decodes an application/json body into the request's
		// params, so the body {"value": …} arrives as one param.
		$value = $this->request->getParam( 'value', '' );

		if ( ! is_string( $value ) )
		{
			return new DataResponse( [ 'error' => 'value must be a string.' ], Http::STATUS_BAD_REQUEST );
		}

		$value = strtolower( trim( $value ) );

		if ( $value === '' )
		{
			$this->userConfig->deleteUserConfig( $uid, Application::APP_ID, $key );
		}
		elseif ( $this->catalogue->isValid( $value ) )
		{
			$this->userConfig->setValueString( $uid, Application::APP_ID, $key, $value );
		}
		else
		{
			return new DataResponse(
				[ 'error' => 'Not an algorithm this instance computes: ' . $value ],
				Http::STATUS_BAD_REQUEST,
			);
		}

		return new DataResponse( $this->preferredAlgorithm( $uid ) );
	}


	/**
	 * @return array{key: string, value: string, default: string, active: string}
	 */
	private function preferredAlgorithm( string $uid ): array
	{

		$stored  = $this->userConfig->getValueString( $uid, Application::APP_ID, ConfigLexicon::USER_PREFERRED_ALGORITHM );
		$default = $this->catalogue->default();
		// A stored preference the administrator has since disallowed is kept
		// but not applied: the default is active until the user picks again.
		$active  = $this->catalogue->isValid( $stored ) ? $stored : $default;

		return [
			'key'     => ConfigLexicon::USER_PREFERRED_ALGORITHM,
			'value'   => $stored,
			'default' => $default,
			'active'  => $active,
		];
	}


	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/api/v1/algorithms' )]
	public function getAlgorithms(): DataResponse
	{

		return new DataResponse( [
			'algorithms' => $this->catalogue->algorithms(),
			'default'    => $this->catalogue->default(),
		] );
	}


	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/api/v1/file/{fileId}/hashes', requirements: [ 'fileId' => '\d+' ] )]
	public function getHashes( int $fileId ): DataResponse
	{

		$own = $this->scopeOrRefusal();

		return $own instanceof DataResponse
			? $own
			: $this->hashesFor( $fileId, $own, [ $own ] );
	}


	/**
	 * {@see getHashes()} for a file that need not be the caller's own. A
	 * password confirmation, and only for those who may look across accounts.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/api/v1/sudo/file/{fileId}/hashes', requirements: [ 'fileId' => '\d+' ] )]
	public function sudoGetHashes( int $fileId ): DataResponse
	{

		$own = $this->sudoFileOrRefusal( $fileId );

		// The caller is who is asking; their ceiling is what may be reached.
		// The reach was settled for this file already, so the body's own
		// check is a repeat — cheap, and it keeps one rule for both routes.
		return $own instanceof DataResponse
			? $own
			: $this->hashesFor( $fileId, $own, $this->ceilingOf( $own ) );
	}


	/**
	 * The route's body, for either wrapper. $actingUser is who asked — the
	 * session's account, never anything a client sent — and $reachUids is
	 * whose files they may ask about: their own, their members', or null for
	 * every account.
	 */
	private function hashesFor(
		int    $fileId,
		string $actingUser,
		?array $reachUids,
	): DataResponse {

		$this->logger->debug(
			'FCIAS PublicApiController: getHashes called',
			[
				'app'    => Application::APP_ID,
				'fileId' => $fileId,
			],
		);

		try
		{
			$result = $this->api->getHashesByFileId( $fileId, $actingUser, $reachUids );

			return new DataResponse( $result );
		}
		catch ( NotFoundException )
		{
			return new DataResponse( [ 'error' => 'File not found.' ], Http::STATUS_NOT_FOUND );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS PublicApiController: getHashes failed',
				[
					'app'       => Application::APP_ID,
					'fileId'    => $fileId,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[ 'error' => 'Internal server error.' ],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * Read-only health/status snapshot.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/api/v1/status' )]
	public function getStatus(): DataResponse
	{

		$this->logger->debug(
			'FCIAS PublicApiController: getStatus called',
			[ 'app' => Application::APP_ID ],
		);

		try
		{
			$result = $this->api->getStatus( $this->userSession->getUser()?->getUID() );

			return new DataResponse( $result );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS PublicApiController: getStatus failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[ 'error' => 'Internal server error.' ],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * Find duplicate groups among the files the session user can reach.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit( limit: 60, period: 60 )]
	#[ApiRoute( verb: 'GET', url: '/api/v1/duplicates' )]
	public function findAllDuplicates(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?string $hash = null,
		bool    $anywhere = false,
	): DataResponse {

		$scope = $this->scopeOrRefusal();

		if ( $scope instanceof DataResponse )
		{
			return $scope;
		}

		$response = $this->duplicatesFor( $scope, $algo, $minCount, $limit, $offset, $hash, $anywhere );

		// Whether the caller may cross into other accounts at all. Rides on
		// the listing the page loads anyway, so the page needs no second
		// request to know whether to offer the tab — and a script gets the
		// same fact for free. mayCross(), not isSudoer(): the latter asks
		// whether they may see *everyone*, which a group leader may not, and
		// asking it here hid the tab from the very people the picker serves.
		if ( $response->getStatus() === Http::STATUS_OK )
		{
			$response->setData( $response->getData() + [ 'canSudo' => $this->sudo->mayCross( $scope ) ] );
		}

		return $response;
	}


	/**
	 * {@see findAllDuplicates()} across accounts: the ones `users[]` and
	 * `groups[]` name, or — with nothing named — the caller's ceiling, every
	 * account for a sudoer and their groups' members for a sub-admin. A
	 * password confirmation, and only for those who may look across accounts.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/api/v1/sudo/duplicates' )]
	public function sudoFindAllDuplicates(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?string $hash = null,
		bool    $anywhere = false,
		?array  $users = null,
		?array  $groups = null,
	): DataResponse {

		// One way to name whom, the one the picker sends. `user=` for a single
		// account was a third encoding of the same idea and is gone: one
		// account is a set of one.
		$scope = ( $users !== null || $groups !== null )
			? $this->sudoSetOrRefusal(
				array_values( array_filter( (array) $users, 'is_string' ) ),
				array_values( array_filter( (array) $groups, 'is_string' ) ),
			)
			: $this->sudoScopeOrRefusal();

		return $scope instanceof DataResponse
			? $scope
			: $this->duplicatesFor( $scope, $algo, $minCount, $limit, $offset, $hash, $anywhere );
	}


	/**
	 * The listing's body, for either wrapper: $scope is whose files — a
	 * uid, or null for every account.
	 */
	private function duplicatesFor(
		string|array|null $scope,
		?string $algo,
		int     $minCount,
		int     $limit,
		int     $offset,
		?string $hash = null,
		bool    $anywhere = false,
	): DataResponse {

		// One account, several or every: the API takes a list or null, and
		// the normalising happens here, once, rather than in each caller.
		$reachUids = $this->reachOf( $scope );

		$this->logger->debug(
			'FCIAS PublicApiController: findAllDuplicates called',
			[
				'app'      => Application::APP_ID,
				'algo'     => $algo,
				'minCount' => $minCount,
				'limit'    => $limit,
				'offset'   => $offset,
			],
		);

		try
		{
			$result = $this->api->findDuplicatesFor( $reachUids, $algo, $minCount, $limit, $offset, $hash, $anywhere );

			return new DataResponse( $result );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS PublicApiController: findAllDuplicates failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[ 'error' => 'Internal server error.' ],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * Find files sharing hash values with a given file.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit( limit: 60, period: 60 )]
	#[ApiRoute( verb: 'GET', url: '/api/v1/file/{fileId}/duplicates', requirements: [ 'fileId' => '\d+' ] )]
	public function findDuplicates( int $fileId ): DataResponse
	{

		$own = $this->scopeOrRefusal();

		return $own instanceof DataResponse
			? $own
			: $this->sameHashFor( $fileId, [ $own ] );
	}


	/**
	 * {@see findDuplicates()} for a file that need not be the caller's own,
	 * with the duplicates drawn from the caller's whole reach — every
	 * account for a sudoer, their members' for a group leader. A password
	 * confirmation, and only for those who may look across accounts.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/api/v1/sudo/file/{fileId}/duplicates', requirements: [ 'fileId' => '\d+' ] )]
	public function sudoFindDuplicates( int $fileId ): DataResponse
	{

		$own = $this->sudoFileOrRefusal( $fileId );

		// The ceiling bounds the *duplicates* as well as the reference file:
		// a group leader is shown copies their members hold, not copies held
		// anywhere on the instance.
		return $own instanceof DataResponse
			? $own
			: $this->sameHashFor( $fileId, $this->ceilingOf( $own ) );
	}


	/**
	 * The route's body, for either wrapper: $reachUids is whose files may
	 * be read — the caller's own, their members', or null for every account.
	 */
	private function sameHashFor( int $fileId, ?array $reachUids ): DataResponse
	{

		$this->logger->debug(
			'FCIAS PublicApiController: findDuplicates (per-file) called',
			[
				'app'    => Application::APP_ID,
				'fileId' => $fileId,
			],
		);

		try
		{
			$result = $this->api->findSameHash( $fileId, $reachUids );

			return new DataResponse( $result );
		}
		catch ( NotFoundException )
		{
			return new DataResponse( [ 'error' => 'File not found.' ], Http::STATUS_NOT_FOUND );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS PublicApiController: findDuplicates (per-file) failed',
				[
					'app'       => Application::APP_ID,
					'fileId'    => $fileId,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[ 'error' => 'Internal server error.' ],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * Search files by hash value.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit( limit: 60, period: 60 )]
	#[ApiRoute( verb: 'GET', url: '/api/v1/lookup' )]
	public function lookup(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
	): DataResponse
	{

		$scope = $this->scopeOrRefusal();

		return $scope instanceof DataResponse
			? $scope
			: $this->lookupFor( $hash, $algo, $limit, $scope );
	}


	/**
	 * The groups and accounts the caller may name on the cross-account
	 * routes.
	 *
	 * The companion question to {@see sudoFindAllDuplicates()}: before you
	 * can ask for someone's duplicates you have to know whom you may ask
	 * about. A member of `admin`, or anyone `instance_view` names, may name
	 * anyone; a sub-admin only the groups they administer and their members;
	 * anyone else may name nobody and is refused.
	 *
	 * `prefill` false means there are more than a picker holds at once, so
	 * the caller should come back with `?search=` as the user types. The
	 * threshold is an instance setting (admin settings → Advanced).
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit( limit: 60, period: 60 )]
	#[ApiRoute( verb: 'GET', url: '/api/v1/sudo/selectable' )]
	public function sudoSelectable( ?string $search = null ): DataResponse
	{

		$own = $this->scopeOrRefusal();

		if ( $own instanceof DataResponse )
		{
			return $own;
		}

		$offer = $this->sudo->selectableFor(
			$own,
			$search,
			$this->appConfig->getValueInt(
				Application::APP_ID,
				ConfigLexicon::CROSS_ACCOUNT_PREFILL_LIMIT,
			),
		);

		if ( $offer === false )
		{
			return new DataResponse(
				[ 'success' => false, 'error' => 'Not yours to look at.' ],
				Http::STATUS_FORBIDDEN,
			);
		}

		// Whether the caller may also ask for every account at once — only a
		// sudoer is offered that.
		$offer['all'] = $this->sudo->isSudoer( $own );

		return new DataResponse( $offer );
	}


	/**
	 * {@see lookup()} across every account. A password confirmation, and only
	 * for those who may look across accounts.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/api/v1/sudo/lookup' )]
	public function sudoLookup(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
	): DataResponse
	{

		$scope = $this->sudoScopeOrRefusal(  );

		return $scope instanceof DataResponse
			? $scope
			: $this->lookupFor( $hash, $algo, $limit, $scope );
	}


	/**
	 * The route's body, for either wrapper: $scope is whose files may be
	 * read — a uid, a list of them, or null for every account.
	 */
	private function lookupFor( string $hash, ?string $algo, int $limit, string|array|null $scope ): DataResponse
	{

		$this->logger->debug(
			'FCIAS PublicApiController: lookup called',
			[
				'app'  => Application::APP_ID,
				'algo' => $algo,
			],
		);

		try
		{
			$result = $this->api->findByHash( $hash, $algo, $limit, $this->reachOf( $scope ) );

			return new DataResponse( $result );
		}
		catch ( \InvalidArgumentException $e )
		{
			return new DataResponse(
				[ 'error' => $e->getMessage() ],
				Http::STATUS_BAD_REQUEST,
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS PublicApiController: lookup failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[ 'error' => 'Internal server error.' ],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * Recalculate hash for a file.
	 *
	 * @noinspection PhpUnused
	 */
	// No #[NoCSRFRequired]: this is a mutating POST, and that attribute drops
	// the strict-cookie check with the CSRF one. A browser sends the request
	// token (useSidebarHashes.ts, useDuplicates.ts); an API caller sends
	// OCS-APIRequest, which OCS accepts in its place. The read routes below
	// keep it — a GET is not the concern, and an app password carries no
	// token.
	#[NoAdminRequired]
	#[UserRateLimit( limit: 20, period: 60 )]
	#[ApiRoute( verb: 'POST', url: '/api/v1/file/{fileId}/recalc', requirements: [ 'fileId' => '\d+' ] )]
	public function recalcHash( int $fileId ): DataResponse
	{

		$scope = $this->scopeOrRefusal();

		return $scope instanceof DataResponse
			? $scope
			: $this->recalcFor( $fileId, $scope, [ $scope ] );
	}


	/**
	 * {@see recalcHash()} for a file that need not be the caller's own.
	 *
	 * Refuses in the order the other cross-account routes do — who may use
	 * the API at all, then whether this file is theirs to reach, then the
	 * password confirmation — so someone who may not ask is told so without
	 * being made to type a password first.
	 *
	 * Reaching the file is not permission to make the server work on it: the
	 * manual-calculation permission is still answered against the caller,
	 * inside {@see ChecksumApi::recalcHash()}, and so is any rule excluding
	 * the path.
	 *
	 * @noinspection PhpUnused
	 */
	// No #[NoCSRFRequired], for the reason given on the route above.
	#[NoAdminRequired]
	#[UserRateLimit( limit: 20, period: 60 )]
	#[ApiRoute( verb: 'POST', url: '/api/v1/sudo/file/{fileId}/recalc', requirements: [ 'fileId' => '\d+' ] )]
	public function sudoRecalcHash( int $fileId ): DataResponse
	{

		$scope = $this->sudoFileOrRefusal( $fileId );

		if ( $scope instanceof DataResponse )
		{
			return $scope;
		}

		// Info, not debug: this one writes a hash onto a file that is not the
		// caller's, and the log is the only place that says who asked. The
		// lines further down carry the fileid and never a uid, so without
		// this a cross-account recalculation reads as though the owner did it.
		$this->logger->info(
			'FCIAS PublicApiController: cross-account recalculation',
			[
				'app'        => Application::APP_ID,
				'fileId'     => $fileId,
				'actingUser' => $scope,
			],
		);

		return $this->recalcFor( $fileId, $scope, $this->ceilingOf( $scope ) );
	}


	/**
	 * Recalculate several files in one request.
	 *
	 * One gesture, one call: verifying a group of a hundred files used to be
	 * a hundred requests against a limit of twenty a minute, so small files
	 * — which answer fastest — hit it hardest. The body is JSON,
	 * `{ "fileIds": [ … ], "algo": "sha1" }`; the answer carries one result
	 * per file and the ids it stopped short of, capped as
	 * {@see ChecksumApi::recalcMany()} says.
	 *
	 * `many` is a literal in the position `{fileId}` takes on the route
	 * beside it; the `\d+` requirement on that route is what keeps this one
	 * from being parsed as a file called "many".
	 *
	 * @noinspection PhpUnused
	 */
	// No #[NoCSRFRequired], for the reason given on recalcHash().
	#[NoAdminRequired]
	#[UserRateLimit( limit: 20, period: 60 )]
	#[ApiRoute( verb: 'POST', url: '/api/v1/file/many/recalc' )]
	public function recalcMany(): DataResponse
	{

		$own = $this->scopeOrRefusal();

		return $own instanceof DataResponse
			? $own
			: $this->recalcManyFor( $own, [ $own ] );
	}


	/**
	 * {@see recalcMany()} across the caller's whole reach. The reach is
	 * decided per file inside the API, so a mixed batch answers per file —
	 * a group on the Others tab can hold several accounts' copies, and
	 * refusing the whole batch for one unreachable id would make such a
	 * group unverifiable.
	 *
	 * @noinspection PhpUnused
	 */
	// No #[NoCSRFRequired], for the reason given on recalcHash().
	#[NoAdminRequired]
	#[UserRateLimit( limit: 20, period: 60 )]
	#[ApiRoute( verb: 'POST', url: '/api/v1/sudo/file/many/recalc' )]
	public function sudoRecalcMany(): DataResponse
	{

		$scope = $this->sudoScopeOrRefusal();

		if ( $scope instanceof DataResponse )
		{
			return $scope;
		}

		$own = $this->userSession->getUser()?->getUID() ?? '';

		$this->logger->info(
			'FCIAS PublicApiController: cross-account batch recalculation',
			[
				'app'        => Application::APP_ID,
				'actingUser' => $own,
			],
		);

		return $this->recalcManyFor( $own, $scope );
	}


	/**
	 * The batch route's body, for either wrapper.
	 */
	private function recalcManyFor(
		string $actingUser,
		?array $reachUids,
	): DataResponse {

		$body    = json_decode( (string) file_get_contents( 'php://input' ), true );
		$fileIds = is_array( $body ) && is_array( $body['fileIds'] ?? null )
			? array_values( array_filter( $body['fileIds'], 'is_int' ) )
			: [];
		$algo    = is_array( $body ) && is_string( $body['algo'] ?? null )
			? $body['algo']
			: $this->request->getParam( 'algo' );

		if ( $fileIds === [] )
		{
			return new DataResponse(
				[ 'success' => false, 'error' => 'fileIds must be a non-empty list of integers.' ],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try
		{
			return new DataResponse( $this->api->recalcMany( $fileIds, $algo, $actingUser, $reachUids ) );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS PublicApiController: recalcMany failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[ 'success' => false, 'error' => 'Internal server error.' ],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * The route's body, for either wrapper. $actingUser is who asked —
	 * always the session's account, never anything a client sent — and
	 * $reachUids is whose files they may act on: their own, their members',
	 * or null for every account.
	 */
	private function recalcFor(
		int    $fileId,
		string $actingUser,
		?array $reachUids,
	): DataResponse {

		$body = json_decode( file_get_contents( 'php://input' ), true );
		$algo = is_array( $body )
			? ( $body['algo'] ?? null )
			: null;

		// Fall back to query-string parameter (used by frontend fetch calls)
		if ( $algo === null )
		{
			$algo = $this->request->getParam( 'algo' );
		}

		$this->logger->debug(
			'FCIAS PublicApiController: recalcHash called',
			[
				'app'        => Application::APP_ID,
				'fileId'     => $fileId,
				'algo'       => $algo,
				'actingUser' => $actingUser,
			],
		);

		try
		{
			$result = $this->api->recalcHash( $fileId, $algo, $actingUser, $reachUids );

			if ( $result['success'] )
			{
				return new DataResponse( $result );
			}

			// A rule forbidding hashing, or an account that may not ask by
			// hand, is a policy refusal, not a malformed request — 403 tells a
			// client that retrying will not help.
			return new DataResponse(
				$result,
				empty( $result['excluded'] ) && empty( $result['forbidden'] )
					? Http::STATUS_BAD_REQUEST
					: Http::STATUS_FORBIDDEN,
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS PublicApiController: recalcHash failed',
				[
					'app'       => Application::APP_ID,
					'fileId'    => $fileId,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Internal server error.',
				],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}

}
