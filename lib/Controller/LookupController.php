<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\Service\HashIndexService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use PDO;

class LookupController
	extends
	ApiController
{

	private IDBConnection    $db;

	private HashIndexService $hashIndexService;


	public function __construct(
		string           $appName,
		IRequest         $request,
		IDBConnection    $db,
		HashIndexService $hashIndexService,
	) {

		parent::__construct( $appName, $request );
		$this->db               = $db;
		$this->hashIndexService = $hashIndexService;
	}


	/**
	 * Look up files by hash value.
	 *
	 * @param  string       $hash  The hash value (hex string, 32/40/64 chars)
	 * @param  string|null  $algo  Optional algorithm filter (e.g. 'sha1', 'sha256')
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function byHash(
		string  $hash,
		?string $algo = null,
	): DataResponse {

		$hash = trim( $hash );

		if ( $hash === '' )
		{
			return new DataResponse( [ 'error' => 'Hash parameter is required.' ], Http::STATUS_BAD_REQUEST );
		}

		$qb = $this->db->getQueryBuilder();

		$qb->select( 'h.fileid', 'h.algo', 'h.hash_value', 'fc.path', 'fc.name' )
		   ->from( 'file_checksum_search_hashes', 'h' )
		   ->innerJoin( 'h', 'filecache', 'fc', 'h.fileid = fc.fileid' )
		   ->where(
			   $qb->expr()
			      ->eq( 'h.hash_value', $qb->createNamedParameter( $hash ) ),
		   )
		;

		if ( $algo !== null && $algo !== '' )
		{
			$qb->andWhere(
				$qb->expr()
				   ->eq( 'h.algo', $qb->createNamedParameter( $algo ) ),
			);
		}

		$qb->setMaxResults( 100 );

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

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
	 * Get all checksums for a given file ID.
	 *
	 * @param  int  $fileId  The filecache fileid
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getHashesByFileId( int $fileId ): DataResponse
	{

		$qb = $this->db->getQueryBuilder();

		$qb->select( 'fileid', 'algo', 'hash_value' )
		   ->from( 'file_checksum_search_hashes' )
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
				'algo' => $row['algo'],
				'hash' => $row['hash_value'],
			];
		}, $rows );

		return new DataResponse(
			[
				'hashes' => $hashes,
				'fileid' => $fileId,
			],
		);
	}


	#[NoAdminRequired]
	public function recalcHash(
		int    $fileId,
		string $algo = 'sha1',
	): DataResponse {

		$result = $this->hashIndexService->recalcHash( $fileId, $algo );

		if ( $result['success'] )
		{
			return new DataResponse( $result );
		}

		return new DataResponse( $result, Http::STATUS_BAD_REQUEST );
	}

}
