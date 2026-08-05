<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Duplicate detection and hash lookup queries.
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class DuplicateService
{

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}


	/**
	 * Find all duplicate hash groups across the entire system.
	 *
	 * Groups files by (algo, hash_value) where more than one file shares
	 * the same hash.  Returns raw fileid lists — path resolution and
	 * access filtering are the caller's responsibility.
	 *
	 * @param  string|null  $algo      Optional algorithm filter
	 * @param  int          $minCount  Minimum files per group (default 2)
	 * @param  int          $limit     Max groups to return
	 * @param  int          $offset    Pagination offset
	 *
	 * @return array{algo: string, hash_value: string, file_count: int, fileids: int[]}[]
	 */
	public function findAllDuplicates(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = 50,
		int     $offset = 0,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select( 'algo', 'hash_value' )
		   ->selectAlias(
			   $qb->func()
			      ->count( 'fileid' ),
			   'cnt',
		   )
		   ->selectAlias(
			   $qb->func()
			      ->groupConcat( 'fileid' ),
			   'fileids',
		   )
		   ->from( TableNameService::TABLE_FILE_CHECKSUM_SEARCH_HASHES, 'h' )
		   ->groupBy( 'algo' )
		   ->addGroupBy( 'hash_value' )
		;

		if ( $algo !== null && $algo !== '' )
		{
			$qb->andWhere(
				$qb->expr()
				   ->eq( 'algo', $qb->createNamedParameter( $algo ) ),
			);
		}

		$qb->having(
			$qb->expr()
			   ->gte( 'cnt', $qb->createNamedParameter( $minCount, IQueryBuilder::PARAM_INT ) ),
		)
		   ->orderBy( 'cnt', 'DESC' )
		   ->setMaxResults( $limit )
		   ->setFirstResult( $offset )
		;

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		return array_map( function (
			array $row,
		): array {

			$fileidStr = (string) $row['fileids'];
			$fileids   = $fileidStr !== ''
				? array_map( 'intval', explode( ',', $fileidStr ) )
				: [];

			return [
				'algo'       => $row['algo'],
				'hash_value' => $row['hash_value'],
				'file_count' => (int) $row['cnt'],
				'fileids'    => $fileids,
			];
		}, $rows );
	}


	/**
	 * Batch-lookup filecache paths for a list of file IDs.
	 *
	 * Joins storages to resolve the storage ID for each file.
	 * When $userName is provided, only files from that user's home
	 * storage are returned (matched via storages.id = 'home::{uid}').
	 *
	 * @param  int[]        $fileIds
	 * @param  string|null  $userName
	 *
	 * @return array<int, array{path: string, name: string, storage_id: string, user: string}>
	 */
	public function batchLookupFilecachePaths(
		array   $fileIds,
		?string $userName = null,
	): array {

		if ( empty( $fileIds ) )
		{
			return [];
		}

		$qb = $this->db->getQueryBuilder();

		$qb->select( 'fc.fileid', 'fc.path', 'fc.name', 's.id' )
		   ->from( 'filecache', 'fc' )
		   ->innerJoin(
			   'fc',
			   'storages',
			   's',
			   'fc.storage = s.numeric_id',
		   )
		;

		if ( $userName !== null )
		{
			$qb->where(
				$qb->expr()
				   ->eq(
					   's.id',
					   $qb->createNamedParameter( 'home::' . $userName ),
				   ),
			)
			   ->andWhere(
				   $qb->expr()
				      ->in(
					      'fc.fileid',
					      $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
				      ),
			   )
			;
		}
		else
		{
			$qb->where(
				$qb->expr()
				   ->in(
					   'fc.fileid',
					   $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
				   ),
			);
		}

		$result = $qb->executeQuery();
		$paths  = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$sid  = (string) $row['id'];
			$user = $sid;

			if ( str_starts_with( $sid, 'home::' ) )
			{
				$user = substr( $sid, 6 );
			}
			elseif ( str_starts_with( $sid, 'local::' ) )
			{
				$user = basename( $sid );
			}

			$paths[ (int) $row['fileid'] ] = [
				'path'       => (string) $row['path'],
				'name'       => (string) $row['name'],
				'storage_id' => $sid,
				'user'       => $user,
			];
		}
		$result->closeCursor();

		return $paths;
	}


	/**
	 * Find hash rows matching a given hash value, with optional algo filter.
	 *
	 * Returns rows from file_checksum_search_hashes joined with filecache.
	 *
	 * @return array<int, array{fileid: int, algo: string, hash_value: string, path: string, name: string}>
	 */
	public function findByHash(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select( 'h.fileid', 'h.algo', 'h.hash_value', 'fc.path', 'fc.name' )
		   ->from( TableNameService::TABLE_FILE_CHECKSUM_SEARCH_HASHES, 'h' )
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

		$qb->setMaxResults( $limit );

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

}
