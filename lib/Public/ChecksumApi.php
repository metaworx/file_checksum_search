<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Public;

use Generator;
use OCA\FileChecksumSearch\Service\DuplicateService;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCA\FileChecksumSearch\Service\ReachResolver;
use OCP\Config\IUserConfig;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\HashListingService;
use OCA\FileChecksumSearch\Service\HintedInvalidArgumentException;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\MimeTypeService;
use OCA\FileChecksumSearch\Service\RuleDefinitionValidator;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCP\IGroupManager;
use InvalidArgumentException;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Service\Selector;
use OCA\FileChecksumSearch\Service\StatusService;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Stable public API for checksum operations.
 *
 * This is the single public contract for all three consumer surfaces:
 * - HTTP REST (via PublicApiController)
 * - PHP DI (via constructor injection in other NC apps)
 * - PHP Bootstrap (via \OC::$server->get() after require_once base.php)
 *
 * Reads are unrestricted; the mutating surface is recalcHash() and the
 * rules methods, which follow the trusted-caller pattern described on the
 * rules section below. Internal lifecycle operations (rebuild, purge,
 * teardown, etc.) are NOT exposed here.
 */
class ChecksumApi
{

//  constructor

	public function __construct(
		private readonly HashIndexService        $hashIndexService,
		private readonly MetadataService         $metadataService,
		private readonly StatusService           $statusService,
		private readonly IRootFolder             $rootFolder,
		private readonly IUserSession            $userSession,
		private readonly RuleService             $ruleService,
		private readonly RuleDefinitionValidator $definitionValidator,
		private readonly PermissionService       $permissionService,
		private readonly IGroupManager           $groupManager,
		private readonly AlgorithmCatalogue      $catalogue,
		private readonly IUserConfig             $userConfig,
		private readonly IUserMountCache         $userMountCache,
		private readonly IUserManager            $userManager,
		private readonly ReachResolver           $reach,
		private readonly IL10N $l10n,
		private readonly HashListingService      $listing,
		private readonly MimeTypeService         $mimeTypes,
	) {
	}


//  getters / setters / is* / has*

	/**
	 * Get all checksums for a File object.
	 *
	 * Convenience method that resolves the file ID from the File node.
	 *
	 * @param  File  $file  A Nextcloud File node
	 *
	 * @return array{fileid: int, updated_at: ?string, hashes: array<string, array{algo: string, hash: string}>, algos: list<string>, preferred: string, default: string, canRecalc: bool}
	 */
	public function getHashesByFile( File $file ): array
	{
		return $this->getHashesByFileId( $file->getId(), null );
	}

	/**
	 * Get all checksums for a file by its filecache ID.
	 *
	 * Two questions, kept apart. *Who is asking* decides what the answer
	 * reports about them — whether they may recalculate, and whose stored
	 * preference it carries. *What they may reach* decides whether the file
	 * may be asked about at all. One parameter used to carry both, and null
	 * for "every account" then also read as "an administrator is asking".
	 *
	 * `hashes` is keyed by algorithm, in the order the file's document holds
	 * them, and holds what a lookup would find the file by: after a change of
	 * its content, nothing until the hashes are computed again. `updated_at`
	 * is the file's stamp, ISO 8601, null for none: the app keeps one per
	 * file, not one per algorithm, and it says until when the hashes are held
	 * current, not when they were written ({@see HashListingService::stamp()}).
	 *
	 * @param  int                $fileId      The filecache fileid
	 * @param  list<string>|null  $reachUids   Whose files may be asked about:
	 *                                         the caller's own account, a
	 *                                         group leader's members, or null
	 *                                         for every account. The file
	 *                                         must lie within one of their
	 *                                         mounts ({@see ReachResolver}).
	 * @param  string|null        $actingUser  Who is asking — the session's
	 *                                         account. Null is a trusted
	 *                                         DI/bootstrap caller, for whom
	 *                                         the answer reports what such a
	 *                                         caller may do: everything.
	 * @param  bool               $withMimeType  The answer gains `mimetype`:
	 *                                           the content's type as
	 *                                           detected, null for none, and
	 *                                           null with the hashes
	 *                                           ({@see withMimeTypes()}).
	 * @param  bool               $withStat      The answer gains the file's
	 *                                           state ({@see withStates()});
	 *                                           `hashes`, `updated_at` and
	 *                                           `etag` are null where the
	 *                                           hashes are stale or revoked,
	 *                                           as the listing gives them,
	 *                                           and `hashes` is `{}` where the
	 *                                           file has none.
	 *
	 * @return array{fileid: int, updated_at: ?string, hashes: array<string, array{algo: string, hash: string}>|null, algos: list<string>, preferred: string, default: string, canRecalc: bool, mimetype?: ?string, etag?: ?string, size?: ?int, mtime?: ?string, storageMtime?: ?string}
	 * @throws NotFoundException  If $reachUids is set and the file lies outside it
	 * @throws HintedInvalidArgumentException  For $withMimeType while detection
	 *                                         is off ({@see mimeTypeRefusal()})
	 */
	public function getHashesByFileId(
		int     $fileId,
		?array  $reachUids,
		?string $actingUser = null,
		bool    $withMimeType = false,
		bool    $withStat = false,
	): array
	{
		$this->assertMimeTypesReadable( $withMimeType );

		if ( ! $this->reach->contains( $this->reach->mountsFor( $reachUids ), $fileId ) )
		{
			throw new NotFoundException( "Invalid file ID: $fileId" );
		}

		$updatedAt = $this->metadataService->getUpdatedAt( $fileId );

		$result = [];

		// The hashes a lookup would find the file by: none between a change
		// of its content and their recomputation, the app having heard of
		// the change or not. Decided once, from the one read of the file's
		// state that `stat` answers with too.
		$state   = $this->metadataService->fileStatesOf( [ $fileId ] )[ $fileId ] ?? null;
		$vouched = $state !== null && $state['vouched'];
		$listed  = $vouched
			? $this->metadataService->listedHashes( [ $fileId ], null )[ $fileId ] ?? []
			: [];

		foreach ( $listed as $algo => $hash )
		{
			$result[ $algo ] = [
				'algo' => $algo,
				'hash' => $hash,
			];
		}

		// What the sidebar composes its quick buttons from: the algorithms the
		// file's governing include rule computes (nothing for a file no rule
		// maintains), the asking user's stored preference where it is still in
		// force, and the instance default.
		$rule      = $this->ruleService->findFirstMatchingRule( $fileId );
		$uid       = $actingUser ?? $this->userSession->getUser()?->getUID();
		$preferred = $uid !== null
			? $this->userConfig->getValueString( $uid, Application::APP_ID, ConfigLexicon::USER_PREFERRED_ALGORITHM )
			: '';

		$answer = [
			'fileid'     => $fileId,
			'updated_at' => HashListingService::stamp( $updatedAt ),
			'hashes'     => $result,
			'algos'      => RuleService::maintainsHashes( $rule )
				? array_values( array_filter( (array) ( $rule['algos'] ?? [] ), 'is_string' ) )
				: [],
			'preferred'  => $this->catalogue->isValid( $preferred ) ? $preferred : '',
			'default'    => $this->catalogue->default(),
			// So the sidebar can hide its Recalculate buttons for an account
			// that may not, instead of offering them to fail.
			'canRecalc'  => $this->mayRecalc( $actingUser ),
		];

		// The type was detected from the content the hashes were read from,
		// and goes with them.
		if ( $withMimeType )
		{
			$answer = $vouched
				? $this->withMimeTypes( [ $answer ] )[0]
				: $answer + [ 'mimetype' => null ];
		}

		if ( ! $withStat )
		{
			return $answer;
		}

		// Under `stat`, what the content was read for is valid or null
		// together: the hashes, the type detected with them, their stamp
		// and the ETag. Revoked hashes too, as the listing has them; a file
		// whose document holds none has none, `{}`.
		$marker = $this->metadataService->getMarker( $fileId );
		$valid  = $vouched && ! ( $marker !== null && str_starts_with( $marker, MetadataService::STATE_STALE_PREFIX ) );

		if ( ! $valid && $this->metadataService->getHashes( $this->metadataService->getMetadata( $fileId ) ) !== [] )
		{
			$answer['hashes']     = null;
			$answer['updated_at'] = null;

			if ( $withMimeType )
			{
				$answer['mimetype'] = null;
			}
		}

		return $answer + ( $state === null
				? self::NO_STATE
				: HashListingService::stat( $state, $valid, $state['stampEtag'] ) );
	}

	/**
	 * Get checksums by filesystem path.
	 *
	 * Path resolution rules:
	 * - $user is null: path is treated as absolute filesystem path
	 *   or relative to the Nextcloud data root.
	 * - $user is provided: path is relative to that user's home folder.
	 *
	 * @param  string       $path  Filesystem path
	 * @param  string|null  $user  If provided, path is relative to this user's home
	 *
	 * @return array{fileid: int, path: string, updated_at: ?string, hashes: array<string, array{algo: string, hash: string}>, algos: list<string>, preferred: string, default: string, canRecalc: bool}
	 * @throws NotFoundException  If the path cannot be resolved to a file
	 */
	public function getHashesByPath(
		string  $path,
		?string $user = null,
	): array
	{
		if ( $user !== null )
		{
			$userFolder = $this->rootFolder->getUserFolder( $user );
			$node       = $userFolder->get( $path );
			$relative   = $path;
		}
		else
		{
			$node     = $this->rootFolder->get( $path );
			$relative = $node->getPath();
		}

		if ( ! $node instanceof File )
		{
			throw new NotFoundException( $this->l10n->t( 'Path does not resolve to a file: %s', [ $path ] ) );
		}

		$fileId         = $node->getId();
		$result         = $this->getHashesByFileId( $fileId, null );
		$result['path'] = $relative;

		return $result;
	}

	/**
	 * What a client may ask of this server, for anyone, without the status
	 * snapshot: the app's version; `features`, `mimetype` whether the routes
	 * give a file's MIME type when asked (`?mimetype=1`); and the algorithms
	 * the server computes, with its default.
	 *
	 * The pages get it as initial state, so that they ask for nothing before
	 * their own request ({@see \OCA\FileChecksumSearch\Listener\BeforeTemplateRenderedListener}).
	 *
	 * @return array{version: string, features: array{mimetype: bool}, algorithms: array{allowed: list<string>, default: string}}
	 */
	public function getFeatures(): array
	{
		return [
			'version'    => $this->statusService->getAppVersion(),
			'features'   => [
				'mimetype' => $this->mimeTypes->isActive(),
			],
			'algorithms' => [
				'allowed' => $this->catalogue->algorithms(),
				'default' => $this->catalogue->default(),
			],
		];
	}

	/**
	 * Read-only health/status snapshot: the version for anyone, the rest for
	 * an administrator only.
	 *
	 * `features` says what a caller may ask for: `mimetype`, whether the
	 * routes give a file's MIME type when asked (`?mimetype=1`).
	 *
	 * `rowCount` is the stored count of indexed checksums, `rowCountAt` when
	 * it was taken ({@see StatusService::getHashRowCount()}). `mimeTypes` is
	 * where MIME type detection stands, one of `enabled`, `disabled`,
	 * `unavailable` and `deleting`, with the types stored, counted with the
	 * checksums.
	 *
	 * @return array{version: string, features: array{mimetype: bool}, dbVersion?: string, rowCount?: int, rowCountAt?: int, pendingRows?: int, mimeTypes?: array{state: string, rows: int, deleteRequestedAt: int|null}}
	 */
	public function getStatus( ?string $requestingUser = null ): array
	{
		// The version and the features are harmless — clients check them
		// before they ask. The rest describes the instance (its database
		// version, how much it holds, its backlog) and is the
		// administrator's to see, not every account's.
		$features = [
			'mimetype' => $this->mimeTypes->isActive(),
		];

		if ( ! $this->actsAsAdmin( $requestingUser ) )
		{
			return [
				'version'  => $this->statusService->getAppVersion(),
				'features' => $features,
			];
		}

		$rows      = $this->statusService->getHashRowCount();
		$mimeTypes = $this->statusService->getMimeTypeStatus( $rows );

		return [
			'version'     => $this->statusService->getAppVersion(),
			'features'    => $features,
			'dbVersion'   => $this->statusService->getDbVersion(),
			'rowCount'    => $rows['rows'],
			'rowCountAt'  => $rows['at'],
			'pendingRows' => $this->statusService->getPendingRowCount(),
			'mimeTypes'   => [
				'state'             => $mimeTypes['state'],
				'rows'              => $mimeTypes['rows'],
				'deleteRequestedAt' => $mimeTypes['deleteRequestedAt'],
			],
		];
	}


//  other non-static methods

	/**
	 * Search for files by hash value, with optional algorithm filter.
	 *
	 * @param  string       $hash            Hex-encoded hash value
	 * @param  list<string>|null  $reachUids  Whose files to search: one account
	 *                                        or several — a group leader's
	 *                                        ceiling — each resolved through
	 *                                        its own mounts, so shares and
	 *                                        group folders count. Null is every
	 *                                        account: a sudoer, or the occ
	 *                                        command. A reach, not a
	 *                                        permission; nothing is checked
	 *                                        against it.
	 * @param  string|null  $algo            Optional algorithm filter (sha1, md5, sha256, sha512, sha3-256, sha3-512,
	 *                                       crc32)
	 * @param  int                $limit      Max results (1–500)
	 * @param  bool               $withLocalPath  Each row gains `localPath`:
	 *                                        the file's absolute path on this
	 *                                        server's disk, null for a storage
	 *                                        that has none (an object store, a
	 *                                        share, a remote mount) and for
	 *                                        every file while server-side
	 *                                        encryption is enabled. It reveals
	 *                                        the server's layout, so a route
	 *                                        offers it to sudoers only; a
	 *                                        native caller is trusted with it.
	 * @param  bool               $canonical  Every `location` the file's own
	 *                                        address, as a sudoer reads it.
	 *                                        Otherwise a file the reach holds
	 *                                        through a share is located by the
	 *                                        share, `share:<id>//…`: nothing of
	 *                                        the sharer's above it.
	 * @param  bool               $withMimeType  Each row gains `mimetype`, as
	 *                                           {@see getHashesByFileId()}.
	 * @param  bool               $withStat      Each row gains the file's
	 *                                           state ({@see withStates()});
	 *                                           a file found is one whose
	 *                                           hashes are current.
	 *
	 * @return array{results: array<int, array{fileid: int, algo: string, hash: string, path: string, name: string, owner: ?string, location: string, localPath?: ?string, mimetype?: ?string, etag?: ?string, size?: ?int, mtime?: ?string, storageMtime?: ?string}>}
	 * @throws \InvalidArgumentException  When $hash is empty once trimmed, or
	 *                                    for $withMimeType while detection is
	 *                                    off. The REST layer turns this into
	 *                                    a 400.
	 */
	public function findByHash(
		string  $hash,
		?array  $reachUids,
		?string $algo = null,
		int     $limit = 100,
		bool    $withLocalPath = false,
		bool    $canonical = false,
		bool    $withMimeType = false,
		bool    $withStat = false,
	): array
	{
		$this->assertMimeTypesReadable( $withMimeType );

		$hash = trim( $hash );

		if ( $hash === '' )
		{
			// TRANSLATORS: "hash" is a parameter name; keep it
			throw new \InvalidArgumentException( $this->l10n->t( 'The "hash" parameter is required.' ) );
		}

		$limit = max( 1, min( $limit, 500 ) );

		// No reach to scope to — a sudoer reading every account, or the occ
		// command. Instance-wide, resolved against the filecache as before.
		if ( $reachUids === null )
		{
			$rows = $this->hashIndexService->findByHash( $hash, $algo, $limit, null, $withLocalPath );

			$results = array_map( static function(
				array $row,
			) use ( $withLocalPath ): array
			{
				return [
					'fileid'   => (int) $row['fileid'],
					'algo'     => $row['algo'],
					'hash'     => $row['hash_value'],
					'path'     => $row['path'],
					'name'     => $row['name'],
					'owner'    => $row['owner'] ?? null,
					'location' => $row['location'] ?? '',
				] + ( $withLocalPath ? [ 'localPath' => $row['local_path'] ?? null ] : [] );
			}, $rows );

			if ( $withMimeType )
			{
				$results = $this->withMimeTypes( $results );
			}

			return [
				'results' => $withStat
					? $this->withStates( $results )
					: $results,
			];
		}

		// Scoped to one account, or to several — a caller's own reach, or
		// the ceiling a group leader is allowed ({@see SudoScope::resolve()}).
		// Spend the limit on rows in those accounts' mounts — not the query's
		// first N regardless of who owns them, which hid a user's own file
		// behind foreign copies of the same hash — and let getById() be the
		// authority, so shares, group folders and object-store homes count
		// too, not just `home::<uid>`. The same shape {@see findSameHash()}
		// and the unified-search provider use.
		$uids    = array_values( $reachUids );
		$folders = [];

		foreach ( $uids as $uid )
		{
			$uid = (string) $uid;

			if ( $this->userManager->get( $uid ) === null )
			{
				continue;
			}

			$folders[ $uid ] = $this->rootFolder->getUserFolder( $uid );
		}

		if ( $folders === [] )
		{
			return [ 'results' => [] ];
		}

		// Narrowing only — getById() below stays the authority — so bare
		// storage ids are enough here, from the one resolver the listing
		// takes its mounts from.
		$visibleStorageIds = $this->reach->storageIdsFor( array_keys( $folders ) ) ?? [];

		$rows = $this->metadataService->confirmFullHash(
			$this->metadataService->queryByHash( $hash, $algo, $limit, $visibleStorageIds ),
			$hash,
		);

		$results = [];

		foreach ( $rows as $row )
		{
			$fileId   = (int) $row[ MetadataService::FIELD_FILE_ID ];
			$node     = null;
			$relative = null;

			// The first account whose folder can open it names the path. With
			// several accounts in reach the row does not yet say whose path
			// that is — the owner column is its own block.
			foreach ( $folders as $folder )
			{
				$nodes = $folder->getById( $fileId );

				if ( $nodes === [] )
				{
					continue;
				}

				$node     = $nodes[0];
				$relative = $folder->getRelativePath( $node->getPath() );

				if ( $relative !== null )
				{
					break;
				}
			}

			if ( $node === null || $relative === null )
			{
				continue;
			}

			$extracted = $this->metadataService->extractAlgorithm( $fileId, $row );

			$results[] = [
				'fileid' => $fileId,
				'algo'   => $extracted['algo'],
				'hash'   => $extracted['hash'] ?? $hash,
				'path'   => $relative,
				'name'   => $node->getName(),
			];
		}

		$results = $this->withLocations( $results, $withLocalPath, $uids, $canonical );

		if ( $withMimeType )
		{
			$results = $this->withMimeTypes( $results );
		}

		return [
			'results' => $withStat
				? $this->withStates( $results )
				: $results,
		];
	}

	/**
	 * Every hash in reach, page by page: one entry per file, its hashes
	 * keyed by algorithm, in file id order.
	 *
	 * For a caller that keeps a copy and would otherwise ask hash by hash.
	 * Pass `next` back as `$after` until it is null. The files listed are
	 * the rows {@see findByHash()} selects, within the reach's mounts; the
	 * listing does not yet apply a team folder's access rules, which a
	 * lookup does by opening each file. `path` is the file's path as the
	 * first account in reach that holds it names it, with a leading slash —
	 * with $reachUids null, the owner's, or for a file no account owns, its
	 * path in the area `location` names.
	 *
	 * `since` lists the files with a hash written to this instance at or
	 * after it — computed, taken over, imported, or computed again after a
	 * change: a feed of hashes added or changed. Pass the `now` of the
	 * previous run's first page: this server's clock, taken before that
	 * page was read. A file moved, renamed or deleted
	 * writes no hash, and is not in the feed; a copy kept with it reads the
	 * whole listing now and then for those. Hashes written before their rows
	 * carried a time are in no feed: a copy starts with the whole listing.
	 *
	 * @param  list<string>|null  $reachUids      Whose files: as {@see findByHash()}.
	 * @param  string|null        $algo           Only files with a hash in this
	 *                                            algorithm, in any case, and
	 *                                            only that hash.
	 * @param  int                $limit          Files per page, clamped to 0
	 *                                            to 1000; 0 lists none, and
	 *                                            from the start is the count
	 *                                            alone.
	 * @param  int                $after          The last file id received; 0
	 *                                            starts the listing.
	 * @param  int|null           $since          Only files with a hash written
	 *                                            at or after it, unix seconds.
	 * @param  bool               $withLocalPath  Each entry gains `localPath`,
	 *                                            as {@see findByHash()}.
	 * @param  bool               $canonical      As {@see findByHash()}.
	 * @param  bool               $withMimeType   Each entry gains `mimetype`,
	 *                                            as {@see getHashesByFileId()}.
	 *                                            A type detected by a refresh
	 *                                            writes no hash, and is in no
	 *                                            `since` feed: after a refresh,
	 *                                            read the listing whole.
	 *
	 * @param  bool               $withStat       Each entry gains the file's
	 *                                            state ({@see withStates()}),
	 *                                            and the files whose hashes
	 *                                            are stale — changed, or
	 *                                            revoked — are listed too,
	 *                                            `hashes` and `etag` null.
	 *
	 * @return array{files: list<array{fileid: int, path: string, name: string, owner: ?string, location: string, localPath?: ?string, updated_at: ?string, hashes: array<string, array{algo: string, hash: string}>|null, mimetype?: ?string, etag?: ?string, size?: int, mtime?: string, storageMtime?: string}>, next: ?int, now: int, estimated_total?: int}
	 *         `next` is null when no file follows, and echoes $after for a
	 *         count. `now` is this server's clock, unix seconds, taken before
	 *         the page was read. Starting from 0, `estimated_total` counts the
	 *         whole listing: an estimate, as files come and go while it is
	 *         read.
	 * @throws \OCP\DB\Exception
	 * @throws HintedInvalidArgumentException  For $withMimeType while detection
	 *                                         is off.
	 */
	public function listHashes(
		?array  $reachUids,
		?string $algo = null,
		int     $limit = HashListingService::DEFAULT_LIMIT,
		int     $after = 0,
		?int    $since = null,
		bool    $withLocalPath = false,
		bool    $canonical = false,
		bool    $withMimeType = false,
		bool    $withStat = false,
	): array
	{
		$this->assertMimeTypesReadable( $withMimeType );

		return $this->listing->page( $reachUids, $algo, $limit, $after, $since, $withLocalPath, $canonical, $withMimeType, $withStat );
	}

	/**
	 * Every hash in reach, file by file: {@see listHashes()} without the
	 * paging, for a caller in this process.
	 *
	 * Read in batches of {@see HashListingService::ITERATE_BATCH} files,
	 * one held at a time, each a query of its own, so the caller may run
	 * its own queries between two files. The entries are `listHashes()`'s
	 * `files`, keyed by file id. No count: `listHashes()` with a limit of 0
	 * gives one.
	 *
	 * @param  list<string>|null  $reachUids      Whose files: as {@see findByHash()}.
	 * @param  string|null        $algo           As {@see listHashes()}.
	 * @param  int|null           $since          As {@see listHashes()}.
	 * @param  bool               $withLocalPath  As {@see listHashes()}.
	 * @param  int                $after          The last file id a walk that
	 *                                            was cut short received; 0
	 *                                            starts at the beginning.
	 * @param  bool               $canonical      As {@see findByHash()}.
	 * @param  bool               $withMimeType   As {@see listHashes()}.
	 * @param  bool               $withStat       As {@see listHashes()}.
	 *
	 * @return Generator<int, array{fileid: int, path: string, name: string, owner: ?string, location: string, localPath?: ?string, updated_at: ?string, hashes: array<string, array{algo: string, hash: string}>|null, mimetype?: ?string, etag?: ?string, size?: int, mtime?: string, storageMtime?: string}>
	 * @throws \OCP\DB\Exception  While the walk is read, not when it is asked for.
	 * @throws HintedInvalidArgumentException  For $withMimeType while detection
	 *                                         is off, when it is asked for.
	 */
	public function iterateHashes(
		?array  $reachUids,
		?string $algo = null,
		?int    $since = null,
		bool    $withLocalPath = false,
		int     $after = 0,
		bool    $canonical = false,
		bool    $withMimeType = false,
		bool    $withStat = false,
	): Generator
	{
		$this->assertMimeTypesReadable( $withMimeType );

		return $this->listing->iterate( $reachUids, $algo, $since, $withLocalPath, $after, $canonical, $withMimeType, $withStat );
	}

	/**
	 * The files in reach without a hash, page by page: the other half of
	 * {@see listHashes()} under `$withStat`, which lists every file with a
	 * hash row, stale ones included. Read both, and a file of the files area
	 * in neither is gone.
	 *
	 * By default the files an include rule governs, which the queue would
	 * hash; `$unmatched` adds those no rule matches, `$withIgnored` those an
	 * `ignore` rule matches, `$withExcluded` those an `exclude` rule matches.
	 * A page reads a window of file ids and may come back short, or empty:
	 * pass `next` back as `$after` until it is null.
	 *
	 * @param  list<string>|null  $reachUids  Whose files: as {@see findByHash()}.
	 * @param  string|null        $algo       Files without a hash in this
	 *                                        algorithm, whatever others they hold.
	 * @param  int                $limit      Files on the page, 1 to 1000.
	 * @param  int                $after      The `next` of the page before;
	 *                                        0 starts the listing.
	 * @param  bool               $canonical  As {@see listHashes()}: every
	 *                                        `location` the file's own
	 *                                        address, for a sudoer.
	 * @param  bool               $withStat   Each file gains its state:
	 *                                        `etag` null, and its present
	 *                                        size and mtimes.
	 *
	 * @return array{files: list<array{fileid: int, path: string, name: string, owner: ?string, location: string, etag?: ?string, size?: int, mtime?: string, storageMtime?: string}>, next: ?int, now: int}
	 * @throws \OCP\DB\Exception
	 */
	public function listMissing(
		?array  $reachUids,
		?string $algo = null,
		int     $limit = HashListingService::DEFAULT_LIMIT,
		int     $after = 0,
		bool    $unmatched = false,
		bool    $withIgnored = false,
		bool    $withExcluded = false,
		bool    $canonical = false,
		bool    $withStat = false,
	): array
	{
		return $this->listing->missingPage( $reachUids, $algo, $limit, $after, $unmatched, $withIgnored, $withExcluded, $canonical, $withStat );
	}

	/**
	 * Find duplicate hash groups among the files the session user can reach.
	 *
	 * Not an instance-wide view: without a session user there is nobody to
	 * resolve reachability against, and the answer is empty rather than
	 * everybody's files.
	 *
	 * @param  string|null  $algo      Optional algorithm filter
	 * @param  int          $minCount  Minimum files per group (default 2)
	 * @param  int          $limit     Max groups (1–500, default 50)
	 * @param  int          $offset        Pagination offset (default 0)
	 * @param  bool         $includeEmpty  List the groups of empty files
	 *                                     too; left out by default, since
	 *                                     every empty file shares one
	 *                                     checksum per algorithm
	 * @param  bool         $withMimeType  Each group gains `mimetype`, as
	 *                                     {@see findDuplicatesFor()}.
	 *
	 * @return array{duplicates: array, total_groups: int, pagination: array{offset: int, limit: int}}
	 * @throws HintedInvalidArgumentException  For $withMimeType while detection
	 *                                         is off.
	 */
	public function findDuplicates(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?string $hash = null,
		bool    $anywhere = false,
		bool    $includeEmpty = false,
		bool    $withMimeType = false,
	): array
	{
		$this->assertMimeTypesReadable( $withMimeType );

		$user = $this->userSession->getUser();
		$uid  = $user?->getUID();

		if ( $uid === null )
		{
			return [
				'duplicates'   => [],
				'total_groups' => 0,
				'pagination'   => [
					'offset' => $offset,
					'limit'  => max( 1, min( $limit, 500 ) ),
				],
			];
		}

		return $this->findDuplicatesFor( [ $uid ], $algo, $minCount, $limit, $offset, $hash, $anywhere, $includeEmpty, withMimeType: $withMimeType );
	}

	/**
	 * Duplicate groups for one account, or for every account.
	 *
	 * The scoped core behind {@see findDuplicates()}. Not for a session's
	 * own use: a null $scope lists every account's files, and a string one
	 * lists an account the caller did not have to be. The routes that reach
	 * it have already decided the caller may — {@see SudoScope} — and have
	 * asked for a password on the way.
	 *
	 * @param  list<string>|null  $reachUids  Whose files to list: one account,
	 *                                        several, or null for the whole
	 *                                        instance. A reach, resolved to
	 *                                        mounts one layer down.
	 * @param  bool               $includeEmpty  As {@see findDuplicates()}.
	 * @param  bool               $canonical  As {@see findByHash()}.
	 * @param  bool               $withMimeType  Each group gains `mimetype`:
	 *                                           the type of the content its
	 *                                           files share, as detected
	 *                                           ({@see groupsWithMimeTypes()}).
	 *
	 * @return array{duplicates: array, total_groups: int, pagination: array{offset: int, limit: int}}
	 * @throws HintedInvalidArgumentException  For $withMimeType while detection
	 *                                         is off.
	 */
	public function findDuplicatesFor(
		?array  $reachUids,
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?string $hash = null,
		bool    $anywhere = false,
		bool    $includeEmpty = false,
		bool    $canonical = false,
		bool    $withMimeType = false,
	): array
	{
		$this->assertMimeTypesReadable( $withMimeType );

		$limit  = max( 1, min( $limit, 500 ) );
		$answer = $this->hashIndexService->listDuplicatesForUser(
			$reachUids,
			$algo,
			$minCount,
			$limit,
			$offset,
			$hash,
			$anywhere,
			$includeEmpty,
			$canonical,
		);

		if ( $withMimeType )
		{
			$answer['duplicates'] = $this->groupsWithMimeTypes( $answer['duplicates'] );
		}

		return $answer;
	}

	/**
	 * Find other files sharing the same hash values as a given file.
	 *
	 * Both ends are within the reach: the reference file must lie in it, and
	 * only duplicates in it are listed — each rendered through a folder that
	 * can open it, never through the session's. Rendering through the
	 * session's folder is what made this answer only the caller's own copies
	 * whatever reach it had been granted, so the cross-account twin of this
	 * route returned nothing across accounts for as long as it existed.
	 *
	 * @param  int                $fileId     The filecache fileid of the reference file
	 * @param  list<string>|null  $reachUids  Whose files: one account, several,
	 *                                        or null for every account.
	 * @param  bool               $canonical  As {@see findByHash()}.
	 * @param  bool               $withMimeType  Each group gains `mimetype`, as
	 *                                           {@see findDuplicatesFor()}.
	 *
	 * Unlike the listing, this leaves no empty file out: the caller named the
	 * file. Each group says whether it is the empty files' (`empty`), and the
	 * caller decides how to show it.
	 *
	 * @return array{duplicates: array<int, array{algo: string, hash_value: string, empty: bool, files:
	 *                           array<int, array{fileid: int, path: string, name: string}>, mimetype?: ?string}>}
	 * @throws NotFoundException  If $reachUids is set and the reference file lies outside it
	 * @throws HintedInvalidArgumentException  For $withMimeType while detection
	 *                                         is off.
	 */
	public function findSameHash(
		int    $fileId,
		?array $reachUids,
		bool   $canonical = false,
		bool   $withMimeType = false,
	): array
	{
		$this->assertMimeTypesReadable( $withMimeType );

		// Before the hashes are read, not after. A hash is a fingerprint of
		// content, so answering for a file the caller cannot open turns this
		// into a content-equality oracle over every file on the instance:
		// sweep the ids, and a non-empty answer says that file holds
		// something you also hold.
		$mounts = $this->reach->mountsFor( $reachUids );

		if ( ! $this->reach->contains( $mounts, $fileId ) )
		{
			throw new NotFoundException( "Invalid file ID: $fileId" );
		}

		// Current hashes only: an outdated one would pair the file with copies
		// of what it used to hold, and so would one its stamp no longer
		// vouches for — changed without the app hearing of it.
		$hashes = $this->metadataService->isVouched( $fileId )
			? $this->metadataService->listedHashes( [ $fileId ], null )[ $fileId ] ?? []
			: [];

		if ( empty( $hashes ) )
		{
			return [ 'duplicates' => [] ];
		}

		// The folders a duplicate may be rendered through: the reach's own
		// accounts. For every account there is no list to try, and each
		// file is rendered through whoever holds it instead.
		$folders = [];

		foreach ( $reachUids ?? [] as $uid )
		{
			$uid = (string) $uid;

			if ( $this->userManager->get( $uid ) !== null )
			{
				$folders[ $uid ] = $this->rootFolder->getUserFolder( $uid );
			}
		}

		// Narrowing only, as in findByHash(): the per-file check below stays
		// the authority. Null mounts narrow nothing.
		$visibleStorageIds = $mounts === null
			? null
			: array_values( array_unique( array_column( $mounts, 'storage' ) ) );

		$grouped = [];

		foreach ( $hashes as $algo => $hashValue )
		{
			// queryByHash() compares against the index, which holds at most
			// 63 characters, so a long-hash lookup can return a file that
			// only shares that prefix. One confirmation, shared with every
			// other caller ({@see MetadataService::confirmFullHash()}).
			$rows = $this->metadataService->confirmFullHash(
				$this->metadataService->queryByHash( $hashValue, $algo, 100, $visibleStorageIds ),
				$hashValue,
			);

			foreach ( $rows as $row )
			{
				$dupFileId = (int) $row[ MetadataService::FIELD_FILE_ID ];

				if ( $dupFileId === $fileId )
				{
					continue;
				}

				// Within the reach, by the rule the listing applies — then a
				// path for it, from a folder that can open it.
				if ( ! $this->reach->contains( $mounts, $dupFileId ) )
				{
					continue;
				}

				$located = $this->pathWithin( $dupFileId, $folders, $reachUids === null );

				if ( $located === null )
				{
					continue;
				}

				[ $resolvedPath, $resolvedName ] = $located;

				$key = $algo . "\0" . $hashValue;

				$grouped[ $key ] ??= [
					'algo'       => $algo,
					'hash_value' => $hashValue,
					'empty'      => AlgorithmCatalogue::isEmptyDigest( $algo, $hashValue ),
					'files'      => [],
				];

				$grouped[ $key ]['files'][] = [
					'fileid' => $dupFileId,
					'path'   => $resolvedPath,
					'name'   => $resolvedName,
				];
			}
		}

		foreach ( $grouped as $key => $group )
		{
			$grouped[ $key ]['files'] = $this->withLocations( $group['files'], false, $reachUids, $canonical );
		}

		return [
			'duplicates' => $withMimeType
				? $this->groupsWithMimeTypes( array_values( $grouped ) )
				: array_values( $grouped ),
		];
	}

	/**
	 * Trigger hash recalculation for a file.
	 *
	 * This is the only mutating operation in the public API.
	 *
	 * Two questions, and they are not the same one. *Who is asking* decides
	 * whether the account may calculate by hand at all; *what they may reach*
	 * decides whether this file is theirs to ask about. The read methods
	 * beside this one fold both into a single parameter and are right to —
	 * they only read, so the parameter has one job. This one writes, and a
	 * caller that needed to widen the reach would otherwise have had to pass
	 * null and give away the permission check with it.
	 *
	 * Neither is a boundary against PHP callers, and cannot be: code running
	 * in this process already has the server's privileges, and anything able
	 * to call this can call {@see IRootFolder::getUserFolder()} for any
	 * account directly. These are a convenience for the HTTP layer, whose
	 * boundary is {@see PublicApiController::scopeOrRefusal()} — that reads
	 * the session and never a request parameter, so `$actingUser` cannot be
	 * chosen by a client.
	 *
	 * @param  int          $fileId      The filecache fileid
	 * @param  list<string>|null  $reachUids  Whose files may be acted on: the
	 *                                        caller's own account, a group
	 *                                        leader's members, or null when the
	 *                                        reach was settled before this was
	 *                                        called ({@see SudoScope::mayReachFile()})
	 *                                        or the caller is trusted. Null
	 *                                        waives the reach check and nothing
	 *                                        else: the permission, and any rule
	 *                                        excluding the path, are still
	 *                                        answered.
	 * @param  string|null  $algo        Algorithm (default: sha1)
	 * @param  string|null  $actingUser  Who is asking. The manual-calculation
	 *                                   permission is checked against this
	 *                                   always, and unless `$anyAccount` the
	 *                                   fileid must resolve within their own
	 *                                   file tree. Null is a trusted
	 *                                   DI/bootstrap caller: both are skipped.
	 * @param  bool         $withMimeType  The answer gains `mimetype`, below.
	 * @param  bool         $withStat      The answer gains the file's state,
	 *                                     below.
	 *
	 * @return array{success: bool, algo?: string, hash?: string, existed?: bool, locked?: bool, error?: string, excluded?: bool, ruleId?: string, ruleOwner?: string, forbidden?: bool, mimetype?: ?string, etag?: ?string, size?: ?int, mtime?: ?string, storageMtime?: ?string}
	 *         `excluded` says a rule refused the file rather than anything
	 *         going wrong, and names the rule in `ruleId` and whose it is in
	 *         `ruleOwner` — `admin`, or a uid; `forbidden` says
	 *         the account may not recalculate checksums. The REST layer
	 *         answers 403 for either and 400 for every other failure.
	 *         With $withMimeType, `mimetype` is the type the recalculation
	 *         left, as {@see getHashesByFileId()}; null where it failed.
	 *         With $withStat, the file's state as the recalculation left it
	 *         ({@see withStates()}): `etag` the one it read against; all
	 *         null where it failed.
	 * @throws HintedInvalidArgumentException  For $withMimeType while detection
	 *                                         is off.
	 */
	public function recalcHash(
		int     $fileId,
		?array  $reachUids,
		?string $algo = null,
		?string $actingUser = null,
		bool    $withMimeType = false,
		bool    $withStat = false,
	): array
	{
		$this->assertMimeTypesReadable( $withMimeType );

		$result = $this->recalcFile( $fileId, $reachUids, $algo, $actingUser );

		// Only for a file the recalculation reached: a failure may be one the
		// caller may not see.
		if ( $withMimeType )
		{
			$result['mimetype'] = $result['success']
				? $this->metadataService->mimeTypesOf( [ $fileId ] )[ $fileId ] ?? null
				: null;
		}

		if ( $withStat )
		{
			$result += $result['success']
				? array_diff_key( $this->withStates( [ [ 'fileid' => $fileId ] ] )[0], [ 'fileid' => true ] )
				: self::NO_STATE;
		}

		return $result;
	}

	/**
	 * {@see recalcHash()}'s work, without the MIME type.
	 *
	 * @param  list<string>|null  $reachUids
	 *
	 * @return array{success: bool, algo?: string, hash?: string, existed?: bool, locked?: bool, error?: string, excluded?: bool, ruleId?: string, ruleOwner?: string, forbidden?: bool}
	 */
	private function recalcFile(
		int     $fileId,
		?array  $reachUids,
		?string $algo,
		?string $actingUser,
	): array
	{
		if ( ! $this->reach->contains( $this->reach->mountsFor( $reachUids ), $fileId ) )
		{
			return [
				'success' => false,
				'error'   => $this->l10n->t( 'File not found.' ),
			];
		}

		// Owning the file is not the same as being allowed to make the
		// server work on it: the manual-recalculation permission is checked
		// here, before any rule is consulted, so the reason is the plain one.
		// Reaching across accounts never waives it — whoever is acting needs
		// the permission whether the file is theirs or somebody else's.
		if ( ! $this->mayRecalc( $actingUser ) )
		{
			return [
				'success'   => false,
				'error'     => $this->l10n->t( 'This account may not recalculate checksums.' ),
				'forbidden' => true,
			];
		}

		$excludedBy = $this->excludingRuleFor( $fileId );

		if ( $excludedBy !== null )
		{
			// Whose rule, so the reader is sent to the right page: their own
			// rules live on their personal page, an administrator's do not.
			// The message used to say "an administrator rule" of every rule,
			// the reader's own included.
			return [
				'success'   => false,
				'error'     => $this->l10n->t( 'Hashing is excluded for this path by a rule.' ),
				'excluded'  => true,
				'ruleId'    => (string) ( $excludedBy['id'] ?? '' ),
				'ruleOwner' => self::ruleOwner( $excludedBy ),
			];
		}

		$algo ??= $this->catalogue->default();

		// Resolving by id alone goes through the root folder, which holds
		// only what the *session's* account has mounted — so a member's file
		// a group leader may reach, or another account's a sudoer may, is
		// "not found" there however far the checks above got. A trusted
		// caller with no session (occ, DI) has the whole filesystem in its
		// root and resolves as before; everyone else resolves the node where
		// it can actually be seen — inside a folder of an account that holds
		// it.
		if ( $actingUser === null && $reachUids === null )
		{
			return $this->hashIndexService->recalcHash( $fileId, $algo );
		}

		$file = $this->fileForAnyAccount( $fileId );

		return $file === null
			? [ 'success' => false, 'error' => $this->l10n->t( 'File not found.' ) ]
			: $this->hashIndexService->recalcFileHash( $file, $algo );
	}


//  constants

	/**
	 * How many files one {@see recalcMany()} call will read, at most. The
	 * rate limit counts requests; this is what keeps one request from being
	 * an unbounded amount of work behind it.
	 */
	public const RECALC_BATCH_FILES = 25;

	/**
	 * How many bytes one {@see recalcMany()} call will read, at most.
	 * Counting requests alone is a poor proxy for cost — small files answer
	 * fast and hit a request limit hardest, large files slowly and barely —
	 * and the filecache knows each file's size before it is read, so the
	 * budget can be stated in bytes as well as files.
	 */
	public const RECALC_BATCH_BYTES = 100 * 1024 * 1024;

	/**
	 * The state `stat` gives a file the filecache no longer has: none of it
	 * ({@see HashListingService::stat()}).
	 */
	private const NO_STATE = [
		'etag'         => null,
		'size'         => null,
		'mtime'        => null,
		'storageMtime' => null,
	];

	/**
	 * Recalculate several files in one request — one gesture, one call.
	 *
	 * Each file is put through {@see recalcHash()} with the same asker and
	 * the same reach, so every check that applies to one file applies to
	 * each of these, and a file out of reach or refused by a rule is its
	 * own failed result rather than the whole request's. The call stops at
	 * {@see RECALC_BATCH_FILES} files or {@see RECALC_BATCH_BYTES}, whichever
	 * comes first, and says which ids it did not get to; a single file
	 * always goes through, however large, or a large file could never be
	 * verified at all.
	 *
	 * @param  list<int>          $fileIds
	 * @param  list<string>|null  $reachUids   As for {@see recalcHash()}.
	 * @param  string|null        $algo
	 * @param  string|null        $actingUser  As for {@see recalcHash()}.
	 * @param  bool               $withMimeType  Each result gains `mimetype`,
	 *                                           as {@see recalcHash()}.
	 * @param  bool               $withStat      Each result gains the file's
	 *                                           state, as {@see recalcHash()}.
	 *
	 * @return array{results: list<array{fileid: int, success: bool, algo?: string, hash?: string, existed?: bool, locked?: bool, error?: string, excluded?: bool, ruleId?: string, ruleOwner?: string, forbidden?: bool, mimetype?: ?string, etag?: ?string, size?: ?int, mtime?: ?string, storageMtime?: ?string}>, remaining: list<int>}
	 *         `remaining` are the ids not processed, in the order given; the
	 *         caller sends them again.
	 * @throws HintedInvalidArgumentException  For $withMimeType while detection
	 *                                         is off.
	 */
	public function recalcMany(
		array   $fileIds,
		?array  $reachUids,
		?string $algo = null,
		?string $actingUser = null,
		bool    $withMimeType = false,
		bool    $withStat = false,
	): array
	{
		$this->assertMimeTypesReadable( $withMimeType );

		$fileIds   = array_values( array_unique( array_map( 'intval', $fileIds ) ) );
		$sizes     = $this->hashIndexService->fileSizes( $fileIds );
		$results   = [];
		$bytes     = 0;
		$remaining = [];

		foreach ( $fileIds as $index => $fileId )
		{
			// The filecache's size, or nothing if it does not know the file —
			// recalcHash() will say "not found" for that one at no cost.
			$size = $sizes[ $fileId ] ?? 0;

			// At a cap, unless nothing has been read yet: the first file is
			// always read, so a file larger than the whole budget still can be.
			if ( $index > 0 && ( $index >= self::RECALC_BATCH_FILES || $bytes + $size > self::RECALC_BATCH_BYTES ) )
			{
				$remaining = array_slice( $fileIds, $index );

				break;
			}

			$bytes += $size;
			$results[] = [ 'fileid' => $fileId ] + $this->recalcFile( $fileId, $reachUids, $algo, $actingUser );
		}

		if ( $withMimeType )
		{
			// Only for the files the recalculation reached, as recalcHash().
			$types = $this->metadataService->mimeTypesOf(
				array_column( array_filter( $results, static fn( array $result ): bool => $result['success'] ), 'fileid' ),
			);

			foreach ( $results as $i => $result )
			{
				$results[ $i ]['mimetype'] = $result['success']
					? $types[ $result['fileid'] ] ?? null
					: null;
			}
		}

		if ( $withStat )
		{
			// The same: a failure says nothing of a file the caller may not see.
			$reached = array_values( array_filter( $results, static fn( array $result ): bool => $result['success'] ) );
			$states  = array_column( $this->withStates( $reached ), null, 'fileid' );

			foreach ( $results as $i => $result )
			{
				$state = $states[ $result['fileid'] ] ?? [];

				$results[ $i ] += [
					'etag'         => $state['etag'] ?? null,
					'size'         => $state['size'] ?? null,
					'mtime'        => $state['mtime'] ?? null,
					'storageMtime' => $state['storageMtime'] ?? null,
				];
			}
		}

		return [
			'results'   => $results,
			'remaining' => $remaining,
		];
	}

	/**
	 * Which of these files $viewer could open in the Files app.
	 *
	 * A file link resolves in the viewer's own folder — core's `/f/{fileid}`
	 * looks the id up there and nowhere else —
	 * so a row the viewer does not hold cannot be opened by any link, and a
	 * page should not offer one. The client cannot tell from `owner`: a
	 * received share is somebody else's and opens fine. One batched lookup
	 * against the viewer's own mounts answers for the whole page.
	 *
	 * @param  list<int>  $fileIds
	 *
	 * @return array<int, bool>  keyed by file id; an id the viewer cannot
	 *                           open is false
	 */
	public function openableBy(
		array  $fileIds,
		string $viewer,
	): array
	{
		if ( $fileIds === [] )
		{
			return [];
		}

		$held = $this->hashIndexService->batchLookupFilecachePaths( $fileIds, [ $viewer ] );

		return array_combine(
			$fileIds,
			array_map( static fn ( int $id ): bool => isset( $held[ $id ] ), $fileIds ),
		);
	}

	/**
	 * Why a caller cannot be given MIME types now, in their language; null
	 * where they can.
	 *
	 * Asked by the REST layer before it reads anything, so that a client
	 * asking `?mimetype=1` of a server that does not detect them learns why,
	 * and where it would be switched on.
	 */
	public function mimeTypeRefusal(): ?string
	{
		if ( $this->mimeTypes->isActive() )
		{
			return null;
		}

		if ( $this->mimeTypes->isEnabled() )
		{
			return $this->l10n->t( 'MIME types cannot be detected on this server: PHP\'s Fileinfo extension is not loaded.' );
		}

		return $this->l10n->t(
			// TRANSLATORS: %1$s is the app's name in the administration settings, %2$s the name of its tab
			'MIME type detection is off on this server. An administrator can switch it on in the administration settings, under %1$s → %2$s.',
			[
				$this->l10n->t( 'File Checksum Index & Search' ),
				$this->l10n->t( 'Advanced' ),
			],
		);
	}

	/**
	 * Refuse MIME types asked for while they are not detected.
	 *
	 * @throws HintedInvalidArgumentException  With {@see mimeTypeRefusal()}
	 *                                         as its hint.
	 */
	private function assertMimeTypesReadable( bool $asked ): void
	{
		if ( ! $asked )
		{
			return;
		}

		$refusal = $this->mimeTypeRefusal();

		if ( $refusal !== null )
		{
			throw new HintedInvalidArgumentException( 'MIME types were asked for, and MIME type detection is not on.', $refusal );
		}
	}

	/**
	 * The given file rows, each with `mimetype`: the content's type as
	 * detected, null for none. One query for all of them.
	 *
	 * @param  list<array{fileid: int, ...}>  $rows
	 *
	 * @return list<array>  the same rows plus `mimetype: ?string`
	 * @throws \OCP\DB\Exception
	 */
	private function withMimeTypes( array $rows ): array
	{
		$types = $this->metadataService->mimeTypesOf(
			array_map( static fn( array $row ): int => (int) $row['fileid'], $rows ),
		);

		foreach ( $rows as $i => $row )
		{
			$rows[ $i ]['mimetype'] = $types[ (int) $row['fileid'] ] ?? null;
		}

		return $rows;
	}

	/**
	 * The given file rows, each with its state: `etag`, the ETag its hashes
	 * were read against, null where they are stale; and `size`, `mtime` and
	 * `storageMtime`, the file's present state ({@see HashListingService::stat()}).
	 * All null for a file the filecache no longer has. Two queries for all
	 * of them.
	 *
	 * @param  list<array{fileid: int, ...}>  $rows
	 *
	 * @return list<array>  the same rows plus `etag: ?string, size: ?int, mtime: ?string, storageMtime: ?string`
	 * @throws \OCP\DB\Exception
	 */
	private function withStates( array $rows ): array
	{
		$states = $this->metadataService->fileStatesOf(
			array_map( static fn( array $row ): int => (int) $row['fileid'], $rows ),
		);

		foreach ( $rows as $i => $row )
		{
			$state = $states[ (int) $row['fileid'] ] ?? null;

			$rows[ $i ] += $state === null
				? self::NO_STATE
				: HashListingService::stat( $state, $state['vouched'], $state['stampEtag'] );
		}

		return $rows;
	}

	/**
	 * The given groups of files sharing one content, each with `mimetype`:
	 * that content's type, as detected for the first of its files that has
	 * one, and null where none has. One query for all of them.
	 *
	 * The files of a group share their content, and so their type; they can
	 * disagree only where some were detected under another PHP's libmagic,
	 * or not yet.
	 *
	 * @param  list<array{files: list<array{fileid: int, ...}>, ...}>  $groups
	 *
	 * @return list<array>  the same groups plus `mimetype: ?string`
	 * @throws \OCP\DB\Exception
	 */
	private function groupsWithMimeTypes( array $groups ): array
	{
		$fileIds = [];

		foreach ( $groups as $group )
		{
			foreach ( $group['files'] as $file )
			{
				$fileIds[] = (int) $file['fileid'];
			}
		}

		$types = $this->metadataService->mimeTypesOf( $fileIds );

		foreach ( $groups as $i => $group )
		{
			$type = null;

			foreach ( $group['files'] as $file )
			{
				$type = $types[ (int) $file['fileid'] ] ?? null;

				if ( $type !== null )
				{
					break;
				}
			}

			$groups[ $i ]['mimetype'] = $type;
		}

		return $groups;
	}

	/**
	 * The given file rows, each with whose file it is and where it lives.
	 *
	 * `path` on these rows is some viewer's name for the file, and with more
	 * than one account in reach nothing about it says whose. `owner` and
	 * `location` do — the file's canonical identity from its filecache row
	 * ({@see FileLocation}), which the listing's rows carry from the start
	 * and these, rendered from nodes, gain here in one batched lookup.
	 *
	 * A file the reach holds through a share is located by the share, unless
	 * $canonical: nothing of the sharer's above it, which Nextcloud never
	 * shows a recipient either.
	 *
	 * @param  list<array{fileid: int, ...}>  $rows
	 * @param  list<string>|null              $reachUids  Whose view locates a file;
	 *                                                    null, its own address.
	 *
	 * @return list<array>  the same rows plus `owner: ?string` and `location: string`, and `localPath: ?string` when asked
	 */
	private function withLocations(
		array  $rows,
		bool   $withLocalPath = false,
		?array $reachUids = null,
		bool   $canonical = false,
	): array
	{
		if ( $rows === [] )
		{
			return [];
		}

		// No reach filter: what may be listed was decided row by row already;
		// this only finds words for it.
		$located = $this->hashIndexService->batchLookupFilecachePaths( array_column( $rows, 'fileid' ), null, $withLocalPath );
		$views   = $reachUids === null || $canonical
			? null
			: $this->reach->filesViewsFor( array_values( $reachUids ) );

		return array_map(
			function( array $row ) use ( $located, $views, $withLocalPath ): array
			{
				$at = $located[ $row['fileid'] ] ?? null;

				return $row + [
					'owner'    => $at['owner'] ?? null,
					'location' => $at === null ? '' : $this->reach->asSeenIn( $views, $at, false )['location'],
				] + ( $withLocalPath ? [ 'localPath' => $at['local_path'] ?? null ] : [] );
			},
			$rows,
		);
	}

	/**
	 * A user-relative path and name for $fileId, from the first of $folders
	 * that can open it — or, when $anyHolder, from whichever account holds
	 * it. Null when none can.
	 *
	 * Whether the file is *within reach* was decided already
	 * ({@see ReachResolver::contains()}); this only finds words for it. With
	 * several accounts in reach the path is whichever folder opened it
	 * first, and nothing here says whose — that is the owner column.
	 *
	 * @param  array<string, \OCP\Files\Folder>  $folders
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function pathWithin(
		int   $fileId,
		array $folders,
		bool  $anyHolder,
	): ?array
	{
		if ( $anyHolder )
		{
			foreach ( $this->userMountCache->getMountsForFileId( $fileId ) as $mount )
			{
				$uid = $mount->getUser()->getUID();

				if ( ! isset( $folders[ $uid ] ) )
				{
					try
					{
						$folders[ $uid ] = $this->rootFolder->getUserFolder( $uid );
					}
					catch ( \Throwable )
					{
						continue;
					}
				}
			}
		}

		foreach ( $folders as $folder )
		{
			$nodes = $folder->getById( $fileId );

			if ( $nodes === [] )
			{
				continue;
			}

			$relative = $folder->getRelativePath( $nodes[0]->getPath() );

			if ( $relative !== null )
			{
				return [ $relative, $nodes[0]->getName() ];
			}
		}

		return null;
	}

	/**
	 * One file, resolved through a folder that can see it.
	 *
	 * The mount cache says which accounts hold the id; asking any of them for
	 * their own folder sets that account's filesystem up, which is what makes
	 * the id resolvable at all. The first holder that yields a file wins —
	 * they are all the same file, reached by different paths.
	 *
	 * Whether the *caller* may do this was settled before we got here
	 * ({@see SudoScope::mayReachFile()} or {@see ReachResolver::contains()});
	 * this only finds the node.
	 */
	private function fileForAnyAccount( int $fileId ): ?File
	{
		foreach ( $this->userMountCache->getMountsForFileId( $fileId ) as $mount )
		{
			try
			{
				$nodes = $this->rootFolder->getUserFolder( $mount->getUser()->getUID() )
				                          ->getById( $fileId )
				;
			}
			catch ( \Throwable )
			{
				// An account whose folder will not open tells us nothing
				// about the next one.
				continue;
			}

			foreach ( $nodes as $node )
			{
				if ( $node instanceof File )
				{
					return $node;
				}
			}
		}

		return null;
	}


//  static methods

	/**
	 * Whose a rule is, for a message: `admin` for an enforced rule and for
	 * any rule that is not on one account's home — those only an
	 * administrator can make — else the uid the `home:<uid>` selector names.
	 * Not who typed it: a rule on alice's home that an administrator made
	 * and did not enforce is alice's to edit, and that is what the reader
	 * needs to know.
	 */
	private static function ruleOwner( array $rule ): string
	{
		if ( ! empty( $rule['admin_enforced'] ) )
		{
			return 'admin';
		}

		try
		{
			$selector = Selector::fromStored( (string) ( $rule['selector'] ?? '' ) );
		}
		catch ( \Throwable )
		{
			return 'admin';
		}

		return $selector->kind === Selector::KIND_USER
			? (string) $selector->target
			: 'admin';
	}

	/**
	 * The exclude rule governing $fileId, or null where none does.
	 *
	 * Only `exclude` blocks a deliberate, user-initiated recalculation.
	 * `ignore` merely stops *automatic* hashing — asking for it by hand is
	 * exactly the case it leaves open — and an unmatched file was never
	 * governed by a rule at all.
	 *
	 * @return array<string, mixed>|null
	 */
	private function excludingRuleFor( int $fileId ): ?array
	{
		try
		{
			$rule = $this->ruleService->findFirstMatchingRule( $fileId );
		}
		catch ( \Throwable )
		{
			// Never block a recalculation because the rule lookup failed —
			// the ownership check above is the security boundary; this is a
			// policy check, and failing it open preserves existing behaviour.
			return null;
		}

		return $rule !== null
		&& RuleService::verdictOf( $rule ) === RuleService::TYPE_EXCLUDE
			? $rule
			: null;
	}

	// ─── rules ──────────────────────────────────────────────────────
	//
	// The trusted-caller pattern, same as the rest of this class:
	// $requestingUser === null means the caller is server-side code acting
	// with full authority (occ-equivalent); a non-null user is enforced
	// exactly as the REST API enforces that user. All payloads validate
	// through the same RuleDefinitionValidator as REST and occ, and every
	// mutation is audit-logged by RuleService with the actor named.
	/**
	 * List rules, in evaluation order, annotated for display.
	 *
	 * Null = the administrator's full view; a user gets the rules that can
	 * concern them, with per-rule canEdit.
	 *
	 * @return array{rules: array, canCreate: bool}
	 */
	public function listRules( ?string $requestingUser = null ): array
	{
		return [
			'rules'     => $this->ruleService->listRulesFor( $requestingUser ),
			'canCreate' => $this->actsAsAdmin( $requestingUser )
				|| $this->permissionService->canUserEditRules( (string) $requestingUser ),
		];
	}

	/**
	 * Create a rule.
	 *
	 * @return string  The new rule's id
	 * @throws InvalidArgumentException  on an invalid payload, or when
	 *                                   $requestingUser may not create rules
	 *                                   or write to the rule's path
	 * @throws \JsonException
	 */
	public function createRule(
		array   $definition,
		?string $requestingUser = null,
	): string
	{
		$isAdmin = $this->actsAsAdmin( $requestingUser );

		if ( ! $isAdmin && ! $this->permissionService->canUserEditRules( (string) $requestingUser ) )
		{
			throw new InvalidArgumentException( $this->l10n->t( 'This account may not edit rules.' ) );
		}

		$validated = $this->definitionValidator->definitionFrom(
			$definition,
			$requestingUser ?? self::TRUSTED_ACTOR,
			$isAdmin,
		);

		if ( ! $isAdmin
			&& ( $refusal = $this->ruleService->ruleTargetRefusal(
				(string) $requestingUser,
				$validated['path'],
			) ) !== null )
		{
			throw new InvalidArgumentException( $refusal );
		}

		return $this->ruleService->ruleAdd( $validated, $requestingUser ?? self::TRUSTED_ACTOR );
	}

	/**
	 * Update a rule; omitted fields keep their value.
	 *
	 * @throws InvalidArgumentException  on an unknown rule, an invalid
	 *                                   payload, or a caller who may not
	 *                                   change this rule
	 * @throws \JsonException
	 */
	public function updateRule(
		string  $id,
		array   $definition,
		?string $requestingUser = null,
	): void
	{
		$existing = $this->requireRule( $id );
		$isAdmin  = $this->actsAsAdmin( $requestingUser );

		if ( ! $this->mayMutate( $requestingUser, $existing ) )
		{
			throw new InvalidArgumentException( $this->l10n->t( 'This account may not change this rule.' ) );
		}

		$validated = $this->definitionValidator->definitionFrom(
			$definition,
			$requestingUser ?? self::TRUSTED_ACTOR,
			$isAdmin,
			$existing,
		);

		if ( ! $isAdmin
			&& ( $refusal = $this->ruleService->ruleTargetRefusal(
				(string) $requestingUser,
				$validated['path'],
			) ) !== null )
		{
			throw new InvalidArgumentException( $refusal );
		}

		$this->ruleService->ruleUpdate( $id, $validated, $requestingUser ?? self::TRUSTED_ACTOR );
	}

	/**
	 * Delete a rule. A deleted shipped default is recreated (disabled) by
	 * the repair step, so deleting one is reversible housekeeping, not a
	 * decision that needs guarding.
	 *
	 * @throws InvalidArgumentException
	 * @throws \JsonException
	 */
	public function deleteRule(
		string  $id,
		?string $requestingUser = null,
	): void
	{
		$existing = $this->requireRule( $id );

		if ( ! $this->mayMutate( $requestingUser, $existing ) )
		{
			throw new InvalidArgumentException( $this->l10n->t( 'This account may not change this rule.' ) );
		}

		$this->ruleService->ruleDelete( $id, $requestingUser ?? self::TRUSTED_ACTOR );
	}

	/**
	 * Apply a rule now: scan and queue every file it currently governs.
	 *
	 * Synchronous, unlike the REST endpoint (which enqueues a background
	 * job): a DI caller controls its own execution context and usually
	 * wants the result. Wrap it in a job of your own for a large instance.
	 *
	 * @return array{matched: int, marked: int, skipped: int, fresh: int}
	 * @throws InvalidArgumentException
	 */
	public function applyRule(
		string  $id,
		?string $requestingUser = null,
	): array
	{
		$existing = $this->requireRule( $id );

		if ( ! $this->mayMutate( $requestingUser, $existing ) )
		{
			throw new InvalidArgumentException( $this->l10n->t( 'This account may not apply this rule.' ) );
		}

		try
		{
			return $this->ruleService->applyRule(
				$existing,
				null,
				null,
				$requestingUser ?? self::TRUSTED_ACTOR,
			);
		}
		catch ( HintedInvalidArgumentException $e )
		{
			// This API's refusals are in the caller's language, as the
			// others above are; the English stays with the previous.
			throw new InvalidArgumentException( $e->hint, $e->getCode(), $e );
		}
	}

	/** The audit actor named for mutations by trusted (null-user) callers. */
	private const TRUSTED_ACTOR = 'api';

	/**
	 * @throws InvalidArgumentException
	 */
	private function requireRule( string $id ): array
	{
		$rule = $this->ruleService->findRuleById( $id );

		if ( $rule === null )
		{
			// TRANSLATORS: %s is the rule's ID, 32 hexadecimal characters
			throw new InvalidArgumentException( $this->l10n->t( 'No rule with ID "%s".', [ $id ] ) );
		}

		return $rule;
	}

	private function actsAsAdmin( ?string $requestingUser ): bool
	{
		return $requestingUser === null
			|| $this->groupManager->isAdmin( $requestingUser );
	}

	/**
	 * Whether the caller may trigger a recalculation by hand: a trusted
	 * caller or an administrator always, anyone else if the permission names
	 * them. The permission ships allowed, so this is "yes" until an
	 * administrator narrows it.
	 */
	private function mayRecalc( ?string $requestingUser ): bool
	{
		return $this->actsAsAdmin( $requestingUser )
			|| $this->permissionService->isAllowed( PermissionService::PERMISSION_MANUAL_RECALC, $requestingUser );
	}

	/**
	 * The same rule as REST: an administrator may change anything; anyone
	 * else needs the edit permission and the rule has to be their own.
	 */
	private function mayMutate(
		?string $requestingUser,
		array   $rule,
	): bool
	{
		if ( $this->actsAsAdmin( $requestingUser ) )
		{
			return true;
		}

		return $this->permissionService->canUserEditRules( (string) $requestingUser )
			&& $this->ruleService->canUserMutateRule( (string) $requestingUser, $rule );
	}
}
