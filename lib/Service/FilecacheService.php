<?php

namespace OCA\FileChecksumSearch\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;

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


	public function __construct(
		private readonly IRootFolder   $rootFolder,
		private readonly IDBConnection $db,
		private readonly IConfig       $config,
	) {
	}


	/**
	 * @param  int|\OCP\Files\File  $file
	 *
	 * @return File
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
	public function pageStorageFiles(
		int $storageNumericId,
		int $lastFileId,
		int $limit,
	): array {

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'fc.fileid', 'fc.path', 'fc.mtime', 'st.id' )
		   ->from( 'filecache', 'fc' )
		   ->innerJoin( 'fc', 'storages', 'st', 'fc.storage = st.numeric_id' )
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

		$result    = $qb->executeQuery();
		$locations = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$locations[] = FileLocation::fromRow(
				(int) $row['fileid'],
				(string) $row['id'],
				(string) $row['path'],
				(int) $row['mtime'],
			);
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
	 * @throws \OCP\User\Exceptions\UserNotFoundException
	 */
	public function getUserFolderPath( string $userId ): string
	{

		$userFolder = $this->getUserFolder( $userId );

		return $userFolder->getPath();
	}


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


	/**
	 * @param  int|\OCP\Files\File  $file
	 *
	 * @return int
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	public static function getFileId( int|File $file ): int
	{

		/** @noinspection PhpUnhandledExceptionInspection */
		return $file instanceof File
			? $file->getId()
			: $file;
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

}
