<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Encryption\IManager as IEncryptionManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Filecache checksum read/write bridge.
 *
 * Reads and writes hash values in Nextcloud's native filecache.checksum
 * column (format: "ALGO:hex ALGO:hex ...").  Also provides Node resolution
 * helpers (by filecache ID), batch path lookups via filecache+storages join,
 * and checksum-copy support for NodeCopiedEvent.
 */
class FilecacheService
{

	public const CHECKSUM_MAX_LENGTH = 255;

	/**
	 * Storage id => numeric id, for the length of one request.
	 *
	 * A backup of a home folder names the same storage on every one of its
	 * records; without this, resolving it would ask the same question once
	 * per thousand paths.
	 *
	 * @var array<string, int|null>
	 */
	private array $storageNumericIds = [];


	public function __construct(
		private readonly IRootFolder        $rootFolder,
		private readonly IDBConnection      $db,
		private readonly IConfig            $config,
		private readonly IUserManager       $userManager,
		private readonly IEncryptionManager $encryption,
	) {
	}


	/**
	 * @noinspection PhpDocMissingThrowsInspection
	 * @throws \OCP\Files\NotFoundException
	 */
	public function getFile( int|File $file ): File
	{

		/** @noinspection PhpUnhandledExceptionInspection */
		if ( $file instanceof File )
		{
			return $file;
		}

		$node = $this->rootFolder->getFirstNodeById( $file );

		if ( $node instanceof File )
		{
			return $node;
		}

		throw  new NotFoundException( "Invalid Filecache ID: $file" );
	}


	/**
	 * The checksums Nextcloud itself holds for a file, as algo => hex.
	 *
	 * Not this app's hashes: this is the filecache's own column, which is
	 * where an upload's checksum lands and what the backfill adopts. A null
	 * filter means everything; a filter is matched against **lowercase**
	 * algorithm names, so asking for `SHA256` returns nothing.
	 */
	public function getChecksums(
		int|File $file,
		?array   $hashFilter = null,
	): array {

		$file   = $this->getFile( $file );
		$hashes = self::parseChecksumString( $file->getChecksum() ?? '' );

		if ( $hashFilter === null )
		{
			return $hashes;
		}

		return array_intersect_key( $hashes, array_flip( $hashFilter ) );
	}


	/**
	 * Parse a filecache checksum column value ("ALGO:hex ALGO:hex ...") into
	 * lowercase algo => hex pairs. Malformed fragments are skipped rather
	 * than fatal — the column is free text as far as the database cares.
	 *
	 * @return array<string, string>
	 */
	public static function parseChecksumString( string $checksum ): array
	{

		$hashes = [];

		foreach ( explode( ' ', $checksum ) as $pair )
		{
			if ( ! str_contains( $pair, ':' ) )
			{
				continue;
			}

			[
				$algoUpper,
				$hash,
			]
				= explode( ':', $pair, 2 );

			if ( $algoUpper === '' || $hash === '' )
			{
				continue;
			}

			$hashes[ strtolower( $algoUpper ) ] = $hash;
		}

		return $hashes;
	}


	/** Cache for {@see directoryMimetypeId()}. */
	private ?int $directoryMimetypeId = null;


	/**
	 * The mimetype id of httpd/unix-directory — per-instance, so looked up
	 * once and cached for the process.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function directoryMimetypeId(): int
	{

		if ( $this->directoryMimetypeId !== null )
		{
			return $this->directoryMimetypeId;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'id' )
		   ->from( 'mimetypes' )
		   ->where(
			   $qb->expr()
			      ->eq( 'mimetype', $qb->createNamedParameter( 'httpd/unix-directory' ) ),
		   )
		;

		$result = $qb->executeQuery();
		$id     = $result->fetchOne();
		$result->closeCursor();

		// -1 can never equal a real mimetype id, so a missing row (an
		// instance that has never indexed a folder) filters nothing out.
		return $this->directoryMimetypeId = $id === false
			? - 1
			: (int) $id;
	}


	/**
	 * A file's canonical identity — its actual filecache row, classified.
	 *
	 * One indexed query, and deliberately NOT derived from a Node: a Node
	 * from a share recipient's context reports the recipient's view path
	 * and a wrapper storage, while the row is the single truth every view
	 * resolves to.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function locate( int $fileId ): ?FileLocation
	{

		return $this->locateAll( [ $fileId ] )[ $fileId ] ?? null;
	}


	/**
	 * The canonical identities of many files in one scan.
	 *
	 * The batch face of {@see locate()}, for loops that resolve verdicts
	 * per file: one IN() query per thousand ids instead of one round-trip
	 * each. Ids without a filecache row are simply absent from the result.
	 *
	 * @param  list<int>  $fileIds
	 *
	 * @return array<int, FileLocation>  keyed by file id
	 * @throws \OCP\DB\Exception
	 */
	public function locateAll( array $fileIds ): array
	{

		$locations = [];

		// 1000 per IN(): Oracle's placeholder ceiling, and the chunk size
		// Nextcloud itself uses.
		foreach (
			array_chunk(
				array_values( array_unique( array_map( intval( ... ), $fileIds ) ) ),
				1000,
			) as $chunk
		)
		{
			$qb = $this->db->getQueryBuilder();
			$qb->select( 'fc.fileid', 'fc.path', 'fc.mtime', 'st.id' )
			   ->from( 'filecache', 'fc' )
			   ->innerJoin( 'fc', 'storages', 'st', 'fc.storage = st.numeric_id' )
			   ->where(
				   $qb->expr()
				      ->in(
					      'fc.fileid',
					      $qb->createNamedParameter( $chunk, IQueryBuilder::PARAM_INT_ARRAY ),
				      ),
			   )
			;

			$result = $qb->executeQuery();

			while ( ( $row = $result->fetch() ) !== false )
			{
				$location = FileLocation::fromRow(
					(int) $row['fileid'],
					(string) $row['id'],
					(string) $row['path'],
					(int) $row['mtime'],
				);

				$locations[ $location->fileId ] = $location;
			}
			$result->closeCursor();
		}

		return $locations;
	}


	/**
	 * Resolve portable identities back to this instance's file ids.
	 *
	 * The inverse of {@see locateAll()}, and the step on which an import
	 * lives or dies: a record names a file by the storage it lives on and
	 * the path inside it, because a file id means nothing outside the
	 * instance that issued it. A pair with no filecache row is a file this
	 * instance does not have — it is reported, never created.
	 *
	 * Storage ids are looked up once and cached for the call: a backup of a
	 * home folder names the same storage on every one of its records, and
	 * joining `storages` per chunk would ask the same question thousands of
	 * times.
	 *
	 * @param  array<string, list<string>>  $pathsByStorage  storage id => internal paths
	 *
	 * @return array<string, FileLocation>  "<storage>\0<path>" => the row it names
	 * @throws \OCP\DB\Exception
	 */
	public function locateAllByPath( array $pathsByStorage ): array
	{

		$located = [];

		foreach ( $pathsByStorage as $storageId => $paths )
		{
			$numericId = $this->storageNumericId( $storageId );

			if ( $numericId === null )
			{
				continue;
			}

			// 1000 per IN(): Oracle's placeholder ceiling, and the chunk size
			// Nextcloud itself uses.
			foreach ( array_chunk( array_values( array_unique( $paths ) ), 1000 ) as $chunk )
			{
				$qb = $this->db->getQueryBuilder();
				$qb->select( 'fileid', 'path', 'mtime' )
				   ->from( 'filecache' )
				   ->where(
					   $qb->expr()
					      ->eq( 'storage', $qb->createNamedParameter( $numericId, IQueryBuilder::PARAM_INT ) ),
					   $qb->expr()
					      ->in( 'path', $qb->createNamedParameter( $chunk, IQueryBuilder::PARAM_STR_ARRAY ) ),
				   )
				;

				$result = $qb->executeQuery();

				while ( ( $row = $result->fetch() ) !== false )
				{
					$location = FileLocation::fromRow(
						(int) $row['fileid'],
						$storageId,
						(string) $row['path'],
						(int) $row['mtime'],
					);

					$located[ self::identityKey( $storageId, $location->internalPath ) ] = $location;
				}
				$result->closeCursor();
			}
		}

		return $located;
	}


	/**
	 * The key both directions of an import agree on.
	 *
	 * A NUL separator because it is the one byte a storage id and a path
	 * cannot contain, so no two different pairs can collide on it.
	 */
	public static function identityKey(
		string $storageId,
		string $path,
	): string {

		return $storageId . "\0" . $path;
	}


	/**
	 * A storage's numeric id, or null where this instance has no such
	 * storage — which is the honest answer for a backup taken elsewhere.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function storageNumericId( string $storageId ): ?int
	{

		if ( array_key_exists( $storageId, $this->storageNumericIds ) )
		{
			return $this->storageNumericIds[ $storageId ];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'numeric_id' )
		   ->from( 'storages' )
		   ->where(
			   $qb->expr()
			      ->eq( 'id', $qb->createNamedParameter( $storageId ) ),
		   )
		   ->setMaxResults( 1 )
		;

		$result = $qb->executeQuery();
		$found  = $result->fetchOne();
		$result->closeCursor();

		return $this->storageNumericIds[ $storageId ] = $found === false || $found === null
			? null
			: (int) $found;
	}


	/**
	 * The storages a rule could sensibly address by raw id.
	 *
	 * The coverage view's third question — after "are home folders ruled
	 * on?" and "is each group folder?" — is "and what about everything
	 * else that is mounted?". Home storages answer to `home:*` and group
	 * folder jails to `groupfolder:<id>`, so both are excluded here; share
	 * wrappers (`shared::…`) are per-mount views of a file that already
	 * lives somewhere else; and the instance root holds appdata and jails
	 * rather than a files area of its own, so no rule could ever match in
	 * it ({@see FileLocation}). What remains is external mounts and the
	 * like: namespaces only `storage:<id>` or `*` reaches.
	 *
	 * @return list<string>  Raw storage ids, sorted.
	 * @throws \OCP\DB\Exception
	 */
	public function listAddressableStorages(): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'id' )
		   ->from( 'storages' )
		   ->where(
			   $qb->expr()
			      ->notLike( 'id', $qb->createNamedParameter( 'home::%' ) ),
			   $qb->expr()
			      ->notLike( 'id', $qb->createNamedParameter( 'object::user:%' ) ),
			   $qb->expr()
			      ->notLike( 'id', $qb->createNamedParameter( 'shared::%' ) ),
		   )
		   ->orderBy( 'id', 'ASC' )
		;

		$dataRoot = rtrim(
			$this->config->getSystemValue( 'datadirectory', '' ),
			'/',
		);

		$result   = $qb->executeQuery();
		$storages = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$storageId = (string) $row['id'];

			// The instance root: appdata and (legacy) group folder jails,
			// no files area of its own.
			if ( $dataRoot !== '' && rtrim( $storageId, '/' ) === 'local::' . $dataRoot )
			{
				continue;
			}

			// A group folder's own jail — addressed as groupfolder:<id>.
			if ( preg_match( '#^local::.*/__groupfolders/\d+/?$#', $storageId ) === 1 )
			{
				continue;
			}

			$storages[] = $storageId;
		}
		$result->closeCursor();

		return $storages;
	}


	/**
	 * The numeric ids of the given users' home storages.
	 *
	 * Each uid has at most one: `home::<uid>` on filesystem-backed
	 * instances, `object::user:<uid>` on primary object storage. Kept
	 * separate from {@see storageNumericIdsFor()} because expanding a
	 * group selector to member uids needs the group manager, which lives
	 * a layer above this service.
	 *
	 * @param  string[]  $uids
	 *
	 * @return int[]
	 * @throws \OCP\DB\Exception
	 */
	public function homeStorageNumericIds( array $uids ): array
	{

		if ( $uids === [] )
		{
			return [];
		}

		$storageIds = [];

		foreach ( $uids as $uid )
		{
			$storageIds[] = 'home::' . $uid;
			$storageIds[] = 'object::user:' . $uid;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'numeric_id' )
		   ->from( 'storages' )
		   ->where(
			   $qb->expr()
			      ->in(
				      'id',
				      $qb->createNamedParameter( $storageIds, IQueryBuilder::PARAM_STR_ARRAY ),
			      ),
		   )
		;

		$result = $qb->executeQuery();
		$ids    = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$ids[] = (int) $row['numeric_id'];
		}
		$result->closeCursor();

		return $ids;
	}


	/**
	 * The numeric storage ids a non-group selector sweeps.
	 *
	 * home:* is every home storage (matched by id prefix — cheaper and no
	 * less exact than enumerating users). groupfolder:<id> is the folder's
	 * dedicated jail storage plus, for the legacy layout, any local root
	 * storage — whose rows are told apart per row at classification time.
	 * storage:<raw> is an exact id. '*' is every storage there is: rows are
	 * unique per file, so sweeping all storages never double-counts.
	 *
	 * user: and group: selectors resolve via {@see homeStorageNumericIds()}.
	 *
	 * @return int[]
	 * @throws \OCP\DB\Exception
	 */
	public function storageNumericIdsFor( Selector $selector ): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'numeric_id', 'id' )
		   ->from( 'storages' )
		;

		switch ( $selector->kind )
		{
		case Selector::KIND_STORAGE:
			$qb->where(
				$qb->expr()
				   ->eq( 'id', $qb->createNamedParameter( (string) $selector->target ) ),
			);

			break;

		case Selector::KIND_HOME_ALL:
			$qb->where(
				$qb->expr()
				   ->orX(
					   $qb->expr()
					      ->like( 'id', $qb->createNamedParameter( 'home::%' ) ),
					   $qb->expr()
					      ->like( 'id', $qb->createNamedParameter( 'object::user:%' ) ),
				   ),
			);

			break;
		}

		$result = $qb->executeQuery();
		$ids    = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$storageId = (string) $row['id'];

			if ( $selector->kind === Selector::KIND_GROUPFOLDER )
			{
				$isJail      = preg_match(
						'#^local::.*/__groupfolders/' . (int) $selector->target . '/?$#',
						$storageId,
					) === 1;
				$isLocalRoot = str_starts_with( $storageId, 'local::' )
					&& ! str_contains( $storageId, '__groupfolders' );

				// The jail is the folder; the root storage may hold legacy
				// rows, told apart per row at classification time.
				if ( ! $isJail && ! $isLocalRoot )
				{
					continue;
				}
			}

			$ids[] = (int) $row['numeric_id'];
		}
		$result->closeCursor();

		return $ids;
	}


	/**
	 * One page of a storage's filecache rows, classified — for non-home
	 * sweeps, which iterate the storage once instead of once per member
	 * view. Keyset-paged like the backfill.
	 *
	 * @return FileLocation[]
	 * @throws \OCP\DB\Exception
	 */
	/**
	 * A page of a storage's files, keyset-ordered by file id.
	 *
	 * Each row carries this app's `updated_at` stamp from the metadata index
	 * (null when it never hashed the file), joined here so a sweep judges
	 * freshness without a query per file. `$staleOnly` keeps only the rows a
	 * sweep would act on — no stamp, or a stamp older than the file's mtime —
	 * so a sweep of an already-hashed instance fetches nothing rather than
	 * every file. The stamp is one row of the index (`meta_key =
	 * file-checksum-updated_at`); the LEFT JOIN pins that key.
	 */
	public function pageStorageFiles(
		int  $storageNumericId,
		int  $lastFileId,
		int  $limit,
		bool $staleOnly = false,
	): array {

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'fc.fileid', 'fc.path', 'fc.mtime', 'st.id', 'mu.' . MetadataService::FIELD_META_VALUE_INT . ' AS updated_at' )
		   ->from( 'filecache', 'fc' )
		   ->innerJoin( 'fc', 'storages', 'st', 'fc.storage = st.numeric_id' )
		   ->leftJoin(
			   'fc',
			   MetadataService::TABLE_FILES_METADATA_INDEX,
			   'mu',
			   $qb->expr()
			      ->andX(
				      $qb->expr()->eq( 'mu.' . MetadataService::FIELD_FILE_ID, 'fc.fileid' ),
				      $qb->expr()->eq(
					      'mu.' . MetadataService::FIELD_META_KEY,
					      $qb->createNamedParameter( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT ),
				      ),
			      ),
		   )
		   ->where(
			   $qb->expr()
			      ->eq( 'fc.storage', $qb->createNamedParameter( $storageNumericId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->gt( 'fc.fileid', $qb->createNamedParameter( $lastFileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->neq(
				      'fc.mimetype',
				      $qb->createNamedParameter( $this->directoryMimetypeId(), IQueryBuilder::PARAM_INT ),
			      ),
		   )
		   ->orderBy( 'fc.fileid', 'ASC' )
		   ->setMaxResults( $limit )
		;

		if ( $staleOnly )
		{
			// No stamp at all, or one older than the file — the two states a
			// sweep exists to fix. A fresh file is filtered out here rather
			// than fetched and skipped.
			$qb->andWhere(
				$qb->expr()
				   ->orX(
					   $qb->expr()->isNull( 'mu.' . MetadataService::FIELD_META_VALUE_INT ),
					   $qb->expr()->lt( 'mu.' . MetadataService::FIELD_META_VALUE_INT, 'fc.mtime' ),
				   ),
			);
		}

		$result    = $qb->executeQuery();
		$locations = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$locations[] = FileLocation::fromRow(
				(int) $row['fileid'],
				(string) $row['id'],
				(string) $row['path'],
				(int) $row['mtime'],
			)->withUpdatedAt( $row['updated_at'] !== null ? (int) $row['updated_at'] : null );
		}
		$result->closeCursor();

		return $locations;
	}


	/**
	 * One page of filecache rows that carry a checksum, for backfilling.
	 *
	 * Keyset pagination (fileid > $lastFileId, ordered ascending) so a full
	 * sweep over a large instance never re-reads or skips rows regardless of
	 * concurrent inserts.
	 *
	 * @return array<int, array{checksum: string, mtime: int}>  Keyed by fileid
	 * @throws \OCP\DB\Exception
	 */
	public function pageFileidChecksums(
		int $lastFileId,
		int $limit,
	): array {

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'fileid', 'checksum', 'mtime' )
		   ->from( 'filecache' )
		   ->where(
			   $qb->expr()
			      ->gt( 'fileid', $qb->createNamedParameter( $lastFileId, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()
			      ->isNotNull( 'checksum' ),
			   $qb->expr()
			      ->neq( 'checksum', $qb->createNamedParameter( '' ) ),
		   )
		   ->orderBy( 'fileid', 'ASC' )
		   ->setMaxResults( $limit )
		;

		$result = $qb->executeQuery();
		$rows   = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$rows[ (int) $row['fileid'] ] = [
				'checksum' => (string) $row['checksum'],
				'mtime'    => (int) $row['mtime'],
			];
		}
		$result->closeCursor();

		return $rows;
	}


	/**
	 * Resolve a fileid to a node, searching every storage.
	 *
	 * Two things a caller must handle. It throws rather than returning null
	 * when nothing matches, and it returns a Node — a folder resolves as
	 * happily as a file, so anything that means to hash the result has to
	 * check that it got a File.
	 *
	 * This bypasses per-user reachability by design; a caller answering a
	 * request must apply its own, normally by resolving through the user's
	 * folder instead.
	 *
	 * @throws NotFoundException  No file with this id, in any storage.
	 */
	/**
	 * The sizes the filecache records for these files, by id.
	 *
	 * Known before anything is read, which is the point: a caller about to
	 * read several files can tell what that will cost and stop at a budget,
	 * rather than discover the cost by paying it. Ids the filecache does
	 * not know are absent from the answer.
	 *
	 * @param  int[]  $fileIds
	 *
	 * @return array<int, int>
	 * @throws \OCP\DB\Exception
	 */
	public function fileSizes( array $fileIds ): array
	{

		if ( $fileIds === [] )
		{
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'fileid', 'size' )
		   ->from( 'filecache' )
		   ->where(
			   $qb->expr()
			      ->in(
				      'fileid',
				      $qb->createNamedParameter( array_values( $fileIds ), IQueryBuilder::PARAM_INT_ARRAY ),
			      ),
		   )
		;

		$result = $qb->executeQuery();
		$sizes  = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$sizes[ (int) $row['fileid'] ] = max( 0, (int) $row['size'] );
		}
		$result->closeCursor();

		return $sizes;
	}


	public function getNodeById( int $fileId ): Node
	{

		$nodes = $this->rootFolder->getById( $fileId );

		if ( empty( $nodes ) )
		{
			throw new NotFoundException( "Invalid file ID: $fileId" );
		}

		return $nodes[0];

	}


	/**
	 * @param  string  $userId
	 *
	 * @return \OCP\Files\Folder
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OC\User\NoUserException  If $userId doesn't resolve to a real user.
	 *                                   Callers that can't use OCP\Server::get()
	 *                                   internals should catch \Throwable instead
	 *                                   of this internal class — see
	 *                                   RuleService::ruleTargetRefusal() and
	 *                                   HashFiles::executeMarkOnly() for the
	 *                                   established pattern.
	 */
	public function getUserFolder( string $userId ): Folder
	{

		return $this->rootFolder->getUserFolder( $userId );
	}


	/**
	 * @param  string  $userId
	 *
	 * @return string  The user folder's absolute path.
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OC\User\NoUserException  No such account. Private, because OCP
	 *                                   has no public class for it.
	 */
	public function getUserFolderPath( string $userId ): string
	{

		$userFolder = $this->getUserFolder( $userId );

		return $userFolder->getPath();
	}


	/**
	 * Write hashes into the filecache's checksum column.
	 *
	 * The column is one string shared with whatever else writes checksums,
	 * so $keepAdditional decides whether this is a merge or a replacement.
	 * True keeps the algorithms already there that $hashes does not mention,
	 * with $hashes winning where both name one. False replaces the lot —
	 * and $hashes of null with false is how the column is cleared.
	 *
	 * The column has a fixed width, so a merge that would overflow it drops
	 * whole pairs rather than truncating one: half a hash would read as a
	 * hash. What is dropped is not reported, because nothing above this
	 * could act on it — the values live in this app's own metadata too.
	 */
	public function setHashes(
		int|File $file,
		?array   $hashes = null,
		bool     $keepAdditional = false,
	): void {

		$file = $this->getFile( $file );

		$existingHashes = $keepAdditional
			? $this->getChecksums( $file )
			: [];

		$newHashes = [];
		$hashes    ??= [];

		foreach ( $hashes as $algo => $hash )
		{
			$algoUpper   = strtoupper( $algo );
			$newHashes[] = "$algoUpper:$hash";
			if ( $keepAdditional )
			{
				unset( $existingHashes[ $algo ] );
			}
		}

		if ( $keepAdditional )
		{
			foreach ( $existingHashes as $algo => $hash )
			{
				$algoUpper   = strtoupper( $algo );
				$newHashes[] = "$algoUpper:$hash";
			}
		}

		$file->getStorage()
		     ->getCache()
		     ->update( $file->getId(), [ 'checksum' => self::fitChecksumPairs( $newHashes ) ] )
		;
	}


	/**
	 * Build a checksum string from "ALGO:hash" pairs, keeping pairs in the
	 * given order and dropping any that would exceed the filecache.checksum
	 * column limit. A pair that does not fit is omitted entirely (never
	 * truncated mid-hash).
	 *
	 * @param  list<string>  $pairs
	 *
	 * @return string
	 */
	public static function fitChecksumPairs( array $pairs ): string
	{

		$checksum = '';

		foreach ( $pairs as $pair )
		{
			$candidate = $checksum === ''
				? $pair
				: "$checksum $pair";

			if ( strlen( $candidate ) > self::CHECKSUM_MAX_LENGTH )
			{
				continue;
			}

			$checksum = $candidate;
		}

		return $checksum;
	}


	/**
	 * Copy the filecache checksum from source to target file.
	 *
	 * Used by NodeCopiedEvent to preserve NC's native checksum
	 * on copied files.
	 */
	public function copyFilecacheChecksum(
		File $source,
		File $target,
	): void {

		/** @noinspection PhpUnhandledExceptionInspection */
		$checksum = $source->getChecksum();

		if ( $checksum === null || $checksum === '' )
		{
			return;
		}

		/** @noinspection PhpUnhandledExceptionInspection */
		$targetStorage = $target->getStorage();
		$targetCache   = $targetStorage->getCache();

		/** @noinspection PhpUnhandledExceptionInspection */
		$targetCache->update( $target->getId(), [ 'checksum' => $checksum ] );
	}


	/** @noinspection PhpDocMissingThrowsInspection */
	public static function getFileId( int|File $file ): int
	{

		/** @noinspection PhpUnhandledExceptionInspection */
		return $file instanceof File
			? $file->getId()
			: $file;
	}


	/**
	/**
	 * Paths for a set of file ids, kept to those within the given mounts.
	 *
	 * This is an *authority*: what it drops, the listing never shows. So
	 * the filter is the mount — a storage **and** a root — and never the
	 * storage alone. A share of a subfolder mounts the owner's whole
	 * storage; filtering on the storage would list everything the owner has
	 * for whoever received one folder of it. Filtering on the root admits
	 * the subtree and nothing beside it. `oc_filecache` indexes
	 * `(storage, path)` for this prefix shape.
	 *
	 * @param  int[]                                         $fileIds
	 * @param  list<array{storage: int, root: string}>|null  $mounts  From
	 *         {@see ReachResolver::mountsFor()}: null for every file, an
	 *         empty list for none.
	 * @param  bool  $withLocalPath  Add `local_path` to each row: the file's
	 *         absolute path on this server's disk
	 *         ({@see FileLocation::localPath()}), null for a storage that
	 *         has none and for every file while server-side encryption is
	 *         enabled, since what is on disk is then not the file. Off by
	 *         default: it costs a user lookup per home storage, and most
	 *         callers render rows for people.
	 *
	 * @return array<int, array{path: string, name: string, storage_id: string, owner: ?string, location: string, local_path?: ?string}>
	 *         `owner` is the uid a home file belongs to, null for a group
	 *         folder or an external storage, which have none; `location` is
	 *         {@see FileLocation::describe()}.
	 */
	/**
	 * Path prefixes of the areas no rule governs and no listing offers:
	 * the trash, the versions, and every app's own data.
	 */
	public const UNGOVERNED_PREFIXES = [ 'files_trashbin/', 'files_versions/', 'appdata_' ];


	public function batchLookupFilecachePaths(
		array  $fileIds,
		?array $mounts = null,
		bool   $withLocalPath = false,
	): array {

		if ( empty( $fileIds ) )
		{
			return [];
		}

		if ( $mounts === [] )
		{
			// A reach holding nothing matches nothing — not everything.
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

		$qb->where(
			$qb->expr()
			   ->in(
				   'fc.fileid',
				   $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
			   ),
		);

		// Nothing in the trash, in the versions, or in an app's own data is
		// a file anyone holds: the contract says those areas are governed by
		// nothing, the sweep and the sidebar refuse them, and a listing or a
		// lookup that offered a trashed copy as a duplicate of a live file
		// contradicted both. A row here is dropped by every caller, and a
		// group's count follows the rows it keeps.
		foreach ( self::UNGOVERNED_PREFIXES as $prefix )
		{
			$qb->andWhere( $qb->expr()->notLike(
				'fc.path',
				$qb->createNamedParameter( $this->db->escapeLikeParameter( $prefix ) . '%' ),
			) );
		}

		if ( $mounts !== null )
		{
			$within = $qb->expr()->orX();

			foreach ( $mounts as $mount )
			{
				$sameStorage = $qb->expr()->eq(
					'fc.storage',
					$qb->createNamedParameter( $mount['storage'], IQueryBuilder::PARAM_INT ),
				);

				// A home's root is '' — the whole storage, and the common
				// case, which the index answers on the storage alone.
				if ( $mount['root'] === '' )
				{
					$within->add( $sameStorage );

					continue;
				}

				$root = $this->db->escapeLikeParameter( $mount['root'] );

				$within->add( $qb->expr()->andX(
					$sameStorage,
					$qb->expr()->orX(
						$qb->expr()->eq( 'fc.path', $qb->createNamedParameter( $mount['root'] ) ),
						$qb->expr()->like( 'fc.path', $qb->createNamedParameter( $root . '/%' ) ),
					),
				) );
			}

			$qb->andWhere( $within );
		}

		$result = $qb->executeQuery();
		$paths  = [];

		// One answer per account for where its home is, and one for the
		// instance on whether the disk holds the files at all.
		$homes     = [];
		$encrypted = $withLocalPath && $this->encryption->isEnabled();

		while ( ( $row = $result->fetch() ) !== false )
		{
			$sid = (string) $row['id'];

			// Whose file it is and where it really lives — the row's canonical
			// identity, not any one viewer's path for it. Across accounts two
			// rows can read `files/Templates/Certificate.odt` and be two
			// people's files; the location is what tells them apart. One
			// reading of the storage id, FileLocation's: the hand-rolled one
			// that used to sit beside it called a group folder's basename and
			// an object-store home's whole id the "user".
			$location = FileLocation::fromRow( (int) $row['fileid'], $sid, (string) $row['path'], 0 );

			$paths[ (int) $row['fileid'] ] = [
				'path'       => (string) $row['path'],
				'name'       => (string) $row['name'],
				'storage_id' => $sid,
				'owner'      => $location->owner,
				'location'   => $location->describe(),
			];

			if ( $withLocalPath )
			{
				$paths[ (int) $row['fileid'] ]['local_path'] = $encrypted
					? null
					: $location->localPath( $this->homeOf( $location, $homes ) );
			}
		}
		$result->closeCursor();

		return $paths;
	}


	/**
	 * The home directory of the account a home-storage row belongs to,
	 * asked once per account; null for a row no account owns, or whose
	 * account is gone.
	 *
	 * @param  array<string, ?string>  $homes  The answers so far, by uid.
	 */
	private function homeOf( FileLocation $location, array &$homes ): ?string
	{

		if ( $location->owner === null )
		{
			return null;
		}

		if ( ! array_key_exists( $location->owner, $homes ) )
		{
			$homes[ $location->owner ] = $this->userManager->get( $location->owner )?->getHome();
		}

		return $homes[ $location->owner ];
	}

}
