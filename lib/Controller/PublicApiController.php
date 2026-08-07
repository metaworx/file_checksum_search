<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public REST API v1 controller.
 *
 * Thin HTTP adapter over ChecksumApi. All endpoints are public
 * (NoAdminRequired) and CSRF-exempt (NoCSRFRequired) for API access.
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
		private readonly LoggerInterface $logger,
	) {

		parent::__construct( $appName, $request );
	}


	/**
	 * Get all checksums for a file by filecache ID.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/file/{fileId}/hashes')]
	public function getHashes( int $fileId ): DataResponse
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
			$result = $this->api->getHashesByFileId( $fileId );

			return new DataResponse( $result );
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
	#[ApiRoute(verb: 'GET', url: '/api/v1/status')]
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
	 * Find all duplicate groups across the system.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/duplicates')]
	public function findAllDuplicates(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = 50,
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
	#[ApiRoute(verb: 'GET', url: '/api/v1/file/{fileId}/duplicates')]
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
	#[ApiRoute(verb: 'GET', url: '/api/v1/lookup')]
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

		try
		{
			$result = $this->api->findByHash( $hash, $algo, $limit );

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
	#[ApiRoute(verb: 'POST', url: '/api/v1/file/{fileId}/recalc')]
	public function recalcHash( int $fileId ): DataResponse
	{

		$body = json_decode( file_get_contents( 'php://input' ), true );
		$algo = is_array( $body )
			? ( $body['algo'] ?? null )
			: null;

		$this->logger->debug(
			'FCIAS PublicApiController: recalcHash called',
			[
				'app'    => Application::APP_ID,
				'fileId' => $fileId,
				'algo'   => $algo,
			],
		);

		try
		{
			$result = $this->api->recalcHash( $fileId, $algo );

			if ( $result['success'] )
			{
				return new DataResponse( $result );
			}

			return new DataResponse( $result, Http::STATUS_BAD_REQUEST );
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
