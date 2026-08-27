<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Public;

use OCA\FileChecksumSearch\Service\DuplicateService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleDefinitionValidator;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCP\IGroupManager;
use InvalidArgumentException;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
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
	) {
	}


	/**
	 * Get all checksums for a File object.
	 *
	 * Convenience method that resolves the file ID from the File node.
	 *
	 * @param  File  $file  A Nextcloud File node
	 *
	 * @return array{fileid: int, hashes: array<int, array{algo: string, hash: string}>}
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
	 * @return array{fileid: int, hashes: array<int, array{algo: string, hash: string}>}
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

		return [
			'hashes' => $result,
			'fileid' => $fileId,
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
	 * @return array{fileid: int, path: string, hashes: array<int, array{algo: string, hash: string}>}
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
	public function getStatus(): array
	{

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

		$rows = $this->hashIndexService->findByHash( $hash, $algo, $limit, $requestingUser );

		$results = array_map( function (
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


	/**
	 * Find all duplicate hash groups across the system.
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
	): array {

		$limit = max( 1, min( $limit, 500 ) );

		$user = $this->userSession->getUser();
		$uid  = $user?->getUID();

		if ( $uid === null )
		{
			return [
				'duplicates'   => [],
				'total_groups' => 0,
				'pagination'   => [
					'offset' => $offset,
					'limit'  => $limit,
				],
			];
		}

		return $this->hashIndexService->listDuplicatesForUser( $uid, $algo, $minCount, $limit, $offset );
	}


	/**
	 * Find other files sharing the same hash values as a given file.
	 *
	 * @param  int  $fileId  The filecache fileid of the reference file
	 *
	 * @return array{duplicates: array<int, array{algo: string, hash_value: string, files: array<int, array{fileid:
	 *                           int, path: string, name: string}>}>}
	 */
	public function findSameHash( int $fileId ): array
	{

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
			$rows = $this->metadataService->queryByHash( $hashValue, $algo );

			// queryByHash() matches against the truncated index value for
			// hashes longer than the index column allows, so a candidate
			// row may only share a truncated prefix with $hashValue.
			$isTruncatable = strlen( $hashValue ) > MetadataService::META_VALUE_STRING_MAX_LENGTH;

			foreach ( $rows as $row )
			{
				$dupFileId = (int) $row[ MetadataService::FIELD_FILE_ID ];

				if ( $dupFileId === $fileId )
				{
					continue;
				}

				if ( $isTruncatable )
				{
					$extracted = $this->metadataService->extractAlgorithm( $dupFileId, $row );

					if ( ( $extracted['hash'] ?? null ) !== $hashValue )
					{
						continue;
					}
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
	 * @return array{success: bool, algo?: string, hash?: string, fileid?: int, error?: string}
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

		$algo ??= HashCalculationService::getDefaultAlgo();

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
			$nodes = $this->rootFolder->getById( $fileId );
			$node  = $nodes[0] ?? null;

			if ( $node === null )
			{
				return null;
			}

			$rule = $this->ruleService->findFirstMatchingRule(
				$node->getPath(),
				$node->getOwner()
				     ?->getUID(),
			);
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
			&& ! $this->ruleService->isPathWritableByUser( (string) $requestingUser, $validated['path'] ) )
		{
			throw new InvalidArgumentException( 'The path is not in a folder this user can write to.' );
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
			&& ! $this->ruleService->isPathWritableByUser( (string) $requestingUser, $validated['path'] ) )
		{
			throw new InvalidArgumentException( 'The path is not in a folder this user can write to.' );
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
