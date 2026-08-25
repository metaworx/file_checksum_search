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
use OCP\Files\IRootFolder;
use OCP\Files\Search\ISearchComparison;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
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

	/** Scope value meaning "every user". */
	public const SCOPE_ALL = 'all';

	/**
	 * Prefix marking a group-scoped rule: `group:<gid>`. Nextcloud user IDs
	 * cannot contain a colon, so this can never collide with a uid.
	 */
	public const SCOPE_GROUP_PREFIX = 'group:';

	/**
	 * Priority bands. Rules are stored and evaluated in band order, first
	 * match wins, so a lower band number is a higher priority.
	 *
	 * Enforced beats unenforced; within each half, specific beats general;
	 * the pinned catch-all is last. Band membership is always *derived* from
	 * a rule's scope and flags — it is never stored.
	 */
	public const BAND_USER_ENFORCED = 1;

	public const BAND_GROUP_ENFORCED = 2;

	public const BAND_GLOBAL_ENFORCED = 3;

	public const BAND_USER = 4;

	public const BAND_GROUP = 5;

	public const BAND_GLOBAL = 6;

	/** The single pinned `**` default. Not orderable. */
	public const BAND_DEFAULT = 7;

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
	 * @var list<string>
	 */
	public const MODES
		= [
			'auto',
			'missing',
			'force',
			'lazy',
			'off',
		];

	/** @var list<string> */
	public const TYPES
		= [
			self::TYPE_INCLUDE,
			self::TYPE_IGNORE,
			self::TYPE_EXCLUDE,
		];


	public function __construct(
		private readonly IAppConfig        $appConfig,
		private readonly IRootFolder       $rootFolder,
		private readonly IUserManager      $userManager,
		private readonly MetadataService   $metadataService,
		private readonly LoggerInterface   $logger,
		private readonly PermissionService $permissionService,
		private readonly IGroupManager     $groupManager,
	) {
	}


	/**
	 * Resolve a rule's scope to the list of user IDs it applies to.
	 *
	 * @return string[]
	 */
	public function resolveUsers( string $userScope ): array
	{

		if ( $userScope === 'all' )
		{
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
		}

		if ( self::scopeKind( $userScope ) === 'group' )
		{
			$groupId = (string) self::scopeGroupId( $userScope );
			$group   = $this->groupManager->get( $groupId );

			if ( $group === null )
			{
				$this->logger->warning(
					'FCIAS: resolveUsers — group not found.',
					[
						'app'     => Application::APP_ID,
						'groupId' => $groupId,
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
		}

		$user = $this->userManager->get( $userScope );

		if ( $user === null )
		{
			$this->logger->warning(
				'FCIAS: resolveUsers — user not found.',
				[
					'app'       => Application::APP_ID,
					'userScope' => $userScope,
				],
			);

			return [];
		}

		return [ $user->getUID() ];
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
	 * @return list<array>
	 */
	public function loadRules(): array
	{

		return self::sortIntoBands( $this->loadRulesRaw() );
	}


	/**
	 * Load rule definitions exactly as stored, without the band sort.
	 *
	 * Only {@see migrateToBands()} needs this: it has to inspect the
	 * *original* slot 0 to recognise the pre-band catch-all convention, and
	 * sorting first would hide it.
	 *
	 * @return list<array>
	 */
	private function loadRulesRaw(): array
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
	 * Which kind of scope a `userScope` value expresses.
	 *
	 * @param  string  $userScope
	 *
	 * @return 'global'|'group'|'user'
	 */
	public static function scopeKind( string $userScope ): string
	{

		if ( $userScope === self::SCOPE_ALL )
		{
			return 'global';
		}

		if ( str_starts_with( $userScope, self::SCOPE_GROUP_PREFIX ) )
		{
			return 'group';
		}

		return 'user';
	}


	/**
	 * The group ID of a group-scoped rule, or null for any other scope.
	 */
	public static function scopeGroupId( string $userScope ): ?string
	{

		return self::scopeKind( $userScope ) === 'group'
			? substr( $userScope, strlen( self::SCOPE_GROUP_PREFIX ) )
			: null;
	}


	/**
	 * The priority band a rule belongs to — always derived, never stored.
	 *
	 * Enforced beats unenforced; within each half, specific beats general;
	 * the pinned catch-all is last. See the BAND_* constants.
	 */
	public static function bandOf( array $rule ): int
	{

		if ( ! empty( $rule['pinned'] ) )
		{
			return self::BAND_DEFAULT;
		}

		$enforced = ! empty( $rule['admin_enforced'] );

		return match ( self::scopeKind( $rule['userScope'] ?? self::SCOPE_ALL ) )
		{
			'user' => $enforced
				? self::BAND_USER_ENFORCED
				: self::BAND_USER,
			'group' => $enforced
				? self::BAND_GROUP_ENFORCED
				: self::BAND_GROUP,
			// must be 'global'
			default => $enforced
				? self::BAND_GLOBAL_ENFORCED
				: self::BAND_GLOBAL,
		};
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
	 * Whether a scope applies to a given user.
	 */
	public function scopeAppliesTo(
		string $userScope,
		string $userId,
	): bool {

		return match ( self::scopeKind( $userScope ) )
		{
			'global' => true,
			'group' => $this->groupManager->isInGroup(
				$userId,
				(string) self::scopeGroupId( $userScope ),
			),
			// must be 'user'
			default => $userScope === $userId,
		};
	}


	/**
	 * Order rules by band, preserving each band's existing internal order.
	 *
	 * `usort()` has been stable since PHP 8.0, so equal-band rules keep the
	 * relative order they were given — which is what makes within-band
	 * position the authoritative priority.
	 *
	 * @param  list<array>  $rules
	 *
	 * @return list<array>
	 */
	public static function sortIntoBands( array $rules ): array
	{

		$rules = array_values( $rules );

		usort(
			$rules,
			static fn(
				array $a,
				array $b,
			): int => self::bandOf( $a ) <=> self::bandOf( $b ),
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

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY_RULES,
			json_encode(
				self::sortIntoBands( self::normalisePinned( $rules ) ),
				JSON_THROW_ON_ERROR,
			),
		);
	}


	/**
	 * Enforce the at-most-one-pinned-rule invariant.
	 *
	 * `pinned` marks the single catch-all default and puts a rule in the
	 * last band. A second pinned rule would give the instance two
	 * "last" rules, one of which could never be reached, so extras are
	 * demoted to ordinary rules of their own scope. Applied at the write
	 * gate, this holds no matter which mutation path set the flag.
	 *
	 * @param  list<array>  $rules
	 *
	 * @return list<array>
	 */
	private static function normalisePinned( array $rules ): array
	{

		$seen = false;

		foreach ( $rules as $index => $rule )
		{
			if ( empty( $rule['pinned'] ) )
			{
				unset( $rules[ $index ]['pinned'] );

				continue;
			}

			if ( $seen )
			{
				unset( $rules[ $index ]['pinned'] );

				continue;
			}

			$rules[ $index ]['pinned'] = true;
			$seen                      = true;
		}

		return array_values( $rules );
	}


	/**
	 * Process a single rule: resolve users, search files, mark stale.
	 *
	 * @param  array  $rule             Rule definition from IAppConfig
	 * @param  int[]  $excludedFileIds  File IDs matched by higher-priority rules
	 *
	 * @return array{marked: int, matched: int, fileIds: int[]}
	 */
	public function processRule(
		array $rule,
		array $excludedFileIds,
	): array {

		$marked  = 0;
		$matched = 0;
		$fileIds = [];

		$mode      = $rule['mode'] ?? 'auto';
		$pathGlob  = $rule['path'] ?? '/';
		$userScope = $rule['userScope'] ?? 'all';
		$batchSize = 100;
		$maintains = self::maintainsHashes( $rule );

		if ( $pathGlob === '' || $pathGlob === '/' )
		{
			$pathGlob = '**';
		}

		$users = $this->resolveUsers( $userScope );

		foreach ( $users as $userId )
		{
			try
			{
				$userFolder = $this->rootFolder->getUserFolder( $userId );
			}
			catch ( Throwable )
			{
				$this->logger->warning(
					'FCIAS RuleService: unable to get user folder, skipping user.',
					[
						'app'    => Application::APP_ID,
						'userId' => $userId,
					],
				);

				continue;
			}

			try
			{
				$files = $this->searchFiles( $userFolder, $pathGlob, $batchSize );
			}
			catch ( Throwable $e )
			{
				$this->logger->warning(
					'FCIAS RuleService: file search failed for user.',
					[
						'app'       => Application::APP_ID,
						'userId'    => $userId,
						'pathGlob'  => $pathGlob,
						'exception' => $e,
					],
				);

				continue;
			}

			foreach ( $files as $file )
			{
				if ( ! $file instanceof File )
				{
					continue;
				}

				$fileId = $file->getId();

				if ( in_array( $fileId, $excludedFileIds, true ) )
				{
					continue;
				}

				$matched ++;
				$fileIds[] = $fileId;

				if ( ! $maintains )
				{
					// The rule claims the file — that is what stops a
					// lower-priority rule from hashing it — but an ignore or
					// exclude verdict means nothing gets queued for it.
					continue;
				}

				$updatedAt = $this->metadataService->getUpdatedAt( $fileId );

				if ( $updatedAt !== null && $updatedAt >= $file->getMTime() )
				{
					continue; // fresh, skip
				}

				$this->metadataService->markPending(
					$fileId,
					MetadataService::PENDING_PREFIX . $mode,
				);

				$marked ++;

				if ( $marked >= $batchSize )
				{
					break 2;
				}
			}
		}

		return [
			'marked'  => $marked,
			'matched' => $matched,
			'fileIds' => $fileIds,
		];
	}


	/**
	 * Find the first enabled rule whose path glob matches the given file path.
	 *
	 * @param  string       $filePath  Path to match against each rule's glob
	 * @param  string|null  $ownerUid  The file's owning user. When provided,
	 *                                 rules scoped to a *different* specific
	 *                                 user are skipped — mirrors the user
	 *                                 resolution {@see processRule()} already
	 *                                 does for the batch path. Omit only when
	 *                                 the caller has no reliable owner to
	 *                                 check against.
	 *
	 * @return array|null Rule definition or null if no match
	 */
	public function findFirstMatchingRule(
		string  $filePath,
		?string $ownerUid = null,
	): ?array {

		$rules = $this->loadRules();

		foreach ( $rules as $rule )
		{
			if ( empty( $rule['enabled'] ) )
			{
				continue;
			}

			$userScope = $rule['userScope'] ?? self::SCOPE_ALL;

			// An unknown owner cannot be tested against a user- or
			// group-scoped rule, so those are left in play rather than
			// silently dropped — preserving the pre-band behaviour.
			if ( $ownerUid !== null && ! $this->scopeAppliesTo( $userScope, $ownerUid ) )
			{
				continue;
			}

			$pathGlob = $rule['path'] ?? '**';

			if ( $pathGlob === '' || $pathGlob === '/' )
			{
				$pathGlob = '**';
			}

			if ( PathUtil::matchesGlob( $pathGlob, $filePath ) )
			{
				return $rule;
			}
		}

		return null;
	}


	/**
	 * Append a new rule at the end of the band its scope and flags place it
	 * in (the stable band sort in {@see saveRules()} does the placing).
	 *
	 * @throws JsonException
	 * @throws \Random\RandomException
	 */
	public function ruleAdd( array $definition ): void
	{

		$rules            = $this->loadRules();
		$definition['id'] = bin2hex( random_bytes( 16 ) );
		$rules[]          = $definition;

		$this->saveRules( $rules );
	}


	/**
	 * @throws JsonException
	 */
	public function ruleDelete( string $id ): void
	{

		$rules = $this->loadRules();
		$rules = array_values(
			array_filter(
				$rules,
				static fn(
					array $rule,
				): bool => ( $rule['id'] ?? '' ) !== $id,
			),
		);

		$this->saveRules( $rules );
	}


	/**
	 * @throws JsonException
	 */
	public function ruleToggle(
		string $id,
		bool   $enabled,
	): void {

		$rules = $this->loadRules();

		foreach ( $rules as &$rule )
		{
			if ( ( $rule['id'] ?? '' ) === $id )
			{
				$rule['enabled'] = $enabled;

				break;
			}
		}
		unset( $rule );

		$this->saveRules( $rules );
	}


	/**
	 * @throws JsonException
	 */
	public function ruleUpdate(
		string $id,
		array  $definition,
	): void {

		$rules = $this->loadRules();

		foreach ( $rules as $index => $existing )
		{
			if ( ( $existing['id'] ?? '' ) !== $id )
			{
				continue;
			}

			$definition['id'] = $id;

			// `pinned` identifies the one catch-all default and is never
			// settable through a rule payload — carry it across the update
			// rather than letting an edit silently unpin it.
			if ( ! empty( $existing['pinned'] ) )
			{
				$definition['pinned'] = true;
			}

			if ( self::bandOf( $definition ) === self::bandOf( $existing ) )
			{
				$rules[ $index ] = $definition;
			}
			else
			{
				// Position is only meaningful within a band, so an edit that
				// changes scope or the enforced flag re-enters at the end of
				// the band it now belongs to (appending before the stable
				// band sort puts it last among its new peers).
				unset( $rules[ $index ] );
				$rules   = array_values( $rules );
				$rules[] = $definition;
			}

			break;
		}

		$this->saveRules( $rules );
	}


	/**
	 * One-time migration of a pre-band rule list into band order.
	 *
	 * Before bands, the rule at slot 0 was the "global rule (priority 0)"
	 * by convention, and evaluation ran front-to-first-match — which made
	 * that catch-all shadow every rule below it. Bands put the catch-all
	 * last, where the original design intended it, so this marks it
	 * `pinned` and sorts everything into band order.
	 *
	 * Idempotent: once a pinned rule exists the marking is skipped, and the
	 * band sort is stable, so repeated runs change nothing.
	 *
	 * @return array{pinnedId: string|null, rules: int}
	 * @throws JsonException
	 */
	public function migrateToBands(): array
	{

		$rules = $this->loadRulesRaw();

		if ( $rules === [] )
		{
			return [
				'pinnedId' => null,
				'rules'    => 0,
			];
		}

		$pinnedId = null;

		foreach ( $rules as $rule )
		{
			if ( ! empty( $rule['pinned'] ) )
			{
				$pinnedId = (string) ( $rule['id'] ?? '' );

				break;
			}
		}

		if ( $pinnedId === null )
		{
			// Slot 0 is the catch-all by the old convention — but only trust
			// it if it really is global-scoped; otherwise take the first
			// global rule, and if there is none, pin nothing (an instance
			// with no default is a legitimate state).
			$target = ( $rules[0]['userScope'] ?? '' ) === self::SCOPE_ALL
				? 0
				: null;

			if ( $target === null )
			{
				foreach ( $rules as $index => $rule )
				{
					if ( ( $rule['userScope'] ?? '' ) === self::SCOPE_ALL )
					{
						$target = $index;

						break;
					}
				}
			}

			if ( $target !== null )
			{
				$rules[ $target ]['pinned'] = true;
				$pinnedId                   = (string) ( $rules[ $target ]['id'] ?? '' );
			}
		}

		$this->saveRules( $rules );

		return [
			'pinnedId' => $pinnedId,
			'rules'    => count( $rules ),
		];
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
	 * Reorder the rules inside one band — the single mutation point for
	 * rule priority.
	 *
	 * Bands make cross-boundary moves structurally impossible rather than
	 * merely validated: a reorder only ever permutes rules *within* one
	 * band, and band membership is derived from a rule's scope and flags
	 * ({@see bandOf()}), so no reorder can promote a rule past one it must
	 * not outrun. Changing a rule's band is done by editing its scope or
	 * its enforced flag, not by dragging.
	 *
	 * $orderedIds must be exactly a permutation of the IDs in the target
	 * band — never trust a client to have submitted the full picture, and
	 * never accept a partial order, which would silently drop rules.
	 *
	 * Band 4 holds every user's own rules, and different users' rules never
	 * compete (they are filtered by scope at match time), so a band-4
	 * reorder targets exactly one owner's segment and leaves every other
	 * owner's rules untouched.
	 *
	 * @param  int                 $band              1..6; {@see BAND_DEFAULT} is not orderable
	 * @param  string|null         $ownerId           required for {@see BAND_USER}; ignored otherwise
	 * @param  array<int, string>  $orderedIds        the band's IDs in their new order
	 * @param  string|null         $requestingUserId  null = admin; a uid restricts the
	 *                                                caller to their own band-4 segment
	 *
	 * @throws InvalidArgumentException  on a non-orderable band, a band the
	 *                                   caller may not touch, or anything
	 *                                   that is not an exact permutation
	 * @throws JsonException
	 */
	public function reorderBand(
		int     $band,
		?string $ownerId,
		array   $orderedIds,
		?string $requestingUserId = null,
	): void {

		if ( $band < self::BAND_USER_ENFORCED || $band > self::BAND_GLOBAL )
		{
			throw new InvalidArgumentException(
				sprintf( 'Band %d cannot be reordered.', $band ),
			);
		}

		if ( $requestingUserId !== null )
		{
			// A non-admin owns nothing outside band 4.
			if ( $band !== self::BAND_USER )
			{
				throw new InvalidArgumentException(
					'You may only reorder your own rules.',
				);
			}

			$ownerId = $requestingUserId;
		}

		if ( $band === self::BAND_USER && ( $ownerId === null || $ownerId === '' ) )
		{
			throw new InvalidArgumentException(
				'ownerId is required when reordering user rules.',
			);
		}

		$rules = $this->loadRules();

		// The slots this reorder may rewrite. Every other slot keeps its
		// current rule, so nothing outside the target segment can move.
		$targetSlots = [];
		$currentIds  = [];

		foreach ( $rules as $index => $rule )
		{
			if ( self::bandOf( $rule ) !== $band )
			{
				continue;
			}

			if ( $band === self::BAND_USER
				&& ( $rule['userScope'] ?? self::SCOPE_ALL ) !== $ownerId )
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
				'orderedIds must be exactly a permutation of the rule IDs in this band.',
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

			$band               = self::bandOf( $rule );
			$positions[ $band ] = ( $positions[ $band ] ?? 0 ) + 1;

			$rule['admin_enforced'] = (bool) ( $rule['admin_enforced'] ?? false );
			$rule['pinned']         = (bool) ( $rule['pinned'] ?? false );
			$rule['band']           = $band;
			$rule['position']       = $positions[ $band ];
			$rule['canEdit']        = $userId === null
				|| ( $canEditAny && $this->canUserMutateRule( $userId, $rule ) );

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

		return $this->scopeAppliesTo( $rule['userScope'] ?? self::SCOPE_ALL, $userId )
			&& $this->isPathVisibleToUser( $userId, $rule['path'] ?? '/' );
	}


	/**
	 * Whether $userId may create/update/delete/toggle/reorder $rule.
	 *
	 * A non-admin's writable surface is exactly band 4 restricted to their
	 * own rules: the rule must not be admin_enforced, must not be the pinned
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

		if ( ! empty( $rule['admin_enforced'] ) || ! empty( $rule['pinned'] ) )
		{
			return false;
		}

		if ( ( $rule['userScope'] ?? self::SCOPE_ALL ) !== $userId )
		{
			return false;
		}

		return $this->isPathWritableByUser( $userId, $rule['path'] ?? '/' );
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
	 * Whether the path's target folder is write-accessible to the user.
	 */
	public function isPathWritableByUser(
		string $userId,
		string $path,
	): bool {

		$folderPath = $this->pathToFolder( $path );

		try
		{
			$userFolder = $this->rootFolder->getUserFolder( $userId );

			if ( $folderPath === '/' )
			{
				return $userFolder->isCreatable();
			}

			$node = $userFolder->get( $folderPath );

			return $node instanceof Folder && $node->isCreatable();
		}
		catch ( Throwable )
		{
			return false;
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

		$likePattern = self::globToLike( $pathGlob );
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

				// SQL LIKE is approximate — PathUtil::matchesGlob ensures exact glob semantics
				if ( ! PathUtil::matchesGlob( $pathGlob, $node->getPath() ) )
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
