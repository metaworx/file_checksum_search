<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use InvalidArgumentException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Service\HashIndexService;
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
 * Legacy REST API controller (deprecated).
 *
 * All methods now delegate to ChecksumApi — the single source of truth.
 * Routes are kept for backward compatibility with external consumers
 * that may still call /api/1.0/ endpoints.
 *
 * New integrations should use the v1 /api/v1/ routes via PublicApiController.
 */
class LookupController
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
	 * Look up files by hash value, with optional algorithm filter.
	 *
	 * @param  string       $hash  The hash value (hex string, 32/40/64 chars)
	 * @param  string|null  $algo  Optional algorithm filter (e.g. 'sha1', 'sha256')
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/1.0/lookup/{hash}')]
	public function byHash(
		string  $hash,
		?string $algo = null,
	): DataResponse {

		$this->logger->debug(
			'FCIAS LookupController: byHash called',
			[
				'app'  => Application::APP_ID,
				'algo' => $algo,
			],
		);

		try
		{
			$result = $this->api->findByHash( $hash, $algo );

			return new DataResponse( $result );
		}
		catch ( InvalidArgumentException $e )
		{
			return new DataResponse( [ 'error' => $e->getMessage() ], Http::STATUS_BAD_REQUEST );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS LookupController: byHash failed',
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
	 * Get all checksums for a given filecache file ID.
	 *
	 * @param  int  $fileId  The filecache fileid
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/1.0/file/{fileId}/hashes')]
	public function getHashesByFileId( int $fileId ): DataResponse
	{

		$this->logger->debug(
			'FCIAS LookupController: getHashesByFileId called',
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
				'FCIAS LookupController: getHashesByFileId failed',
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
	 * Find other files sharing the same hash values as a given file.
	 *
	 * @param  int  $fileId  The filecache fileid
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/1.0/file/{fileId}/duplicates')]
	public function sameHash( int $fileId ): DataResponse
	{

		$this->logger->debug(
			'FCIAS LookupController: sameHash called',
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
				'FCIAS LookupController: sameHash failed',
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
	 * Recalculate a hash for a given file ID and algorithm.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/1.0/file/{fileId}/recalc')]
	public function recalcHash(
		int     $fileId,
		?string $algo = null,
	): DataResponse {

		$algo ??= HashIndexService::getDefaultAlgo();

		$this->logger->debug(
			'FCIAS LookupController: recalcHash called',
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
				'FCIAS LookupController: recalcHash failed',
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
