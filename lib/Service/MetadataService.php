<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OC\FilesMetadata\Model\FilesMetadata;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\DB\Exception;
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
	public const PENDING_MODE_AUTO            = 'auto';
	public const PENDING_MODE_MISSING         = 'missing';
	public const PENDING_MODE_FORCE           = 'force';
	public const PENDING_MODE_LAZY            = 'lazy';
	public const PENDING_PREFIX               = 'pending:';
	public const PENDING_AUTO                 = self::PENDING_PREFIX . self::PENDING_MODE_AUTO;
	public const PENDING_FORCE                = self::PENDING_PREFIX . self::PENDING_MODE_FORCE;
	public const PENDING_LAZY                 = self::PENDING_PREFIX . self::PENDING_MODE_LAZY;
	public const PENDING_LIKE                 = self::PENDING_PREFIX . '%';
	/**
	 * States meaning the stored hashes are not to be trusted, and nothing is
	 * coming to fix them by itself.
	 *
	 * Namespaced like `pending:` so one `LIKE` answers "which files have
	 * untrusted hashes" whatever the reason, and so a reason added later is
	 * excluded from scans by construction rather than by remembering to.
	 *
	 * The two differ in what is left behind: erosion already removed the
	 * hashes ({@see markEroded()} strips them), while a reset disowns hashes
	 * that are still stored, awaiting the drain — which is why scans have to
	 * exclude the namespace rather than trust it to be empty.
	 *
	 * Not to be confused with a hash that is merely **outdated** — older than
	 * the file it describes. That is computed from `updated_at < mtime`, never
	 * stored: a written file is queued as `pending:<mode>` in the same column,
	 * so the two could not both be recorded.
	 */
	public const STATE_STALE_PREFIX = 'stale:';

	public const STATE_ERODED = self::STATE_STALE_PREFIX . 'eroded';

	public const STATE_RESET = self::STATE_STALE_PREFIX . 'reset';

	public const STALE_LIKE = self::STATE_STALE_PREFIX . '%';

	/** The value written before the states were namespaced; migrated by repair. */
	public const LEGACY_STATE_ERODED        = 'eroded';
	public const TABLE_FILES_METADATA       = 'files_metadata';
	public const TABLE_FILES_METADATA_INDEX = 'files_metadata_index';

	/**
	 * Nextcloud core's `files_metadata_index.meta_value_string` column
	 * length (see core Migrations\Version28000Date20231004103301). Hash
	 * values longer than this (sha256, sha3-256: 64 chars; sha512,
	 * sha3-512: 128 chars) are silently truncated by the database when
	 * the index row is written — sha1 (40), md5 (32), and crc32 (8) fit
	 * within it untouched.
	 */
	public const META_VALUE_STRING_MAX_LENGTH = 63;


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

		foreach ( HashCalculationService::SUPPORTED_ALGOS as $algo )
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
	 *
	 * When $rawMetadata is null (default), the metadata is loaded from the
	 * metadata manager.  When $rawMetadata is provided, metadata is
	 * reconstructed in-memory from raw data — useful when the caller
	 * already has the data (e.g. from a JOIN query).
	 *
	 * Accepted forms for $rawMetadata:
	 * - string:                JSON string; decoded with json_decode()
	 * - array{meta_json: ...}: DB result row; the 'meta_json' value is
	 *                          extracted and JSON-decoded
	 * - array (no meta_json):  Already-decoded associative array, used as-is
	 *
	 * @param  int|File           $file         File ID or File node
	 * @param  string|array|null  $rawMetadata  Raw metadata (see above) or
	 *                                          null to load from manager
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
		$metadata->setInt( self::KEY_FILE_CHECKSUM_UPDATED_AT, 0, true );

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
		catch ( FilesMetadataNotFoundException|FilesMetadataTypeException )
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
	 * Upserts meta_value_string on the file-checksum-updated_at index row.
	 * The row exists iff the file has ever been queued, hashed, or eroded —
	 * absence means "never considered". (This replaces the old contract of
	 * refusing to insert and relying on universal seeding, which made every
	 * mark on an unseeded file a silent no-op.)
	 */
	public function markPending(
		int    $fileId,
		string $mode,
	): void {

		$this->upsertUpdatedAtString( $fileId, $mode );
	}


	/**
	 * Record that a file's hashes were dropped because nothing maintains them.
	 *
	 * Strips every stored hash and stamps the updated_at index row with the
	 * literal 'eroded'. Unlike a bare clear, this leaves a queryable, indexed
	 * trace: the status page can count it, and the state is self-healing —
	 * the next time a rule covers the file again, re-hashing overwrites it.
	 * 'eroded' never matches the queue's 'pending:%' filter.
	 *
	 * Distinguishes "had hashes, lost them on write" (eroded) from "never
	 * considered" (no row at all).
	 *
	 * @throws \OCP\FilesMetadata\Exceptions\FilesMetadataException
	 */
	public function markEroded( int $fileId ): void
	{

		$metadata = $this->getMetadata( $fileId );
		$metadata->removeStartsWith( self::KEY_FILE_CHECKSUM_PREFIX );
		$metadata->setInt( self::KEY_FILE_CHECKSUM_UPDATED_AT, 0, true );
		$this->metadataManager->saveMetadata( $metadata );

		// After the save: saving regenerates the index rows, so the string
		// has to be written once the regenerated row exists.
		$this->upsertUpdatedAtString( $fileId, self::STATE_ERODED );
	}


	/**
	 * How many files lost their hashes to erosion and have not been re-hashed.
	 */
	public function countEroded(): int
	{

		return $this->countByState( self::STATE_ERODED );
	}


	/**
	 * How many files carry one untrusted-hash state, or all of them.
	 *
	 * Pass a `stale:%` pattern for the whole namespace — the point of naming
	 * the states that way is that "how many files have untrusted hashes"
	 * stays one question with one answer, however many reasons there are.
	 *
	 * @param  string  $state  An exact state, or a LIKE pattern such as
	 *                         {@see STALE_LIKE}.
	 */
	public function countByState( string $state ): int
	{

		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias(
			$qb->func()
			   ->count( self::FIELD_FILE_ID ),
			'cnt',
		)
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ) ),
			   str_contains( $state, '%' )
				   ? $qb->expr()
				        ->like( self::FIELD_META_VALUE_STRING, $qb->createNamedParameter( $state ) )
				   : $qb->expr()
				        ->eq( self::FIELD_META_VALUE_STRING, $qb->createNamedParameter( $state ) ),
		   )
		;

		$result = $this->executeQuery( $qb );
		$count  = (int) $result->fetchOne();
		$result->closeCursor();

		return $count;
	}


	/**
	 * How many files have untrusted hashes, broken down by why.
	 *
	 * @return array<string, int>  State value => count, e.g. `stale:reset` => 12
	 */
	public function getStaleStats(): array
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
			      ->like( self::FIELD_META_VALUE_STRING, $qb->createNamedParameter( self::STALE_LIKE ) ),
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
	 * Set the file-checksum-updated_at index row's string value, creating the
	 * row when the file has never been considered before.
	 *
	 * UPDATE first (the common case), INSERT on a miss. The race window
	 * between the two legs is closed by retrying the UPDATE once when the
	 * INSERT collides with a concurrent writer.
	 */
	private function upsertUpdatedAtString(
		int    $fileId,
		string $value,
	): void {

		if ( $this->updateUpdatedAtString( $fileId, $value ) > 0 )
		{
			return;
		}

		try
		{
			$qb = $this->db->getQueryBuilder();
			$qb->insert( self::TABLE_FILES_METADATA_INDEX )
			   ->values(
				   [
					   self::FIELD_FILE_ID           => $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ),
					   self::FIELD_META_KEY          => $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
					   self::FIELD_META_VALUE_STRING => $qb->createNamedParameter( $value ),
					   self::FIELD_META_VALUE_INT    => $qb->createNamedParameter( 0, IQueryBuilder::PARAM_INT ),
				   ],
			   )
			;

			$this->executeStatement( $qb );
		}
		catch ( Exception )
		{
			// Lost the insert race — the row exists now, so the update wins.
			$this->updateUpdatedAtString( $fileId, $value );
		}
	}


	private function updateUpdatedAtString(
		int    $fileId,
		string $value,
	): int {

		$qb = $this->db->getQueryBuilder();
		$qb->update( self::TABLE_FILES_METADATA_INDEX )
		   ->set( self::FIELD_META_VALUE_STRING, $qb->createNamedParameter( $value ) )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_FILE_ID, $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->eq( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ) ),
		   )
		;

		return $this->executeStatement( $qb );
	}


	/**
	 * Copy already-known hashes into the metadata index for one file.
	 *
	 * Backfill only: writes hash keys the file does not have yet and never
	 * overwrites an existing one — the filecache value is the *older* claim,
	 * so where both exist the metadata wins. When at least one key was added
	 * and no valid timestamp exists yet, updated_at is stamped with the
	 * file's mtime rather than now(): the copied hash describes the content
	 * as of that mtime, which is exactly what the filecache asserts. Reads
	 * no file content.
	 *
	 * @param  array<string, string>  $algoToHash  lowercase algo => hex hash
	 *
	 * @return int  Number of hash keys added
	 * @throws \OCP\FilesMetadata\Exceptions\FilesMetadataException
	 */
	public function backfillHashes(
		int   $fileId,
		array $algoToHash,
		int   $mtime,
	): int {

		if ( $algoToHash === [] )
		{
			return 0;
		}

		$metadata = $this->getMetadata( $fileId );
		$added    = 0;

		foreach ( $algoToHash as $algo => $hash )
		{
			$metaKey = self::getHashKey( $algo );

			if ( $metadata->hasKey( $metaKey ) )
			{
				continue;
			}

			$metadata->setString( $metaKey, $hash, true );
			$added ++;
		}

		if ( $added === 0 )
		{
			return 0;
		}

		if ( ( $this->getUpdatedAt( $metadata ) ?? 0 ) < 1 )
		{
			$metadata->setInt( self::KEY_FILE_CHECKSUM_UPDATED_AT, $mtime, true );
		}

		$this->metadataManager->saveMetadata( $metadata );

		return $added;
	}


	/**
	 * Parse a pending mode string by stripping the 'pending:' prefix.
	 *
	 * @param  string  $status  E.g. 'pending:auto', 'pending:force', or 'auto'
	 *
	 * @return string  The processing mode without the prefix, e.g. 'auto'
	 */
	public static function parseMode( string $status ): string
	{

		if ( str_starts_with( $status, self::PENDING_PREFIX ) )
		{
			return substr( $status, strlen( self::PENDING_PREFIX ) );
		}

		return $status;
	}


	/**
	 * Truncate a value to what the index column actually stores.
	 */
	public static function truncateForIndex( string $value ): string
	{

		return strlen( $value ) > self::META_VALUE_STRING_MAX_LENGTH
			? substr( $value, 0, self::META_VALUE_STRING_MAX_LENGTH )
			: $value;
	}


	/**
	 * Find files matching a given hex hash value.
	 *
	 * Searches across all file-checksum-* keys. The index column
	 * truncates values longer than {@see META_VALUE_STRING_MAX_LENGTH}
	 * (sha256, sha3-256, sha512, sha3-512), so the comparison matches
	 * against the same truncated prefix the database actually stored —
	 * callers that need the full, untruncated hash confirmed should
	 * verify it against the authoritative value from
	 * {@see extractAlgorithm()} before trusting a match.
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
			      ->eq(
				      'i.' . self::FIELD_META_VALUE_STRING,
				      $qb->createNamedParameter( self::truncateForIndex( $hash ) ),
			      ),
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
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
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
		   ->selectAlias(
			   $qb->createFunction( 'MAX(i.' . self::FIELD_META_VALUE_STRING . ')' ),
			   'index_hash',
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
			      ->andX(
				      $qb->expr()
				         ->like(
					         'i.' . self::FIELD_META_KEY,
					         $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_LIKE ),
				         ),
				      $qb->expr()
				         ->neq(
					         'i.' . self::FIELD_META_KEY,
					         $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
				         ),
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

		$groups = array_map( function (
			array $row,
		): array {

			$fileIdStr = (string) $row['file_ids'];
			$fileIds   = $fileIdStr !== ''
				? array_map( 'intval', explode( ',', $fileIdStr ) )
				: [];

			// Read the authoritative hash from oc_files_metadata.json,
			// falling back to the index value (meta_value_string).
			$metaValueJson = (string) ( $row[ self::FIELD_JSON_ALIAS ] ?? '' );
			$metaValue     = $metaValueJson !== ''
				? json_decode( $metaValueJson, true )
				: [];
			$rawValue      = $metaValue[ $row[ self::FIELD_META_KEY ] ] ?? '';
			$jsonHash      = is_array( $rawValue )
				? (string) ( $rawValue[0] ?? '' )
				: (string) $rawValue;
			$hashValue     = $jsonHash !== ''
				? $jsonHash
				: (string) ( $row['index_hash'] ?? '' );

			return [
				self::FIELD_META_KEY          => $row[ self::FIELD_META_KEY ],
				self::FIELD_META_VALUE_STRING => $hashValue,
				'file_count'                  => (int) $row['cnt'],
				'file_ids'                    => $fileIds,
			];
		}, $rows );

		return $this->verifyTruncatedDuplicateGroups( $groups, $minCount );
	}


	/**
	 * Re-verify duplicate groups whose meta_value_string may have been
	 * truncated by the index column (see {@see META_VALUE_STRING_MAX_LENGTH}).
	 *
	 * SQL groups by the truncated index value, so two files whose full
	 * hashes only agree on the truncated prefix would otherwise be
	 * reported as duplicates of each other. Re-fetch each member's own
	 * authoritative hash from oc_files_metadata.json and split the group
	 * by the real, full value, dropping any resulting sub-group below
	 * $minCount.
	 *
	 * @param  list<array{meta_key: string, meta_value_string: string, file_count: int, file_ids: int[]}>  $groups
	 *
	 * @return list<array{meta_key: string, meta_value_string: string, file_count: int, file_ids: int[]}>
	 */
	private function verifyTruncatedDuplicateGroups(
		array $groups,
		int   $minCount,
	): array {

		$verified = [];

		foreach ( $groups as $group )
		{
			if ( strlen( $group[ self::FIELD_META_VALUE_STRING ] ) < self::META_VALUE_STRING_MAX_LENGTH )
			{
				// Short enough that the index couldn't have truncated it —
				// no collision risk, keep the group as-is.
				$verified[] = $group;

				continue;
			}

			$metaKey    = $group[ self::FIELD_META_KEY ];
			$byFullHash = [];

			foreach ( $group['file_ids'] as $fileId )
			{
				try
				{
					$fullHash = $this->getMetadata( $fileId )
					                 ->getString( $metaKey )
					;
				}
				catch ( FilesMetadataNotFoundException|FilesMetadataTypeException )
				{
					$fullHash = $group[ self::FIELD_META_VALUE_STRING ];
				}

				$byFullHash[ $fullHash ][] = $fileId;
			}

			foreach ( $byFullHash as $fullHash => $fileIds )
			{
				if ( count( $fileIds ) >= $minCount )
				{
					$verified[] = [
						self::FIELD_META_KEY          => $metaKey,
						self::FIELD_META_VALUE_STRING => $fullHash,
						'file_count'                  => count( $fileIds ),
						'file_ids'                    => $fileIds,
					];
				}
			}
		}

		return $verified;
	}


	/**
	 * Register all metadata keys on app boot.
	 *
	 * Idempotent — safe to call on every boot.
	 */
	public function register(): void
	{

		foreach ( HashCalculationService::SUPPORTED_ALGOS as $algo )
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
	 * Count all hash metadata index entries (file-checksum-* keys excluding updated_at).
	 *
	 * @return int
	 */
	public function countHashEntries(): int
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select(
			$qb->func()
			   ->count( '*', 'cnt' ),
		)
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
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


	public static function parseQueryTerm( string $term ): ?array
	{

		// Parse algo:hash or raw hash
		if ( ! preg_match( '/^(?:([a-zA-F0-9]+):)?([a-fA-F0-9]{8,128})$/', $term, $matches ) )
		{
			// Not a valid hex hash
			return null;
		}

		$hash = strtolower( $matches[2] );
		$algo = $matches[1] ?? null;

		if ( is_string( $algo ) )
		{
			$algo = strtolower( $algo );
		}

		return [
			'algo' => $algo,
			'hash' => $hash,
		];
	}

}
