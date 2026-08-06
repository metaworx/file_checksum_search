<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OC\FilesMetadata\Model\FilesMetadata;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\Exceptions\FilesMetadataTypeException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

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
 */
class MetadataService
{

// constants
	public const FIELD_FILE_ID                = 'file_id';
	public const FIELD_JSON                   = 'json';
	public const FIELD_JSON_ALIAS             = 'meta_json';
	public const FIELD_META_KEY               = 'meta_key';
	public const FIELD_META_VALUE_INT         = 'meta_value_int';
	public const FIELD_META_VALUE_STRING      = 'meta_value_string';
	public const KEY_FILE_CHECKSUM_LIKE       = self::KEY_FILE_CHECKSUM_PREFIX . '%';
	public const KEY_FILE_CHECKSUM_PREFIX     = 'file-checksum-';
	public const KEY_FILE_CHECKSUM_UPDATED_AT = 'file-checksum-updated_at';
	public const PENDING_LIKE                 = self::PENDING_PREFIX . '%';
	public const PENDING_PREFIX               = 'pending:';
	public const TABLE_FILES_METADATA         = 'files_metadata';
	public const TABLE_FILES_METADATA_INDEX   = 'files_metadata_index';


	public function __construct(
		private readonly IDBConnection         $db,
		private readonly IFilesMetadataManager $metadataManager,
		private readonly FilecacheService      $filecacheService,
		private readonly LoggerInterface       $logger,
	) {
	}


	public function &getHashes( int|File|IFilesMetadata $fileOrMetadata ): array
	{

		if ( ! $fileOrMetadata instanceof IFilesMetadata )
		{
			$fileOrMetadata = $this->getMetadata( $fileOrMetadata );
		}

		$hashes = [];

		foreach ( HashIndexService::SUPPORTED_ALGOS as $algo )
		{
			$key = self::getHashKey( $algo );
			try
			{
				$hashes[ $algo ] = $fileOrMetadata->getString( $key );
			}
			catch ( FilesMetadataNotFoundException|FilesMetadataTypeException )
			{
			}
		}

		return $hashes;
	}


	/**
	 * Get metadata for a file. Creates empty metadata if it does not exist.
	 */
	public function getMetadata(
		int|File          $file,
		string|array|null $rawMetadata = null,
	): IFilesMetadata {

		if ( $rawMetadata !== null )
		{
			if ( is_array( $rawMetadata ) && array_key_exists( MetadataService::FIELD_JSON_ALIAS, $rawMetadata ) )
			{
				$rawMetadata = (string) ( $rawMetadata[ MetadataService::FIELD_JSON_ALIAS ] );
			}

			if ( ! is_array( $rawMetadata ) )
			{
				$rawMetadata = $rawMetadata !== ''
					? json_decode( $rawMetadata, true )
					: [];
			}

			$metadata = new FilesMetadata( FilecacheService::getFileId( $file ) );
			$metadata->import( $rawMetadata );

			return $metadata;
		}

		/** @noinspection PhpUnhandledExceptionInspection */
		return $this->metadataManager->getMetadata( FilecacheService::getFileId( $file ), true );
	}


	/**
	 * Get pending statistics grouped by meta_value_string.
	 *
	 * @return array<string, int>
	 */
	public function getPendingStats(): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select( self::FIELD_META_VALUE_STRING )
		   ->selectAlias(
			   $qb->func()
			      ->count( self::FIELD_FILE_ID ),
			   'cnt',
		   )
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ) ),
			   $qb->expr()
			      ->like(
				      self::FIELD_META_VALUE_STRING,
				      $qb->createNamedParameter( self::PENDING_LIKE ),
			      ),
		   )
		   ->groupBy( self::FIELD_META_VALUE_STRING )
		;

		$result = $this->executeQuery( $qb );
		$stats  = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$stats[ (string) $row[ self::FIELD_META_VALUE_STRING ] ] = (int) $row['cnt'];
		}
		$result->closeCursor();

		return $stats;
	}


	/**
	 * Get the updated_at timestamp for a file from the metadata index.
	 *
	 * @return int|null Unix timestamp or null if not set
	 */
	public function getUpdatedAt( int|File|IFilesMetadata $fileOrMetadata ): ?int
	{

		if ( $fileOrMetadata instanceof IFilesMetadata )
		{
			try
			{
				return $fileOrMetadata->getInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT );
			}
			catch ( FilesMetadataNotFoundException|FilesMetadataTypeException )
			{
			}

			$fileOrMetadata = $fileOrMetadata->getFileId();
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select( self::FIELD_META_VALUE_INT )
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq(
				      self::FIELD_FILE_ID,
				      $qb->createNamedParameter(
					      FilecacheService::getFileId( $fileOrMetadata ),
					      IQueryBuilder::PARAM_INT,
				      ),
			      ),
			   $qb->expr()
			      ->eq( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ) ),
		   )
		;

		$result = $this->executeQuery( $qb )
		               ->fetchOne()
		;

		return $result !== false && $result !== null
			? (int) $result
			: null;
	}


	public function clearMetadata(
		int|File|IFilesMetadata $fileOrMetadata,
		bool                    $save = true,
	): void {

		if ( $fileOrMetadata instanceof IFilesMetadata )
		{
			$metadata = $fileOrMetadata;
		}
		else
		{
			$metadata = $this->getMetadata( $fileOrMetadata );
			$save     = true;
		}

		$metadata->removeStartsWith( self::KEY_FILE_CHECKSUM_PREFIX );
		$metadata->setInt( self::KEY_FILE_CHECKSUM_UPDATED_AT, 0 );

		if ( $save )
		{
			$this->metadataManager->saveMetadata( $metadata );
		}
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
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_FILE_ID, $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->like(
				      self::FIELD_META_KEY,
				      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_LIKE ),
			      ),
			   $qb->expr()
			      ->neq(
				      self::FIELD_META_KEY,
				      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
			      ),
		   )
		;

		return (int) $this->executeQuery( $qb )
		                  ->fetchOne()
		;
	}


	/**
	 * Ensure a metadata reference is set for a file. If $metadata is null,
	 * loads or creates it. Returns true if newly created (caller must save).
	 *
	 * @param  int|File             $file
	 * @param  IFilesMetadata|null  $metadata  Reference that will be set
	 *
	 * @return bool True if metadata was newly created and caller is responsible for saving
	 */
	public function ensureMetadata(
		int|File        $file,
		?IFilesMetadata &$metadata,
	): bool {

		if ( $metadata !== null )
		{
			return false;
		}

		$metadata = $this->getMetadata( $file );

		return true;
	}


	/**
	 * @param  \OCP\DB\QueryBuilder\IQueryBuilder  $qb
	 *
	 * @return \OCP\DB\IResult
	 * @throws \OCP\DB\Exception
	 */
	private function executeQuery( IQueryBuilder $qb ): IResult
	{

		return $qb->executeQuery();
	}


	/**
	 * @param  \OCP\DB\QueryBuilder\IQueryBuilder  $qb
	 *
	 * @return int
	 * @throws \OCP\DB\Exception
	 */
	private function executeStatement( IQueryBuilder $qb ): int
	{

		return $qb->executeStatement();
	}


	public function extractAlgorithm(
		int   $fileId,
		array $row,
	): array {

		// Read authoritative hash from oc_files_metadata.json
		$metadata = $this->getMetadata( $fileId, $row );
		$metaKey  = $row[ MetadataService::FIELD_META_KEY ];

		try
		{
			$authoritativeHash = $metadata->getString( $metaKey );
		}
		catch ( FilesMetadataNotFoundException|FilesMetadataTypeException $e )
		{
			$authoritativeHash = null;
		}

		// Determine algo from input or from meta_key
		$resultAlgo = MetadataService::getAlgorithmenFromKey( $metaKey );

		return [
			'algo' => $resultAlgo,
			'hash' => $authoritativeHash,
		];
	}


	/**
	 * Fetch a batch of pending rows ordered by file_id.
	 *
	 * @return array<int, array{file_id: int, meta_value_string: string}>
	 */
	public function fetchPendingBatch( int $limit = 50 ): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select( self::FIELD_FILE_ID, self::FIELD_META_VALUE_STRING )
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ) ),
			   $qb->expr()
			      ->like(
				      self::FIELD_META_VALUE_STRING,
				      $qb->createNamedParameter( self::PENDING_LIKE ),
			      ),
		   )
		   ->orderBy( self::FIELD_FILE_ID, 'ASC' )
		   ->setMaxResults( $limit )
		;

		$result = $this->executeQuery( $qb );
		$rows   = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$rows[] = [
				self::FIELD_FILE_ID           => (int) $row[ self::FIELD_FILE_ID ],
				self::FIELD_META_VALUE_STRING => (string) $row[ self::FIELD_META_VALUE_STRING ],
			];
		}
		$result->closeCursor();

		return $rows;
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
		$qb->update( self::TABLE_FILES_METADATA_INDEX )
		   ->set( self::FIELD_META_VALUE_STRING, $qb->createNamedParameter( $mode ) )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_FILE_ID, $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->eq( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ) ),
		   )
		;

		$updated = $this->executeStatement( $qb );

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
		$qb->select( 'i.' . self::FIELD_FILE_ID, 'i.' . self::FIELD_META_KEY )
		   ->selectAlias( 'm.' . self::FIELD_JSON, self::FIELD_JSON_ALIAS )
		   ->from( self::TABLE_FILES_METADATA_INDEX, 'i' )
		   ->innerJoin(
			   'i',
			   self::TABLE_FILES_METADATA,
			   'm',
			   'i.' . self::FIELD_FILE_ID . ' = m.' . self::FIELD_FILE_ID,
		   )
		   ->where(
			   $qb->expr()
			      ->eq( 'i.' . self::FIELD_META_VALUE_STRING, $qb->createNamedParameter( $hash ) ),
		   )
		   ->setMaxResults( $limit )
		;

		if ( $algo !== null && $algo !== '' )
		{
			$qb->andWhere(
				$qb->expr()
				   ->eq(
					   'i.' . self::FIELD_META_KEY,
					   $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_PREFIX . $algo ),
				   ),
			);
		}
		else
		{
			$qb->andWhere(
				$qb->expr()
				   ->like(
					   'i.' . self::FIELD_META_KEY,
					   $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_LIKE ),
				   ),
			);
		}

		$result = $this->executeQuery( $qb );
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

		$qb->select( 'i.' . self::FIELD_META_KEY )
		   ->selectAlias(
			   $qb->func()
			      ->count( 'i.' . self::FIELD_FILE_ID ),
			   'cnt',
		   )
		   ->selectAlias(
			   $qb->func()
			      ->groupConcat( 'i.' . self::FIELD_FILE_ID ),
			   'file_ids',
		   )
		   ->selectAlias(
			   $qb->createFunction( 'MAX(m.' . self::FIELD_JSON . ')' ),
			   self::FIELD_JSON_ALIAS,
		   )
		   ->from( self::TABLE_FILES_METADATA_INDEX, 'i' )
		   ->innerJoin(
			   'i',
			   self::TABLE_FILES_METADATA,
			   'm',
			   'i.' . self::FIELD_FILE_ID . ' = m.' . self::FIELD_FILE_ID,
		   )
		   ->where(
			   $qb->expr()
			      ->like(
				      'i.' . self::FIELD_META_KEY,
				      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_LIKE ),
			      ),
		   )
		   ->groupBy( 'i.' . self::FIELD_META_VALUE_STRING )
		   ->addGroupBy( 'i.' . self::FIELD_META_KEY )
		;

		if ( $algo !== null && $algo !== '' )
		{
			$qb->andWhere(
				$qb->expr()
				   ->eq(
					   'i.' . self::FIELD_META_KEY,
					   $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_PREFIX . $algo ),
				   ),
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

		$result = $this->executeQuery( $qb );
		$rows   = $result->fetchAll();
		$result->closeCursor();

		return array_map( function (
			array $row,
		): array {

			$fileIdStr = (string) $row['file_ids'];
			$fileIds   = $fileIdStr !== ''
				? array_map( 'intval', explode( ',', $fileIdStr ) )
				: [];

			// Read the authoritative hash from oc_files_metadata.json,
			// not from the index (which may truncate long hashes like SHA-512).
			$metaValueJson = (string) ( $row[self::FIELD_JSON_ALIAS] ?? '' );
			$metaValue     = $metaValueJson !== ''
				? json_decode( $metaValueJson, true )
				: [];
			$hashValue     = (string) ( $metaValue[ $row[ self::FIELD_META_KEY ] ] ?? '' );

			return [
				self::FIELD_META_KEY          => $row[ self::FIELD_META_KEY ],
				self::FIELD_META_VALUE_STRING => $hashValue,
				'file_count'                  => (int) $row['cnt'],
				'file_ids'                    => $fileIds,
			];
		}, $rows );
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
				self::KEY_FILE_CHECKSUM_PREFIX . $algo,
				IMetadataValueWrapper::TYPE_STRING,
				true,
				IMetadataValueWrapper::EDIT_FORBIDDEN,
			);
		}

		$this->metadataManager->initMetadata(
			self::KEY_FILE_CHECKSUM_UPDATED_AT,
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
	 * Save metadata via IFilesMetadataManager.
	 *
	 * @throws \OCP\FilesMetadata\Exceptions\FilesMetadataException
	 */
	public function saveMetadata(
		IFilesMetadata $metadata,
		int|File|null  $file = null,
	): void {

		$this->metadataManager->saveMetadata( $metadata );

		$this->filecacheService->setHashes( $file ?? $metadata->getFileId(), $this->getHashes( $metadata ) );
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
				<<<"SQL"
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
		catch ( Throwable $e )
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


	/**
	 * @param  mixed  $metaKey
	 *
	 * @return mixed|string|string[]
	 */
	public static function getAlgorithmenFromKey( mixed $metaKey ): mixed
	{

		return str_replace( MetadataService::KEY_FILE_CHECKSUM_PREFIX, '', $metaKey );
	}


	/**
	 * @param  string  $algo
	 *
	 * @return string
	 */
	public static function getHashKey( string $algo ): string
	{

		return self::KEY_FILE_CHECKSUM_PREFIX . strtolower( $algo );
	}

}
