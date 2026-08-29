<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use InvalidArgumentException;
use JsonException;
use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchQuery;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IHomeStorage;
use OCP\Files\IRootFolder;
use OCP\Files\Search\ISearchComparison;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Rule evaluation engine for hash-generation rules.
 *
 * Loads rules from IAppConfig, resolves user scope, searches for
 * matching files via Folder::search(), checks staleness via
 * MetadataService, and marks stale files as pending:{mode}.
 *
 * Who may edit rules at all is {@see PermissionService}'s concern; this class
 * only asks it, when deciding whether a user may mutate a particular rule.
 */
class RuleService
{

// constants
	private const CONFIG_KEY_RULES = 'rule_definitions';

	/**
	 * Set when an administrator acknowledged the idle banner; cleared by
	 * {@see saveRules()} the moment an enabled include rule exists, so a
	 * later return to the idle state shows the banner afresh (D5).
	 */
	public const CONFIG_KEY_IDLE_BANNER_ACK = 'idle_banner_ack';

	/** Scope value meaning "every user". */
	/**
	 * Prefix marking a group-scoped rule: `group:<gid>`. Nextcloud user IDs
	 * cannot contain a colon, so this can never collide with a uid.
	 */
	/**
	 * Priority bands. Rules are stored and evaluated in band order, first
	 * match wins, so a lower band number is a higher priority.
	 *
	 * Enforced beats unenforced; within each half, specific beats general;
	 * each segment's bare-`**` default last within it. Band membership is always *derived* from
	 * a rule's scope and flags — it is never stored.
	 */
	public const BAND_EXACT_ENFORCED = 1;

	public const BAND_GROUP_ENFORCED = 2;

	public const BAND_NAMESPACE_ENFORCED = 3;

	public const BAND_UNIVERSAL_ENFORCED = 4;

	public const BAND_EXACT = 5;

	public const BAND_GROUP = 6;

	public const BAND_NAMESPACE = 7;

	public const BAND_UNIVERSAL = 8;

	/**
	 * Rule verdicts. The first matching rule decides a file's fate outright —
	 * there is no fall-through and no per-dimension resolution.
	 *
	 * Hashing has two triggers, and the verdicts differ in which they stop:
	 *
	 *  - `include` — maintain hashes automatically, and allow on-demand
	 *                recalculation. The default when `type` is absent.
	 *  - `ignore`  — never hash automatically, but a user may still ask for
	 *                it explicitly. For carve-outs that are about noise.
	 *  - `exclude` — never hash at all, by any route. For storage that must
	 *                not be read: metered links, cold archives.
	 *
	 * Authority comes from band position, not from the verdict: an enforced
	 * exclude is a mandate nothing below can undo, while a user's own
	 * exclude only overrides the defaults beneath it.
	 */
	public const TYPE_INCLUDE = 'include';

	public const TYPE_IGNORE = 'ignore';

	public const TYPE_EXCLUDE = 'exclude';

	/**
	 * Modes an include rule may use — how eagerly it refreshes hashes.
	 *
	 * `new` is deliberately absent: it is an internal pending-queue state
	 * meaning "resolve the rule at processing time", never something a rule
	 * declares about itself.
	 *
	 * `off` is gone: "claim the file, hash nothing automatically" is what the
	 * verdict `ignore` says, properly and in one place. As a mode it only
	 * ever suppressed *event*-driven queueing, so the periodic sweep went on
	 * marking files `pending:off` for a drain that had no such case and
	 * discarded them with a warning. The repair step converts any rule still
	 * carrying it into an `ignore` rule.
	 *
	 * @var list<string>
	 */
	public const MODES
		= [
			'auto',
			'missing',
			'force',
			'lazy',
		];

	/** @var list<string> */
	public const TYPES
		= [
			self::TYPE_INCLUDE,
			self::TYPE_IGNORE,
			self::TYPE_EXCLUDE,
		];

	/** Decoded, sorted rules — memoised per process by {@see loadRules()}. */
	private ?array $rulesCache = null;


	public function __construct(
		private readonly IAppConfig        $appConfig,
		private readonly IRootFolder       $rootFolder,
		private readonly IUserManager      $userManager,
		private readonly MetadataService   $metadataService,
		private readonly LoggerInterface   $logger,
		private readonly PermissionService $permissionService,
		private readonly IGroupManager     $groupManager,
		private readonly FilecacheService  $filecacheService,
	) {
	}


	/**
	 * The users whose home folders a home-universe selector sweeps.
	 *
	 * Only home-kind selectors (and the universal one, whose home half this
	 * is) resolve to users at all; groupfolder: and storage: selectors are
	 * swept by storage, not by user.
	 *
	 * @return string[]
	 */
	public function resolveUsers( string $selectorValue ): array
	{

		$selector = Selector::fromStored( $selectorValue );

		switch ( $selector->kind )
		{
		case Selector::KIND_HOME_ALL:
		case Selector::KIND_UNIVERSAL:
			$allUsers = [];

			$this->userManager->callForAllUsers(
				function (
					$user,
				) use
				(
					&
					$allUsers,
				): void
				{

					$allUsers[] = $user->getUID();
				},
			);

			return $allUsers;

		case Selector::KIND_GROUP:
			$group = $this->groupManager->get( (string) $selector->target );

			if ( $group === null )
			{
				$this->logger->warning(
					'FCIAS: resolveUsers — group not found.',
					[
						'app'     => Application::APP_ID,
						'groupId' => $selector->target,
					],
				);

				return [];
			}

			return array_values(
				array_map(
					static fn(
						IUser $member,
					): string => $member->getUID(),
					$group->getUsers(),
				),
			);

		case Selector::KIND_USER:
			$user = $this->userManager->get( (string) $selector->target );

			if ( $user === null )
			{
				$this->logger->warning(
					'FCIAS: resolveUsers — user not found.',
					[
						'app'      => Application::APP_ID,
						'selector' => $selectorValue,
					],
				);

				return [];
			}

			return [ $user->getUID() ];

		default:
			return [];
		}
	}


	/**
	 * Evaluate all enabled rules and mark stale files as pending.
	 *
	 * Rules are stored in band order and processed top to bottom, so the
	 * first matching rule wins and a lower band is a higher priority
	 * ({@see bandOf()}). Later rules exclude files already matched by
	 * earlier ones.
	 *
	 * @return array{marked: int, matched: int}
	 */
	public function evaluateRules(): array
	{

		$rules   = $this->loadRules();
		$marked  = 0;
		$matched = 0;

		$excludedFileIds = [];

		foreach ( $rules as $rule )
		{
			if ( empty( $rule['enabled'] ) )
			{
				continue;
			}

			$result = $this->processRule(
				$rule,
				$excludedFileIds,
			);

			$marked          += $result['marked'];
			$matched         += $result['matched'];
			$excludedFileIds = array_merge( $excludedFileIds, $result['fileIds'] );
		}

		$this->logger->info(
			'FCIAS RuleService: evaluation complete',
			[
				'app'     => Application::APP_ID,
				'rules'   => count( $rules ),
				'matched' => $matched,
				'marked'  => $marked,
			],
		);

		return [
			'marked'  => $marked,
			'matched' => $matched,
		];
	}


	/**
	 * Load rule definitions in evaluation order.
	 *
	 * {@see saveRules()} already stores them band-sorted, so this sort is
	 * normally a no-op — but evaluation order is the one thing this app must
	 * never get wrong, and the config key is reachable by hand and by
	 * `occ config:app:set`. Sorting on read makes correct evaluation
	 * independent of whether every writer honoured the invariant.
	 *
	 * The decoded, sorted list is memoised for the process: verdict loops
	 * resolve a rule per file, and re-paying the JSON decode and sort per
	 * file bought nothing — IAppConfig already serves the raw string from
	 * its own in-memory cache, so a re-read never saw fresher data anyway.
	 * Every mutation invalidates the memo through {@see saveRules()}, the
	 * single write path.
	 *
	 * @param  bool  $refresh  Drop the memo and re-derive from storage —
	 *                         for callers that must see ground truth, such
	 *                         as the repair step.
	 *
	 * @return list<array>
	 */
	public function loadRules( bool $refresh = false ): array
	{

		if ( ! $refresh && $this->rulesCache !== null )
		{
			return $this->rulesCache;
		}

		return $this->rulesCache = self::sortRules( $this->readStoredRules() );
	}


	/**
	 * The stored list, exactly as persisted — no derived ordering applied.
	 *
	 * @return list<array>
	 */
	private function readStoredRules(): array
	{

		$json = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_KEY_RULES,
			'[]',
		);

		try
		{
			$rules = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		}
		catch ( JsonException )
		{
			return [];
		}

		return is_array( $rules )
			? array_values( $rules )
			: [];
	}


	/**
	 * The rule's selector, reading the canonical 'selector' key and falling
	 * back to the pre-selector 'userScope' key so rules written before the
	 * migration keep working while it runs.
	 */
	public static function ruleSelector( array $rule ): Selector
	{

		return Selector::fromStored(
			(string) ( $rule['selector'] ?? $rule['userScope'] ?? '*' ),
		);
	}


	/**
	 * Whether a rule is default-shaped: its glob is the bare catch-all.
	 *
	 * Shape drives the per-segment partition — within every segment,
	 * default-shaped rules form a trailing sub-segment, so a segment's
	 * catch-all can never shadow the specific rules above it and a newly
	 * created rule never needs dragging past the default.
	 */
	public static function isDefaultShaped( array $rule ): bool
	{

		return in_array(
			$rule['path'] ?? '**',
			[
				'**',
				'',
				'/',
			],
			true,
		);
	}


	/**
	 * The display band a rule occupies — always derived, never stored.
	 *
	 * Band = the selector's specificity rank (exact > group > namespace-wide
	 * > universal), in the enforced tier (1–4) or the unenforced tier (5–8).
	 * Namespaces are disjoint, so rules of different segments in one band
	 * can never compete for a file.
	 */
	public static function bandOf( array $rule ): int
	{

		return self::ruleSelector( $rule )
		           ->band( ! empty( $rule['admin_enforced'] ) )
		;
	}


	/**
	 * A rule's verdict. Absent `type` means include, so every rule written
	 * before verdicts existed keeps behaving exactly as it did.
	 *
	 * @return self::TYPE_*
	 */
	public static function verdictOf( array $rule ): string
	{

		$type = $rule['type'] ?? self::TYPE_INCLUDE;

		return in_array( $type, self::TYPES, true )
			? $type
			: self::TYPE_INCLUDE;
	}


	/**
	 * Whether a rule causes hashes to be maintained automatically.
	 *
	 * The one question the file listeners and the batch job need to ask.
	 */
	public static function maintainsHashes( ?array $rule ): bool
	{

		return $rule !== null && self::verdictOf( $rule ) === self::TYPE_INCLUDE;
	}


	/**
	 * Whether a value is an accepted rule mode.
	 */
	public static function isValidMode( mixed $mode ): bool
	{

		return is_string( $mode ) && in_array( $mode, self::MODES, true );
	}


	/**
	 * Whether a value is an accepted rule type.
	 */
	public static function isValidType( mixed $type ): bool
	{

		return is_string( $type ) && in_array( $type, self::TYPES, true );
	}


	/**
	 * Whether a selector covers this user's own (home) files.
	 *
	 * The home-universe half of matching: exact user, group membership,
	 * all-homes, and the universal selector answer here; groupfolder: and
	 * storage: selectors never do — their files are not anyone's home.
	 */
	public function selectorAppliesTo(
		Selector $selector,
		string   $userId,
	): bool {

		return match ( $selector->kind )
		{
			Selector::KIND_USER => $selector->target === $userId,
			Selector::KIND_GROUP => $this->groupManager->isInGroup( $userId, (string) $selector->target ),
			Selector::KIND_HOME_ALL,
			Selector::KIND_UNIVERSAL => true,
			default => false,
		};
	}


	/**
	 * Derive the evaluation order: band, then segment, then the shape
	 * partition, preserving stored order inside each partition.
	 *
	 * `usort()` has been stable since PHP 8.0, so equal-key rules keep the
	 * relative order they were given — which is what makes within-partition
	 * position the authoritative priority. The partition key is what makes a
	 * segment's bare-`**` default always evaluate after its specific rules:
	 * a newly created rule never needs dragging past the default, and the
	 * old priority inversion cannot recur inside a segment.
	 *
	 * @param  list<array>  $rules
	 *
	 * @return list<array>
	 */
	public static function sortRules( array $rules ): array
	{

		$rules = array_values( $rules );

		usort(
			$rules,
			static function (
				array $a,
				array $b,
			): int {

				$cmp = self::bandOf( $a ) <=> self::bandOf( $b );

				if ( $cmp !== 0 )
				{
					return $cmp;
				}

				$cmp = self::ruleSelector( $a )
				           ->canonical() <=> self::ruleSelector( $b )
				                                 ->canonical()
				;

				if ( $cmp !== 0 )
				{
					return $cmp;
				}

				return (int) self::isDefaultShaped( $a ) <=> (int) self::isDefaultShaped( $b );
			},
		);

		return $rules;
	}


	/**
	 * Persist the rule list — the single write path for rule storage.
	 *
	 * Applies the band sort on every write, so the stored array is always
	 * in evaluation order and no caller has to remember to sort. Route every
	 * mutation through this rather than writing the config key directly.
	 *
	 * @param  list<array>  $rules
	 *
	 * @throws JsonException
	 */
	private function saveRules( array $rules ): void
	{

		// Canonicalise on every write: the selector key in its canonical
		// spelling, legacy keys gone. The stored array is always in
		// evaluation order, so no reader has to remember to sort.
		foreach ( $rules as &$rule )
		{
			$rule['selector'] = self::ruleSelector( $rule )
			                        ->canonical()
			;
			unset( $rule['userScope'], $rule['pinned'] );
		}
		unset( $rule );

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY_RULES,
			json_encode(
				self::sortRules( $rules ),
				JSON_THROW_ON_ERROR,
			),
		);

		// Invalidate rather than assign: the next read re-derives from the
		// persisted JSON, so the memo can never diverge from what a storage
		// round-trip actually yields.
		$this->rulesCache = null;

		// The idle banner's acknowledgement expires the moment hashing is
		// actually on. Done here because this is the single write gate:
		// UI, REST, occ and the PHP API all enable rules through this line.
		foreach ( $rules as $rule )
		{
			if ( ! empty( $rule['enabled'] ) && self::verdictOf( $rule ) === self::TYPE_INCLUDE )
			{
				$this->appConfig->deleteKey( Application::APP_ID, self::CONFIG_KEY_IDLE_BANNER_ACK );

				break;
			}
		}
	}


	/**
	 * Mark one rule's stale files as pending, up to an internal batch cap.
	 *
	 * Storage-paged: the selector resolves to the set of storages it sweeps
	 * and each storage's filecache rows are walked directly — no user views,
	 * no mounts. A share or group folder mounted into someone's home is
	 * therefore never swept as that person's file; every row is classified
	 * once, by identity ({@see FileLocation}).
	 *
	 * $excludedFileIds are files a higher-priority rule already claimed in
	 * this evaluation round; they are skipped entirely.
	 *
	 * @return array{marked: int, matched: int, fileIds: list<int>}
	 */
	public function processRule(
		array $rule,
		array $excludedFileIds,
	): array {

		$marked  = 0;
		$matched = 0;
		$fileIds = [];

		$mode      = $rule['mode'] ?? 'auto';
		$batchSize = 100;
		$maintains = self::maintainsHashes( $rule );
		$excluded  = array_flip( $excludedFileIds );

		try
		{
			foreach ( $this->sweepLocations( $rule ) as $location )
			{
				if ( isset( $excluded[ $location->fileId ] ) )
				{
					continue;
				}

				$matched ++;
				$fileIds[] = $location->fileId;

				if ( ! $maintains )
				{
					// The rule claims the file — that is what stops a
					// lower-priority rule from hashing it — but an ignore or
					// exclude verdict means nothing gets queued for it.
					continue;
				}

				$updatedAt = $this->metadataService->getUpdatedAt( $location->fileId );

				if ( $updatedAt !== null && $updatedAt >= $location->mtime )
				{
					continue; // fresh, skip
				}

				$this->metadataService->markPending(
					$location->fileId,
					MetadataService::PENDING_PREFIX . $mode,
				);

				$marked ++;

				if ( $marked >= $batchSize )
				{
					break;
				}
			}
		}
		catch ( Throwable $e )
		{
			// A rule whose sweep fails is skipped, not fatal: the periodic
			// evaluation must survive one bad storage or one bad rule.
			$this->logger->warning(
				'FCIAS RuleService: rule sweep failed.',
				[
					'app'       => Application::APP_ID,
					'ruleId'    => $rule['id'] ?? null,
					'exception' => $e,
				],
			);
		}

		return [
			'marked'  => $marked,
			'matched' => $matched,
			'fileIds' => $fileIds,
		];
	}


	/**
	 * The numeric storage ids a selector sweeps.
	 *
	 * user: and group: selectors expand to member uids first (the group
	 * manager lives here, not in the filecache layer); everything else maps
	 * straight to storages.
	 *
	 * @return int[]
	 * @throws \OCP\DB\Exception
	 */
	private function storageIdsForSelector( Selector $selector ): array
	{

		return match ( $selector->kind )
		{
			Selector::KIND_USER,
			Selector::KIND_GROUP => $this->filecacheService->homeStorageNumericIds(
				$this->resolveUsers( $selector->canonical() ),
			),
			default => $this->filecacheService->storageNumericIdsFor( $selector ),
		};
	}


	/**
	 * Every file location a rule's selector and glob cover, lazily.
	 *
	 * Walks each swept storage's filecache rows in keyset pages and yields
	 * only classified, in-files-area rows whose namespace-relative path
	 * matches the rule's glob. For a groupfolder selector the per-row folder
	 * id is checked too: the legacy root-jail layout stores many folders on
	 * one storage.
	 *
	 * @return \Generator<FileLocation>
	 * @throws \OCP\DB\Exception
	 */
	private function sweepLocations( array $rule ): \Generator
	{

		$selector = self::ruleSelector( $rule );
		$pathGlob = $rule['path'] ?? '**';

		foreach ( $this->storageIdsForSelector( $selector ) as $storageNumericId )
		{
			$lastFileId = 0;

			while ( true )
			{
				$page = $this->filecacheService->pageStorageFiles( $storageNumericId, $lastFileId, 500 );

				if ( $page === [] )
				{
					break;
				}

				foreach ( $page as $location )
				{
					$lastFileId = $location->fileId;

					if ( $location->relativePath === null )
					{
						continue;
					}

					if ( $selector->kind === Selector::KIND_GROUPFOLDER
						&& $location->groupFolderId !== (int) $selector->target )
					{
						continue;
					}

					if ( ! PathUtil::matchesRelativeGlob( $pathGlob, $location->relativePath ) )
					{
						continue;
					}

					yield $location;
				}
			}
		}
	}


	/**
	 * Whether a selector's slice of the file universe contains this location.
	 *
	 * The universal selector contains everything; a storage selector contains
	 * exactly its raw storage id, whatever namespace that storage serves
	 * (storage:home::alice and home:alice describe the same files); the rest
	 * is per namespace — home files belong to their owner's user, group and
	 * all-homes selectors, group-folder files to their folder's selector, and
	 * neither ever to the other's.
	 */
	public function selectorMatchesLocation(
		Selector     $selector,
		FileLocation $location,
	): bool {

		if ( $selector->kind === Selector::KIND_UNIVERSAL )
		{
			return true;
		}

		if ( $selector->kind === Selector::KIND_STORAGE )
		{
			return $selector->target === $location->storageId;
		}

		return match ( $location->namespace )
		{
			FileLocation::NS_HOME => match ( $selector->kind )
			{
				Selector::KIND_USER => $selector->target === $location->owner,
				Selector::KIND_GROUP => $location->owner !== null
					&& $this->groupManager->isInGroup( $location->owner, (string) $selector->target ),
				Selector::KIND_HOME_ALL => true,
				default => false,
			},
			FileLocation::NS_GROUPFOLDER => $selector->kind === Selector::KIND_GROUPFOLDER
				&& (int) $selector->target === $location->groupFolderId,
			default => false,
		};
	}


	/**
	 * The first enabled rule that governs this location, or null.
	 *
	 * The single verdict path: selectors are matched against the file's
	 * canonical identity and globs against its namespace-relative path — the
	 * same subject the rule's author wrote the glob for, no matter through
	 * whose view or mount the file was reached. Locations outside a files
	 * area (trash bins, versions, appdata) are governed by nothing.
	 *
	 * @param  list<string>  $ignoreRuleIds  Rule IDs to evaluate as though they
	 *                                       did not exist, so the next matching
	 *                                       rule decides. Set aside a rule for
	 *                                       one run without editing it; a file
	 *                                       no other rule matches still falls
	 *                                       through to null.
	 */
	public function governingRuleForLocation(
		FileLocation $location,
		array        $ignoreRuleIds = [],
	): ?array {

		if ( $location->relativePath === null )
		{
			return null;
		}

		$ignore = array_flip( $ignoreRuleIds );

		foreach ( $this->loadRules() as $rule )
		{
			if ( empty( $rule['enabled'] ) )
			{
				continue;
			}

			if ( isset( $ignore[ (string) ( $rule['id'] ?? '' ) ] ) )
			{
				continue;
			}

			if ( ! $this->selectorMatchesLocation( self::ruleSelector( $rule ), $location ) )
			{
				continue;
			}

			if ( PathUtil::matchesRelativeGlob( $rule['path'] ?? '**', $location->relativePath ) )
			{
				return $rule;
			}
		}

		return null;
	}


	/**
	 * The first enabled rule that governs this file, or null.
	 *
	 * Identity-based: the file id resolves to its canonical location
	 * ({@see FilecacheService::locate()}), never to the path of whoever
	 * happens to be acting — a share recipient editing an owner's file is
	 * governed by the rules that govern the owner's file, under the path
	 * the owner (or the group folder) knows it by.
	 *
	 * @param  list<string>  $ignoreRuleIds  See {@see governingRuleForLocation()}.
	 *
	 * @return array|null Rule definition or null if no match
	 * @throws \OCP\DB\Exception
	 */
	public function findFirstMatchingRule(
		int   $fileId,
		array $ignoreRuleIds = [],
	): ?array {

		return $this->governingRulesForFileIds( [ $fileId ], $ignoreRuleIds )[ $fileId ];
	}


	/**
	 * The governing rules for many files, resolved in one scan.
	 *
	 * The batch face of {@see findFirstMatchingRule()}, for loops that
	 * resolve a verdict per file: one filecache query per thousand ids
	 * ({@see FilecacheService::locateAll()}), then in-memory matching
	 * against the memoised rule list. Every requested id has an entry —
	 * null when no filecache row exists, no rule matches, or the row lies
	 * outside a files area.
	 *
	 * @param  list<int>     $fileIds
	 * @param  list<string>  $ignoreRuleIds  See {@see governingRuleForLocation()}.
	 *
	 * @return array<int, array|null>  keyed by file id
	 * @throws \OCP\DB\Exception
	 */
	public function governingRulesForFileIds(
		array $fileIds,
		array $ignoreRuleIds = [],
	): array {

		$locations = $this->filecacheService->locateAll( $fileIds );
		$rules     = [];

		foreach ( $fileIds as $fileId )
		{
			$location = $locations[ $fileId ] ?? null;

			$rules[ $fileId ] = $location === null
				? null
				: $this->governingRuleForLocation( $location, $ignoreRuleIds );
		}

		return $rules;
	}


	/**
	 * Append a new rule at the end of the band its scope and flags place it
	 * in (the stable band sort in {@see saveRules()} does the placing).
	 *
	 * @throws JsonException
	 * @throws \Random\RandomException
	 */
	public function ruleAdd(
		array   $definition,
		?string $actor = null,
	): string {

		$rules            = $this->loadRules();
		$definition['id'] = bin2hex( random_bytes( 16 ) );
		$rules[]          = $definition;

		$this->saveRules( $rules );
		$this->auditLog( 'created', $definition, $actor );

		return $definition['id'];
	}


	/**
	 * @throws JsonException
	 */
	public function ruleDelete(
		string  $id,
		?string $actor = null,
	): void {

		$rules   = $this->loadRules();
		$deleted = null;

		$rules = array_values(
			array_filter(
				$rules,
				static function (
					array $rule,
				) use
				(
					$id,
					&
					$deleted,
				): bool
				{

					if ( ( $rule['id'] ?? '' ) === $id )
					{
						$deleted = $rule;

						return false;
					}

					return true;
				},
			),
		);

		$this->saveRules( $rules );

		if ( $deleted !== null )
		{
			$this->auditLog( 'deleted', $deleted, $actor );
		}
	}


	/**
	 * @throws JsonException
	 */
	public function ruleToggle(
		string  $id,
		bool    $enabled,
		?string $actor = null,
	): void {

		$rules   = $this->loadRules();
		$toggled = null;

		foreach ( $rules as &$rule )
		{
			if ( ( $rule['id'] ?? '' ) === $id )
			{
				$rule['enabled'] = $enabled;
				$toggled         = $rule;

				break;
			}
		}
		unset( $rule );

		$this->saveRules( $rules );

		if ( $toggled !== null )
		{
			$this->auditLog(
				$enabled
					? 'enabled'
					: 'disabled',
				$toggled,
				$actor,
			);
		}
	}


	/**
	 * @throws JsonException
	 */
	public function ruleUpdate(
		string  $id,
		array   $definition,
		?string $actor = null,
	): void {

		$rules = $this->loadRules();

		foreach ( $rules as $index => $existing )
		{
			if ( ( $existing['id'] ?? '' ) !== $id )
			{
				continue;
			}

			$definition['id'] = $id;

			// Same band, same segment, same partition: an in-place edit
			// keeps its position. Anything else re-enters through the
			// derived ordering (appended, so it lands last within its new
			// partition — before the defaults, if it is not one itself).
			$samePlace = self::bandOf( $definition ) === self::bandOf( $existing )
				&& self::ruleSelector( $definition )
				       ->canonical() === self::ruleSelector( $existing )
				                             ->canonical()
				&& self::isDefaultShaped( $definition ) === self::isDefaultShaped( $existing );

			if ( $samePlace )
			{
				$rules[ $index ] = $definition;
			}
			else
			{
				unset( $rules[ $index ] );
				$rules   = array_values( $rules );
				$rules[] = $definition;
			}

			$this->auditLog( 'updated', $definition, $actor, $existing );

			break;
		}

		$this->saveRules( $rules );
	}


	/**
	 * Audit every rule mutation, whichever surface asked for it.
	 *
	 * INFO for ordinary rules; WARNING when the mutation touches an
	 * admin-enforced one — those are the rules someone wrote down as
	 * non-negotiable, so changing one should leave a trace another person
	 * can find. UI, REST, occ and the public PHP API all funnel through the
	 * mutation methods on this class, so no surface can mutate silently.
	 */
	private function auditLog(
		string  $operation,
		array   $rule,
		?string $actor,
		?array  $previous = null,
	): void {

		$enforced = ! empty( $rule['admin_enforced'] ) || ! empty( $previous['admin_enforced'] );

		$context = [
			'app'       => Application::APP_ID,
			'operation' => $operation,
			'ruleId'    => (string) ( $rule['id'] ?? '' ),
			'path'      => $rule['path'] ?? '',
			'selector'  => self::ruleSelector( $rule )
			                   ->canonical(),
			'type'      => self::verdictOf( $rule ),
			'enabled'   => ! empty( $rule['enabled'] ),
			'enforced'  => $enforced,
			'actor'     => $actor ?? 'unknown',
		];

		if ( $enforced )
		{
			$this->logger->warning( 'FCIAS rule audit: admin-enforced rule {operation}', $context );

			return;
		}

		$this->logger->info( 'FCIAS rule audit: rule {operation}', $context );
	}


	/**
	 * Whether a rule can meaningfully be applied at all.
	 *
	 * Shared by {@see applyRule()} and the surfaces that *enqueue* an apply,
	 * so an impossible request is refused at submission time instead of
	 * becoming a background job that can only fail out of sight.
	 *
	 * @throws InvalidArgumentException for a disabled or non-include rule
	 */
	public static function assertApplicable( array $rule ): void
	{

		if ( empty( $rule['enabled'] ) )
		{
			throw new InvalidArgumentException( 'A disabled rule cannot be applied — enable it first.' );
		}

		if ( self::verdictOf( $rule ) !== self::TYPE_INCLUDE )
		{
			throw new InvalidArgumentException(
				sprintf( 'An %s rule computes nothing, so there is nothing to apply.', self::verdictOf( $rule ) ),
			);
		}
	}


	/**
	 * Apply one rule to the files it currently governs: an uncapped,
	 * paged scan that queues every matching stale-or-unhashed file as
	 * pending:<mode>.
	 *
	 * Band discipline holds for a single-rule apply exactly as for the full
	 * sweep: each candidate is resolved through {@see governingRuleForLocation()}
	 * and only marked when *this* rule is the one that governs it — a file
	 * claimed by a higher band is reported as skipped, never marked.
	 *
	 * $modeOverride queues a different processing mode than the rule's own
	 * (the drain still takes verdict and algorithms from the rule itself).
	 * Deviating from an enforced rule's mode is logged at warning level.
	 *
	 * @return array{matched: int, marked: int, skipped: int, fresh: int}
	 * @throws InvalidArgumentException for a disabled or non-include rule, or an unknown mode
	 */
	public function applyRule(
		array            $rule,
		?string          $modeOverride = null,
		?OutputInterface $output = null,
		?string          $actor = null,
	): array {

		self::assertApplicable( $rule );

		$mode = $modeOverride ?? ( $rule['mode'] ?? 'auto' );

		if ( ! self::isValidMode( $mode ) )
		{
			throw new InvalidArgumentException( sprintf( 'Unknown mode "%s".', $mode ) );
		}

		$ruleId = (string) ( $rule['id'] ?? '' );

		$matched = 0;
		$marked  = 0;
		$skipped = 0;
		$fresh   = 0;

		// The same identity-based sweep the periodic evaluation uses — but
		// uncapped: an explicit apply runs to completion; only the periodic
		// sweep trickles.
		foreach ( $this->sweepLocations( $rule ) as $location )
		{
			$matched ++;

			$governing = $this->governingRuleForLocation( $location );

			if ( ( $governing['id'] ?? null ) !== $ruleId )
			{
				$skipped ++;
				$output?->writeln(
					sprintf(
						'    skip %s [claimed by %s]',
						$location->describe(),
						$governing['id'] ?? 'no rule',
					),
					OutputInterface::VERBOSITY_VERBOSE,
				);

				continue;
			}

			// force and lazy act on fresh files by definition; auto and
			// missing have nothing to do where the hash is current.
			if ( in_array(
				$mode,
				[
					'auto',
					'missing',
				],
				true,
			) )
			{
				$updatedAt = $this->metadataService->getUpdatedAt( $location->fileId );

				if ( $updatedAt !== null && $updatedAt >= $location->mtime )
				{
					$fresh ++;

					continue;
				}
			}

			$this->metadataService->markPending(
				$location->fileId,
				MetadataService::PENDING_PREFIX . $mode,
			);

			$marked ++;
			$output?->writeln(
				sprintf( '    queue %s [pending:%s]', $location->describe(), $mode ),
				OutputInterface::VERBOSITY_VERBOSE,
			);
		}

		$context = [
			'app'      => Application::APP_ID,
			'ruleId'   => $ruleId,
			'mode'     => $mode,
			'override' => $modeOverride !== null,
			'matched'  => $matched,
			'marked'   => $marked,
			'skipped'  => $skipped,
			'fresh'    => $fresh,
			'actor'    => $actor ?? 'unknown',
		];

		if ( $modeOverride !== null && ! empty( $rule['admin_enforced'] ) )
		{
			// Deviating from the mode someone wrote down as non-negotiable.
			$this->logger->warning( 'FCIAS rule audit: admin-enforced rule applied with mode override', $context );
		}
		else
		{
			$this->logger->info( 'FCIAS rule audit: rule applied', $context );
		}

		return [
			'matched' => $matched,
			'marked'  => $marked,
			'skipped' => $skipped,
			'fresh'   => $fresh,
		];
	}


	/**
	 * Re-persist the stored rules through the canonical write path.
	 *
	 * One stroke migrates everything saveRules() normalises: legacy
	 * 'userScope' values become canonical selectors, retired keys are
	 * dropped, and the derived ordering applies. Idempotent.
	 *
	 * @return int  Number of rules stored
	 * @throws JsonException
	 */
	public function resaveCanonical(): int
	{

		$rules = $this->loadRules( refresh: true );
		$this->saveRules( $rules );

		return count( $rules );
	}


	/**
	 * Find a rule by its ID.
	 *
	 * @param  string  $id
	 *
	 * @return array|null
	 */
	public function findRuleById( string $id ): ?array
	{

		foreach ( $this->loadRules() as $rule )
		{
			if ( ( $rule['id'] ?? '' ) === $id )
			{
				return $rule;
			}
		}

		return null;
	}


	/**
	 * Reorder the rules inside one segment partition — the single mutation
	 * point for rule priority.
	 *
	 * A segment is one selector value's rules; the partition separates its
	 * regular rules from its bare-`**` defaults. Reordering only ever
	 * permutes rules *within* one partition of one segment: cross-segment
	 * and cross-partition moves are structurally impossible, so no reorder
	 * can promote a rule past one it must not outrun, and a segment's
	 * default can never be dragged above its specific rules.
	 *
	 * $orderedIds must be exactly a permutation of that partition's IDs —
	 * never trust a client to have submitted the full picture, and never
	 * accept a partial order, which would silently drop rules.
	 *
	 * @param  list<string>  $orderedIds
	 * @param  string|null   $requestingUserId  Non-null = a non-admin, who
	 *                                          may only reorder the segment
	 *                                          home:<their own uid>
	 *
	 * @throws InvalidArgumentException
	 * @throws JsonException
	 */
	public function reorderSegment(
		string  $selectorValue,
		bool    $defaultsPartition,
		array   $orderedIds,
		?string $requestingUserId = null,
		?string $actor = null,
	): void {

		$selector  = Selector::parse( $selectorValue );
		$canonical = $selector->canonical();

		if ( $requestingUserId !== null && $canonical !== 'home:' . $requestingUserId )
		{
			throw new InvalidArgumentException( 'You may only reorder your own rules.' );
		}

		$rules = $this->loadRules();

		// The slots this reorder may rewrite. Every other slot keeps its
		// current rule, so nothing outside the target partition can move.
		$targetSlots = [];
		$currentIds  = [];

		foreach ( $rules as $index => $rule )
		{
			if ( self::ruleSelector( $rule )
			         ->canonical() !== $canonical )
			{
				continue;
			}

			if ( self::isDefaultShaped( $rule ) !== $defaultsPartition )
			{
				continue;
			}

			$targetSlots[] = $index;
			$currentIds[]  = (string) ( $rule['id'] ?? '' );
		}

		$submittedIds = array_map( 'strval', $orderedIds );

		$sortedCurrent   = $currentIds;
		$sortedSubmitted = $submittedIds;
		sort( $sortedCurrent );
		sort( $sortedSubmitted );

		if ( $sortedCurrent !== $sortedSubmitted
			|| count( $submittedIds ) !== count( array_unique( $submittedIds ) ) )
		{
			throw new InvalidArgumentException(
				'orderedIds must be exactly a permutation of the rule IDs in this segment partition.',
			);
		}

		$rulesById = [];

		foreach ( $rules as $rule )
		{
			$rulesById[ (string) ( $rule['id'] ?? '' ) ] = $rule;
		}

		foreach ( $targetSlots as $position => $index )
		{
			$rules[ $index ] = $rulesById[ $submittedIds[ $position ] ];
		}

		$this->saveRules( $rules );

		$this->logger->info(
			'FCIAS rule audit: segment {selector} reordered',
			[
				'app'      => Application::APP_ID,
				'selector' => $canonical,
				'defaults' => $defaultsPartition,
				'order'    => $orderedIds,
				'actor'    => $actor ?? $requestingUserId ?? 'unknown',
			],
		);
	}


	/**
	 * List rules for one caller, in band order, annotated for display.
	 *
	 * @param  string|null  $userId  null lists every rule (the admin view);
	 *                               a uid lists the rules that concern that
	 *                               user's own workspace — covered by scope
	 *                               *and* able to reach a path they can see
	 *                               ({@see ruleConcernsUser()}).
	 *
	 * Each rule gains:
	 *  - `band`     — its derived priority band ({@see bandOf()})
	 *  - `position` — 1-based position within that band, counted over the
	 *                 rules *this caller can see*. Another user's band-4
	 *                 rules never compete with this caller's (they are
	 *                 filtered by scope at match time), so numbering them in
	 *                 would only show gaps for rules that cannot affect them.
	 *  - `canEdit`  — whether this caller may mutate it
	 *
	 * @return list<array>
	 */
	public function listRulesFor( ?string $userId ): array
	{

		$canEditAny = $userId === null
			|| $this->permissionService->canUserEditRules( $userId );

		$positions = [];
		$listed    = [];

		foreach ( $this->loadRules() as $rule )
		{
			if ( $userId !== null && ! $this->ruleConcernsUser( $userId, $rule ) )
			{
				continue;
			}

			$selector = self::ruleSelector( $rule )
			                ->canonical()
			;
			$band     = self::bandOf( $rule );

			// A segment is one selector value *within one band*: the same
			// selector's enforced and unenforced rules are different
			// segments and number independently.
			$segmentKey               = $band . '|' . $selector;
			$positions[ $segmentKey ] = ( $positions[ $segmentKey ] ?? 0 ) + 1;

			$rule['admin_enforced'] = (bool) ( $rule['admin_enforced'] ?? false );
			$rule['selector']       = $selector;
			$rule['band']           = $band;
			$rule['position']       = $positions[ $segmentKey ];
			$rule['isDefault']      = self::isDefaultShaped( $rule );
			$rule['canEdit']        = $userId === null
				|| ( $canEditAny && $this->canUserMutateRule( $userId, $rule ) );
			unset( $rule['userScope'], $rule['pinned'] );

			$listed[] = $rule;
		}

		return $listed;
	}


	/**
	 * Whether a rule belongs on a page about this user's own files.
	 *
	 * Both halves must hold: the rule's scope has to cover the user, and its
	 * path has to be able to reach something in their storage.
	 */
	public function ruleConcernsUser(
		string $userId,
		array  $rule,
	): bool {

		$selector = self::ruleSelector( $rule );

		if ( ! $selector->isHomeKind() && $selector->kind !== Selector::KIND_UNIVERSAL )
		{
			// groupfolder:/storage: rules govern shared infrastructure, not
			// anyone's own files — not this page's subject.
			return false;
		}

		return $this->selectorAppliesTo( $selector, $userId )
			&& $this->isPathVisibleToUser( $userId, $rule['path'] ?? '/' );
	}


	/**
	 * Whether $userId may create/update/delete/toggle/reorder $rule.
	 *
	 * A non-admin's writable surface is exactly band 4 restricted to their
	 * own rules: the rule must not be admin_enforced, must be the segment
	 * default, must be scoped to $userId specifically, and its path must be
	 * write-accessible to them.
	 *
	 * Scope 'all' is deliberately NOT accepted. It used to be, which let a
	 * non-admin edit an instance-wide rule's path/mode/algos for every user
	 * on the instance.
	 *
	 * Does NOT check the global rule-editing permission — call
	 * {@see PermissionService::canUserEditRules()} for that separately.
	 */
	public function canUserMutateRule(
		string $userId,
		array  $rule,
	): bool {

		if ( ! empty( $rule['admin_enforced'] ) )
		{
			return false;
		}

		if ( self::ruleSelector( $rule )
		         ->canonical() !== 'home:' . $userId )
		{
			return false;
		}

		return $this->ruleTargetRefusal( $userId, $rule['path'] ?? '/' ) === null;
	}


	/**
	 * Whether a rule's path can reach anything in this user's own storage.
	 *
	 * Scope answers "does this rule cover me"; this answers "could it ever
	 * touch a file I can see". A rule targeting a folder that does not exist
	 * in the user's tree — another department's share, say — is noise on a
	 * page about their own files, even though its scope includes them.
	 *
	 * Only the literal prefix of the glob is tested ({@see pathToFolder()}),
	 * so broad patterns resolve to the user root and are always visible.
	 * Existence is enough: read-only shares are visible, and rules genuinely
	 * do apply to them.
	 */
	public function isPathVisibleToUser(
		string $userId,
		string $path,
	): bool {

		$folderPath = $this->pathToFolder( $path );

		if ( $folderPath === '/' )
		{
			return true;
		}

		try
		{
			return $this->rootFolder->getUserFolder( $userId )
			                        ->nodeExists( $folderPath )
			;
		}
		catch ( Throwable )
		{
			return false;
		}
	}


	/**
	 * Why this user may not target a personal rule at this path — null when
	 * they may.
	 *
	 * Two requirements. The folder must be write-accessible, and it must
	 * live on the user's own home storage: a received share or a mounted
	 * group folder is writable, but a personal rule is 'home:<uid>' and by
	 * identity ({@see governingRuleForLocation()}) never governs another
	 * namespace's files — accepting such a path would store a rule that can
	 * structurally never match anything. Refused with the reason instead.
	 */
	public function ruleTargetRefusal(
		string $userId,
		string $path,
	): ?string {

		$folderPath = $this->pathToFolder( $path );

		try
		{
			$userFolder = $this->rootFolder->getUserFolder( $userId );

			$node = $folderPath === '/'
				? $userFolder
				: $userFolder->get( $folderPath );

			if ( ! $node->getStorage()
			            ->instanceOfStorage( IHomeStorage::class ) )
			{
				return 'The path leads into a received share, a group folder or another mounted storage. '
					. 'A personal rule only governs your own files; those files are governed by their '
					. 'owner\'s or the folder\'s own rules.';
			}

			if ( ! ( $node instanceof Folder ) || ! $node->isCreatable() )
			{
				return 'The path is not in a folder you can write to.';
			}

			return null;
		}
		catch ( Throwable )
		{
			return 'The path is not in a folder you can write to.';
		}
	}


	/**
	 * Derive the literal folder portion of a rule path glob.
	 */
	private function pathToFolder( string $path ): string
	{

		$path = trim( $path );

		if ( $path === '' || $path === '/' || $path === '**' )
		{
			return '/';
		}

		$cut     = strcspn( $path, '*?[{' );
		$literal = substr( $path, 0, $cut );
		$folder  = rtrim( $literal, '/' );

		if ( $folder === '' )
		{
			return '/';
		}

		if ( $folder[0] !== '/' )
		{
			$folder = '/' . $folder;
		}

		return $folder;
	}


	/**
	 * Search for files matching a path glob within a user folder.
	 *
	 * Delegates to {@see searchFilesByGlob()}.
	 *
	 * @return File[]
	 */
	public function searchFiles(
		Folder $userFolder,
		string $pathGlob,
		int    $limit,
	): array {

		return $this->searchFilesByGlob( $userFolder, $pathGlob, $limit );
	}


	/**
	 * Search for files matching a path glob within a folder.
	 *
	 * The glob is matched against paths *relative to the searched folder* —
	 * the same coordinate system rule globs use relative to their namespace
	 * root, so 'Photos/**' finds /alice/files/Photos/x when $folder is
	 * alice's user folder. The SQL LIKE pre-filter gets a leading wildcard
	 * for the same reason: the database compares full paths, the pattern is
	 * relative, and precision comes from the fnmatch post-filter anyway.
	 *
	 * Uses offset-based pagination: fetches SQL batches, filters each
	 * with fnmatch (SQL LIKE over-matches because % matches / while
	 * glob * does not), and stops when enough matches are collected.
	 *
	 * A safety cap limits total scanned rows to 5× the requested
	 * limit to avoid unbounded scanning on very broad patterns.
	 * When $limit <= 0 the search is unlimited (no cap is applied).
	 *
	 * @param  int  $limit  Maximum files to return (a value <= 0 means unlimited)
	 *
	 * @return File[]
	 */
	public function searchFilesByGlob(
		Folder $folder,
		string $pathGlob,
		int    $limit,
		int    $pageSize = 500,
	): array {

		$likePattern = '%' . ltrim( self::globToLike( $pathGlob ), '/%' );
		$folderPath  = rtrim( (string) $folder->getPath(), '/' );
		$unlimited   = $limit <= 0;
		$maxScan     = $unlimited
			? PHP_INT_MAX
			: max( $limit * 5, $pageSize );
		$files       = [];
		$offset      = 0;

		while ( ( $unlimited || count( $files ) < $limit ) && $offset < $maxScan )
		{
			$query = new SearchQuery(
				new SearchComparison(
					ISearchComparison::COMPARE_LIKE,
					'path',
					$likePattern,
				),
				$pageSize,
				$offset,
				[],
			);

			$results = $folder->search( $query );

			if ( empty( $results ) )
			{
				break;
			}

			foreach ( $results as $node )
			{
				if ( ! ( $node instanceof File ) )
				{
					continue;
				}

				// SQL LIKE is approximate — the fnmatch ensures exact glob
				// semantics, on the folder-relative path the glob speaks of.
				$relative = str_starts_with( $node->getPath(), $folderPath . '/' )
					? substr( $node->getPath(), strlen( $folderPath ) )
					: $node->getPath();

				if ( ! PathUtil::matchesRelativeGlob( $pathGlob, $relative ) )
				{
					continue;
				}

				$files[] = $node;

				if ( ! $unlimited && count( $files ) >= $limit )
				{
					break;
				}
			}

			$offset += $pageSize;
		}

		return $files;
	}


	/**
	 * Convert a glob pattern to SQL LIKE pattern.
	 */
	public static function globToLike( string $glob ): string
	{

		$like = str_replace(
			[
				'%',
				'_',
			],
			[
				'\%',
				'\_',
			],
			$glob,
		);

		return str_replace(
			[
				'*',
				'?',
			],
			[
				'%',
				'_',
			],
			$like,
		);
	}

}
