# AP BackupReset v1.3: occ backup, reset and import

*Preliminary — not yet approved. Supersedes v1.2, v1.1 and v1.0; all stay
where they are.*

## Discussion

Three commands over the state the app owns: **backup** (hand it to me),
**reset** (take it away, survivably) and **import** (give it back, or give me
someone else's).

Reset does not rewrite one metadata document per file. It marks each file
**`stale`** — a new state beside `eroded` — and the background job does the
clearing, leaving a window in which an import can arrive and make that work
unnecessary. v1.3 closes the one hole that opened: **a stale file is invisible
to index scans from the moment it is marked**, so the deferral costs nothing in
correctness.

### `stale` and `eroded` are different words for different events

| State | Means | Set by | Cleared by |
|-------|-------|--------|------------|
| `eroded` | the file changed while no rule maintained it, so its hashes were dropped — an involuntary loss | the file listener | re-hashing once a rule covers the file again |
| `stale` | an operator disowned these hashes — they are not to be trusted | `fcias:reset --hashes` | the drain clearing them, or an import replacing them |

Both live in `meta_value_string` on `file-checksum-updated_at`, and neither
matches `pending:%`, so neither pollutes the queue statistics
(`MetadataService.php:61,391-403`).

### Why deferring is safe once scans exclude

Clearing directly means one document write per file — 328 of them on the test
instance, however many there are on a real one — inside a single occ command.
Marking is one bulk `UPDATE`. The app already has machinery to act on a marker
later; that is what the pending queue is.

The objection to deferring was that disowned hashes would remain *findable*
until the job caught up. They will not. Both scan queries share one shape —
`FROM files_metadata_index i INNER JOIN files_metadata m ON i.file_id =
m.file_id` (`MetadataService::queryByHash():672-690` and the duplicates
grouping query at `:757-780`) — so one shared predicate excludes stale files
from all of them:

```php
/**
 * Exclude files whose hashes an operator disowned.
 *
 * Applied by every scan, so a disowned hash stops being findable the moment
 * it is marked rather than when the background job gets to it.
 */
private function andWhereNotStale( IQueryBuilder $qb, string $alias ): void
{
    $sub = $this->db->getQueryBuilder();
    $sub->select( 'x.' . self::FIELD_FILE_ID )
        ->from( self::TABLE_FILES_METADATA_INDEX, 'x' )
        ->where(
            $sub->expr()->eq( 'x.' . self::FIELD_FILE_ID, $alias . '.' . self::FIELD_FILE_ID ),
            $sub->expr()->eq( 'x.' . self::FIELD_META_KEY,
                $qb->createNamedParameter( self::KEY_FILE_CHECKSUM_UPDATED_AT ) ),
            $sub->expr()->eq( 'x.' . self::FIELD_META_VALUE_STRING,
                $qb->createNamedParameter( self::STATE_STALE ) ),
        );

    $qb->andWhere( $qb->expr()->notExists( $sub ) );
}
```

**The cost is one index-only lookup per candidate row.** `f_meta_index` is
`(file_id, meta_key, meta_value_string)` — verified on the test instance —
which is exactly this predicate's shape, leading column first.

### What stays visible, deliberately

The **per-file view** — the sidebar's Checksums tab, `getHashesByFileId()` —
keeps showing a stale file's hashes, labelled as disowned. Hiding them would
tell the user the file has no checksums, which is false; showing them with
their status is the truth, and the Recalculate button beside them is the
remedy. Scans are where a stale hash does damage (a wrong duplicate group, a
search hit for content that may have changed); a per-file view the user
navigated to deliberately is where it informs.

`--now` therefore remains, but as a convenience for the operator who wants the
rows gone before they walk away — not as the fix for a correctness hole.

### Import first wins

An import writing acceptable hashes for a stale file clears the marker, and the
drain then has nothing to do there. That is the sequence: disown everything,
load the checksums the filesystem already knew, recompute only what the import
did not cover.

## Analysis

### What the app owns

- **appconfig**, app id `file_checksum_search`: the ten keys in
  `ConfigLexicon.php`, plus `process_pending_interval` and
  `pending_batch_limit`, read but undeclared (`ProcessPendingUpdates.php:49,97`)
  — Block 0.
- **metadata**: `files_metadata_index` and the `files_metadata` document, keys
  prefixed `file-checksum-`, spanning both index columns:

| Column of `file-checksum-updated_at` | Holds | Slice |
|---|---|---|
| `meta_value_int` | the freshness stamp | `--hashes` |
| `meta_value_string` | `pending:<mode>`, `eroded`, **`stale`** | `--status` |

- **Nothing else.** `oc_filecache.checksum` is Nextcloud's, shared with other
  apps; no command here may write it.

### Identity across instances

Every record is keyed by **(storage id, internal path)** and resolved through
the filecache at load time; file ids are meaningless elsewhere. Import never
creates a file — an unknown path is counted and skipped, or fails under
`--strict`.

### The timestamp is the dangerous part

`updated_at >= mtime` is the freshness test the sweep, the drain and `applyRule`
all use, so **a hash stamped later than the content it describes is invisible to
every correction path the app has**.

| `--stamp=` | Stores | When it is right |
|---|---|---|
| `source` *(default where the input carries timestamps)* | the imported timestamp; **skips entries older than the file's mtime** | restoring a backup |
| `mtime` *(default for a sumfile)* | the file's mtime — the claim `backfillHashes()` already makes | a `sha1sum` run from just now |
| `now` | the import moment. **Warns**: a stale hash imported this way can never be corrected | rarely |

`--allow-stale` imports what `source` would skip, warning per entry.

### The merge primitive already exists

`MetadataService::backfillHashes()` (`:579-618`) writes only absent algorithms
and stamps `updated_at` only when unset — `--merge`, already tested, already
used by the install migration and `rebuild`.

## Implementation Plan

### Block 0 — Declare the two undeclared keys *(prerequisite)*

Add `process_pending_interval` and `pending_batch_limit` to `ConfigLexicon.php`
(INT, 60 and 50, `FLAG_INTERNAL`); `ConfigLexiconTest` count 10 → 12 plus two
`assertContains`.

**Verification:** `composer test:unit --filter ConfigLexiconTest`; the lexicon
warnings leave `data/nextcloud.log`.

### Block 1 — The `stale` state, and scans that ignore it

`MetadataService`:

```php
public const STATE_STALE = 'stale';

public function markStale( array $fileIds ): int;   // one UPDATE, not a document rewrite each
public function markAllStale(): int;
public function countStale(): int;                  // mirrors countEroded()
public function fetchStaleBatch( int $limit = 50 ): array;
private function andWhereNotStale( IQueryBuilder $qb, string $alias ): void;
```

`andWhereNotStale()` is applied by `queryByHash()`, the duplicates grouping
query and `findSameHash()`'s query — every path that *scans* for hashes.
`getHashes()` / `getHashesByFileId()` do not apply it: a file the user opened
shows what is stored, labelled.

Marking writes the string half only, leaving the stamp and the hashes for the
drain or an import.

**Verification:** `MetadataServiceTest` — marking touches neither
`meta_value_int` nor any `file-checksum-<algo>` row; `stale` never matches
`PENDING_LIKE`, so the queue statistics ignore it (the guarantee `eroded`
already has, asserted the same way). Integration: hash two identical files,
mark one stale, and assert it vanishes from `queryByHash()` and from the
duplicate group **before any job runs**, while `getHashesByFileId()` still
returns its hashes.

### Block 2 — The drain acts on `stale`

`ProcessPendingUpdates` takes a stale batch alongside its pending batch. Per
file: clear this app's metadata, drop the marker, and — if an enabled `include`
rule governs the file — mark `pending:<its mode>` so the existing machinery
recomputes it. A file no rule governs is left without hashes.

An import that has already written acceptable hashes cleared the marker, so the
drain never sees it. That case needs no handling: it is the absence of work.

**Verification:** integration — mark a hashed file stale, run the job, assert
the hashes are gone and (with a governing include rule) that they return on the
next pass; mark, import, run, and assert the imported hashes survive untouched.

### Block 3 — `AppStateService`

One service, so "the hashes" cannot mean different things to the exporter, the
eraser and the importer. Not `readonly` (TESTING.md §6.1).

```php
public function exportConfig(): array;
public function countQueueState(): array;                     // pending, eroded, stale
public function exportHashes( int $batch = 500 ): \Generator;
public function importConfig( array $config, bool $replace ): int;
public function importHashes( iterable $records, ImportPolicy $policy ): ImportReport;
public function clearConfig(): int;
public function clearQueueState(): int;
public function markHashesStale(): int;                       // the default path
public function clearHashesNow( int $batch = 500 ): int;      // --now
```

`ImportReport` counts `written / skippedStale / skippedExisting / unknownPath /
overwritten / markerCleared`.

### Block 4 — `fcias:backup`

`--config` / `--status` / `--hashes` (none = all), `-o|--output`, `--pretty`.
JSON with a header (schema version, app version, instance id, timestamp, slices)
so a partial backup cannot pass for a whole one. Exit 1 on an unwritable path,
checked **before** exporting.

### Block 5 — `fcias:reset`

`--config` / `--status` / `--hashes` (none = all), `--force`, `--backup=<path>`,
`--now`.

```
Would remove:
  config   12 app configuration keys (rule_definitions, idle_banner_ack, …)
  status   66 queued files, 4 eroded markers
  hashes   760 stored hashes across 328 files
           → marked stale: excluded from search and duplicates immediately,
             cleared by the background job, or replaced by an import arriving
             first. --now clears them here instead.
Nothing was changed. Re-run with --force to apply.
```

`--backup` exports first and aborts before touching anything if that fails.
`--force` logs one audit line at **warning** level naming actor and slices.

**Verification:** the central assertion is that a default run calls no mutating
method at all; `--force` marks stale and rewrites no document; `--now` does the
opposite; a failing `--backup` aborts first.

### Block 6 — `fcias:import`

`--merge` | `--replace` (**one required**), `--config` / `--hashes`
(`--status` refused, with the reason), `--format=json|csv|sum`, `--algo=`,
`--user=` | `--storage=`, `--stamp=`, `--allow-stale`, `--strict`, `--dry-run`.
Writing acceptable hashes clears any `stale` marker on the file.

**Verification:** a policy test per row of the stamp table; parser tests for all
three formats including `sha1sum` output with two-space separators and paths
containing spaces; live round trip on instance 34 — backup, `reset --hashes
--force`, import, counts restored — and a `sum` import anchored with `--user`.
**Path anchoring gets its own pass**: a mis-anchored import silently attaches
hashes to the wrong files, which is worse than importing nothing.

### Block 7 — Status surface

`stale` joins the status page and `occ …:status` next to `eroded`, so "reset
ran, the job has not caught up" is visible rather than mysterious.

### Block 8 — Fixtures and the e2e reset

`cy.resetFciasState()` (reset `--force --now` + `maintenance:repair`) and
`cy.importFciasFixture(name)` from `tests/e2e/fixtures/`; a `duplicates.json`
fixture so that spec stops depending on uploads accumulating across runs.
**Depends on `AP_E2ETests_v1.1`.**

**Verification:** `duplicates.cy.js` passes twice in a row, which it does not
today.

### Block 9 — Documentation

README (three commands, count 12 → 15; a *Backing up, resetting and importing*
section covering the slices, the dry-run default, `stale` and what it hides,
and the stamp policies), `docs/FAQ.md` ("How do I start over?", "I already have
checksums — can I load them?"), CHANGELOG (`### Added`).

## Proposed commit message

```
[TASK] occ commands to back up, reset and import the app's own state

The app owns three slices of state — its appconfig keys, the pending
queue, and the stored hashes — and could neither hand them to you, take
them away safely, nor accept them from anywhere else. A shell mistake
could empty rule_definitions with nothing to restore from; the e2e suite
had no way to start from a known state; and an instance whose filesystem
already knew every checksum still had to read every byte to learn them
again.

fcias:backup writes any or all slices as one JSON document, identifying
files by storage and path rather than by file id. fcias:import loads
config and hashes back — from that document, from a CSV, or from the
output of sha1sum — requiring --merge or --replace so that neither is a
default anyone gets by accident. fcias:reset removes the same slices,
but only reports what it would remove until --force, and can take a
backup first and refuse to continue if that fails.

Resetting hashes does not rewrite one metadata document per file. It
marks each file stale — a new state beside eroded — and the background
job does the clearing, which leaves a window in which an import can
arrive and make the work unnecessary. A stale file is excluded from
every scan the moment it is marked, so nothing findable outlives the
decision to disown it: one shared predicate on the index the app already
has, keyed exactly as f_meta_index is. The file's own sidebar still
shows what is stored, labelled, because telling someone their file has
no checksums would be a different lie.

Import will not load the pending queue: a sweep reconstructs it, and
restoring one would enqueue work against rules that have since changed.
It is careful with timestamps for a sharper reason: freshness is decided
by updated_at >= mtime, so a hash stamped later than the content it
describes is invisible to every correction path the app has. Imports
skip records older than the file they describe unless told otherwise,
and say loudly when they are told otherwise.
```

## Change History

| Version | Date | Change |
|---------|------|--------|
| v1.3 | 2026-08-29 | Stale files are excluded from index scans the moment they are marked (user's question), so deferring the clearing costs nothing in correctness: one shared `andWhereNotStale()` predicate applied by `queryByHash()`, the duplicates grouping query and `findSameHash()`, carried by the existing `f_meta_index (file_id, meta_key, meta_value_string)` as an index-only lookup. The per-file view deliberately keeps showing a stale file's hashes, labelled — hiding them would claim the file has none. `--now` is demoted from correctness fix to convenience. |
| v1.2 | 2026-08-29 | Reset marks files **`stale`** rather than rewriting metadata documents; the background job clears them unless an import arrives first (user's design). Added the state, the drain handling, the status count, `--now`, and an explicit statement of the window. |
| v1.1 | 2026-08-29 | Adds `fcias:import` and fixtures. Restates the slice boundary: `file-checksum-updated_at` spans both index columns. Turns the three timestamp policies into `--stamp=source\|mtime\|now` plus `--allow-stale`, with `source` as the default, because stamping import time makes a stale hash permanently invisible to the sweep. Records that `backfillHashes()` is already the merge primitive. |
| v1.0 | 2026-08-29 | Initial plan: backup and reset. Written after the e2e analysis named an occ state reset the highest-value missing test helper, and after a maintenance session wiped an instance's `rule_definitions` with no backup to recover from. |
