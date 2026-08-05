<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Manages the pending hash update queue table.
 *
 * Files are queued when immediate recalculation is not possible
 * (e.g. lazy mode, or file locked at write time). The
 * DrainPendingUpdates background job consumes this queue.
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class PendingQueueService
{

	public function __construct(
		private readonly IDBConnection    $db,
		private readonly TableNameService $tables,
		private readonly LoggerInterface  $logger,
	) {
	}


	/**
	 * Queue a file for deferred hash recalculation.
	 *
	 * INSERT IGNORE ensures duplicate events for the same fileid
	 * within the drain interval are silently dropped (debounce).
	 */
	public function addPending(
		int    $fileId,
		string $eventType,
	): void {

		$qb = $this->db->getQueryBuilder();

		$qb->insert( $this->tables->getPendingTableName() )
		   ->values( [
			   'fileid'     => $qb->createNamedParameter( $fileId, PDO::PARAM_INT ),
			   'created_at' => $qb->createNamedParameter( time(), PDO::PARAM_INT ),
			   'event_type' => $qb->createNamedParameter( $eventType ),
		   ] )
		;

		// INSERT IGNORE via raw executeStatement — IQueryBuilder has no native IGNORE support
		$this->db->executeStatement(
			str_replace( 'INSERT INTO ', 'INSERT IGNORE INTO ', $qb->getSQL() ),
			$qb->getParameters(),
		);
	}


	/**
	 * Fetch pending rows ordered by creation time.
	 *
	 * @return array<int, array{fileid: int, event_type: string}>
	 */
	public function fetchPending( int $limit = 50 ): array
	{

		$selectQb = $this->db->getQueryBuilder();
		$selectQb->select( 'fileid', 'event_type' )
		         ->from( TableNameService::TABLE_FILE_CHECKSUM_SEARCH_PENDING )
		         ->orderBy( 'created_at', 'ASC' )
		         ->setMaxResults( $limit )
		;

		$result = $selectQb->executeQuery();
		$rows   = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$rows[] = [
				'fileid'     => (int) $row['fileid'],
				'event_type' => (string) $row['event_type'],
			];
		}
		$result->closeCursor();

		return $rows;
	}


	/**
	 * Delete successfully processed rows from the queue.
	 *
	 * @param  int[]  $fileIds
	 *
	 * @return int Number of deleted rows
	 */
	public function deletePending( array $fileIds ): int
	{

		if ( empty( $fileIds ) )
		{
			return 0;
		}

		$deleteQb = $this->db->getQueryBuilder();
		$deleteQb->delete( TableNameService::TABLE_FILE_CHECKSUM_SEARCH_PENDING )
		         ->where(
			         $deleteQb->expr()
			                  ->in(
				                  'fileid',
				                  $deleteQb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
			                  ),
		         )
		;

		return $deleteQb->executeStatement();
	}


	/**
	 * Count pending rows in the queue table.
	 */
	public function getPendingRowCount(): int
	{

		try
		{
			$qb = $this->db->getQueryBuilder();

			$qb->select(
				$qb->func()
				   ->count( '*', 'cnt' ),
			)
			   ->from( $this->tables->getPendingTableName() )
			;

			return (int) $qb->executeQuery()
			                ->fetchOne()
			;
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS: pending row count query failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return 0;
		}
	}

}
