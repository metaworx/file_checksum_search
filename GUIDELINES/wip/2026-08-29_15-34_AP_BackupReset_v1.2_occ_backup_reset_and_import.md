# AP BackupReset v1.2: occ backup, reset and import

*Preliminary — not yet approved. Supersedes v1.1 (which added import) and
v1.0 (backup and reset only); both stay where they are.*

## Discussion

Three commands over the state the app owns:

- **backup** — hand it to me.
- **reset** — take it away, survivably.
- **import** — give it back, or give me someone else's.

v1.2 changes how reset removes hashes, on the user's design: it does **not**
rewrite `files_metadata` per file. It marks each file **`stale`** — a new state
alongside `eroded` in the same column — and lets the background job do the
work, which also leaves a window for an import to arrive first and make the
work unnecessary.

### Why deferring matters

Clearing hashes directly means rewriting one metadata document per file: on an
instance with 328 hashed files that is 328 document writes inside one occ
command, and on a real instance it is however many files there are. Marking is
one bulk `UPDATE` over the index. The app already has the machinery to act on a
marker later — that is what the pending queue *is* — so reset should use it
rather than becoming a second, slower path to the same end.

### `stale` and `eroded` are different words for different events

| State | Means | Set by | Cleared by |
|-------|-------|--------|------------|
| `eroded` | the file changed while no rule maintained it, so its hashes were dropped — an involuntary loss | the file listener | re-hashing once a rule covers the file again |
| `stale` | an operator disowned these hashes — they are not to be trusted | `fcias:reset --hashes` | the drain clearing them, or an import replacing them |

Both live in `meta_value_string` on `file-checksum-updated_at`, and neither
matches `pending:%`, so neither pollutes the queue statistics
(`MetadataService.php:61,391-403`).

### The honest consequence: a window

Between the marking and the job, **the hashes are still stored and still
searchable**. The marker says they are disowned, not that they are gone. That
is a real difference from the v1.1 plan and has to be said out loud rather than
discovered:

- Search and the duplicate browser will still return them until the drain runs.
- `--now` therefore exists as the escape hatch: clear synchronously, the slow
  way, for the operator who needs them gone before they walk away.
- The status page counts stale files next to eroded ones, so "reset ran, the
  job has not caught up" is visible rather than mysterious.

### Import first wins

An import that writes acceptable hashes for a stale file clears the marker, and
the drain then has nothing to do there. That is the sequence the user named —
reset, then import, and the job never recomputes what was handed to it. It also
makes the pair usable as a migration: disown everything, load the checksums the
filesystem already knew, recompute only what the import did not cover.

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

Every exported and imported record is keyed by **(storage id, internal path)**,
resolved through the filecache at load time; file ids are meaningless elsewhere.
Import never creates a file — an unknown path is counted and skipped, or fails
under `--strict`.

### The timestamp is the dangerous part

`updated_at >= mtime` is the freshness test the sweep, the drain and `applyRule`
all use, so **a hash stamped later than the content it describes is invisible to
every correction path the app has**.

| `--stamp=` | Stores | When it is right |
|---|---|---|
| `source` *(default where the input carries timestamps)* | the imported timestamp; **skips entries older than the file's mtime** | restoring a backup |
| `mtime` *(default for a sumfile)* | the file's mtime — the claim `backfillHashes()` already makes | a `sha1sum` run from just now |
| `now` | the import moment. **Warns**: it asserts every imported hash matches current content, and a stale one imported this way can never be corrected | rarely |

`--allow-stale` imports what `source` would skip, warning per entry.

### The merge primitive already exists

`MetadataService::backfillHashes()` (`:579-618`) writes only absent algorithms
and stamps `updated_at` only when unset — `--merge`, already tested, already
used by the install migration and `rebuild`.

## Implementation Plan

### Block 0 — Declare the two undeclared keys *(prerequisite)*

Add `process_pending_interval` and `pending_batch_limit` to
`ConfigLexicon.php` (INT, 60 and 50, `FLAG_INTERNAL`); `ConfigLexiconTest`
count 10 → 12 plus two `assertContains`.

**Verification:** `composer test:unit --filter ConfigLexiconTest`; the lexicon
warnings leave `data/nextcloud.log`.

### Block 1 — The `stale` state

`MetadataService`:

```php
public const STATE_STALE = 'stale';

/** Mark many files at once — one UPDATE, not one document rewrite per file. */
public function markStale( array $fileIds ): int;
public function markAllStale(): int;   // every file carrying this app's hashes
public function countStale(): int;     // mirrors countEroded()
/** @return list<int> file ids awaiting the drain's attention */
public function fetchStaleBatch( int $limit = 50 ): array;
```

`markStale()` writes the string half only, leaving the int stamp and the hashes
themselves untouched — the marker disowns them, the drain removes them.

**Verification:** `MetadataServiceTest` — marking does not touch
`meta_value_int` or any `file-checksum-<algo>` row; `stale` never matches
`PENDING_LIKE`, so `getPendingStats()` and `fetchPendingBatch()` ignore it
(the same guarantee `eroded` already has, asserted the same way).

### Block 2 — The drain acts on `stale`

`ProcessPendingUpdates` takes a stale batch alongside its pending batch. Per
file: clear this app's metadata, drop the marker, and — if an enabled `include`
rule governs the file — mark `pending:<its mode>` so the existing machinery
recomputes it. A file no rule governs is simply left without hashes.

An import that has since written acceptable hashes has already cleared the
marker, so the drain never sees it. That is the "unless an import is first"
case, and it needs no special handling: it is the absence of work.

**Verification:** integration test — mark a hashed file stale, run the job,
assert the hashes are gone and (with a governing include rule) that it comes
back on the next pass; mark, import, run, and assert the imported hashes
survive untouched.

### Block 3 — `AppStateService`

One service, so "the hashes" cannot mean different things to the exporter, the
eraser and the importer. Not `readonly` (TESTING.md §6.1).

```php
public function exportConfig(): array;                       // every lexicon key
public function countQueueState(): array;                    // pending, eroded, stale
public function exportHashes( int $batch = 500 ): \Generator; // HashRecord
public function importConfig( array $config, bool $replace ): int;
public function importHashes( iterable $records, ImportPolicy $policy ): ImportReport;
public function clearConfig(): int;
public function clearQueueState(): int;
public function markHashesStale(): int;                      // the default path
public function clearHashesNow( int $batch = 500 ): int;     // --now
```

`ImportReport` counts `written / skippedStale / skippedExisting / unknownPath /
overwritten / markerCleared` — "imported 4,000 hashes" without those is not a
result anyone can act on.

**Verification:** unit tests with the established query-builder mocks; merge
leaves an existing algorithm untouched; replace clears first; an unknown path is
counted, not fatal.

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
           → marked stale; the background job clears them, and an import
             arriving first makes that unnecessary. --now clears immediately.
Nothing was changed. Re-run with --force to apply.
```

`--backup` exports first and aborts before touching anything if that fails.
`--force` logs one audit line at **warning** level naming the actor and slices.

**Verification:** the central assertion is that a default run calls no mutating
method at all; `--force` marks stale and does **not** rewrite documents;
`--now` does the opposite; a failing `--backup` aborts first.

### Block 6 — `fcias:import`

`--merge` | `--replace` (**one required**), `--config` / `--hashes`
(`--status` refused, with the reason), `--format=json|csv|sum`, `--algo=` (for
`sum`), `--user=` | `--storage=` to anchor relative paths, `--stamp=`,
`--allow-stale`, `--strict`, `--dry-run`.

Writing acceptable hashes for a file clears any `stale` marker on it.

**Verification:** a policy test per row of the stamp table; parser tests for all
three formats including `sha1sum` output with two-space separators and paths
containing spaces; live round trip on instance 34 — backup, `reset --hashes
--force`, import, counts restored — and a `sum` import anchored with `--user`.
**The path anchoring gets its own pass**: a mis-anchored import silently
attaches hashes to the wrong files, which is worse than importing nothing.

### Block 7 — Status surface

`stale` joins the status page and `occ …:status` next to `eroded`, so a reset
whose job has not caught up is visible. One line, one count, same shape as the
erosion row.

### Block 8 — Fixtures and the e2e reset

`cy.resetFciasState()` (reset `--force --now` + `maintenance:repair` — a test
wants the state gone now, not eventually) and `cy.importFciasFixture(name)`
from `tests/e2e/fixtures/`; a `duplicates.json` fixture so that spec stops
depending on uploads accumulating across runs. **Depends on `AP_E2ETests_v1.1`.**

**Verification:** `duplicates.cy.js` passes twice in a row, which it does not
today.

### Block 9 — Documentation

README (three commands, count 12 → 15; a *Backing up, resetting and importing*
section covering the slices, the dry-run default, `stale` and its window, and
the stamp policies), `docs/FAQ.md` ("How do I start over?", "I already have
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
arrive and make the work unnecessary. Until the job runs those hashes
are still stored and still searchable, so the status page counts stale
files, and --now clears synchronously for anyone who needs them gone
before they walk away.

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
| v1.2 | 2026-08-29 | Reset no longer rewrites metadata documents: it marks files **`stale`**, a new state beside `eroded`, and the background job clears them — unless an import arrives first and makes that unnecessary (user's design). Adds Block 1 (the state), Block 2 (the drain acting on it) and Block 7 (the status count), plus `--now` for synchronous clearing and an explicit statement of the window in which disowned hashes remain stored and searchable. |
| v1.1 | 2026-08-29 | Adds `fcias:import` and fixtures. Restates the slice boundary: `file-checksum-updated_at` spans both index columns, so the int stamp belongs to `--hashes` and the string state to `--status`. Turns the three timestamp policies into `--stamp=source\|mtime\|now` plus `--allow-stale`, with `source` as the default, because stamping import time makes a stale hash permanently invisible to the sweep. Records that `backfillHashes()` is already the merge primitive. |
| v1.0 | 2026-08-29 | Initial plan: backup and reset. Written after the e2e analysis named an occ state reset the highest-value missing test helper, and after a maintenance session wiped an instance's `rule_definitions` with no backup to recover from. |
