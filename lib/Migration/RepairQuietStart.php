<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use OC\FilesMetadata\FilesMetadataManager;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\FilecacheBackfill;
use OCA\FileChecksumSearch\BackgroundJob\HashIndexCheck;
use OCA\FileChecksumSearch\BackgroundJob\RuleProcessingJob;
use OCA\FileChecksumSearch\BackgroundJob\StampCheck;
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
 * The app's repair steps, in one class.
 *
 * Every step is idempotent and safe to re-run — `occ maintenance:repair`
 * included, which is the documented lever for working copies that track the
 * repository between releases and therefore never enter the upgrade path.
 * Each carries a {@see RepairStep} attribute naming it and saying whether it
 * is expensive; the attribute is the registry, so adding a step is adding a
 * method.
 *
 * What they cover, in the order they run: the selector model and the two
 * shipped defaults, metadata key registration, the two rebuild paths
 * (from the filecache, and from documents written before the key rename),
 * the stamp of hashes saved without one, index rows that lost their
 * document or their file, and the leftovers of
 * models this app has since abandoned — 'pending:new' rows, the deleted
 * seed job's schedule.
 *
 * The defaults are created **disabled**. No app should silently start doing
 * work the administrator has not configured; two visible disabled rules —
 * `home:*` in band 7 and the universal `*` in band 8 — make the decision one
 * click instead of an empty table. An existing rule, enabled or not, is left
 * exactly as it is: upgrades never turn off what an administrator turned on.
 *
 * Warns and logs rather than throwing: a throwing repair step aborts the
 * whole Nextcloud upgrade, and everything here is recoverable by hand.
 */
class RepairQuietStart
    implements
    IRepairStep
{

//  constants

	/**
	 * Where a queued job's progress can be read, for the line that says it
	 * was queued.
	 */
	private const WHERE_TO_WATCH = '; its progress shows under Administration settings → '
		. 'File Checksum Index & Search → Advanced → Status, and in occ fcias:status.';

	private const LEGACY_SEED_JOB = 'OCA\\FileChecksumSearch\\BackgroundJob\\SeedPendingUpdates';

	/** Seconds between two progress lines of a long step at `-v`. */
	private const PROGRESS_EVERY = 30;

	private const LEGACY_PENDING_NEW = 'pending:new';


//  constructor

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


//  private properties

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

	/**
	 * The steps named for the run under way, or null for a whole repair —
	 * which is what install, enable and upgrade run, inside whatever
	 * request asked for them.
	 *
	 * @var list<string>|null
	 */
	private ?array $only = null;


//  other non-static methods

	public function withExpensive( bool $includeExpensive = true ): self
	{
		$this->includeExpensive = $includeExpensive;

		return $this;
	}


//  getters / setters / is* / has*

	#[\Override]
	public function getName(): string
	{
		return 'File Checksum Index & Search: quiet-start defaults and cleanup';
	}


//  config/init/exe/run methods

	#[\Override]
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
	): array
	{
		$ran        = [];
		$this->only = $only;

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
	): void
	{
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
	 * Move the markers out of the stamp rows, into rows of their own.
	 *
	 * Versions before kept a file's marker — queued, disowned, eroded — in
	 * the string half of its `file-checksum-updated_at` index row, which
	 * Nextcloud rewrites from the metadata document whenever any app saves
	 * it, and the document does not hold the marker. Before every step that
	 * reads markers. Markers are few, the queue and the disowned, so it runs
	 * inline.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'marker-row',
		title: 'Give the queue and disowned markers rows of their own',
		description: 'Moves the markers that say a file is queued or its hashes are disowned out of the row that holds its freshness stamp, which Nextcloud rewrites whenever any app saves the file\'s metadata, into a row this app alone writes. A marker left there is lost on the next such save. Reads no file content; idempotent.',
		expensive: false,
	)]
	private function moveMarkers( IOutput $output ): void
	{
		try
		{
			$moved = $this->metadataService->moveMarkersToStateRows();

			if ( $moved > 0 )
			{
				$output->info( sprintf( 'FCIAS: moved %d marker(s) to rows of their own.', $moved ) );
			}
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not move the markers to rows of their own', $e );
		}
	}

	/**
	 * Copy the checksums Nextcloud already holds into this app's own store.
	 *
	 * `oc_filecache.checksum` is core's column, written when a sync client
	 * uploads with an `OC-Checksum` header and served back over WebDAV. Core
	 * stores that header verbatim, so the values are the client's word rather
	 * than anything this instance computed: only pairs that could be ours are
	 * adopted ({@see AlgorithmCatalogue::keepPlausible()}), and even those are
	 * a shape check, not proof. They are simply not searchable where they sit,
	 * because the column is one unindexed TEXT field. This copies
	 * them across, reading no file content and overwriting no hash this app
	 * already holds.
	 *
	 * Was the first phase of the retired `fcias:rebuild`.
	 *
	 * The copy reads every filecache row that carries a checksum, and a whole
	 * repair runs inside the request that asked for it: enabling the app on
	 * the Apps page ran it for sixteen minutes on an instance with 300,000
	 * such rows. So a whole repair only queues it
	 * ({@see FilecacheBackfill}); named, or with the expensive steps asked
	 * for, it runs here and now, which is what an operator at a console
	 * means.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'rebuild-from-filecache',
		title: 'Copy the checksums Nextcloud already holds',
		description: 'Copies checksums out of Nextcloud\'s own filecache column — the ones sync clients '
		. 'sent on upload and WebDAV serves back — into this app, where they become searchable. Reads no '
		. 'file content and never overwrites a hash this app already has. A whole repair, as installing '
		. 'or enabling the app runs, queues the copy for the background jobs; named here, it runs at '
		. 'once. Run it when clients show a checksum for a file that this app does not know.',
		expensive: true,
	)]
	private function rebuildFromFilecache( IOutput $output ): void
	{
		$named = $this->only !== null && in_array( 'rebuild-from-filecache', $this->only, true );

		if ( ! $named && ! $this->includeExpensive )
		{
			$output->info(
				( FilecacheBackfill::queue( $this->jobList, $this->appConfig )
					? 'FCIAS: queued the copy of the filecache\'s checksums for the background jobs'
					: 'FCIAS: the copy of the filecache\'s checksums is already queued' )
				. self::WHERE_TO_WATCH,
			);

			return;
		}

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
	 * Give the hashes saved without a stamp one.
	 *
	 * A recalculation — `occ fcias:hash`, the sidebar, the API — used to save
	 * hashes without `file-checksum-updated_at`; only the queue stamped. Such
	 * a file counts as never hashed: every `missing` run hashed it again, and
	 * a reset or the index check passed it by. Before the index check, so
	 * that a named run finds them stamped.
	 *
	 * The files are found through the index, cheaply, but stamping one
	 * rewrites its metadata document, and one run could leave tens of
	 * thousands. So a whole repair only queues {@see StampCheck}.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'missing-stamps',
		title: 'Stamp the hashes saved without a stamp',
		description: 'Gives every file whose hashes carry no freshness stamp one: the file\'s modification time where Nextcloud\'s filecache still holds the same hashes; otherwise none, which hides the hashes until the queue has computed them again. Reads no file content. A whole repair, as installing, enabling or upgrading the app runs, queues the work for the background jobs; named here, it runs at once.',
		expensive: true,
	)]
	private function stampUnstampedHashes( IOutput $output ): void
	{
		$named = $this->only !== null && in_array( 'missing-stamps', $this->only, true );

		if ( ! $named && ! $this->includeExpensive )
		{
			$output->info(
				( StampCheck::queue( $this->jobList, $this->appConfig )
					? 'FCIAS: queued the stamping of hashes saved without a stamp for the background jobs'
					: 'FCIAS: the stamping of hashes saved without a stamp is already queued' )
				. self::WHERE_TO_WATCH,
			);

			return;
		}

		try
		{
			$output->info( 'FCIAS: stamping the hashes saved without a stamp.' );

			$result = $this->metadataService->stampUnstampedAfter(
				onFile: $this->reportStamping( $output ),
			);

			$output->info(
				$result['stamped'] === 0
					? 'FCIAS: every stored hash carries a stamp.'
					: sprintf(
					'FCIAS: stamped the hashes of %d files; %d of them went back on the queue to be computed again.',
					$result['stamped'],
					$result['queued'],
				),
			);
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not stamp the hashes saved without a stamp', $e );
		}
	}

	/**
	 * What the stamping says as it goes, at the console's levels: `-v` how
	 * many files it found and, every {@see PROGRESS_EVERY} seconds, how far it
	 * has got; `-vv` each file; `-vvv` each file's hashes. A file it could
	 * not stamp is a warning, at every level.
	 *
	 * Nextcloud's own repair hands a plain `IOutput`, which hears only of the
	 * warnings.
	 *
	 * @return callable(int, array{current: bool, stamp: int, hashes: array<string, string>}|Throwable): void
	 */
	private function reportStamping( IOutput $output ): callable
	{
		$verbose = $output instanceof VerboseOutput ? $output : null;
		$total   = null;

		if ( $verbose?->shows( VerboseOutput::VERBOSE ) )
		{
			$total = $this->metadataService->countUnstampedFiles();
			$verbose->line( VerboseOutput::VERBOSE, sprintf( 'FCIAS: found %d files whose hashes carry no stamp.', $total ) );
		}

		$done = 0;
		$next = time() + self::PROGRESS_EVERY;

		return static function(
			int             $fileId,
			array|Throwable $outcome,
		) use
		(
			$output,
			$verbose,
			$total,
			&$done,
			&$next,
		): void
		{
			if ( $outcome instanceof Throwable )
			{
				$output->warning( sprintf( 'FCIAS: could not stamp the hashes of file %d: %s', $fileId, $outcome->getMessage() ) );

				return;
			}

			$done ++;

			if ( $verbose?->shows( VerboseOutput::VERY_VERBOSE ) )
			{
				$verbose->line(
					VerboseOutput::VERY_VERBOSE,
					sprintf(
						'file %d: %s',
						$fileId,
						$outcome['current']
							? 'stamped ' . date( 'c', $outcome['stamp'] )
							: 'the filecache holds other hashes; stamped 0, hidden and queued',
					),
				);
			}

			if ( $verbose?->shows( VerboseOutput::DEBUG ) )
			{
				foreach ( $outcome['hashes'] as $algo => $hash )
				{
					$verbose->line( VerboseOutput::DEBUG, sprintf( '  %s %s', $algo, $hash ) );
				}
			}

			if ( $total !== null && time() >= $next )
			{
				$verbose?->line( VerboseOutput::VERBOSE, sprintf( 'FCIAS: stamped %d of %d files so far.', $done, $total ) );
				$next = time() + self::PROGRESS_EVERY;
			}
		};
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
	 * A whole repair only queues {@see HashIndexCheck}: even the question
	 * whether anything is missing reads every stamped metadata document, a
	 * minute on an instance with 450,000 index rows, and a whole repair runs
	 * in the request that enables or upgrades the app.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'rebuild-from-metadata',
		title: 'Index the hashes this app has already computed',
		description: 'Writes index rows for hashes the metadata documents hold and the index does not. Reads no file content and changes no stored hash. A whole repair, as installing, enabling or upgrading the app runs, queues the check for the background jobs; named here, it runs at once. Run this when a file\'s details show a hash but searching for that hash finds nothing.',
		expensive: true,
	)]
	private function backfillHashIndex( IOutput $output ): void
	{
		$named = $this->only !== null && in_array( 'rebuild-from-metadata', $this->only, true );

		if ( ! $named && ! $this->includeExpensive )
		{
			$output->info(
				( HashIndexCheck::queue( $this->jobList, $this->appConfig )
					? 'FCIAS: queued the check of the hash index for the background jobs'
					: 'FCIAS: the check of the hash index is already queued' )
				. self::WHERE_TO_WATCH,
			);

			return;
		}

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
	 * That is why it is `manualOnly`. The other expensive steps ask first —
	 * two counts, an empty page — and skip themselves when there is nothing
	 * to do, or queue the asking for the background jobs where even that
	 * costs too much for a request. Here the asking *is* the work, and the
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
					      $qb->createNamedParameter( MetadataService::KEY_FILE_CHECKSUM_STATE ),
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
					      $qb->createNamedParameter( MetadataService::KEY_FILE_CHECKSUM_STATE ),
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
	 * A whole repair leaves the purge to the rule sweep, which already runs
	 * it once a day, batch after batch until nothing is left, and books it
	 * for the status views: it makes that purge due, as deleting a user
	 * does. Even an empty purge reads every hash row to find no orphan —
	 * four seconds on an instance with 450,000 index rows, paid twice when
	 * an installed app is enabled from the Apps page.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection  Invoked through its attribute.
	 */
	#[RepairStep(
		name: 'orphaned-metadata',
		title: 'Forget files that no longer exist',
		description: 'Removes this app\'s metadata and index rows for files that are gone from the '
		. 'filecache — what deleting a user or removing a storage leaves behind, because Nextcloud\'s '
		. 'own cleanup does not run on those paths. Deletes such a file\'s whole metadata document, '
		. 'other apps\' keys included, as Nextcloud does when a file is deleted. Reads no file content. '
		. 'A whole repair, as installing, enabling or upgrading the app runs, makes the daily purge due '
		. 'on the next rule sweep; named here, it purges at once.',
		expensive: true,
	)]
	private function purgeOrphanedMetadata( IOutput $output ): void
	{
		$named = $this->only !== null && in_array( 'orphaned-metadata', $this->only, true );

		if ( ! $named && ! $this->includeExpensive )
		{
			try
			{
				$this->appConfig->setValueInt( Application::APP_ID, RuleProcessingJob::ORPHAN_PURGE_LAST_RUN, 0 );
				$output->info( 'FCIAS: the purge of files that no longer exist runs with the next rule sweep.' );
			}
			catch ( Throwable $e )
			{
				$this->warn( $output, 'could not make the orphan purge due', $e );
			}

			return;
		}

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
	): void
	{
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
