# AP BackupReset v1.1: occ backup, reset and import

*Preliminary — not yet approved. Supersedes v1.0, which planned backup and
reset only; v1.0 stays where it is.*

## Discussion

v1.0 paired backup with reset because reset without backup is how state gets
lost. Import is the third side of the same shape, and it earns its place for
reasons neither of the others cover:

- **Checksums often already exist.** A filesystem that stores them (ZFS, btrfs),
  a `sha1sum -r` run, a migration from another tool — computing them again
  means reading every byte on disk to learn what was already known.
- **Fixtures.** Tests currently have to *make* the state they assert on. An
  import lets a spec load a known hash set in one command, which is what the
  e2e work needs and what makes its assertions about duplicates, erosion and
  sweeps cheap to set up.
- **A backup nobody can load is a comfort blanket.** v1.0 deferred restore and
  reserved a header for it; import is that restore, generalised.

### What import must not do, and why

**Import does not apply to `--status`.** The queue is work-in-progress: a sweep
reconstructs it, and loading someone else's queue would enqueue work for files
whose governing rules may say something different now. Restoring a queue is
restoring an opinion the rules have since revised.

But the slice boundary needs restating, because v1.0 drew it imprecisely.
`file-checksum-updated_at` occupies **both columns** of its index row
(`MetadataService.php:47-51`):

| Column | Holds | Slice |
|--------|-------|-------|
| `meta_value_int` | the freshness stamp — when these hashes were computed | `--hashes` |
| `meta_value_string` | the queue state — `pending:<mode>`, `eroded` | `--status` |

So `--hashes` carries the stamp and `--status` carries the state, and they
share a key. Import writes the int and never the string.

### The timestamp is the dangerous part

`updated_at >= mtime` is the freshness test the sweep, the drain and
`applyRule` all use. **A hash stamped later than the content it describes is
invisible to every correction path the app has** — the sweep will call the file
fresh forever. That is the failure the erosion design exists to prevent: a
wrong hash is worse than none, because it makes a changed file look intact.

So of the three policies asked for, the safe one is not the obvious one:

| `--stamp=` | Stores | When it is right |
|------------|--------|------------------|
| `source` *(default where the input carries timestamps)* | the imported timestamp, and **skips any entry older than the file's mtime** | restoring a backup; importing from a tool that recorded when it hashed |
| `mtime` *(default for a sumfile, which carries none)* | the file's current mtime — the same claim `backfillHashes()` already makes | a `sha1sum` run from just now, over files nobody has touched since |
| `now` | the moment of import | **rarely.** Prints a warning: it asserts every imported hash matches current content, and a stale one imported this way can never be corrected by a sweep |

`--allow-stale` imports entries that `source` would skip, warning per entry and
naming the file — the loud version asked for. Without it, a stale entry is
counted and reported, not written.

### The existing primitive already does the merge half

`MetadataService::backfillHashes()` (`:579-618`) writes only algorithms the
file does not already have, and stamps `updated_at` only when unset. That is
`--merge` exactly, already tested, already used by the install migration and
`rebuild`. Import builds on it rather than beside it.

## Analysis

### What the app owns

- **appconfig**, app id `file_checksum_search`: the ten keys in
  `ConfigLexicon.php`, plus `process_pending_interval` and
  `pending_batch_limit`, which are read but undeclared
  (`ProcessPendingUpdates.php:49,97`) — Block 0.
- **metadata**: `files_metadata_index` and the `files_metadata` JSON document,
  keys prefixed `file-checksum-`, split across the two columns as above.
- **Nothing else.** `oc_filecache.checksum` is Nextcloud's, shared with other
  apps; neither reset nor import may write it. What a tool must *not* touch is
  as much its contract as what it must.

### Identity across instances

File ids are meaningless outside the instance that issued them, so every
record — export and import alike — is keyed by **(storage id, internal path)**
and resolved through the filecache at load time. Import never creates a file:
a record whose path is not in the filecache is counted and skipped, or fails
the run under `--strict`.

### Input formats

| `--format` | Shape | Source |
|------------|-------|--------|
| `json` *(default; auto-detected by the header)* | this app's backup document | `fcias:backup` |
| `csv` | `storage,path,algo,hash,updated_at` with a header row; `storage` and `updated_at` optional | spreadsheets, other tools, fixtures |
| `sum` | `<hash>  <path>` — the native output of `sha1sum`, `md5sum`, `b2sum` | a filesystem walk |

`sum` files carry no algorithm, so `--algo=` is required with them; and their
paths are filesystem paths, so one of `--user=<uid>` (paths relative to that
user's `files/`) or `--storage=<id>` anchors them. Absolute paths under the
data directory are mapped automatically when neither is given. **This anchoring
is the fiddly part of the whole feature** and deserves its own verification
pass — a mis-anchored import silently attaches hashes to the wrong files, which
is worse than importing nothing.

## Implementation Plan

### Block 0 — Declare the two undeclared keys *(prerequisite)*

`process_pending_interval` and `pending_batch_limit` are read from appconfig
but absent from `ConfigLexicon.php`, whose strictness is `WARNING` — every read
logs, and a backup enumerating the lexicon would miss them.

- Add both `Entry` rows (INT, defaults 60 and 50, `FLAG_INTERNAL`).
- `ConfigLexiconTest`: count 10 → 12, plus two `assertContains`.

**Verification:** `composer test:unit --filter ConfigLexiconTest`; the lexicon
warnings disappear from `data/nextcloud.log` after `occ …:status`.

### Block 1 — `AppStateService`

One service, so "the hashes" cannot come to mean different things to the
exporter, the eraser and the importer. Not `readonly` — the command tests
double it (TESTING.md §6.1).

```php
/** @return array<string, string> */            public function exportConfig(): array
/** @return array{pending:int, eroded:int} */   public function countQueueState(): array
/** @return \Generator<HashRecord> */           public function exportHashes( int $batch = 500 ): \Generator

public function importConfig( array $config, bool $replace ): int;
public function importHashes( iterable $records, ImportPolicy $policy ): ImportReport;

public function clearConfig(): int;
public function clearQueueState(): int;
public function clearHashes( int $batch = 500 ): int;
```

`HashRecord` is a small readonly value object — `(storageId, path, algo, hash,
updatedAt)` — and `ImportReport` counts `written / skippedStale / skippedExisting
/ unknownPath / overwritten`, because "imported 4,000 hashes" without those five
numbers is not a result anybody can act on.

`exportHashes()` joins the index to filecache/storages the way
`FilecacheService::pageStorageFiles()` already does. `importHashes()` resolves
each record's path to a file id, then delegates to
`MetadataService::backfillHashes()` for merge, or clears the file's app metadata
first for replace.

**Verification:** `tests/Unit/Service/AppStateServiceTest.php` with the
established query-builder mocks — exports cover every lexicon key; merge leaves
an existing algorithm untouched; replace removes before writing; a record whose
path is unknown is counted, not fatal; `clearHashes()` goes through
`MetadataService` rather than raw deletes.

### Block 2 — `file-checksum-search:backup` *(alias `fcias:backup`)*

`--config` / `--status` / `--hashes` (none = all three), `-o|--output=<path>`,
`--pretty`. JSON document with a header — schema version, app version, instance
id, timestamp, slices present — so a partial backup cannot be mistaken for a
whole one. Exit 1 if the output path is unwritable, checked **before** exporting.

**Verification:** unit tests for slice selection and the pre-flight write check;
live `occ fcias:backup --pretty | python3 -m json.tool` on instance 34, and
`--config` compared against `occ config:app:list`.

### Block 3 — `file-checksum-search:reset` *(alias `fcias:reset`)*

`--config` / `--status` / `--hashes` (none = all three), `--force`,
`--backup=<path>`.

**Reports and changes nothing without `--force`:**

```
Would remove:
  config   12 app configuration keys (rule_definitions, idle_banner_ack, …)
  status   66 queued files, 4 eroded markers
  hashes   760 stored hashes across 328 files
Nothing was changed. Re-run with --force to apply.
```

`--backup` exports first and aborts before deleting if that fails. `--force`
logs one audit line at **warning** level naming the actor and the slices.

**Verification:** the central assertion is that a default run calls *no*
clearing method (`expects($this->never())` on all three); `--force` calls
exactly the selected ones; a failing `--backup` aborts before clearing. Live:
dry run, then `--backup=/tmp/x.json --force`, then `maintenance:repair` to
confirm a reset instance is one repair away from usable.

### Block 4 — `file-checksum-search:import` *(alias `fcias:import`)*

| Option | Effect |
|--------|--------|
| `--merge` \| `--replace` | **One is required.** Merge writes only what is absent; replace clears the covered scope first |
| `--config` / `--hashes` | Which slices; none given means both. `--status` is refused with the reason |
| `--format=json\|csv\|sum` | Default: auto-detect from the file |
| `--algo=<name>` | Required for `sum` input, which names no algorithm |
| `--user=<uid>` \| `--storage=<id>` | Anchors relative paths |
| `--stamp=source\|mtime\|now` | As tabulated above; `now` warns |
| `--allow-stale` | Import entries older than the file's mtime, warning per entry |
| `--strict` | A record whose path is unknown fails the run instead of being skipped |
| `--dry-run` | Report what would be written, change nothing |

Ends with the five-number report and, for a non-empty import, one audit line at
info level (warning under `--stamp=now` or `--allow-stale`, both of which assert
something the app cannot verify).

**Verification:** unit tests for each policy (`source` skips a stale record;
`--allow-stale` writes it and warns; `now` warns once; merge vs replace on a
file that already has one of two algorithms); parser tests for all three formats
including a `sha1sum` file with two-space separators and paths containing
spaces. Live round trip on instance 34: `fcias:backup -o /tmp/b.json`, then
`fcias:reset --hashes --force`, then `fcias:import --hashes --merge /tmp/b.json`,
and `occ fcias:status` back to its original counts. Then a `sum` import:
`find … -type f -exec sha1sum {} + > /tmp/s.txt` inside the container,
`fcias:import --hashes --merge --format=sum --algo=sha1 --user=alice /tmp/s.txt`.

### Block 5 — Fixtures and the e2e reset

- `tests/e2e/support/e2e.js`: `cy.resetFciasState()` (reset `--force` +
  `maintenance:repair`) and `cy.importFciasFixture(name)` loading a JSON fixture
  from `tests/e2e/fixtures/`.
- A `duplicates.json` fixture giving a known duplicate group, so that spec stops
  depending on uploads accumulating across runs — the rot the analysis recorded
  (`tests/e2e/README.md:117-120` claims re-runs are safe; they are not).
- Call the reset from `before()` in the specs that mutate state.

**Depends on `AP_E2ETests_v1.1`** — land it with that work, not ahead of it.

**Verification:** `duplicates.cy.js` passes twice in a row, which it does not
today.

### Block 6 — Documentation

- README: three commands in the CLI reference (count 12 → 15), and a *Backing
  up, resetting and importing* section under Troubleshooting — what each slice
  covers, that reset reports unless forced, and the stamp policies with their
  warning.
- `docs/FAQ.md`: "How do I start over?" and "I already have checksums — can I
  load them?"
- CHANGELOG: one `### Added` bullet covering all three commands.

**Verification:** `composer lint`; the anchor check; every documented command
run once against instance 34.

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
files by storage and path rather than by file id. fcias:reset removes
the same slices, but only reports what it would remove until --force,
and can take a backup first and refuse to continue if that fails.
fcias:import loads config and hashes back — from that document, from a
CSV, or from the output of sha1sum — requiring --merge or --replace so
that neither is a default anyone gets by accident.

Import will not load the pending queue: a sweep reconstructs it, and
restoring one would enqueue work against rules that have since changed.
It is careful with timestamps for a sharper reason: freshness is decided
by updated_at >= mtime, so a hash stamped later than the content it
describes is invisible to every correction path the app has. Imports
therefore skip records older than the file they describe unless told
otherwise, and say loudly when they are told otherwise.
```

## Change History

| Version | Date | Change |
|---------|------|--------|
| v1.1 | 2026-08-29 | Adds `fcias:import` (Block 4) and fixtures (Block 5) on the user's proposal. Restates the slice boundary: `file-checksum-updated_at` spans both index columns — the int stamp belongs to `--hashes`, the string state to `--status` — which is why import covers the first and refuses the second. Turns the three timestamp policies into `--stamp=source\|mtime\|now` plus `--allow-stale`, with `source` (skip records older than mtime) as the default, because stamping import time makes a stale hash permanently invisible to the sweep. Records that `backfillHashes()` is already the merge primitive. |
| v1.0 | 2026-08-29 | Initial plan: backup and reset. Written after the e2e analysis named an occ state reset the highest-value missing test helper, and after a maintenance session wiped an instance's `rule_definitions` with no backup to recover from. |
