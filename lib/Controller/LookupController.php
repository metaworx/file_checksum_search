<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PDO;
use Psr\Log\LoggerInterface;

class LookupController
	extends
	ApiController
{

	public function __construct(
		string                            $appName,
		IRequest                          $request,
		private readonly IDBConnection    $db,
		private readonly HashIndexService $hashIndexService,
		private readonly IRootFolder      $rootFolder,
		private readonly IUserSession     $userSession,
		private readonly LoggerInterface  $logger,
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
	public function byHash(
		string  $hash,
		?string $algo = null,
	): DataResponse {

		$hash = trim( $hash );

		$this->logger->debug(
			'FCIAS LookupController: byHash called',
			[
				'app'  => Application::APP_ID,
				'algo' => $algo,
			],
		);

		if ( $hash === '' )
		{
			return new DataResponse( [ 'error' => 'Hash parameter is required.' ], Http::STATUS_BAD_REQUEST );
		}

		$rows = $this->hashIndexService->findByHash( $hash, $algo, 100 );

		$results = array_map( function (
			array $row,
		): array {

			return [
				'fileid' => (int) $row['fileid'],
				'algo'   => $row['algo'],
				'hash'   => $row['hash_value'],
				'path'   => $row['path'],
				'name'   => $row['name'],
			];
		}, $rows );

		return new DataResponse( [ 'results' => $results ] );
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
	public function getHashesByFileId( int $fileId ): DataResponse
	{

		$this->logger->debug(
			'FCIAS LookupController: getHashesByFileId called',
			[
				'app'    => Application::APP_ID,
				'fileId' => $fileId,
			],
		);

		$qb = $this->db->getQueryBuilder();

		$qb->select( 'fileid', 'algo', 'hash_value', 'updated_at' )
		   ->from( TableNameService::TABLE_FILE_CHECKSUM_SEARCH_HASHES )
		   ->where(
			   $qb->expr()
			      ->eq( 'fileid', $qb->createNamedParameter( $fileId, PDO::PARAM_INT ) ),
		   )
		   ->orderBy( 'algo' )
		;

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		$hashes = array_map( function (
			array $row,
		): array {

			return [
				'algo'       => $row['algo'],
				'hash'       => $row['hash_value'],
				'updated_at' => $row['updated_at'] ?? null,
			];
		}, $rows );

		return new DataResponse(
			[
				'hashes' => $hashes,
				'fileid' => $fileId,
			],
		);
	}


	/**
	 * Find other files sharing the same hash values as a given file.
	 *
	 * @param  int  $fileId  The filecache fileid
	 */
	/** Find other files sharing the same hash values as the given file. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	/** @noinspection PhpUnused */
	public function sameHash( int $fileId ): DataResponse
	{

		$this->logger->debug(
			'FCIAS LookupController: sameHash called',
			[
				'app'    => Application::APP_ID,
				'fileId' => $fileId,
			],
		);

		$table = TableNameService::TABLE_FILE_CHECKSUM_SEARCH_HASHES;
		$qb    = $this->db->getQueryBuilder();

		$rows = $qb->select( 'h2.algo', 'h2.hash_value', 'h2.fileid' )
		           ->from( $table, 'h1' )
		           ->innerJoin(
			           'h1',
			           $table,
			           'h2',
			           'h1.hash_value = h2.hash_value AND h1.algo = h2.algo AND h1.fileid <> h2.fileid',
		           )
		           ->where(
			           $qb->expr()
			              ->eq( 'h1.fileid', $qb->createNamedParameter( $fileId, PDO::PARAM_INT ) ),
		           )
		           ->orderBy( 'h2.algo' )
		           ->addOrderBy( 'h2.hash_value' )
		           ->setMaxResults( 100 )
		           ->executeQuery()
		           ->fetchAll()
		;

		if ( empty( $rows ) )
		{
			return new DataResponse( [ 'duplicates' => [] ] );
		}

		$user       = $this->userSession->getUser();
		$userFolder = $user !== null
			? $this->rootFolder->getUserFolder( $user->getUID() )
			: null;

		$grouped = [];

		foreach ( $rows as $row )
		{
			$dupFileId = (int) $row['fileid'];

			// Resolve via filesystem: path + access check
			$resolvedPath = '';
			$resolvedName = '';

			if ( $userFolder !== null )
			{
				$nodes = $userFolder->getById( $dupFileId );

				if ( empty( $nodes ) )
				{
					continue;
				}

				$node     = $nodes[0];
				$relative = $userFolder->getRelativePath( $node->getPath() );

				if ( $relative === null )
				{
					continue;
				}

				$resolvedPath = $relative;
				$resolvedName = $node->getName();
			}

			$key = $row['algo'] . "\0" . $row['hash_value'];

			$grouped[ $key ] ??= [
				'algo'       => $row['algo'],
				'hash_value' => $row['hash_value'],
				'files'      => [],
			];

			$grouped[ $key ]['files'][] = [
				'fileid' => $dupFileId,
				'path'   => $resolvedPath,
				'name'   => $resolvedName,
			];
		}

		return new DataResponse( [ 'duplicates' => array_values( $grouped ) ] );
	}


	/** Recalculate a hash for a given file ID and algorithm. */
	#[NoAdminRequired]
	/** @noinspection PhpUnused */
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

		$result = $this->hashIndexService->recalcHash( $fileId, $algo );

		if ( $result['success'] )
		{
			return new DataResponse( $result );
		}

		return new DataResponse( $result, Http::STATUS_BAD_REQUEST );
	}

}
