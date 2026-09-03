<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Public;

use OCA\FileChecksumSearch\Service\DuplicateService;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
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
	 * @param  int          $fileId          The filecache fileid
	 * @param  string|null  $requestingUser  When provided, the fileid must resolve
	 *                                       within this user's own file tree or a
	 *                                       NotFoundException is thrown. Omit (or
	 *                                       pass null) for trusted/admin callers
	 *                                       that intentionally bypass this check.
	 *
	 * @return array{fileid: int, hashes: array<int, array{algo: string, hash: string, updated_at: ?string}>, algos: list<string>, preferred: string, default: string}
	 * @throws NotFoundException  If $requestingUser is set and cannot access $fileId
	 */
	public function getHashesByFileId(
		int     $fileId,
		?string $requestingUser = null,
	): array {

		if ( $requestingUser !== null && ! $this->userCanAccessFile( $requestingUser, $fileId ) )
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
		$uid       = $requestingUser ?? $this->userSession->getUser()?->getUID();
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
			'canRecalc' => $this->mayRecalc( $requestingUser ),
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
	 * @param  int          $limit           Max results (1–500)
	 * @param  string|null  $requestingUser  When provided, results are restricted to
	 *                                       files in this user's own home storage.
	 *                                       Omit (or pass null) for trusted/admin
	 *                                       callers that intentionally search
	 *                                       system-wide.
	 *
	 * @return array{results: array<int, array{fileid: int, algo: string, hash: string, path: string, name: string}>}
	 * @throws \InvalidArgumentException  When $hash is empty once trimmed. The
	 *                                    REST layer turns this into a 400.
	 */
	public function findByHash(
		string  $hash,
		?string $algo = null,
		int     $limit = 100,
		?string $requestingUser = null,
	): array {

		$hash = trim( $hash );

		if ( $hash === '' )
		{
			throw new \InvalidArgumentException( 'Hash parameter is required.' );
		}

		$limit = max( 1, min( $limit, 500 ) );

		// No person to scope to — a sudoer reading every account, or the occ
		// command. Instance-wide, resolved against the filecache as before.
		if ( $requestingUser === null )
		{
			$rows = $this->hashIndexService->findByHash( $hash, $algo, $limit, null );

			$results = array_map( static function (
				array $row,
			): array {

				return [
					'fileid' => (int) $row['fileid'],
					'algo'   => $row['algo'],
					'hash'   => $row['hash_value'],
					'path'   => $row['path'],
					'name'   => $row['name'],
				];
			}, $rows );

			return [ 'results' => $results ];
		}

		// Scoped to one account. Spend the limit on rows in that account's
		// mounts — not the query's first N regardless of who owns them, which
		// hid a user's own file behind foreign copies of the same hash — and
		// let getById() be the authority, so shares, group folders and
		// object-store homes count too, not just `home::<uid>`. The same
		// shape {@see findSameHash()} and the unified-search provider use.
		$user = $this->userManager->get( $requestingUser );

		if ( $user === null )
		{
			return [ 'results' => [] ];
		}

		$visibleStorageIds = array_values( array_map(
			static fn ( $mount ) => $mount->getStorageId(),
			$this->userMountCache->getMountsForUser( $user ),
		) );

		$rows = $this->metadataService->confirmFullHash(
			$this->metadataService->queryByHash( $hash, $algo, $limit, $visibleStorageIds ),
			$hash,
		);

		$userFolder = $this->rootFolder->getUserFolder( $requestingUser );
		$results    = [];

		foreach ( $rows as $row )
		{
			$fileId = (int) $row[ MetadataService::FIELD_FILE_ID ];
			$nodes  = $userFolder->getById( $fileId );

			if ( $nodes === [] )
			{
				continue;
			}

			$node     = $nodes[0];
			$relative = $userFolder->getRelativePath( $node->getPath() );

			if ( $relative === null )
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

		return [ 'results' => $results ];
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

		return $this->findDuplicatesFor( $uid, $algo, $minCount, $limit, $offset, $hash, $anywhere );
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
	 * @param  string|list<string>|null  $scope  The account whose files to
	 *                              list, several of them, or null for the
	 *                              whole instance
	 *
	 * @return array{duplicates: array, total_groups: int, pagination: array{offset: int, limit: int}}
	 */
	public function findDuplicatesFor(
		string|array|null $scope,
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?string $hash = null,
		bool    $anywhere = false,
	): array {

		$limit = max( 1, min( $limit, 500 ) );

		return $this->hashIndexService->listDuplicatesForUser( $scope, $algo, $minCount, $limit, $offset, $hash, $anywhere );
	}


	/**
	 * Find other files sharing the same hash values as a given file.
	 *
	 * Both ends are scoped: the reference file must be one $requestingUser
	 * can open, and only duplicates in their own tree are listed.
	 *
	 * @param  int          $fileId          The filecache fileid of the reference file
	 * @param  string|null  $requestingUser  When provided, the reference file
	 *                                       must resolve within this user's
	 *                                       own tree. Omit (or pass null) for
	 *                                       trusted callers that intentionally
	 *                                       read across the instance.
	 *
	 * @return array{duplicates: array<int, array{algo: string, hash_value: string, files: array<int, array{fileid:
	 *                           int, path: string, name: string}>}>}
	 * @throws NotFoundException  If $requestingUser is set and cannot access $fileId
	 */
	public function findSameHash(
		int     $fileId,
		?string $requestingUser = null,
	): array {

		// Before the hashes are read, not after. A hash is a fingerprint of
		// content, so answering for a file the caller cannot open turns this
		// into a content-equality oracle over every file on the instance:
		// sweep the ids, and a non-empty answer says that file holds
		// something you also hold.
		if ( $requestingUser !== null && ! $this->userCanAccessFile( $requestingUser, $fileId ) )
		{
			throw new NotFoundException( "Invalid file ID: $fileId" );
		}

		$hashes = $this->metadataService->getHashes( $fileId );

		if ( empty( $hashes ) )
		{
			return [ 'duplicates' => [] ];
		}

		$user       = $this->userSession->getUser();
		$userFolder = $user !== null
			? $this->rootFolder->getUserFolder( $user->getUID() )
			: null;

		$grouped = [];

		foreach ( $hashes as $algo => $hashValue )
		{
			// queryByHash() compares against the index, which holds at most
			// 63 characters, so a long-hash lookup can return a file that
			// only shares that prefix. One confirmation, shared with every
			// other caller ({@see MetadataService::confirmFullHash()}).
			$rows = $this->metadataService->confirmFullHash(
				$this->metadataService->queryByHash( $hashValue, $algo ),
				$hashValue,
			);

			foreach ( $rows as $row )
			{
				$dupFileId = (int) $row[ MetadataService::FIELD_FILE_ID ];

				if ( $dupFileId === $fileId )
				{
					continue;
				}

				$resolvedPath = '';
				$resolvedName = '';

				if ( $userFolder !== null )
				{
					$nodes = $userFolder->getById( $dupFileId );

					if ( empty( $nodes ) )
					{
						continue;
					}

					$node     = $nodes[0];
					$relative = $userFolder->getRelativePath( $node->getPath() );

					if ( $relative === null )
					{
						continue;
					}

					$resolvedPath = $relative;
					$resolvedName = $node->getName();
				}

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

		return [ 'duplicates' => array_values( $grouped ) ];
	}


	/**
	 * Trigger hash recalculation for a file.
	 *
	 * This is the only mutating operation in the public API.
	 *
	 * @param  int          $fileId          The filecache fileid
	 * @param  string|null  $algo            Algorithm (default: sha1)
	 * @param  string|null  $requestingUser  When provided, the fileid must resolve
	 *                                       within this user's own file tree or
	 *                                       recalculation is refused. Omit (or
	 *                                       pass null) for trusted/admin callers
	 *                                       that intentionally bypass this check.
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
		?string $requestingUser = null,
	): array {

		if ( $requestingUser !== null && ! $this->userCanAccessFile( $requestingUser, $fileId ) )
		{
			return [
				'success' => false,
				'error'   => 'File not found.',
			];
		}

		// Owning the file is not the same as being allowed to make the
		// server work on it: the manual-recalculation permission is checked
		// here, before any rule is consulted, so the reason is the plain one.
		if ( ! $this->mayRecalc( $requestingUser ) )
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

		return $this->hashIndexService->recalcHash( $fileId, $algo );
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


	/**
	 * Whether $uid's own file tree contains $fileId.
	 *
	 * Used to enforce the ownership boundary on the public-facing
	 * (HTTP) surface without restricting trusted DI/bootstrap callers
	 * that intentionally omit $requestingUser.
	 */
	private function userCanAccessFile(
		string $uid,
		int    $fileId,
	): bool {

		try
		{
			$nodes = $this->rootFolder->getUserFolder( $uid )
			                          ->getById( $fileId )
			;
		}
		catch ( \Throwable )
		{
			return false;
		}

		return ! empty( $nodes );
	}

}
