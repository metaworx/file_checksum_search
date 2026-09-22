<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Public;

use OCA\FileChecksumSearch\Service\DuplicateService;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCA\FileChecksumSearch\Service\ReachResolver;
use OCP\Config\IUserConfig;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleDefinitionValidator;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCP\IGroupManager;
use InvalidArgumentException;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
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
	) {
	}


	/**
	 * Get all checksums for a File object.
	 *
	 * Convenience method that resolves the file ID from the File node.
	 *
	 * @param  File  $file  A Nextcloud File node
	 *
	 * @return array{fileid: int, hashes: array<int, array{algo: string, hash: string, updated_at: ?string}>, algos: list<string>, preferred: string, default: string}
	 */
	public function getHashesByFile( File $file ): array
	{

		return $this->getHashesByFileId( $file->getId() );
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
	 * @param  int                $fileId      The filecache fileid
	 * @param  string|null        $actingUser  Who is asking — the session's
	 *                                         account. Null is a trusted
	 *                                         DI/bootstrap caller, for whom
	 *                                         the answer reports what such a
	 *                                         caller may do: everything.
	 * @param  list<string>|null  $reachUids   Whose files may be asked about:
	 *                                         the caller's own account, a
	 *                                         group leader's members, or null
	 *                                         for every account. The file
	 *                                         must lie within one of their
	 *                                         mounts ({@see ReachResolver}).
	 *
	 * @return array{fileid: int, hashes: array<int, array{algo: string, hash: string, updated_at: ?string}>, algos: list<string>, preferred: string, default: string, canRecalc: bool}
	 * @throws NotFoundException  If $reachUids is set and the file lies outside it
	 */
	public function getHashesByFileId(
		int     $fileId,
		?string $actingUser = null,
		?array  $reachUids = null,
	): array {

		if ( ! $this->reach->contains( $this->reach->mountsFor( $reachUids ), $fileId ) )
		{
			throw new NotFoundException( "Invalid file ID: $fileId" );
		}

		$hashes    = $this->metadataService->getHashes( $fileId );
		$updatedAt = $this->metadataService->getUpdatedAt( $fileId );

		$result = [];

		foreach ( $hashes as $algo => $hash )
		{
			$result[] = [
				'algo'       => $algo,
				'hash'       => $hash,
				'updated_at' => $updatedAt !== null
					? date( 'c', $updatedAt )
					: null,
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

		return [
			'hashes'    => $result,
			'fileid'    => $fileId,
			'algos'     => RuleService::maintainsHashes( $rule )
				? array_values( array_filter( (array) ( $rule['algos'] ?? [] ), 'is_string' ) )
				: [],
			'preferred' => $this->catalogue->isValid( $preferred ) ? $preferred : '',
			'default'   => $this->catalogue->default(),
			// So the sidebar can hide its Recalculate buttons for an account
			// that may not, instead of offering them to fail.
			'canRecalc' => $this->mayRecalc( $actingUser ),
		];
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
	 * @return array{fileid: int, path: string, hashes: array<int, array{algo: string, hash: string, updated_at: ?string}>, algos: list<string>, preferred: string, default: string}
	 * @throws NotFoundException  If the path cannot be resolved to a file
	 */
	public function getHashesByPath(
		string  $path,
		?string $user = null,
	): array {

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
			throw new NotFoundException( 'Path does not resolve to a file: ' . $path );
		}

		$fileId         = $node->getId();
		$result         = $this->getHashesByFileId( $fileId );
		$result['path'] = $relative;

		return $result;
	}


	/**
	 * Read-only health/status snapshot.
	 *
	 * @return array{version: string, dbVersion: string, rowCount: int, pendingRows: int}
	 */
	public function getStatus( ?string $requestingUser = null ): array
	{

		// The version is harmless — clients check it for compatibility. The
		// rest describes the instance (its database version, how much it
		// holds, its backlog) and is the administrator's to see, not every
		// account's.
		if ( ! $this->actsAsAdmin( $requestingUser ) )
		{
			return [
				'version' => $this->statusService->getAppVersion(),
			];
		}

		return [
			'version'     => $this->statusService->getAppVersion(),
			'dbVersion'   => $this->statusService->getDbVersion(),
			'rowCount'    => $this->statusService->getHashRowCount(),
			'pendingRows' => $this->statusService->getPendingRowCount(),
		];
	}


	/**
	 * Search for files by hash value, with optional algorithm filter.
	 *
	 * @param  string       $hash            Hex-encoded hash value
	 * @param  string|null  $algo            Optional algorithm filter (sha1, md5, sha256, sha512, sha3-256, sha3-512,
	 *                                       crc32)
	 * @param  int                $limit      Max results (1–500)
	 * @param  list<string>|null  $reachUids  Whose files to search: one account
	 *                                        or several — a group leader's
	 *                                        ceiling — each resolved through
	 *                                        its own mounts, so shares and
	 *                                        group folders count. Null is every
	 *                                        account: a sudoer, or the occ
	 *                                        command. A reach, not a
	 *                                        permission; nothing is checked
	 *                                        against it.
	 *
	 * @return array{results: array<int, array{fileid: int, algo: string, hash: string, path: string, name: string}>}
	 * @throws \InvalidArgumentException  When $hash is empty once trimmed. The
	 *                                    REST layer turns this into a 400.
	 */
	public function findByHash(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
		?array  $reachUids = null,
	): array {

		$hash = trim( $hash );

		if ( $hash === '' )
		{
			throw new \InvalidArgumentException( 'Hash parameter is required.' );
		}

		$limit = max( 1, min( $limit, 500 ) );

		// No reach to scope to — a sudoer reading every account, or the occ
		// command. Instance-wide, resolved against the filecache as before.
		if ( $reachUids === null )
		{
			$rows = $this->hashIndexService->findByHash( $hash, $algo, $limit, null );

			$results = array_map( static function (
				array $row,
			): array {

				return [
					'fileid'   => (int) $row['fileid'],
					'algo'     => $row['algo'],
					'hash'     => $row['hash_value'],
					'path'     => $row['path'],
					'name'     => $row['name'],
					'owner'    => $row['owner'] ?? null,
					'location' => $row['location'] ?? '',
				];
			}, $rows );

			return [ 'results' => $results ];
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

		return [ 'results' => $this->withLocations( $results ) ];
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
	 * @param  int          $offset    Pagination offset (default 0)
	 *
	 * @return array{duplicates: array, total_groups: int, pagination: array{offset: int, limit: int}}
	 */
	public function findDuplicates(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?string $hash = null,
		bool    $anywhere = false,
	): array {

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

		return $this->findDuplicatesFor( [ $uid ], $algo, $minCount, $limit, $offset, $hash, $anywhere );
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
	 *
	 * @return array{duplicates: array, total_groups: int, pagination: array{offset: int, limit: int}}
	 */
	public function findDuplicatesFor(
		?array  $reachUids,
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?string $hash = null,
		bool    $anywhere = false,
	): array {

		$limit = max( 1, min( $limit, 500 ) );

		return $this->hashIndexService->listDuplicatesForUser( $reachUids, $algo, $minCount, $limit, $offset, $hash, $anywhere );
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
	 *
	 * @return array{duplicates: array<int, array{algo: string, hash_value: string, files: array<int, array{fileid:
	 *                           int, path: string, name: string}>}>}
	 * @throws NotFoundException  If $reachUids is set and the reference file lies outside it
	 */
	public function findSameHash(
		int    $fileId,
		?array $reachUids = null,
	): array {

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

		$hashes = $this->metadataService->getHashes( $fileId );

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
			$grouped[ $key ]['files'] = $this->withLocations( $group['files'] );
		}

		return [ 'duplicates' => array_values( $grouped ) ];
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
	 * @param  string|null  $algo        Algorithm (default: sha1)
	 * @param  string|null  $actingUser  Who is asking. The manual-calculation
	 *                                   permission is checked against this
	 *                                   always, and unless `$anyAccount` the
	 *                                   fileid must resolve within their own
	 *                                   file tree. Null is a trusted
	 *                                   DI/bootstrap caller: both are skipped.
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
	 *
	 * @return array{success: bool, algo?: string, hash?: string, existed?: bool, locked?: bool, error?: string, excluded?: bool, ruleId?: string, forbidden?: bool}
	 *         `excluded` says a rule refused the file rather than anything
	 *         going wrong, and names the rule in `ruleId`; `forbidden` says
	 *         the account may not calculate by hand. The REST layer
	 *         answers 403 for either and 400 for every other failure.
	 */
	public function recalcHash(
		int     $fileId,
		?string $algo = null,
		?string $actingUser = null,
		?array  $reachUids = null,
	): array {

		if ( ! $this->reach->contains( $this->reach->mountsFor( $reachUids ), $fileId ) )
		{
			return [
				'success' => false,
				'error'   => 'File not found.',
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
				'error'     => 'This account may not calculate by hand.',
				'forbidden' => true,
			];
		}

		$excludedBy = $this->excludingRuleFor( $fileId );

		if ( $excludedBy !== null )
		{
			return [
				'success'  => false,
				'error'    => 'Hashing is excluded for this path by an administrator rule.',
				'excluded' => true,
				'ruleId'   => $excludedBy,
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
			? [ 'success' => false, 'error' => 'File not found.' ]
			: $this->hashIndexService->recalcFileHash( $file, $algo );
	}


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
	 * @param  string|null        $algo
	 * @param  string|null        $actingUser  As for {@see recalcHash()}.
	 * @param  list<string>|null  $reachUids   As for {@see recalcHash()}.
	 *
	 * @return array{results: list<array{fileid: int, success: bool, algo?: string, hash?: string, existed?: bool, locked?: bool, error?: string, excluded?: bool, ruleId?: string, forbidden?: bool}>, remaining: list<int>}
	 *         `remaining` are the ids not processed, in the order given; the
	 *         caller sends them again.
	 */
	public function recalcMany(
		array   $fileIds,
		?string $algo = null,
		?string $actingUser = null,
		?array  $reachUids = null,
	): array {

		$fileIds = array_values( array_unique( array_map( 'intval', $fileIds ) ) );
		$sizes   = $this->hashIndexService->fileSizes( $fileIds );
		$results = [];
		$bytes   = 0;

		foreach ( $fileIds as $index => $fileId )
		{
			// The filecache's size, or nothing if it does not know the file —
			// recalcHash() will say "not found" for that one at no cost.
			$size = $sizes[ $fileId ] ?? 0;

			// At a cap, unless nothing has been read yet: the first file is
			// always read, so a file larger than the whole budget still can be.
			if ( $index > 0 && ( $index >= self::RECALC_BATCH_FILES || $bytes + $size > self::RECALC_BATCH_BYTES ) )
			{
				return [
					'results'   => $results,
					'remaining' => array_slice( $fileIds, $index ),
				];
			}

			$bytes    += $size;
			$results[] = [ 'fileid' => $fileId ] + $this->recalcHash( $fileId, $algo, $actingUser, $reachUids );
		}

		return [
			'results'   => $results,
			'remaining' => [],
		];
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
	 * @param  list<array{fileid: int}>  $rows
	 *
	 * @return list<array>  the same rows plus `owner: ?string` and `location: string`
	 */
	private function withLocations( array $rows ): array
	{

		if ( $rows === [] )
		{
			return [];
		}

		// No reach filter: what may be listed was decided row by row already;
		// this only finds words for it.
		$located = $this->hashIndexService->batchLookupFilecachePaths( array_column( $rows, 'fileid' ) );

		return array_map(
			static fn ( array $row ): array => $row + [
				'owner'    => $located[ $row['fileid'] ]['owner'] ?? null,
				'location' => $located[ $row['fileid'] ]['location'] ?? '',
			],
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
	): ?array {

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


	/**
	 * The ID of the rule that forbids hashing this file, or null if none does.
	 *
	 * Only `exclude` blocks a deliberate, user-initiated recalculation.
	 * `ignore` merely stops *automatic* hashing — asking for it by hand is
	 * exactly the case it leaves open — and an unmatched file was never
	 * governed by a rule at all.
	 */
	private function excludingRuleFor( int $fileId ): ?string
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
			? (string) ( $rule['id'] ?? '' )
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
	): string {

		$isAdmin = $this->actsAsAdmin( $requestingUser );

		if ( ! $isAdmin && ! $this->permissionService->canUserEditRules( (string) $requestingUser ) )
		{
			throw new InvalidArgumentException( 'This user may not edit rules.' );
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
	): void {

		$existing = $this->requireRule( $id );
		$isAdmin  = $this->actsAsAdmin( $requestingUser );

		if ( ! $this->mayMutate( $requestingUser, $existing ) )
		{
			throw new InvalidArgumentException( 'This user may not change this rule.' );
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
	): void {

		$existing = $this->requireRule( $id );

		if ( ! $this->mayMutate( $requestingUser, $existing ) )
		{
			throw new InvalidArgumentException( 'This user may not change this rule.' );
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
	): array {

		$existing = $this->requireRule( $id );

		if ( ! $this->mayMutate( $requestingUser, $existing ) )
		{
			throw new InvalidArgumentException( 'This user may not apply this rule.' );
		}

		return $this->ruleService->applyRule(
			$existing,
			null,
			null,
			$requestingUser ?? self::TRUSTED_ACTOR,
		);
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
			throw new InvalidArgumentException( sprintf( 'No rule with ID "%s".', $id ) );
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
	): bool {

		if ( $this->actsAsAdmin( $requestingUser ) )
		{
			return true;
		}

		return $this->permissionService->canUserEditRules( (string) $requestingUser )
			&& $this->ruleService->canUserMutateRule( (string) $requestingUser, $rule );
	}


}
