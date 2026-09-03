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
 * - Key registration (initMetadata for every algorithm in force or ever written, + updated_at)
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
	public const FIELD_FILE_ID           = 'file_id';
	public const FIELD_JSON              = 'json';
	public const FIELD_JSON_ALIAS        = 'meta_json';
	public const FIELD_META_KEY          = 'meta_key';
	public const FIELD_META_VALUE_INT    = 'meta_value_int';
	public const FIELD_META_VALUE_STRING = 'meta_value_string';
	/** Every key this app writes, hashes and stamp alike. */
	public const KEY_FILE_CHECKSUM_PREFIX = 'file-checksum-';

	/**
	 * The hashes, and nothing else.
	 *
	 * Their own prefix, so that a query can say "the hash keys" instead of
	 * saying "this app's keys, except the stamp" — which is what every such
	 * query had to say before, and what two of them silently got wrong.
	 */
	public const KEY_FILE_CHECKSUM_HASH_PREFIX = self::KEY_FILE_CHECKSUM_PREFIX . 'hash-';

	public const KEY_FILE_CHECKSUM_LIKE       = self::KEY_FILE_CHECKSUM_HASH_PREFIX . '%';
	public const KEY_FILE_CHECKSUM_UPDATED_AT = 'file-checksum-updated_at';

	/**
	 * Every algorithm a previous release could have written under the old key
	 * spelling. The repair paths that rename those keys iterate this, not the
	 * live catalogue: an algorithm the administrator has since disallowed
	 * still has rows in the old spelling that must be found and renamed.
	 * Append-only — nothing here may ever be removed.
	 */
	public const LEGACY_ALGOS
		= [
			'sha1',
			'md5',
			'adler32',
			'crc32',
			'sha256',
			'sha512',
			'sha3-256',
			'sha3-512',
		];
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

	/**
	 * How many times {@see register()} restates the declarations before
	 * giving up. Three is one more than the two an eight-key instance was
	 * measured to need.
	 */
	private const REGISTER_PASSES = 3;

	/** How many file ids {@see markAllStale()} disowns per statement. */
	private const MARK_PAGE_SIZE = 1000;


	public function __construct(
		private readonly IDBConnection         $db,
		private readonly IFilesMetadataManager $metadataManager,
		private readonly FilecacheService      $filecacheService,
		private readonly LoggerInterface       $logger,
		private readonly AlgorithmCatalogue    $catalogue,
	) {
	}


	public function &getHashes( int|File|IFilesMetadata $fileOrMetadata ): array
	{

		if ( ! $fileOrMetadata instanceof IFilesMetadata )
		{
			$fileOrMetadata = $this->getMetadata( $fileOrMetadata );
		}

		$hashes = [];


		// What the document holds, not what the catalogue currently allows: a
		// hash computed under an algorithm the administrator has since
		// disallowed is still this file's hash, and still what a backup,
		// a search or the sidebar should see.
		foreach ( $fileOrMetadata->getKeys() as $key )
		{
			if ( ! str_starts_with( $key, self::KEY_FILE_CHECKSUM_HASH_PREFIX ) )
			{
				continue;
			}

			try
			{
				$hashes[ (string) self::getAlgorithmenFromKey( $key ) ] = $fileOrMetadata->getString( $key );
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

			// Saving does not remove index rows for keys the metadata
			// document no longer has: it upserts what is present and leaves
			// the rest. Those orphans still answer searches and still form
			// duplicate groups, so a file whose hashes were cleared keeps
			// being found by hashes it no longer has.
			$this->pruneHashIndexRows( $metadata->getFileId() );
		}
	}


	/**
	 * Remove every trace of this app from a file that no longer exists.
	 *
	 * Not {@see clearMetadata()}, which is for a *live* file: that one puts
	 * `file-checksum-updated_at` back at zero so the file still records
	 * having been considered. For a deleted file that would leave one of our
	 * keys and one index row behind, and a sweep looking for exactly those
	 * would never finish.
	 *
	 * Only our own keys are removed. If the document holds nothing else
	 * afterwards it is deleted outright, through Nextcloud's own API, which
	 * drops the document and its index rows together — safe precisely
	 * because the set is empty, so nothing of another app's goes with it.
	 * A document that still holds another app's keys is kept and saved:
	 * Nextcloud does not delete an emptied document by itself
	 * ({@see IFilesMetadataManager::saveMetadata()} stores `{}`), and it is
	 * not ours to delete when it is not empty.
	 *
	 * @throws Exception
	 */
	public function purgeMetadata( int $fileId ): void
	{

		$metadata = $this->getMetadata( $fileId );

		$metadata->removeStartsWith( self::KEY_FILE_CHECKSUM_PREFIX );

		if ( $metadata->getKeys() === [] )
		{
			$this->metadataManager->deleteMetadata( $fileId );

			return;
		}

		$this->metadataManager->saveMetadata( $metadata );

		$this->pruneAllIndexRows( $fileId );
	}


	/**
	 * Index rows this app owns for one file, the stamp included.
	 *
	 * {@see pruneHashIndexRows()} spares `file-checksum-updated_at` because a
	 * live file still needs it. This one does not, and is only for a file
	 * that is gone.
	 *
	 * @throws Exception
	 */
	private function pruneAllIndexRows( int $fileId ): void
	{

		$qb = $this->db->getQueryBuilder();
		$qb->delete( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_FILE_ID, $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->like(
				      self::FIELD_META_KEY,
				      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_PREFIX . '%' ),
			      ),
		   )
		;

		$qb->executeStatement();
	}


	/**
	 * Files this app still has index rows for, whose file no longer exists.
	 *
	 * Found by their absence from the filecache rather than by anything we
	 * were told: Nextcloud's own metadata cleanup runs from
	 * `CacheEntriesRemovedEvent`, which the bulk teardown paths never
	 * dispatch — deleting a user, removing an external storage, dropping a
	 * group folder all delete filecache rows in a single statement and emit
	 * nothing. An anti-join needs no announcement and cannot be run too
	 * early: a file still present is simply not returned.
	 *
	 * Both tables are asked, because either can outlive the other. An index
	 * row without its document answers a hash search directly; a document
	 * without index rows is worse than inert, because `rebuild-from-metadata`
	 * builds index rows back out of documents — so purging only what the
	 * index still knows about would be undone by the next repair.
	 *
	 * @return list<int>
	 * @throws Exception
	 */
	public function fetchOrphanedFileIds( int $limit = 500 ): array
	{

		$fileIds = [];

		// Index rows whose file is gone.
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct( 'i.' . self::FIELD_FILE_ID )
		   ->from( self::TABLE_FILES_METADATA_INDEX, 'i' )
		   ->leftJoin( 'i', 'filecache', 'f', 'f.fileid = i.' . self::FIELD_FILE_ID )
		   ->where(
			   $qb->expr()
			      ->like(
				      'i.' . self::FIELD_META_KEY,
				      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_PREFIX . '%' ),
			      ),
		   )
		   ->andWhere( $qb->expr()->isNull( 'f.fileid' ) )
		   ->setMaxResults( $limit )
		;

		$result = $this->executeQuery( $qb );

		while ( ( $row = $result->fetch() ) !== false )
		{
			$fileIds[] = (int) $row[ self::FIELD_FILE_ID ];
		}

		$result->closeCursor();

		if ( count( $fileIds ) >= $limit )
		{
			return $fileIds;
		}

		// Documents whose file is gone. The pattern is the key prefix as it
		// appears in the serialised document, the same technique
		// {@see queryByHash()} uses on the same column: it can over-match,
		// and {@see purgeMetadata()} is unharmed by that — it removes our
		// keys, and a document holding none is left exactly as it was.
		$qb2 = $this->db->getQueryBuilder();
		$qb2->selectDistinct( 'm.' . self::FIELD_FILE_ID )
		    ->from( self::TABLE_FILES_METADATA, 'm' )
		    ->leftJoin( 'm', 'filecache', 'f', 'f.fileid = m.' . self::FIELD_FILE_ID )
		    ->where(
			    $qb2->expr()
			        ->like(
				        'm.' . self::FIELD_JSON,
				        $qb2->createNamedParameter(
					        '%' . $this->db->escapeLikeParameter( self::KEY_FILE_CHECKSUM_PREFIX ) . '%',
				        ),
			        ),
		    )
		    ->andWhere( $qb2->expr()->isNull( 'f.fileid' ) )
		    ->setMaxResults( $limit - count( $fileIds ) )
		;

		$result2 = $this->executeQuery( $qb2 );

		while ( ( $row = $result2->fetch() ) !== false )
		{
			$fileIds[] = (int) $row[ self::FIELD_FILE_ID ];
		}

		$result2->closeCursor();

		return array_values( array_unique( $fileIds ) );
	}


	/**
	 * Purge what {@see fetchOrphanedFileIds()} finds, one batch.
	 *
	 * @return int  Files purged.
	 */
	public function purgeOrphanedMetadata( int $batchLimit = 500 ): int
	{

		$purged = 0;

		foreach ( $this->fetchOrphanedFileIds( $batchLimit ) as $fileId )
		{
			try
			{
				$this->purgeMetadata( $fileId );
				$purged ++;
			}
			catch ( Throwable $e )
			{
				// One unreadable document must not strand the rest; the rows
				// stay, so the next run tries them again.
				$this->logger->warning(
					'FCIAS: could not purge orphaned metadata for fileId {fileId}.',
					[
						'app'       => Application::APP_ID,
						'fileId'    => $fileId,
						'exception' => $e,
					],
				);
			}
		}

		return $purged;
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


	/**
	 * Keep only the rows whose *full* hash really is the one searched for.
	 *
	 * {@see queryByHash()} compares against the index, which holds at most
	 * {@see META_VALUE_STRING_MAX_LENGTH} characters, so a long-hash lookup
	 * returns every file whose first 63 characters agree. Confirming the rest
	 * means reading each candidate's authoritative value from
	 * `oc_files_metadata.json`.
	 *
	 * A hash short enough to be stored whole was already compared in full, so
	 * those rows come back untouched and nothing is read.
	 *
	 * This lives here, and not in each caller, because it used to: the same
	 * two steps were written out in `ChecksumApi` and in `DuplicateService`,
	 * `HashSearchProvider` had no copy at all, and a check that is a habit
	 * rather than a function is one the next caller forgets.
	 *
	 * @param  list<array<string, mixed>>  $rows  Rows from {@see queryByHash()}.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function confirmFullHash(
		array  $rows,
		string $hash,
	): array {

		if ( ! self::isTruncatable( $hash ) )
		{
			return $rows;
		}

		$confirmed = [];

		foreach ( $rows as $row )
		{
			$fileId = (int) ( $row[ self::FIELD_FILE_ID ] ?? 0 );

			if ( $fileId === 0 )
			{
				continue;
			}

			if ( ( $this->extractAlgorithm( $fileId, $row )['hash'] ?? null ) === $hash )
			{
				$confirmed[] = $row;
			}
		}

		return $confirmed;
	}


	/**
	 * Whether a value held **in full** is longer than the index can store,
	 * and so was shortened on the way in.
	 *
	 * The question a searcher asks about its own search term.
	 */
	public static function isTruncatable( string $value ): bool
	{

		return strlen( $value ) > self::META_VALUE_STRING_MAX_LENGTH;
	}


	/**
	 * Whether a value read **from the index** may be a prefix rather than the
	 * whole thing.
	 *
	 * The mirror of {@see isTruncatable()}, asked from the other side: at
	 * the column's width there is no way to tell from the row alone, so
	 * anything that long needs its full form read from the metadata
	 * document before it can be trusted. Shorter than that, it was stored
	 * whole.
	 */
	public static function isPossiblyTruncated( string $storedValue ): bool
	{

		return strlen( $storedValue ) >= self::META_VALUE_STRING_MAX_LENGTH;
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

		// The metadata document has no hashes now, so neither may the
		// index: these keys are not indexed by Nextcloud, so nothing else
		// would remove them and the file would keep answering searches by
		// hashes it no longer has.
		$this->syncHashIndex( $fileId, [] );

		// After the save: saving regenerates the index row for updated_at,
		// which *is* indexed, so the string has to be written once the
		// regenerated row exists.
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
	 * Clear this app's hashes from files, now, one metadata document at a
	 * time.
	 *
	 * The synchronous counterpart to {@see markStale()}, for the operator who
	 * wants the rows gone before they walk away rather than whenever the
	 * drain next runs. It is the slow path by nature: `files_metadata_index`
	 * is regenerated from the `files_metadata` document, so hashes can only be
	 * removed by rewriting each of them — a bulk DELETE over the index
	 * would be undone the next time anything saved that file's metadata.
	 *
	 * Paged, and each file's failure is contained: one unreadable metadata
	 * document must not abandon the rest of the instance half-cleared.
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
		$lastId  = 0;

		// Keyset paging, not "always the first page". A file the clear cannot
		// finish stays in the index, and re-reading from the start would hand
		// it back for ever: the loop would never end and the log would fill
		// with the same failure. Moving past it guarantees the walk finishes
		// whatever any one file does.
		while ( true )
		{
			$fileIds = $this->pageHashedFileIdsAfter( $lastId, $batchSize );

			if ( $fileIds === [] )
			{
				break;
			}

			$lastId = $fileIds[ array_key_last( $fileIds ) ];

			foreach ( $fileIds as $fileId )
			{
				try
				{
					$this->clearMetadata( $fileId );
					$cleared ++;
				}
				catch ( Throwable $e )
				{
					// Nextcloud refuses to save a metadata document whose
					// file has no filecache row — it reads the storage id
					// from there and gets `false`. Such a row describes a
					// file that no longer exists, so dropping it outright
					// is the honest outcome rather than leaving it to
					// answer searches for ever.
					$this->logger->warning(
						'FCIAS: could not clear metadata for fileId {fileId}; dropping its index rows.',
						[
							'app'       => Application::APP_ID,
							'fileId'    => $fileId,
							'exception' => $e,
						],
					);

					try
					{
						$this->pruneHashIndexRows( $fileId );
						$cleared ++;
					}
					catch ( Throwable )
					{
						// Nothing further to try for this file; the walk goes on.
					}
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
	 * Read from the **metadata document**, never from the index: the index
	 * truncates a value at {@see META_VALUE_STRING_MAX_LENGTH} characters,
	 * which is shorter than a SHA-512 hash. A backup assembled from index
	 * rows would look complete and restore half a hash, so the index is
	 * used only to find *which* files have hashes — the answer it can give
	 * with one indexed scan — and the metadata document supplies the
	 * values.
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
	 * Keyset paging: `file_id > $afterFileId`, so the walk always moves
	 * forward. Paging by offset would skip rows as a caller deleted what it
	 * read, and re-reading the first page would never end if one row refused
	 * to go.
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
	 * @return array<int, string>  file id => the metadata document's JSON
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
		   )
		;

		$result = $this->executeQuery( $qb );
		$count  = (int) $result->fetchOne();
		$result->closeCursor();

		return $count;
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

		// Not "did the update affect a row?" — an UPDATE that sets a column
		// to the value it already holds reports zero affected rows on MySQL,
		// and treating that as "no row exists" inserts a duplicate. Ask
		// whether the row is there instead.
		$this->updateUpdatedAtString( $fileId, $value );

		if ( $this->hasUpdatedAtRow( $fileId ) )
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


	/**
	 * Whether the file has its `file-checksum-updated_at` index row.
	 *
	 * @throws Exception
	 */
	private function hasUpdatedAtRow( int $fileId ): bool
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select( self::FIELD_FILE_ID )
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_FILE_ID, $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->eq( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ) ),
		   )
		   ->setMaxResults( 1 )
		;

		$result = $this->executeQuery( $qb );
		$found  = $result->fetchOne();
		$result->closeCursor();

		return $found !== false && $found !== null;
	}


	private function updateUpdatedAtString(
		int    $fileId,
		string $value,
	): void {

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

		$this->executeStatement( $qb );
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
			$metaKey = self::getHashKey( $algo );
			$held    = $metadata->hasKey( $metaKey );

			if ( $held && $merge )
			{
				$report['skipped'] ++;

				continue;
			}

			// Not indexed by Nextcloud: it would write the full value into a
			// varchar(63) column and fail for every hash longer than that.
			// {@see syncHashIndex()} writes the row, truncated to fit.
			$metadata->setString( $metaKey, $hash, false );

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

		$hashes = $this->getHashes( $metadata );
		$this->metadataManager->saveMetadata( $metadata );
		$this->filecacheService->setHashes( $fileId, $hashes );
		$this->syncHashIndex( $fileId, $hashes );

		// After the save: saving regenerates the index row for updated_at, so
		// the string half has to be read and cleared once it exists again.
		$report['markerCleared'] = $this->clearStaleMarker( $fileId );

		return $report;
	}


	/**
	 * Match metadata documents that hold an actual hash, not merely a stamp.
	 *
	 * One `LIKE` on the hash prefix, which is what the prefix is for: it
	 * belongs to the hashes alone, so a metadata document holding nothing
	 * but the freshness stamp does not match it. `LIKE '%file-checksum-%'`
	 * did, which on a real instance was the difference between 455
	 * documents and the 302 that hold a hash — enough to make a count
	 * comparison never agree and a walk visit half again as many rows as it
	 * needed to.
	 *
	 * The old spelling still costs one `LIKE` per algorithm, because
	 * `file-checksum-` is exactly the ambiguous prefix this app moved away
	 * from and matching it would bring those false positives back. That price
	 * is paid by the repair's finder alone, which is what makes it worth
	 * paying.
	 *
	 * Both match the key as it appears in the metadata document, quoted. A
	 * value that happened to contain the same text would be a false
	 * positive costing one document read and nothing else: what the walk
	 * does next is decode it and ask which hashes it actually holds.
	 *
	 * @param  bool  $stamped  Which side of the stamp row to take: `true` for
	 *                         the files this app has considered, `false` for
	 *                         the ones it has forgotten.
	 *
	 * @throws Exception
	 */
	private function whereDocumentHoldsAHash(
		IQueryBuilder $qb,
		bool          $stamped = true,
	): void {

		// Narrowed to the files this app has considered. Every one of them
		// has a stamp row, and that row is reliable for a structural reason:
		// it is an INT in meta_value_int and never met the varchar(63) limit
		// that lost the hash rows. So it survived exactly the failure that
		// leaves a metadata document holding hashes the index has never
		// seen — which is what this walk is looking for.
		//
		// A file whose index rows were lost *entirely* has no stamp row
		// either, and is the `unindexed-hashes` step's to find: the same
		// scan, taken from the other side of this subquery.
		$considered = $this->db->getQueryBuilder();
		$considered->select( self::FIELD_FILE_ID )
		           ->from( self::TABLE_FILES_METADATA_INDEX )
		           ->where(
			           $considered->expr()
			                      ->eq(
				                      self::FIELD_META_KEY,
				                      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ),
			                      ),
		           )
		;

		$qb->andWhere(
			$stamped
				? $qb->expr()
				     ->in( self::FIELD_FILE_ID, $qb->createFunction( $considered->getSQL() ) )
				: $qb->expr()
				     ->notIn( self::FIELD_FILE_ID, $qb->createFunction( $considered->getSQL() ) ),
		);

		$patterns = [
			$qb->expr()
			   ->like(
				   self::FIELD_JSON,
				   $qb->createNamedParameter( '%"' . self::KEY_FILE_CHECKSUM_HASH_PREFIX . '%' ),
			   ),
		];

		foreach ( self::LEGACY_ALGOS as $algo )
		{
			// The old spelling, named one algorithm at a time. This is the
			// repair's finder, and a metadata document restored from before
			// the rename is exactly what it exists to find — a repair that
			// cannot recognise what it repairs is no use. Nothing on a
			// request path evaluates these: the only callers are
			// {@see reindexHashes()} and {@see reindexUnstampedHashes()}.
			$patterns[] = $qb->expr()
			                 ->like(
				                 self::FIELD_JSON,
				                 $qb->createNamedParameter( '%"' . self::legacyHashKey( $algo ) . '":%' ),
			                 )
			;
		}

		$qb->andWhere(
			$qb->expr()
			   ->orX( ...$patterns ),
		);
	}


	/**
	 * Whether every file whose metadata document mentions a hash has an index
	 * row.
	 *
	 * Two counts, to answer in a millisecond a question that otherwise costs
	 * a walk over every metadata document:
	 *
	 * - files the index knows a hash for;
	 * - metadata documents that mention one.
	 *
	 * Equal is the state after a completed pass. Fewer on the index side
	 * means work is outstanding.
	 *
	 * **It can only err towards doing the work.** A file with no index rows
	 * at all counts on one side and not the other, and that is the whole
	 * population this exists to find — every file affected by hashes that
	 * were too long for the column to accept. The reverse mistake is the one
	 * that matters and it cannot happen.
	 *
	 * Its limit, stated rather than buried: a file whose metadata document
	 * holds two algorithms while the index holds one counts once on each
	 * side, so this calls it complete. Only an interrupted pass can leave
	 * that, and the full pass is what answers it — which is why the caller
	 * can say it does not want to be asked.
	 *
	 * @throws Exception
	 */
	public function hashIndexIsComplete(): bool
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
		   )
		;

		$result  = $this->executeQuery( $qb );
		$indexed = (int) $result->fetchOne();
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias(
			$qb->func()
			   ->count( self::FIELD_FILE_ID ),
			'cnt',
		)
		   ->from( self::TABLE_FILES_METADATA )
		;

		$this->whereDocumentHoldsAHash( $qb );

		$result  = $this->executeQuery( $qb );
		$holding = (int) $result->fetchOne();
		$result->closeCursor();

		return $indexed >= $holding;
	}


	/**
	 * Rename every hash key still written the old way.
	 *
	 * Two statements per algorithm, and neither reads a metadata document:
	 *
	 * - the metadata documents are renamed by string replacement, because
	 *   `json` is a TEXT column on every backend — narrowed to the files the
	 *   index says still hold the old spelling, so the update touches those
	 *   rows and no others;
	 * - the index rows are renamed outright.
	 *
	 * The pattern carries its quotes and its colon — `"file-checksum-sha256":`
	 * — so it matches a key and can never match a value; values are hex
	 * digests and integers.
	 *
	 * Each half guards itself: nothing old-spelled means an empty id list and
	 * an update that touches nothing, so running this on a renamed instance
	 * costs eight cheap queries and no writes.
	 *
	 * Files whose hash rows were never written are invisible here, since
	 * there is no index row to find them by. Those are
	 * {@see reindexHashes()}'s to rename, and — where even the stamp row is
	 * gone — the `unindexed-hashes` step's.
	 *
	 * @return array{documents: int, rows: int}
	 * @throws Exception
	 */
	public function renameLegacyHashKeys(): array
	{

		$touched = [];
		$rows    = 0;

		foreach ( self::LEGACY_ALGOS as $algo )
		{
			$legacy  = self::legacyHashKey( $algo );
			$current = self::getHashKey( $algo );

			// Collected before the index is renamed, since that is what says
			// which metadata documents still need it.
			$fileIds = $this->fileIdsWithMetaKey( $legacy );

			foreach ( array_chunk( $fileIds, 1000 ) as $chunk )
			{
				$this->renameKeyInDocuments( $chunk, $legacy, $current );
			}

			// Counted as files, not as statements: a metadata document
			// holding four algorithms is updated four times and is still one.
			$touched += array_flip( $fileIds );
			$rows    += $this->renameIndexRows( $legacy, $current );
		}

		return [
			'documents' => count( $touched ),
			'rows'      => $rows,
		];
	}


	/**
	 * Which files have an index row under this key.
	 *
	 * @return list<int>
	 * @throws Exception
	 */
	private function fileIdsWithMetaKey( string $metaKey ): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct( self::FIELD_FILE_ID )
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_META_KEY, $qb->createNamedParameter( $metaKey ) ),
		   )
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
	 * Rewrite one key's name inside a chunk of metadata documents.
	 *
	 * `REPLACE` is implemented by every backend Nextcloud supports, and the
	 * column is TEXT on all of them, so this needs no JSON support from the
	 * database and no round trip through PHP.
	 *
	 * @param  list<int>  $fileIds
	 *
	 * @return int  Rows the statement changed.
	 * @throws Exception
	 */
	private function renameKeyInDocuments(
		array  $fileIds,
		string $legacy,
		string $current,
	): int {

		if ( $fileIds === [] )
		{
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update( self::TABLE_FILES_METADATA )
		   ->set(
			   self::FIELD_JSON,
			   $qb->createFunction(
				   sprintf(
					   'REPLACE(%s, %s, %s)',
					   self::FIELD_JSON,
					   $qb->createNamedParameter( '"' . $legacy . '":' ),
					   $qb->createNamedParameter( '"' . $current . '":' ),
				   ),
			   ),
		   )
		   ->where(
			   $qb->expr()
			      ->in(
				      self::FIELD_FILE_ID,
				      $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
			      ),
		   )
		;

		return $this->executeStatement( $qb );
	}


	/**
	 * @return int  Rows renamed.
	 * @throws Exception
	 */
	private function renameIndexRows(
		string $legacy,
		string $current,
	): int {

		$qb = $this->db->getQueryBuilder();
		$qb->update( self::TABLE_FILES_METADATA_INDEX )
		   ->set( self::FIELD_META_KEY, $qb->createNamedParameter( $current ) )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_META_KEY, $qb->createNamedParameter( $legacy ) ),
		   )
		;

		return $this->executeStatement( $qb );
	}


	/**
	 * Give every stored hash the index row it should always have had.
	 *
	 * The repair for instances that ran before the app took over writing
	 * these rows: the hashes are in the metadata documents, and for every
	 * algorithm longer than the column the row was never written, because
	 * Nextcloud's insert failed and it logged rather than raised.
	 *
	 * Walks the **metadata documents**, not the index. {@see
	 * exportHashes()} finds its files through the index, which is precisely
	 * what is missing here.
	 *
	 * Files whose rows already match are left alone, so a second run costs
	 * one query per page and no writes at all — which matters, because this
	 * runs on every repair.
	 *
	 * @param  callable|null  $progress  Called per page with (files seen, files fixed).
	 *
	 * @return int  Files whose index rows were rewritten.
	 * @throws Exception
	 */
	public function reindexHashes(
		int       $pageSize = 500,
		?callable $progress = null,
		bool      $force = false,
	): int {

		// The guard decides whether to walk, never what the walk repairs. If
		// anything is outstanding the full pass runs and fixes every file it
		// finds broken — a few or all of them. `$force` skips the asking, for
		// the caller who wants the pass regardless of what a count says.
		if ( ! $force && $this->hashIndexIsComplete() )
		{
			return 0;
		}

		return $this->walkHashDocuments(
			true,
			$pageSize,
			$progress,
			function (
				int    $fileId,
				string $json,
				array  $have,
			): bool {

				// Before reading it: a metadata document still in the old
				// spelling reads as holding no hashes at all, and syncing
				// from that would delete the very index rows this exists to
				// write.
				$json   = $this->renameLegacyKeysInDocument( $fileId, $json );
				$hashes = $this->getHashes( $this->getMetadata( $fileId, $json ) );
				$wanted = array_map(
					static fn(
						string $algo,
					): string => self::getHashKey( $algo ),
					array_keys( array_filter( $hashes, static fn(
						string $hash,
					): bool => $hash !== '' ) ),
				);

				sort( $wanted );
				sort( $have );

				if ( $wanted === $have )
				{
					return false;
				}

				$this->syncHashIndex( $fileId, $hashes );

				return true;
			},
		);
	}


	/**
	 * Give back the index rows of a file the index has forgotten entirely.
	 *
	 * The other walk finds its work among the files this app has considered,
	 * which the stamp row identifies. This one takes the same scan from the
	 * other side: a metadata document that holds a hash and has **no** stamp
	 * row at all. That file is invisible to every other correction path,
	 * because every one of them starts from a row it does not have — its
	 * hashes are stored, and nothing this app can be asked will find them.
	 *
	 * How a file gets there is not a bug this app still has. A restore that
	 * brought back `oc_files_metadata` without `oc_files_metadata_index`, an
	 * index truncated by hand, an interrupted migration: the causes are all
	 * outside, which is why there is no cheap question to ask about them.
	 * Finding out costs the scan, so the step that calls this never runs on
	 * its own — it is `manualOnly`, and an administrator asks for it.
	 *
	 * The stamp row goes back with the hash rows, from the value the
	 * metadata document itself carries. Without it the file would be
	 * repaired and still invisible to the next run of everything else.
	 *
	 * @param  callable|null  $progress  Called per page with (files seen, files fixed).
	 *
	 * @return int  Files given their index rows back.
	 * @throws Exception
	 */
	public function reindexUnstampedHashes(
		int       $pageSize = 500,
		?callable $progress = null,
	): int {

		return $this->walkHashDocuments(
			false,
			$pageSize,
			$progress,
			function (
				int    $fileId,
				string $json,
			): bool {

				$json     = $this->renameLegacyKeysInDocument( $fileId, $json );
				$metadata = $this->getMetadata( $fileId, $json );

				$this->syncHashIndex( $fileId, $this->getHashes( $metadata ) );
				$this->insertIndexRow(
					$fileId,
					self::KEY_FILE_CHECKSUM_UPDATED_AT,
					'',
					$this->getUpdatedAt( $metadata ) ?? 0,
				);

				return true;
			},
		);
	}


	/**
	 * Walk the metadata documents that hold a hash, a page at a time.
	 *
	 * Keyset paging, not `OFFSET`: the repair changes the rows it is walking
	 * over — the unstamped walk writes the very row that decides membership —
	 * and an offset over a shifting set skips work silently.
	 *
	 * @param  bool           $stamped   Which population to walk; see
	 *                                   {@see whereDocumentHoldsAHash()}.
	 * @param  callable|null  $progress  Called per page with (files seen, files fixed).
	 * @param  callable       $repair    `fn(int $fileId, string $json, list<string> $have): bool`,
	 *                                   given the hash keys the index already
	 *                                   holds for the file, returning whether
	 *                                   it changed anything.
	 *
	 * @return int  Files the repair reported as changed.
	 * @throws Exception
	 */
	private function walkHashDocuments(
		bool      $stamped,
		int       $pageSize,
		?callable $progress,
		callable  $repair,
	): int {

		$lastId = 0;
		$seen   = 0;
		$fixed  = 0;

		while ( true )
		{
			$documents = $this->pageHashDocumentsAfter( $lastId, $pageSize, $stamped );

			if ( $documents === [] )
			{
				return $fixed;
			}

			$lastId   = array_key_last( $documents );
			$seen     += count( $documents );
			$existing = $this->hashIndexKeysFor( array_keys( $documents ) );

			foreach ( $documents as $fileId => $json )
			{
				if ( $repair( $fileId, $json, $existing[ $fileId ] ?? [] ) )
				{
					$fixed ++;
				}
			}

			if ( $progress !== null )
			{
				$progress( $seen, $fixed );
			}
		}
	}


	/**
	 * Rename any legacy hash key inside one metadata document.
	 *
	 * The bulk rename finds its work through the index, so it cannot reach
	 * a file whose hash rows were never written — which is the population
	 * the index-truncation fix existed to rescue, and exactly what this
	 * walk meets. Here the metadata document is in hand, so the replacement
	 * is done in memory and written back as one statement.
	 *
	 * @return string  The metadata document as it now stands, renamed or unchanged.
	 * @throws Exception
	 */
	private function renameLegacyKeysInDocument(
		int    $fileId,
		string $json,
	): string {

		$renamed = $json;

		foreach ( self::LEGACY_ALGOS as $algo )
		{
			$renamed = str_replace(
				'"' . self::legacyHashKey( $algo ) . '":',
				'"' . self::getHashKey( $algo ) . '":',
				$renamed,
			);
		}

		if ( $renamed === $json )
		{
			return $json;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update( self::TABLE_FILES_METADATA )
		   ->set( self::FIELD_JSON, $qb->createNamedParameter( $renamed ) )
		   ->where(
			   $qb->expr()
			      ->eq( self::FIELD_FILE_ID, $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
		   )
		;

		$this->executeStatement( $qb );

		return $renamed;
	}


	/**
	 * One page of metadata documents that mention any of this app's hashes.
	 *
	 * `LIKE` on the metadata document rather than a join through the index,
	 * because the rows this is here to create are the ones that do not
	 * exist yet. It is a scan, once, over a table with one row per file
	 * that has any metadata at all — the price of having written nothing
	 * down.
	 *
	 * @return array<int, string>  file id => the metadata document's JSON, in id order
	 * @throws Exception
	 */
	private function pageHashDocumentsAfter(
		int  $afterFileId,
		int  $limit,
		bool $stamped = true,
	): array {

		$qb = $this->db->getQueryBuilder();
		$qb->select( self::FIELD_FILE_ID, self::FIELD_JSON )
		   ->from( self::TABLE_FILES_METADATA )
		   ->where(
			   $qb->expr()
			      ->gt(
				      self::FIELD_FILE_ID,
				      $qb->createNamedParameter( $afterFileId, IQueryBuilder::PARAM_INT ),
			      ),
		   )
		   ->orderBy( self::FIELD_FILE_ID, 'ASC' )
		   ->setMaxResults( $limit )
		;

		$this->whereDocumentHoldsAHash( $qb, $stamped );

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
	 * Which hash keys each of these files already has a row for.
	 *
	 * @param  list<int>  $fileIds
	 *
	 * @return array<int, list<string>>
	 * @throws Exception
	 */
	private function hashIndexKeysFor( array $fileIds ): array
	{

		if ( $fileIds === [] )
		{
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select( self::FIELD_FILE_ID, self::FIELD_META_KEY )
		   ->from( self::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->in(
				      self::FIELD_FILE_ID,
				      $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
			      ),
			   $qb->expr()
			      ->like( self::FIELD_META_KEY, $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_LIKE ) ),
		   )
		;

		$result = $this->executeQuery( $qb );
		$keys   = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$keys[ (int) $row[ self::FIELD_FILE_ID ] ][] = (string) $row[ self::FIELD_META_KEY ];
		}
		$result->closeCursor();

		return $keys;
	}


	/**
	 * Write this app's own index rows for a file's hashes.
	 *
	 * Nextcloud cannot do it: it indexes the value the metadata document
	 * holds, and `meta_value_string` is varchar(63) — shorter than four of
	 * the seven algorithms this app supports. So the hash keys are
	 * registered unindexed ({@see register()}) and the rows are written
	 * here instead, truncated to fit, exactly as {@see queryByHash()}
	 * truncates the term it searches for.
	 *
	 * A truncated row is recognisable without a marker: hex digests are
	 * even-length by construction — 8, 32, 40, 64, 128 — so nothing
	 * produces exactly 63 characters, and `meta_key` names the algorithm,
	 * so the full length is known. Callers confirm the full value from the
	 * metadata document where the length says they must.
	 * `MetadataServiceTest` guards the assumption.
	 *
	 * Called after `saveMetadata()`, like {@see upsertUpdatedAtString()}:
	 * saving regenerates the index rows for indexed keys, so a row written
	 * before it would be thrown away.
	 *
	 * Both directions: rows for algorithms the metadata document no longer
	 * has are removed. Nextcloud used to do that on save — it drops and
	 * re-inserts each indexed key — and now that these keys are not indexed
	 * it does not touch them at all, so a hash removed from the document
	 * would otherwise keep answering searches for ever.
	 *
	 * @param  array<string, string>  $algoToHash  The metadata document's hashes; empty removes every row.
	 *
	 * @return int  Rows written.
	 * @throws Exception
	 */
	public function syncHashIndex(
		int   $fileId,
		array $algoToHash,
	): int {

		$rows = [];

		foreach ( $algoToHash as $algo => $hash )
		{
			if ( $hash === '' )
			{
				continue;
			}

			$rows[ self::getHashKey( $algo ) ] = self::truncateForIndex( $hash );
		}

		// Delete then insert, rather than update-or-insert. An UPDATE that
		// sets a column to the value it already holds reports **zero** rows
		// affected on MySQL, so inferring "no row existed" from that count
		// inserts a duplicate — which is exactly what it did, and what left
		// two rows for the same key on a file hashed twice.
		$this->pruneHashIndexRows( $fileId );

		foreach ( $rows as $metaKey => $value )
		{
			$this->insertIndexRow( $fileId, $metaKey, $value );
		}

		return count( $rows );
	}


	/**
	 * One index row.
	 *
	 * Only ever called after {@see pruneHashIndexRows()} has cleared the
	 * file's rows, so there is nothing to update and nothing to race with
	 * beyond another process doing the same thing — which the unique
	 * constraint, if any, would settle either way.
	 *
	 * A hash row carries its value as a string and nothing in the int; the
	 * stamp row is the other way round, which is why `$intValue` is here.
	 *
	 * @throws Exception
	 */
	private function insertIndexRow(
		int    $fileId,
		string $metaKey,
		string $value,
		int    $intValue = 0,
	): void {

		$qb = $this->db->getQueryBuilder();
		$qb->insert( self::TABLE_FILES_METADATA_INDEX )
		   ->values(
			   [
				   self::FIELD_FILE_ID           => $qb->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ),
				   self::FIELD_META_KEY          => $qb->createNamedParameter( $metaKey ),
				   self::FIELD_META_VALUE_STRING => $qb->createNamedParameter( $value ),
				   self::FIELD_META_VALUE_INT    => $qb->createNamedParameter( $intValue, IQueryBuilder::PARAM_INT ),
			   ],
		   )
		;

		$this->executeStatement( $qb );
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

			// Not indexed by Nextcloud: it would write the full value into a
			// varchar(63) column and fail for every hash longer than that.
			// {@see syncHashIndex()} writes the row, truncated to fit.
			$metadata->setString( $metaKey, $hash, false );
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
		$this->syncHashIndex( $fileId, $this->getHashes( $metadata ) );

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
	 * @param  list<int>|null  $visibleStorageIds  The storages the asking user
	 *                                             has mounted, from
	 *                                             {@see IUserMountCache}. Null
	 *                                             for a caller that answers for
	 *                                             the instance rather than for
	 *                                             a person.
	 *
	 * @return array<int, array{file_id: int}>
	 */
	public function queryByHash(
		string     $hash,
		?string    $algo = null,
		int        $limit = 100,
		?array     $visibleStorageIds = null,
	): array {

		// A caller that will drop what its user cannot open has to say so
		// *here*, because the limit is applied by the database. Filtering
		// afterwards means the limit is spent on rows that are then thrown
		// away, and a user whose own file sorts behind enough unreachable
		// ones never sees it at all — with the unified search's default
		// limit of five, five foreign copies of a hash are enough to hide
		// somebody's own file from them.
		//
		// Like the JSON pattern below, this narrows and does not decide:
		// storage membership is not an authorisation test, because a share
		// of a subfolder mounts the owner's whole storage. The caller's
		// per-file check stays the authority. It cannot wrongly exclude,
		// because anything a user can see is in one of their mounts.
		if ( $visibleStorageIds !== null && $visibleStorageIds === [] )
		{
			return [];
		}

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

		// Chunked rather than unbounded: Oracle refuses an IN list beyond
		// 1000, and a user with more distinct storages than that is better
		// served by no narrowing at all than by a query that throws — the
		// per-file check still decides, so the only cost is the one this
		// narrowing exists to avoid.
		if ( $visibleStorageIds !== null && count( $visibleStorageIds ) <= 1000 )
		{
			$qb->innerJoin(
				'i',
				'filecache',
				'f',
				'f.fileid = i.' . self::FIELD_FILE_ID,
			)
			   ->andWhere(
				   $qb->expr()
				      ->in(
					      'f.storage',
					      $qb->createNamedParameter(
						      array_values( array_unique( $visibleStorageIds ) ),
						      IQueryBuilder::PARAM_INT_ARRAY,
					      ),
				      ),
			   )
			;
		}

		$this->andWhereNotStale( $qb );

		// Two paths, by length. A hash the index stored whole was compared
		// whole above and there is nothing left to ask.
		//
		// A longer one matched on its first 63 characters only, so the
		// metadata document is asked as well — server-side, before the row
		// is sent. The pattern is the bare hash rather than the key and
		// value it sits in: a hex digest appears verbatim in the metadata
		// document however Nextcloud serialises it, so this **cannot**
		// exclude a file that really holds the hash, whatever changes
		// around it. It can over-match, which is why {@see
		// confirmFullHash()} stays the authority — this narrows, it does
		// not decide. What it saves is fetching, transporting and decoding
		// a metadata document only to throw the row away in PHP.
		if ( self::isTruncatable( $hash ) )
		{
			$qb->andWhere(
				$qb->expr()
				   ->like(
					   'm.' . self::FIELD_JSON,
					   $qb->createNamedParameter( '%' . $this->db->escapeLikeParameter( $hash ) . '%' ),
				   ),
			);
		}

		if ( $algo !== null && $algo !== '' )
		{
			$qb->andWhere(
				$qb->expr()
				   ->eq(
					   'i.' . self::FIELD_META_KEY,
					   $qb->createNamedParameter( self::getHashKey( $algo ) ),
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
			      ->like(
				      'i.' . self::FIELD_META_KEY,
				      $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_LIKE ),
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
					   $qb->createNamedParameter( self::getHashKey( $algo ) ),
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
			if ( ! self::isPossiblyTruncated( $group[ self::FIELD_META_VALUE_STRING ] ) )
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

		// Repeated because one pass does not always stick. Nextcloud stores
		// the declarations in one lazy app-config value and rewrites the
		// whole of it per key; on an instance that already had these keys
		// declared, the first pass over eight of them landed four. Reading
		// back and going again is the only way to know it took — and once it
		// has, every pass after the first is a no-op Nextcloud returns from
		// immediately.
		for ( $pass = 0; $pass < self::REGISTER_PASSES; $pass ++ )
		{
			foreach ( $this->registeredAlgos() as $algo )
			{
				// Registered **unindexed**, and indexed by this app instead
				// — see {@see syncHashIndex()}. Nextcloud writes the value
				// it finds in the metadata document, and
				// `meta_value_string` is varchar(63): a SHA-256 is 64
				// characters and a SHA-512 is 128, so the insert fails, and
				// `IndexRequestService::updateIndex()` swallows that as a
				// logged warning. The row is simply never written, and
				// searching for one of those hashes finds nothing at all.
				$this->metadataManager->initMetadata(
					self::getHashKey( $algo ),
					IMetadataValueWrapper::TYPE_STRING,
					false,
					IMetadataValueWrapper::EDIT_FORBIDDEN,
				);
			}

			// The stamp *is* Nextcloud's to index: it is an integer, it fits,
			// and the queue and the freshness checks read it from the index.
			$this->metadataManager->initMetadata(
				self::KEY_FILE_CHECKSUM_UPDATED_AT,
				IMetadataValueWrapper::TYPE_INT,
				true,
				IMetadataValueWrapper::EDIT_FORBIDDEN,
			);

			if ( $this->hashKeysAreUnindexed() )
			{
				break;
			}
		}

		$this->logger->debug(
			'FCIAS MetadataService: registered metadata keys',
			[
				'app'     => Application::APP_ID,
				'passes'  => $pass + 1,
				'settled' => $this->hashKeysAreUnindexed(),
			],
		);
	}



	/**
	 * The keys Nextcloud must know about: every algorithm in force plus every
	 * one a previous release wrote, so a key that still exists in some
	 * document stays a known, indexed key however the allowlist changes.
	 *
	 * @return list<string>
	 */
	private function registeredAlgos(): array
	{

		return array_values( array_unique( array_merge( self::LEGACY_ALGOS, $this->catalogue->algorithms() ) ) );
	}

	/**
	 * Whether Nextcloud has taken the hash keys off its own index.
	 *
	 * A key it still believes it owns is one whose row it will keep trying,
	 * and failing, to write.
	 */
	private function hashKeysAreUnindexed(): bool
	{

		$known = $this->metadataManager->getKnownMetadata();

		foreach ( $this->registeredAlgos() as $algo )
		{
			if ( $known->isIndex( self::getHashKey( $algo ) ) )
			{
				return false;
			}
		}

		return true;
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

		$hashes = $this->getHashes( $metadata );
		$this->filecacheService->setHashes( $file ?? $metadata->getFileId(), $hashes );
		$this->syncHashIndex( $metadata->getFileId(), $hashes );
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

		// The hash prefix first: stripping the shorter one from
		// `file-checksum-hash-sha256` would leave `hash-sha256`. A key still
		// in the old spelling falls through to the second replacement, which
		// is what lets the repair read one before renaming it.
		return str_replace(
			[
				MetadataService::KEY_FILE_CHECKSUM_HASH_PREFIX,
				MetadataService::KEY_FILE_CHECKSUM_PREFIX,
			],
			'',
			$metaKey,
		);
	}


	/**
	 * @param  string  $algo
	 *
	 * @return string
	 */
	public static function getHashKey( string $algo ): string
	{

		return self::KEY_FILE_CHECKSUM_HASH_PREFIX . strtolower( $algo );
	}


	/**
	 * What a hash key was called before it had a prefix of its own.
	 *
	 * Only the repair knows this: it is what `key-namespace` renames, and
	 * what `rebuild-from-metadata` must still recognise in a metadata
	 * document restored from before the rename. Nothing on a request path
	 * asks for it.
	 */
	public static function legacyHashKey( string $algo ): string
	{

		return self::KEY_FILE_CHECKSUM_PREFIX . strtolower( $algo );
	}


	/**
	 * Read a search term of the form `<algo>:<hash>`, or a bare hash.
	 *
	 * The algorithm half is matched against what a name may look like
	 * ({@see AlgorithmCatalogue::NAME_PATTERN}), not against what this
	 * instance currently computes: whether `sha3-256` is allowed today has
	 * no bearing on whether somebody may search for a hash stored under it,
	 * and the caller is better placed to say what it does with an algorithm
	 * it does not recognise. The hyphen is why — the class used to be
	 * `[a-zA-F0-9]`, which quietly rejected every hyphenated name and every
	 * uppercase prefix, so `sha3-256:…` and `SHA256:…` were "not a hash".
	 *
	 * A term that is not a hash at all is `null` rather than an exception:
	 * every caller is a search box, where "this is not a hash" is an answer.
	 *
	 * @return array{algo: string, hash: string}|null  Lower-cased; `algo` is
	 *                                                 empty for a bare hash.
	 */
	public static function parseQueryTerm( string $term ): ?array
	{

		if ( ! preg_match( '/^(?:([a-zA-Z0-9-]+):)?([a-fA-F0-9]{8,128})$/', $term, $matches ) )
		{
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
