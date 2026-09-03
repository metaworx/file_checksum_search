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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public REST API v1 controller.
 *
 * Thin HTTP adapter over ChecksumApi. All endpoints are public
 * (NoAdminRequired) and CSRF-exempt (NoCSRFRequired) for API access.
 * Non-admin callers are scoped to their own files — see
 * {@see resolveRequestingUserScope()}.
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
	) {

		parent::__construct( $appName, $request );
	}


	/**
	 * Resolve the ownership scope to enforce for the current request.
	 *
	 * - `false`  : no authenticated user (should not normally be reachable,
	 *              since every route below requires a logged-in user).
	 * - `null`   : an admin is calling — unrestricted, system-wide access.
	 * - `string` : a regular user's UID — results/actions must be
	 *              restricted to files that UID can access.
	 */
	private function resolveRequestingUserScope(): string|false|null
	{

		$user = $this->userSession->getUser();

		if ( $user === null )
		{
			return false;
		}

		return $this->groupManager->isAdmin( $user->getUID() )
			? null
			: $user->getUID();
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

		$this->logger->debug(
			'FCIAS PublicApiController: getHashes called',
			[
				'app'    => Application::APP_ID,
				'fileId' => $fileId,
			],
		);

		$scope = $this->resolveRequestingUserScope();

		if ( $scope === false )
		{
			return new DataResponse( [ 'error' => 'Not authenticated.' ], Http::STATUS_UNAUTHORIZED );
		}

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
			$result = $this->api->findDuplicates( $algo, $minCount, $limit, $offset );

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
	#[ApiRoute( verb: 'GET', url: '/api/v1/file/{fileId}/duplicates' )]
	public function findDuplicates( int $fileId ): DataResponse
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
			$result = $this->api->findSameHash( $fileId );

			return new DataResponse( $result );
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
	): DataResponse {

		$this->logger->debug(
			'FCIAS PublicApiController: lookup called',
			[
				'app'  => Application::APP_ID,
				'algo' => $algo,
			],
		);

		$scope = $this->resolveRequestingUserScope();

		if ( $scope === false )
		{
			return new DataResponse( [ 'error' => 'Not authenticated.' ], Http::STATUS_UNAUTHORIZED );
		}

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

		$scope = $this->resolveRequestingUserScope();

		if ( $scope === false )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Not authenticated.',
				],
				Http::STATUS_UNAUTHORIZED,
			);
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
