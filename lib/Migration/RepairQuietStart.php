<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use OC\FilesMetadata\FilesMetadataManager;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use ReflectionClass;
use ReflectionMethod;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Post-migration repair for the quiet-start model.
 *
 * Three idempotent cleanups, each safe to re-run (`occ maintenance:repair`
 * included — that is the documented lever for working copies that track the
 * repository between releases and therefore never enter the upgrade path):
 *
 * 1. Ensure a catch-all default rule exists, created **disabled**. No app
 *    should silently start doing work the administrator has not configured;
 *    a disabled band-7 rule makes the decision visible and enabling it one
 *    click, instead of an empty table with nothing to look at. An existing
 *    global rule — enabled or not — is left exactly as it is: upgrades never
 *    turn off what an administrator turned on.
 * 2. Purge leftover 'pending:new' index rows. Under the old model one such
 *    row existed per file to mean "seeded, never considered"; the new
 *    model's word for that is *no row*.
 * 3. Deregister the deleted SeedPendingUpdates job by its FQCN string —
 *    Nextcloud does not remove scheduled instances of a class that no
 *    longer exists, and the class name has to be a literal here because
 *    the class is gone.
 *
 * Warns and logs rather than throwing: a throwing repair step aborts the
 * whole Nextcloud upgrade, and everything here is recoverable by hand.
 */
class RepairQuietStart
	implements
	IRepairStep
{

	private const LEGACY_SEED_JOB = 'OCA\\FileChecksumSearch\\BackgroundJob\\SeedPendingUpdates';

	private const LEGACY_PENDING_NEW = 'pending:new';


	public function __construct(
		private readonly RuleService      $ruleService,
		private readonly MetadataService  $metadataService,
		private readonly HashIndexService $hashIndexService,
		private readonly IAppConfig       $appConfig,
		private readonly IDBConnection    $db,
		private readonly IJobList         $jobList,
		private readonly LoggerInterface  $logger,
	) {
	}


	/**
	 * Whether an expensive step should do its full work rather than the
	 * cheapest thing that is correct.
	 *
	 * False for the automatic run — `maintenance:repair` should not spend an
	 * instance-sized walk to confirm what two counts already say. The command
	 * sets it when asked, which is the only way to reach the cases a guard
	 * cannot see.
	 */
	private bool $includeExpensive = false;


	public function withExpensive( bool $includeExpensive = true ): self
	{

		$this->includeExpensive = $includeExpensive;

		return $this;
	}


	public function getName(): string
	{

		return 'File Checksum Index & Search: quiet-start defaults and cleanup';
	}


	public function run( IOutput $output ): void
	{

		$this->runSteps( $output );
	}


	/**
	 * Run some or all of the steps, and say which ran.
	 *
	 * @param  list<string>|null  $only  Names to run; null for every step.
	 *
	 * @return list<string>  The names that ran, in the order they ran.
	 */
	public function runSteps(
		IOutput $output,
		?array  $only = null,
	): array {

		$ran = [];

		foreach ( $this->steps() as $entry )
		{
			if ( $only !== null && ! in_array( $entry['step']->name, $only, true ) )
			{
				continue;
			}

			// A step that cannot ask cheaply whether it has work waits to be
			// asked for. Naming it counts as asking.
			if ( $entry['step']->manualOnly && $only === null && ! $this->includeExpensive )
			{
				continue;
			}

			$this->runStep( $entry['step'], $entry['method'], $output );
			$ran[] = $entry['step']->name;
		}

		return $ran;
	}


	/**
	 * The steps this class carries, in the order they are declared.
	 *
	 * Read from the methods themselves rather than from a list beside them: a
	 * list is right on the day it is written, and a step added later without
	 * an entry in it would simply never run. Declaration order is the running
	 * order, so the file reads top to bottom the way the repair happens.
	 *
	 * @return list<array{step: RepairStep, method: ReflectionMethod}>
	 */
	public function steps(): array
	{

		$steps = [];

		foreach ( ( new ReflectionClass( $this ) )->getMethods() as $method )
		{
			$attributes = $method->getAttributes( RepairStep::class );

			if ( $attributes === [] )
			{
				continue;
			}

			$steps[] = [
				'step'   => $attributes[0]->newInstance(),
				'method' => $method,
			];
		}

		return $steps;
	}


	/**
	 * Run one step, and let the rest carry on if it will not.
	 *
	 * A repair that abandons the remaining work because one part of it failed
	 * leaves an instance in a state nobody chose. Each step reports its own
	 * outcome; what escapes is logged and named here.
	 */
	private function runStep(
		RepairStep       $step,
		ReflectionMethod $method,
		IOutput          $output,
	): void {

		try
		{
			$method->invoke( $this, $output );
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, sprintf( 'step %s did not finish', $step->name ), $e );
		}
	}


	/**
	 * Canonicalise stored rules to the selector model, then make sure both
	 * shipped defaults exist.
	 *
	 * The resave migrates in one stroke: legacy 'userScope' values ('all',
	 * bare uid, group:<gid>) become canonical selectors, the retired
	 * 'pinned' flag is dropped, and the derived segment/partition ordering
	 * applies. The two defaults — home:* (all home folders) and * (every
	 * storage) — are created disabled iff absent: deleting one is
	 * reversible housekeeping, enabling one is the administrator's explicit
	 * decision, and neither ever flips a rule an administrator configured.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'selector-model',
		title: 'Canonicalise the rules and restore the shipped defaults',
		description: 'Rewrites stored rules into the selector model — legacy scopes become selectors and the retired pinned flag is dropped — and recreates either shipped default that is missing, always disabled. Never changes a rule an administrator configured, and never enables anything.',
		expensive: false,
	)]
	private function ensureSelectorModel( IOutput $output ): void
	{

		try
		{
			$this->ruleService->resaveCanonical();
			$this->retireOffMode( $output );

			$existing = [];

			foreach ( $this->ruleService->loadRules() as $rule )
			{
				if ( RuleService::isDefaultShaped( $rule ) )
				{
					$existing[ RuleService::ruleSelector( $rule )
					                      ->canonical() ]
						= true;
				}
			}

			foreach (
				[
					'home:*',
					'*',
				] as $selector
			)
			{
				if ( isset( $existing[ $selector ] ) )
				{
					continue;
				}

				$this->ruleService->ruleAdd(
					[
						'enabled'        => false,
						'type'           => RuleService::TYPE_INCLUDE,
						'path'           => '**',
						'selector'       => $selector,
						'mode'           => 'auto',
						'algos'          => [
							'sha1',
							'md5',
						],
						'admin_enforced' => false,
					],
					'repair',
				);

				$output->info(
					sprintf(
						'FCIAS: created the %s default rule, disabled. '
						. 'No automatic hashing starts until a rule is enabled.',
						$selector,
					),
				);
			}
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not ensure the selector defaults', $e );
		}
	}


	/**
	 * Convert rules still carrying the retired mode `off` into `ignore`
	 * rules — the verdict that says the same thing and says it properly.
	 *
	 * As a mode, `off` suppressed only event-driven queueing: the periodic
	 * sweep still marked matching files `pending:off`, and the drain, having
	 * no such case, discarded each one with a warning. An `ignore` rule
	 * claims the file and queues nothing by any route, which is what these
	 * rules were reaching for.
	 *
	 * Algorithms and mode are dropped, as they are for any ignore rule: it
	 * computes nothing, so there is nothing for them to say.
	 *
	 * @throws \JsonException
	 */
	private function retireOffMode( IOutput $output ): void
	{

		$converted = 0;

		foreach ( $this->ruleService->loadRules() as $rule )
		{
			if ( ( $rule['mode'] ?? null ) !== 'off' )
			{
				continue;
			}

			// ruleUpdate() replaces the rule wholesale, so the definition is
			// built explicitly: carrying $rule over would put `mode: off`
			// straight back into storage.
			$definition         = $rule;
			$definition['type'] = RuleService::TYPE_IGNORE;
			unset( $definition['mode'], $definition['algos'] );

			$this->ruleService->ruleUpdate(
				(string) ( $rule['id'] ?? '' ),
				$definition,
				'repair',
			);

			$converted ++;
		}

		if ( $converted > 0 )
		{
			$output->info(
				sprintf(
					'FCIAS: converted %d rule(s) from the retired mode "off" to the "ignore" verdict.',
					$converted,
				),
			);
		}
	}


	/**
	 * Re-declare the metadata keys, so an existing instance learns that the
	 * hash keys are no longer Nextcloud's to index.
	 *
	 * The declaration is stored, and the app states it once — from the
	 * install migration. An instance that has already run that migration
	 * keeps whatever it was told then, which for the hash keys was
	 * `indexed: true`: Nextcloud then tries to write the full value into a
	 * `varchar(63)` column, the insert fails for every hash longer than that,
	 * and it swallows the failure as a logged warning. The rows were never
	 * written and searching for a SHA-256 found nothing.
	 *
	 * Restating it here is what makes the fix reach instances that already
	 * exist. It is idempotent — Nextcloud compares before it writes — and
	 * cheap enough to run on every repair.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'metadata-keys',
		title: 'Restate which metadata keys Nextcloud may index',
		description: 'Tells Nextcloud that this app indexes its own hash values. Without it, Nextcloud writes the whole hash into a varchar(63) column, the insert fails for SHA-256 and longer, and it records that as a log line rather than an error — so no index row is written and those hashes cannot be found. Cheap, and safe to run at any time.',
		expensive: false,
	)]
	private function reregisterMetadataKeys( IOutput $output ): void
	{

		try
		{
			$this->metadataService->register();
			$output->info( 'FCIAS: metadata key declarations refreshed.' );
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not refresh the metadata key declarations', $e );
		}
	}


	/**
	 * Copy the checksums Nextcloud already holds into this app's own store.
	 *
	 * `oc_filecache.checksum` is core's column, written when a sync client
	 * uploads with an `OC-Checksum` header and served back over WebDAV. Those
	 * values are trusted and visible to clients — they are simply not
	 * searchable, because the column is one unindexed TEXT field. This copies
	 * them across, reading no file content and overwriting no hash this app
	 * already holds.
	 *
	 * Was the first phase of the retired `fcias:rebuild`.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'rebuild-from-filecache',
		title: 'Copy the checksums Nextcloud already holds',
		description: 'Copies checksums out of Nextcloud\'s own filecache column — the ones sync clients '
		. 'sent on upload and WebDAV serves back — into this app, where they become searchable. Reads no '
		. 'file content and never overwrites a hash this app already has. Run this when clients show a '
		. 'checksum for a file that this app does not know.',
		expensive: true,
	)]
	private function rebuildFromFilecache( IOutput $output ): void
	{

		$copied = $this->hashIndexService->backfillFromFilecache();
		$hashes = (int) ( $copied['hashes'] ?? 0 );
		$files  = (int) ( $copied['files'] ?? 0 );

		$output->info(
			$hashes === 0
				? 'FCIAS: the filecache holds no checksums this app was missing.'
				: sprintf(
				'FCIAS: copied %d checksums for %d files out of the filecache.',
				$hashes,
				$files,
			),
		);
	}


	/**
	 * Give the hash keys their own prefix, wherever they are still without
	 * one.
	 *
	 * Every key this app writes used to be spelled `file-checksum-…`, so no
	 * query could say "the hash keys" without subtracting the stamp by name
	 * — which two of them silently got wrong. `file-checksum-hash-…` says it
	 * instead.
	 *
	 * Declared **before** `rebuild-from-metadata`, and the order carries
	 * weight: that step writes index rows from what a metadata document
	 * says, so meeting an old-spelled document first it would faithfully
	 * write old-spelled index rows and undo this.
	 *
	 * @throws \OCP\DB\Exception
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'key-namespace',
		title: 'Give the hash keys their own prefix',
		description: 'Renames stored hashes from file-checksum-<algo> to '
		. 'file-checksum-hash-<algo>, in both the metadata documents and the index rows, so that a '
		. 'query can ask for the hashes without also matching the freshness stamp. Reads no file '
		. 'content, and does nothing on an instance already renamed.',
	)]
	private function renameHashKeys( IOutput $output ): void
	{

		$renamed   = $this->metadataService->renameLegacyHashKeys();
		$rows      = (int) ( $renamed['rows'] ?? 0 );
		$documents = (int) ( $renamed['documents'] ?? 0 );

		$output->info(
			$rows === 0 && $documents === 0
				? 'FCIAS: the hash keys already have their own prefix.'
				: sprintf(
				'FCIAS: renamed %d index rows and %d metadata documents to the hash prefix.',
				$rows,
				$documents,
			),
		);

		$this->withdrawLegacyDeclarations( $output );
	}


	/**
	 * Tell Nextcloud to forget the keys this app no longer writes.
	 *
	 * `initMetadata()` has no counterpart that withdraws a declaration, but
	 * they are kept in one app-config array, so removing an entry is a read
	 * and a write. Safe here because a repair runs inside the upgrade's
	 * maintenance window, where nothing else is registering keys.
	 *
	 * Without it the old names sit in core's configuration for ever, and a
	 * later reader would reasonably wonder what this app failed to clean up.
	 */
	private function withdrawLegacyDeclarations( IOutput $output ): void
	{

		$declared  = $this->appConfig->getValueArray( 'core', FilesMetadataManager::CONFIG_KEY, lazy: true );
		$withdrawn = 0;

		foreach ( MetadataService::LEGACY_ALGOS as $algo )
		{
			$legacy = MetadataService::legacyHashKey( $algo );

			if ( ! isset( $declared[ $legacy ] ) )
			{
				continue;
			}

			unset( $declared[ $legacy ] );
			$withdrawn ++;
		}

		if ( $withdrawn === 0 )
		{
			return;
		}

		$this->appConfig->setValueArray( 'core', FilesMetadataManager::CONFIG_KEY, $declared, lazy: true );
		$output->info( sprintf( 'FCIAS: withdrew %d superseded metadata key declarations.', $withdrawn ) );
	}


	/**
	 * Write the index rows that were never written.
	 *
	 * An instance that ran before the app took over indexing its own hashes
	 * has them in the metadata documents and nowhere else, for every
	 * algorithm longer than the index column: Nextcloud's insert failed and
	 * it logged rather than raised, so a SHA-256 search found nothing.
	 * Declaring the keys differently (above) stops it happening again; this
	 * is what fixes what already happened.
	 *
	 * Files whose rows already match are skipped, so the run after the first
	 * costs a query per page and no writes.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'rebuild-from-metadata',
		title: 'Index the hashes this app has already computed',
		description: 'Writes index rows for hashes the metadata documents hold and the index does not. Reads no file content and changes no stored hash. Run this when a file\'s details show a hash but searching for that hash finds nothing.',
		expensive: true,
	)]
	private function backfillHashIndex( IOutput $output ): void
	{

		try
		{
			$fixed = $this->metadataService->reindexHashes( force: $this->includeExpensive );

			$output->info(
				$fixed === 0
					? 'FCIAS: every stored hash is indexed.'
					: sprintf( 'FCIAS: indexed the stored hashes of %d files.', $fixed ),
			);
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not index the stored hashes', $e );
		}
	}


	/**
	 * Find the files the index has forgotten entirely.
	 *
	 * Every other correction path starts from a row: the bulk rename from the
	 * index, `rebuild-from-metadata` from the stamp row, the queue from a
	 * pending marker. A file whose index rows are *all* gone has none of
	 * them, so its stored hashes answer nothing and no repair reaches it.
	 * This is the one that does, and the only way to find it is to read every
	 * metadata document the instance holds.
	 *
	 * That is why it is `manualOnly`. Every other expensive step can ask
	 * first — two counts, an empty page — and skip itself in a millisecond
	 * when there is nothing to do. Here the asking *is* the work, and the
	 * answer is almost always none, so a repair that ran it automatically
	 * would scan the whole table on every upgrade to find nothing. An
	 * administrator who has restored a database, or who has a file showing a
	 * hash that no search will return, asks for it by name.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'unindexed-hashes',
		title: 'Find stored hashes the index has no record of at all',
		description: 'Reads every metadata document looking for hashes that have no index rows whatsoever, '
		. 'and writes the rows back. Reads no file content and changes no stored hash. This is the only '
		. 'step that finds a file the index has forgotten completely, and the only one that costs a full '
		. 'scan to find out there is nothing to do — so it never runs on its own. Run it after restoring '
		. 'a database, or when a file shows a hash that searching cannot find and rebuild-from-metadata '
		. 'has already been run.',
		expensive: true,
		manualOnly: true,
	)]
	private function reindexForgottenHashes( IOutput $output ): void
	{

		try
		{
			$fixed = $this->metadataService->reindexUnstampedHashes();

			$output->info(
				$fixed === 0
					? 'FCIAS: every stored hash has index rows.'
					: sprintf( 'FCIAS: gave back the index rows of %d forgotten files.', $fixed ),
			);
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not scan for forgotten hashes', $e );
		}
	}


	/**
	 * Finish what a reset deferred.
	 *
	 * `fcias:reset --hashes` marks rather than clears, so the file leaves
	 * every search at once while the expensive half — one metadata document
	 * rewritten per file — is left for later. This is later. A file an
	 * enabled `include` rule still governs goes back on the queue, because
	 * the operator disowned the stored hashes and not the intent to have
	 * them.
	 *
	 * Reads no file content, which is what makes it a repair: it reconciles
	 * state the instance already holds. Computing the hashes the queue then
	 * asks for is different work, and lives in `fcias:queue:drain`.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'clear-disowned',
		title: 'Finish clearing what a reset disowned',
		description: 'Clears the stored hashes of files a reset marked as disowned, and puts back on '
		. 'the queue those a rule still covers. Reads no file content. A reset leaves this work to the '
		. 'background job; run this to have it done now.',
		expensive: true,
	)]
	private function clearDisowned( IOutput $output ): void
	{

		$cleared = $this->ruleService->clearDisownedFiles( $this->batchLimit() );

		$output->info(
			$cleared === 0
				? 'FCIAS: no disowned files were waiting to be cleared.'
				: sprintf( 'FCIAS: cleared %d disowned files.', $cleared ),
		);
	}


	/**
	 * How many files one pass takes.
	 *
	 * The same limit the background job uses, so an administrator who tuned
	 * it gets it honoured wherever the work happens rather than in one place
	 * only.
	 */
	private function batchLimit(): int
	{

		return max(
			1,
			$this->appConfig->getValueInt( Application::APP_ID, 'pending_batch_limit', 50 ),
		);
	}


	/**
	 * Move erosion markers into the `stale:` namespace.
	 *
	 * The states that mean "these hashes are not to be trusted" are namespaced
	 * so one `LIKE` finds them all and a reason added later is excluded from
	 * scans by construction. Rows written before that carry the bare word.
	 *
	 * @throws \OCP\DB\Exception
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'stale-states',
		title: 'Move erosion markers into the stale namespace',
		description: 'Rewrites the bare marker `eroded` as `stale:eroded`, so that one query finds every file whose hashes are not to be trusted, whatever the reason. One update over rows written before the namespace existed.',
		expensive: false,
	)]
	private function namespaceStaleStates( IOutput $output ): void
	{

		try
		{
			$qb = $this->db->getQueryBuilder();
			$qb->update( MetadataService::TABLE_FILES_METADATA_INDEX )
			   ->set(
				   MetadataService::FIELD_META_VALUE_STRING,
				   $qb->createNamedParameter( MetadataService::STATE_ERODED ),
			   )
			   ->where(
				   $qb->expr()
				      ->eq(
					      MetadataService::FIELD_META_KEY,
					      $qb->createNamedParameter( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT ),
				      ),
				   $qb->expr()
				      ->eq(
					      MetadataService::FIELD_META_VALUE_STRING,
					      $qb->createNamedParameter( MetadataService::LEGACY_STATE_ERODED ),
				      ),
			   )
			;

			$migrated = $qb->executeStatement();

			if ( $migrated > 0 )
			{
				$output->info(
					sprintf(
						'FCIAS: moved %d erosion marker(s) to the "%s" state.',
						$migrated,
						MetadataService::STATE_ERODED,
					),
				);
			}
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not namespace the erosion markers', $e );
		}
	}


	/**
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'legacy-pending',
		title: 'Remove a retired queue state',
		description: 'Deletes queue markers written by a version that used a state this app no longer understands. A file carrying one would sit in the queue for ever, since nothing knows what to do with it.',
		expensive: false,
	)]
	private function purgeLegacyPendingNew( IOutput $output ): void
	{

		try
		{
			$qb = $this->db->getQueryBuilder();
			$qb->delete( MetadataService::TABLE_FILES_METADATA_INDEX )
			   ->where(
				   $qb->expr()
				      ->eq(
					      MetadataService::FIELD_META_KEY,
					      $qb->createNamedParameter( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT ),
				      ),
				   $qb->expr()
				      ->eq(
					      MetadataService::FIELD_META_VALUE_STRING,
					      $qb->createNamedParameter( self::LEGACY_PENDING_NEW ),
				      ),
			   )
			;

			$purged = $qb->executeStatement();

			if ( $purged > 0 )
			{
				$output->info(
					sprintf( 'FCIAS: purged %d legacy pending:new rows.', $purged ),
				);
			}
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not purge legacy pending:new rows', $e );
		}
	}


	/**
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'legacy-seed-job',
		title: 'Remove a retired background job',
		description: 'Unschedules a job whose class no longer exists. Nextcloud does not remove scheduled instances of a class that has gone, so it would keep failing on every cron run.',
		expensive: false,
	)]
	private function removeLegacySeedJob( IOutput $output ): void
	{

		try
		{
			if ( ! $this->jobList->has( self::LEGACY_SEED_JOB, null ) )
			{
				return;
			}

			$this->jobList->remove( self::LEGACY_SEED_JOB );
			$output->info( 'FCIAS: removed the legacy seeding background job.' );
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not remove the legacy seeding job', $e );
		}
	}


	/**
	 * Metadata for files that no longer exist.
	 *
	 * Nextcloud removes an app's metadata when a file goes, but only from
	 * `CacheEntriesRemovedEvent`, which its own bulk teardown paths never
	 * dispatch: deleting a user, removing an external storage and dropping a
	 * group folder each delete the filecache rows in one statement and emit
	 * nothing. The rows they leave still answer a hash search, which is how
	 * they were found — a user could not see their own file for the copies
	 * of it belonging to accounts that no longer existed.
	 *
	 * Nothing announces this, so nothing is waited for: the files are
	 * identified by having index rows and no filecache entry. That also
	 * makes the step impossible to run too early — a file still present is
	 * simply not among them.
	 *
	 * Reads no file content.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'orphaned-metadata',
		title: 'Forget files that no longer exist',
		description: 'Removes this app\'s metadata and index rows for files that are gone from the '
		. 'filecache — what deleting a user or removing a storage leaves behind, because Nextcloud\'s '
		. 'own cleanup does not run on those paths. Keeps a metadata document that another app still '
		. 'uses, and deletes one only when nothing but this app\'s keys was in it. Reads no file content.',
		expensive: true,
	)]
	private function purgeOrphanedMetadata( IOutput $output ): void
	{

		try
		{
			$purged = $this->metadataService->purgeOrphanedMetadata( $this->batchLimit() );

			$output->info(
				$purged === 0
					? 'FCIAS: no metadata was left behind by deleted files.'
					: sprintf( 'FCIAS: forgot %d files that no longer exist.', $purged ),
			);
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not purge orphaned metadata', $e );
		}
	}


	private function warn(
		IOutput   $output,
		string    $what,
		Throwable $e,
	): void {

		$this->logger->error(
			'FCIAS RepairQuietStart: ' . $what,
			[
				'app'       => Application::APP_ID,
				'exception' => $e,
			],
		);

		$output->warning(
			sprintf( 'FCIAS: %s: %s', $what, $e->getMessage() ),
		);
	}

}
