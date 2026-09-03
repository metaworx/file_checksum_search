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
	 * @param  string|null  $target  One account, or null for every account.
	 *
	 * @return string|null|DataResponse  The scope to pass down — null means
	 *                                   every account — or the refusal.
	 */
	private function sudoScopeOrRefusal( ?string $target = null ): string|null|DataResponse
	{

		$own = $this->scopeOrRefusal();

		if ( $own instanceof DataResponse )
		{
			return $own;
		}

		$scope = $this->sudo->resolve( $own, $target );

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
	#[ApiRoute( verb: 'GET', url: '/api/v1/file/{fileId}/hashes' )]
	public function getHashes( int $fileId ): DataResponse
	{

		$scope = $this->scopeOrRefusal();

		return $scope instanceof DataResponse
			? $scope
			: $this->hashesFor( $fileId, $scope );
	}


	/**
	 * {@see getHashes()} for a file that need not be the caller's own. A
	 * password confirmation, and only for those who may look across accounts.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/api/v1/sudo/file/{fileId}/hashes' )]
	public function sudoGetHashes( int $fileId ): DataResponse
	{

		$scope = $this->sudoScopeOrRefusal(  );

		return $scope instanceof DataResponse
			? $scope
			: $this->hashesFor( $fileId, $scope );
	}


	/**
	 * The route's body, for either wrapper: $scope is whose files may be
	 * read — a uid, or null for every account.
	 */
	private function hashesFor( int $fileId, ?string $scope ): DataResponse
	{

		$this->logger->debug(
			'FCIAS PublicApiController: getHashes called',
			[
				'app'    => Application::APP_ID,
				'fileId' => $fileId,
			],
		);

		try
		{
			$result = $this->api->getHashesByFileId( $fileId, $scope );

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
			$result = $this->api->getStatus();

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
	): DataResponse {

		$scope = $this->scopeOrRefusal();

		if ( $scope instanceof DataResponse )
		{
			return $scope;
		}

		$response = $this->duplicatesFor( $scope, $algo, $minCount, $limit, $offset );

		// Whether the caller may switch to the instance-wide view. Rides on
		// the listing the page loads anyway, so the page needs no second
		// request to know whether to offer the switch — and a script gets
		// the same fact for free.
		if ( $response->getStatus() === Http::STATUS_OK )
		{
			$response->setData( $response->getData() + [ 'canSudo' => $this->sudo->isSudoer( $scope ) ] );
		}

		return $response;
	}


	/**
	 * {@see findAllDuplicates()} for one named account, or for every account
	 * when `user` is omitted. A password confirmation, and only for those who
	 * may look across accounts — a sub-admin may name a member of their
	 * groups and nothing wider.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/api/v1/sudo/duplicates' )]
	public function sudoFindAllDuplicates(
		?string $user = null,
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
	): DataResponse {

		$scope = $this->sudoScopeOrRefusal( $user );

		return $scope instanceof DataResponse
			? $scope
			: $this->duplicatesFor( $scope, $algo, $minCount, $limit, $offset );
	}


	/**
	 * The listing's body, for either wrapper: $scope is whose files — a
	 * uid, or null for every account.
	 */
	private function duplicatesFor(
		?string $scope,
		?string $algo,
		int     $minCount,
		int     $limit,
		int     $offset,
	): DataResponse {

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
			$result = $this->api->findDuplicatesFor( $scope, $algo, $minCount, $limit, $offset );

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
	#[ApiRoute( verb: 'GET', url: '/api/v1/file/{fileId}/duplicates' )]
	public function findDuplicates( int $fileId ): DataResponse
	{

		$scope = $this->scopeOrRefusal();

		return $scope instanceof DataResponse
			? $scope
			: $this->sameHashFor( $fileId, $scope );
	}


	/**
	 * {@see findDuplicates()} for a file that need not be the caller's own,
	 * with the duplicates drawn from every account. A password confirmation,
	 * and only for those who may look across accounts.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/api/v1/sudo/file/{fileId}/duplicates' )]
	public function sudoFindDuplicates( int $fileId ): DataResponse
	{

		$scope = $this->sudoScopeOrRefusal(  );

		return $scope instanceof DataResponse
			? $scope
			: $this->sameHashFor( $fileId, $scope );
	}


	/**
	 * The route's body, for either wrapper: $scope is whose files may be
	 * read — a uid, or null for every account.
	 */
	private function sameHashFor( int $fileId, ?string $scope ): DataResponse
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
			$result = $this->api->findSameHash( $fileId, $scope );

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
	 * read — a uid, or null for every account.
	 */
	private function lookupFor( string $hash, ?string $algo, int $limit, ?string $scope ): DataResponse
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
			$result = $this->api->findByHash( $hash, $algo, $limit, $scope );

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
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit( limit: 20, period: 60 )]
	#[ApiRoute( verb: 'POST', url: '/api/v1/file/{fileId}/recalc' )]
	public function recalcHash( int $fileId ): DataResponse
	{

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
				'app'    => Application::APP_ID,
				'fileId' => $fileId,
				'algo'   => $algo,
			],
		);

		$scope = $this->scopeOrRefusal();

		if ( $scope instanceof DataResponse )
		{
			return $scope;
		}

		try
		{
			$result = $this->api->recalcHash( $fileId, $algo, $scope );

			if ( $result['success'] )
			{
				return new DataResponse( $result );
			}

			// A rule forbidding hashing is a policy refusal, not a malformed
			// request — 403 tells a client that retrying will not help.
			return new DataResponse(
				$result,
				empty( $result['excluded'] )
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
