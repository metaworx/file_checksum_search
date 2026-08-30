<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use Generator;
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

	/** How many file ids {@see markAllStale()} disowns per statement. */
	private const MARK_PAGE_SIZE = 1000;


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

			// Saving does not remove index rows for keys the document no
			// longer has: it upserts what is present and leaves the rest.
			// Those orphans still answer searches and still form duplicate
			// groups, so a file whose hashes were cleared keeps being found
			// by hashes it no longer has.
			$this->pruneHashIndexRows( $metadata->getFileId() );
		}
	}


	/**
	 * Delete this app's hash rows from the index for one file.
	 *
	 * The index is derived from the metadata document, so a row for a key the
	 * document no longer carries is garbage — Nextcloud simply never collects
	 * it. `file-checksum-updated_at` is deliberately kept: the document still
	 * has it, and it is what records that this file was considered at all.
	 *
	 * @throws Exception
	 */
	private function pruneHashIndexRows( int $fileId ): void
	{

		$qb = $this->db->getQueryBuilder();
		$qb->delete( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_FILE_ID, $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->like( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_LIKE ) ),
			   $qb->expr()
			      ->neq(
				      self::FIELD_META_KEY,
				      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
			      ),
		   )
		;

		$qb->executeStatement();
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
	 * Disown the stored hashes of many files at once.
	 *
	 * Writes the marker only: the hashes and the freshness stamp stay exactly
	 * as they are. That is the point — a reset over a large instance would
	 * otherwise rewrite one metadata document per file, and this is one
	 * UPDATE over rows the index already has. The drain clears them later,
	 * or an import replaces them first and the clearing never needs to
	 * happen.
	 *
	 * Nothing is lost by deferring: {@see andWhereNotStale()} takes these
	 * files out of every scan the moment the marker lands.
	 *
	 * Files with no `file-checksum-updated_at` row are not marked. A file the
	 * app never considered has no hashes to disown, and inventing a row for
	 * it would make "never considered" and "disowned" indistinguishable.
	 *
	 * @param  list<int>  $fileIds
	 *
	 * @return int  Rows marked.
	 * @throws Exception
	 */
	public function markStale( array $fileIds ): int
	{

		if ( $fileIds === [] )
		{
			return 0;
		}

		$marked = 0;

		// Chunked at 1000: Oracle's placeholder ceiling, and the chunk size
		// Nextcloud itself uses.
		foreach ( array_chunk( array_values( array_unique( $fileIds ) ), 1000 ) as $chunk )
		{
			$qb = $this->db->getQueryBuilder();
			$qb->update( self::TABLE_FILES_METADATA_INDEX )
			   ->set(
				   self::FIELD_META_VALUE_STRING,
				   $qb->createNamedParameter( self::STATE_RESET ),
			   )
			   ->where(
				   $qb->expr()
				      ->eq(
					      self::FIELD_META_KEY,
					      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
				      ),
				   $qb->expr()
				      ->in(
					      self::FIELD_FILE_ID,
					      $qb->createNamedParameter( $chunk, IQueryBuilder::PARAM_INT_ARRAY ),
				      ),
			   )
			;

			$marked += $qb->executeStatement();
		}

		return $marked;
	}


	/**
	 * Disown every file this app has hashed.
	 *
	 * The whole-instance form of {@see markStale()}, as one statement rather
	 * than a file list the caller would have to page through first.
	 *
	 * **Only files that actually hold hashes.** The marker shares its column
	 * with the queue, so marking a file writes over whatever it was waiting
	 * for — and a file with no hashes has nothing to disown, which would make
	 * resetting the hashes quietly reset the queue as well. Restricting it to
	 * files with something to lose keeps the two slices separable, and makes
	 * the number reported afterwards the same one the plan promised.
	 *
	 * @return int  Rows marked.
	 * @throws Exception
	 */
	public function markAllStale(): int
	{

		$marked = 0;
		$lastId = 0;

		// Paged rather than one statement with a subquery: MySQL refuses to
		// read the table an UPDATE targets, and every file worth marking has
		// to be found in that same table. One UPDATE per page of ids is the
		// portable shape, and still one write per thousand files.
		while ( true )
		{
			$fileIds = $this->pageHashedFileIdsAfter( $lastId, self::MARK_PAGE_SIZE );

			if ( $fileIds === [] )
			{
				return $marked;
			}

			$lastId = $fileIds[ array_key_last( $fileIds ) ];
			$marked += $this->markStale( $fileIds );
		}
	}


	/**
	 * The next files awaiting the drain's attention because they were
	 * disowned rather than queued.
	 *
	 * Deliberately `stale:reset` alone rather than the whole namespace:
	 * an eroded file has no hashes left to clear, so handing it to the drain
	 * would be work with nothing to do.
	 *
	 * @return list<int>
	 * @throws Exception
	 */
	public function fetchStaleBatch( int $limit = 50 ): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select( self::FIELD_FILE_ID )
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq(
				      self::FIELD_META_KEY,
				      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
			      ),
			   $qb->expr()
			      ->eq(
				      self::FIELD_META_VALUE_STRING,
				      $qb->createNamedParameter( self::STATE_RESET ),
			      ),
		   )
		   ->orderBy( self::FIELD_FILE_ID, 'ASC' )
		   ->setMaxResults( $limit )
		;

		$result  = $this->executeQuery( $qb );
		$fileIds = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$fileIds[] = (int) $row[ self::FIELD_FILE_ID ];
		}
		$result->closeCursor();

		return $fileIds;
	}


	/**
	 * Clear this app's hashes from files, now, one document at a time.
	 *
	 * The synchronous counterpart to {@see markStale()}, for the operator who
	 * wants the rows gone before they walk away rather than whenever the
	 * drain next runs. It is the slow path by nature: `files_metadata_index`
	 * is regenerated from the `files_metadata` document, so hashes can only
	 * be removed by rewriting each document — a bulk DELETE over the index
	 * would be undone the next time anything saved that file's metadata.
	 *
	 * Paged, and each file's failure is contained: one unreadable document
	 * must not abandon the rest of the instance half-cleared.
	 *
	 * @param  callable(int, int): void|null  $progress  Called as (done, total).
	 *
	 * @return int  Files cleared.
	 * @throws Exception
	 */
	public function clearHashesNow(
		int       $batchSize = 500,
		?callable $progress = null,
	): int {

		$total   = $this->countHashedFiles();
		$cleared = 0;

		while ( true )
		{
			$fileIds = $this->pageHashedFileIds( $batchSize );

			if ( $fileIds === [] )
			{
				break;
			}

			foreach ( $fileIds as $fileId )
			{
				try
				{
					$this->clearMetadata( $fileId );
					$cleared ++;
				}
				catch ( Throwable $e )
				{
					$this->logger->warning(
						'FCIAS: could not clear metadata for fileId {fileId}; continuing.',
						[
							'app'       => Application::APP_ID,
							'fileId'    => $fileId,
							'exception' => $e,
						],
					);
				}
			}

			if ( $progress !== null )
			{
				$progress( $cleared, $total );
			}
		}

		return $cleared;
	}


	/**
	 * Every stored hash, one file at a time.
	 *
	 * Read from the **document**, never from the index: the index truncates
	 * a value at {@see META_VALUE_STRING_MAX_LENGTH} characters, which is
	 * shorter than a SHA-512 hash. A backup assembled from index rows would
	 * look complete and restore half a hash, so the index is used only to
	 * find *which* files have hashes — the answer it can give with one
	 * indexed scan — and the document supplies the values.
	 *
	 * Keyset paging by file id rather than by offset: the set is read-only
	 * here, but a keyset page cannot skip a row if anything does change
	 * underneath it, and it costs nothing.
	 *
	 * @return Generator<array{file_id: int, hashes: array<string, string>, updated_at: ?int}>
	 * @throws Exception
	 */
	public function exportHashes( int $pageSize = 500 ): Generator
	{

		$lastId = 0;

		while ( true )
		{
			$fileIds = $this->pageHashedFileIdsAfter( $lastId, $pageSize );

			if ( $fileIds === [] )
			{
				return;
			}

			$lastId = $fileIds[ array_key_last( $fileIds ) ];

			foreach ( $this->fetchDocuments( $fileIds ) as $fileId => $json )
			{
				$metadata = $this->getMetadata( $fileId, $json );
				$hashes   = $this->getHashes( $metadata );

				if ( $hashes === [] )
				{
					continue;
				}

				yield [
					'file_id'    => $fileId,
					'hashes'     => $hashes,
					'updated_at' => $this->getUpdatedAt( $metadata ),
				];
			}
		}
	}


	/**
	 * Every queue and `stale:` marker, one file at a time.
	 *
	 * The string half of `file-checksum-updated_at` — what a file is waiting
	 * for, or why its hashes are not to be trusted. This one *can* come from
	 * the index: a marker is short by construction, and
	 * {@see markPending()} refuses to write one that is not.
	 *
	 * @return Generator<array{file_id: int, state: string}>
	 * @throws Exception
	 */
	public function exportStates( int $pageSize = 1000 ): Generator
	{

		$lastId = 0;

		while ( true )
		{
			$qb = $this->db->getQueryBuilder();
			$qb->select( self::FIELD_FILE_ID, self::FIELD_META_VALUE_STRING )
			   ->from( self::TABLE_FILES_METADATA_INDEX )
			   ->where(
				   $qb->expr()
				      ->eq(
					      self::FIELD_META_KEY,
					      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
				      ),
				   $qb->expr()
				      ->neq(
					      self::FIELD_META_VALUE_STRING,
					      $qb->createNamedParameter( '' ),
				      ),
				   $qb->expr()
				      ->gt(
					      self::FIELD_FILE_ID,
					      $qb->createNamedParameter( $lastId, IQueryBuilder::PARAM_INT ),
				      ),
			   )
			   ->orderBy( self::FIELD_FILE_ID, 'ASC' )
			   ->setMaxResults( $pageSize )
			;

			$result = $this->executeQuery( $qb );
			$rows   = $result->fetchAll();
			$result->closeCursor();

			if ( $rows === [] )
			{
				return;
			}

			foreach ( $rows as $row )
			{
				$lastId = (int) $row[ self::FIELD_FILE_ID ];

				yield [
					'file_id' => $lastId,
					'state'   => (string) $row[ self::FIELD_META_VALUE_STRING ],
				];
			}
		}
	}


	/**
	 * One page of file ids that carry hashes, after the given id.
	 *
	 * The reading counterpart of {@see pageHashedFileIds()}, which always
	 * returns the first page because it is read by something that deletes
	 * what it sees.
	 *
	 * @return list<int>
	 * @throws Exception
	 */
	private function pageHashedFileIdsAfter(
		int $afterFileId,
		int $limit,
	): array {

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct( self::FIELD_FILE_ID )
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->like( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_LIKE ) ),
			   $qb->expr()
			      ->neq(
				      self::FIELD_META_KEY,
				      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
			      ),
			   $qb->expr()
			      ->gt(
				      self::FIELD_FILE_ID,
				      $qb->createNamedParameter( $afterFileId, IQueryBuilder::PARAM_INT ),
			      ),
		   )
		   ->orderBy( self::FIELD_FILE_ID, 'ASC' )
		   ->setMaxResults( $limit )
		;

		$result  = $this->executeQuery( $qb );
		$fileIds = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$fileIds[] = (int) $row[ self::FIELD_FILE_ID ];
		}
		$result->closeCursor();

		return $fileIds;
	}


	/**
	 * The raw metadata documents for a page of file ids.
	 *
	 * @param  list<int>  $fileIds
	 *
	 * @return array<int, string>  file id => the document's JSON
	 * @throws Exception
	 */
	private function fetchDocuments( array $fileIds ): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select( self::FIELD_FILE_ID, self::FIELD_JSON )
		   ->from( self::TABLE_FILES_METADATA )
		   ->where(
			   $qb->expr()
			      ->in(
				      self::FIELD_FILE_ID,
				      $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
			      ),
		   )
		;

		$result    = $this->executeQuery( $qb );
		$documents = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$documents[ (int) $row[ self::FIELD_FILE_ID ] ] = (string) $row[ self::FIELD_JSON ];
		}
		$result->closeCursor();

		return $documents;
	}


	/**
	 * How many files carry any of this app's hashes.
	 *
	 * @throws Exception
	 */
	public function countHashedFiles(): int
	{

		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias(
			$qb->createFunction( 'COUNT(DISTINCT ' . self::FIELD_FILE_ID . ')' ),
			'cnt',
		)
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->like( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_LIKE ) ),
			   $qb->expr()
			      ->neq(
				      self::FIELD_META_KEY,
				      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
			      ),
		   )
		;

		$result = $this->executeQuery( $qb );
		$count  = (int) $result->fetchOne();
		$result->closeCursor();

		return $count;
	}


	/**
	 * One page of file ids that still carry this app's hashes.
	 *
	 * Always the *first* page: {@see clearHashesNow()} removes what it reads,
	 * so the next call sees what is left. Paging by offset would skip files
	 * as the set shrank underneath it.
	 *
	 * @return list<int>
	 * @throws Exception
	 */
	private function pageHashedFileIds( int $limit ): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct( self::FIELD_FILE_ID )
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->like( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_LIKE ) ),
			   $qb->expr()
			      ->neq(
				      self::FIELD_META_KEY,
				      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
			      ),
		   )
		   ->orderBy( self::FIELD_FILE_ID, 'ASC' )
		   ->setMaxResults( $limit )
		;

		$result  = $this->executeQuery( $qb );
		$fileIds = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$fileIds[] = (int) $row[ self::FIELD_FILE_ID ];
		}
		$result->closeCursor();

		return $fileIds;
	}


	/**
	 * Forget the queue: every `pending:%` and `stale:%` marker, cleared.
	 *
	 * The state slice of a reset. It empties the string half of
	 * `file-checksum-updated_at` and leaves the int half — the freshness
	 * stamp — alone, because the stamp belongs to the hashes, not to the
	 * queue, and clearing it would make every file look never-hashed.
	 *
	 * @return int  Rows cleared.
	 * @throws Exception
	 */
	public function clearQueueState(): int
	{

		$qb = $this->db->getQueryBuilder();
		$qb->update( self::TABLE_FILES_METADATA_INDEX )
		   ->set( self::FIELD_META_VALUE_STRING, $qb->createNamedParameter( null ) )
		   ->where(
			   $qb->expr()
			      ->eq(
				      self::FIELD_META_KEY,
				      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
			      ),
			   $qb->expr()
			      ->isNotNull( self::FIELD_META_VALUE_STRING ),
		   )
		;

		return $qb->executeStatement();
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
	 * Write imported hashes onto one file.
	 *
	 * The general form of {@see backfillHashes()}, which is the merge case
	 * with the stamp fixed to the file's mtime. Here the caller decides both
	 * questions, because an import has to answer them differently depending
	 * on where the records came from:
	 *
	 * - **merge** writes only algorithms the file does not have. What is
	 *   stored was computed by this instance from the file itself; an
	 *   imported claim about the same algorithm is at best a duplicate and
	 *   at worst a contradiction, and silently preferring the newcomer is
	 *   not a decision an import should take on its own.
	 * - **replace** overwrites them, which is what restoring a backup over
	 *   a reset instance means.
	 *
	 * The stamp is written whenever one is given, because it is the caller
	 * who has weighed it against the file's mtime — see {@see ImportPolicy}.
	 * A null stamp leaves whatever is there, and stamps zero only where
	 * nothing was there at all, so a file never ends up claiming a freshness
	 * nobody asserted.
	 *
	 * Any `stale:` marker is cleared, but only when something was actually
	 * written: hashes have arrived, so the file is no longer waiting for the
	 * drain to take its old ones away. A `pending:` marker is left alone —
	 * that is a rule asking for its own hashes, which this import has not
	 * satisfied.
	 *
	 * @param  array<string, string>  $algoToHash
	 *
	 * @return array{written: int, overwritten: int, skipped: int, markerCleared: bool}
	 * @throws \OCP\FilesMetadata\Exceptions\FilesMetadataException
	 * @throws Exception
	 */
	public function writeHashes(
		int   $fileId,
		array $algoToHash,
		?int  $stamp,
		bool  $merge,
	): array {

		$report = [
			'written'       => 0,
			'overwritten'   => 0,
			'skipped'       => 0,
			'markerCleared' => false,
		];

		if ( $algoToHash === [] )
		{
			return $report;
		}

		$metadata = $this->getMetadata( $fileId );

		foreach ( $algoToHash as $algo => $hash )
		{
			$metaKey = self::getHashKey( (string) $algo );
			$held    = $metadata->hasKey( $metaKey );

			if ( $held && $merge )
			{
				$report['skipped'] ++;

				continue;
			}

			$metadata->setString( $metaKey, $hash, true );

			if ( $held )
			{
				$report['overwritten'] ++;

				continue;
			}

			$report['written'] ++;
		}

		if ( $report['written'] === 0 && $report['overwritten'] === 0 )
		{
			return $report;
		}

		if ( $stamp !== null )
		{
			$metadata->setInt( self::KEY_FILE_CHECKSUM_UPDATED_AT, $stamp, true );
		}
		elseif ( ! $metadata->hasKey( self::KEY_FILE_CHECKSUM_UPDATED_AT ) )
		{
			$metadata->setInt( self::KEY_FILE_CHECKSUM_UPDATED_AT, 0, true );
		}

		$this->metadataManager->saveMetadata( $metadata );
		$this->filecacheService->setHashes( $fileId, $this->getHashes( $metadata ) );

		// After the save: saving regenerates the index rows, so the string
		// half has to be read and cleared once the regenerated row exists.
		$report['markerCleared'] = $this->clearStaleMarker( $fileId );

		return $report;
	}


	/**
	 * Take one file out of the `stale:` namespace, if it is in it.
	 *
	 * @return bool  Whether there was a marker to clear.
	 * @throws Exception
	 */
	public function clearStaleMarker( int $fileId ): bool
	{

		$qb = $this->db->getQueryBuilder();
		$qb->update( self::TABLE_FILES_METADATA_INDEX )
		   ->set( self::FIELD_META_VALUE_STRING, $qb->createNamedParameter( null ) )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_FILE_ID, $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->eq( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ) ),
			   $qb->expr()
			      ->like( self::FIELD_META_VALUE_STRING, $qb->createNamedParameter( self::STALE_LIKE ) ),
		   )
		;

		return $this->executeStatement( $qb ) > 0;
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
	 * Exclude files whose hashes an operator or the app disowned.
	 *
	 * Applied by every query that **scans** for hashes — search, lookup,
	 * duplicates — so a disowned hash stops being findable the moment it is
	 * marked, rather than when the background job gets round to clearing it.
	 * Without this, a reset would leave wrong hashes answering searches and
	 * forming duplicate groups for as long as the queue took to drain.
	 *
	 * Deliberately **not** applied to the per-file reads
	 * ({@see getHashes()}, and so the sidebar): a file someone opened shows
	 * what is actually stored, labelled. Telling them the file has no
	 * checksums would be a different untruth, and the recalculate button is
	 * right there.
	 *
	 * A LEFT JOIN rather than a correlated NOT EXISTS: it composes with the
	 * GROUP BY in {@see queryDuplicates()}, and its predicate is exactly
	 * `f_meta_index (file_id, meta_key, meta_value_string)`, so the lookup is
	 * index-only.
	 *
	 * @param  string  $alias  Alias of the scanned index table in $qb.
	 */
	private function andWhereNotStale(
		IQueryBuilder $qb,
		string        $alias = 'i',
	): void {

		$qb->leftJoin(
			$alias,
			self::TABLE_FILES_METADATA_INDEX,
			'stale',
			$qb->expr()
			   ->andX(
				   $qb->expr()
				      ->eq( 'stale.' . self::FIELD_FILE_ID, $alias . '.' . self::FIELD_FILE_ID ),
				   $qb->expr()
				      ->eq(
					      'stale.' . self::FIELD_META_KEY,
					      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
				      ),
				   $qb->expr()
				      ->like(
					      'stale.' . self::FIELD_META_VALUE_STRING,
					      $qb->createNamedParameter( self::STALE_LIKE ),
				      ),
			   ),
		)
		   ->andWhere(
			   $qb->expr()
			      ->isNull( 'stale.' . self::FIELD_FILE_ID ),
		   )
		;
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

		$this->andWhereNotStale( $qb );

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

		$this->andWhereNotStale( $qb );

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
