<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Central service for all oc_files_metadata + oc_files_metadata_index operations.
 *
 * Responsibilities:
 * - Key registration (initMetadata for all SUPPORTED_ALGOS + updated_at)
 * - Pending marking (meta_value_string = 'pending:{mode}')
 * - Pending batch fetching
 * - Hash lookup by value
 * - Duplicate detection (GROUP BY + INNER JOIN metadata)
 * - Staleness checks (getUpdatedAt)
 * - Seeding (INSERT...SELECT for unprocessed files)
 * - Pending stats (for :status command)
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class MetadataService
{

	public function __construct(
		private readonly IDBConnection         $db,
		private readonly IFilesMetadataManager $metadataManager,
		private readonly LoggerInterface       $logger,
	) {
	}


	/**
	 * Register all metadata keys on app boot.
	 *
	 * Idempotent — safe to call on every boot.
	 */
	public function register(): void
	{

		foreach ( HashIndexService::SUPPORTED_ALGOS as $algo )
		{
			$this->metadataManager->initMetadata(
				'file-checksum-' . $algo,
				IMetadataValueWrapper::TYPE_STRING,
				true,
				IMetadataValueWrapper::EDIT_FORBIDDEN,
			);
		}

		$this->metadataManager->initMetadata(
			'file-checksum-updated_at',
			IMetadataValueWrapper::TYPE_INT,
			true,
			IMetadataValueWrapper::EDIT_FORBIDDEN,
		);

		$this->logger->debug(
			'FCIAS MetadataService: registered metadata keys',
			[ 'app' => Application::APP_ID ],
		);
	}


	/**
	 * Mark a file as pending for a specific processing mode.
	 *
	 * Updates meta_value_string on the file-checksum-updated_at index row.
	 * Does NOT create the row if it doesn't exist (seeding handles that).
	 */
	public function markPending(
		int    $fileId,
		string $mode,
	): void {

		$qb = $this->db->getQueryBuilder();
		$qb->update( 'files_metadata_index' )
		   ->set( 'meta_value_string', $qb->createNamedParameter( $mode ) )
		   ->where(
			   $qb->expr()
			      ->eq( 'file_id', $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->eq( 'meta_key', $qb->createNamedParameter( 'file-checksum-updated_at' ) ),
		   )
		;

		$updated = $qb->executeStatement();

		$this->logger->debug(
			'FCIAS MetadataService: markPending',
			[
				'app'     => Application::APP_ID,
				'fileId'  => $fileId,
				'mode'    => $mode,
				'updated' => $updated,
			],
		);
	}


	/**
	 * Fetch a batch of pending rows ordered by file_id.
	 *
	 * @return array<int, array{file_id: int, meta_value_string: string}>
	 */
	public function fetchPendingBatch( int $limit = 50 ): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'file_id', 'meta_value_string' )
		   ->from( 'files_metadata_index' )
		   ->where(
			   $qb->expr()
			      ->eq( 'meta_key', $qb->createNamedParameter( 'file-checksum-updated_at' ) ),
			   $qb->expr()
			      ->like(
				      'meta_value_string',
				      $qb->createNamedParameter( 'pending:%' ),
			      ),
		   )
		   ->orderBy( 'file_id', 'ASC' )
		   ->setMaxResults( $limit )
		;

		$result = $qb->executeQuery();
		$rows   = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$rows[] = [
				'file_id'           => (int) $row['file_id'],
				'meta_value_string' => (string) $row['meta_value_string'],
			];
		}
		$result->closeCursor();

		return $rows;
	}


	/**
	 * Get pending statistics grouped by meta_value_string.
	 *
	 * @return array<string, int>
	 */
	public function getPendingStats(): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'meta_value_string' )
		   ->selectAlias(
			   $qb->func()
			      ->count( 'file_id' ),
			   'cnt',
		   )
		   ->from( 'files_metadata_index' )
		   ->where(
			   $qb->expr()
			      ->eq( 'meta_key', $qb->createNamedParameter( 'file-checksum-updated_at' ) ),
			   $qb->expr()
			      ->like(
				      'meta_value_string',
				      $qb->createNamedParameter( 'pending:%' ),
			      ),
		   )
		   ->groupBy( 'meta_value_string' )
		;

		$result = $qb->executeQuery();
		$stats  = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$stats[ (string) $row['meta_value_string'] ] = (int) $row['cnt'];
		}
		$result->closeCursor();

		return $stats;
	}


	/**
	 * Find files matching a given hex hash value.
	 *
	 * Searches across all file-checksum-* keys.
	 *
	 * @return array<int, array{file_id: int}>
	 */
	public function queryByHash(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
	): array {

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'file_id' )
		   ->from( 'files_metadata_index' )
		   ->where(
			   $qb->expr()
			      ->eq( 'meta_value_string', $qb->createNamedParameter( $hash ) ),
		   )
		   ->setMaxResults( $limit )
		;

		if ( $algo !== null && $algo !== '' )
		{
			$qb->andWhere(
				$qb->expr()
				   ->eq( 'meta_key', $qb->createNamedParameter( 'file-checksum-' . $algo ) ),
			);
		}
		else
		{
			$qb->andWhere(
				$qb->expr()
				   ->like(
					   'meta_key',
					   $qb->createNamedParameter( 'file-checksum-%' ),
				   ),
			);
		}

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}


	/**
	 * Find duplicate hash groups across all files.
	 *
	 * INNER JOINs oc_files_metadata to access full JSON for verification
	 * of long hashes (SHA-512/SHA3-512 truncated in index).
	 *
	 * @return array<int, array{meta_key: string, meta_value_string: string, file_count: int, file_ids: int[]}>
	 */
	public function queryDuplicates(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = 50,
		int     $offset = 0,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select( 'i.meta_key', 'i.meta_value_string' )
		   ->selectAlias(
			   $qb->func()
			      ->count( 'i.file_id' ),
			   'cnt',
		   )
		   ->selectAlias(
			   $qb->func()
			      ->groupConcat( 'i.file_id' ),
			   'file_ids',
		   )
		   ->from( 'files_metadata_index', 'i' )
		   ->innerJoin(
			   'i',
			   'files_metadata',
			   'm',
			   'i.file_id = m.file_id',
		   )
		   ->where(
			   $qb->expr()
			      ->like(
				      'i.meta_key',
				      $qb->createNamedParameter( 'file-checksum-%' ),
			      ),
		   )
		   ->groupBy( 'i.meta_value_string' )
		   ->addGroupBy( 'i.meta_key' )
		;

		if ( $algo !== null && $algo !== '' )
		{
			$qb->andWhere(
				$qb->expr()
				   ->eq( 'i.meta_key', $qb->createNamedParameter( 'file-checksum-' . $algo ) ),
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

			$fileIdStr = (string) $row['file_ids'];
			$fileIds   = $fileIdStr !== ''
				? array_map( 'intval', explode( ',', $fileIdStr ) )
				: [];

			return [
				'meta_key'          => $row['meta_key'],
				'meta_value_string' => $row['meta_value_string'],
				'file_count'        => (int) $row['cnt'],
				'file_ids'          => $fileIds,
			];
		}, $rows );
	}


	/**
	 * Count metadata index entries for a given file_id.
	 */
	public function countByFileId( int $fileId ): int
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select(
			$qb->func()
			   ->count( '*', 'cnt' ),
		)
		   ->from( 'files_metadata_index' )
		   ->where(
			   $qb->expr()
			      ->eq( 'file_id', $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->like(
				      'meta_key',
				      $qb->createNamedParameter( 'file-checksum-%' ),
			      ),
		   )
		;

		return (int) $qb->executeQuery()
		                ->fetchOne()
		;
	}


	/**
	 * Get the updated_at timestamp for a file from the metadata index.
	 *
	 * @return int|null Unix timestamp or null if not set
	 */
	public function getUpdatedAt( int $fileId ): ?int
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'meta_value_int' )
		   ->from( 'files_metadata_index' )
		   ->where(
			   $qb->expr()
			      ->eq( 'file_id', $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->eq( 'meta_key', $qb->createNamedParameter( 'file-checksum-updated_at' ) ),
		   )
		;

		$result = $qb->executeQuery()
		             ->fetchOne()
		;

		return $result !== false && $result !== null
			? (int) $result
			: null;
	}


	/**
	 * Seed file-checksum-updated_at index entries for files that don't have one.
	 *
	 * @return int Number of inserted rows
	 */
	public function seedIndex(): int
	{

		try
		{
			$inserted = $this->db->executeStatement(
				<<<SQL
INSERT INTO `*PREFIX*files_metadata_index` (`file_id`, `meta_key`, `meta_value_string`, `meta_value_int`)
SELECT `fc`.`fileid`, 'file-checksum-updated_at', 'pending:new', 0
FROM `*PREFIX*filecache` `fc`
WHERE `fc`.`fileid` NOT IN (
    SELECT `file_id` FROM `*PREFIX*files_metadata_index`
    WHERE `meta_key` = 'file-checksum-updated_at'
)
SQL,
			);

			$this->logger->info(
				'FCIAS MetadataService: seedIndex completed',
				[
					'app'      => Application::APP_ID,
					'inserted' => $inserted,
				],
			);

			return $inserted;
		}
		catch ( \Throwable $e )
		{
			$this->logger->error(
				'FCIAS MetadataService: seedIndex failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return 0;
		}
	}

}
