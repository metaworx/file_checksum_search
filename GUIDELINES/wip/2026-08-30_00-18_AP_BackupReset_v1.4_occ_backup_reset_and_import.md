# AP BackupReset v1.4: occ backup, reset and import

*Preliminary — not yet approved. Supersedes v1.3–v1.0; all stay where they are.*

## Discussion

Three commands over the state the app owns: **backup** (hand it to me),
**reset** (take it away, survivably) and **import** (give it back, or give me
someone else's).

v1.4 settles two structural questions — how the untrusted states are named, and
how the services divide — and adds an explicit commit plan.

### The `stale:` namespace

The two states differ in a way that decides the design:

| State | The hashes are | Scans must |
|-------|----------------|------------|
| erosion | **gone** — `markEroded()` calls `removeStartsWith()` (`MetadataService.php:462-468`) | nothing; there is nothing to hide |
| reset | **still stored**, disowned, awaiting the drain | exclude them |

They still belong together: both mean *this file's hashes are not to be
trusted*. Namespacing them makes that a property of the data rather than a fact
two `if`s have to remember:

| Was | Becomes |
|-----|---------|
| `eroded` | `stale:eroded` |
| *(new)* | `stale:reset` |

What it buys:

- **One predicate.** The scan exclusion is `NOT LIKE 'stale:%'`, correct for
  both — vacuous for erosion, essential for reset — and correct in advance for
  any third reason to distrust a hash. A future state cannot forget to be
  excluded, because being excluded is what the namespace *means*.
- **One question, one query.** "How many files have untrusted hashes, and why?"
  is `LIKE 'stale:%'` grouped by value: the status page gets a total and a
  breakdown from one round trip.
- **Symmetry.** `pending:%` already works exactly this way
  (`MetadataService::PENDING_LIKE`), so a reader learns the shape once.

Costs, both small and both real:

- **A migration.** Existing rows carry the literal `eroded`; the repair step
  converts them (one `UPDATE`), and `countEroded()` becomes
  `countByState('stale:eroded')`.
- **A word collision.** "Stale" already appears in prose for a *computed*
  condition — mode `auto` is documented as "recalculate existing hashes only
  when stale", meaning `updated_at < mtime`. A hash can be stale in that sense
  without being marked `stale:` in this one. **Resolve it in the prose, not the
  data**: the computed sense becomes "out of date" / "older than the file", so
  the word `stale` means exactly one thing across code and docs. Three
  sentences in the README, the FAQ and the mode table.

*(The alternative — namespacing as `untrusted:` — avoids the collision without
the prose change. It reads more precisely and less naturally. `stale:` is the
word an operator reaches for, so this plan takes it and fixes the prose.)*

### Three services, not one

v1.3 put export, import, counting and clearing in one `AppStateService`. That
name describes the noun and the class would have grown to hold three verbs —
including the most dangerous one. The split:

| Class | Owns | Used by |
|-------|------|---------|
| `AppStateService` | **what the app owns**: which appconfig keys, which metadata keys, the counts, and the destructive operations (clear config, clear queue state, mark stale, clear now) | reset, the status surface |
| `StateExportService` | building the backup document from those readers | backup |
| `StateImportService` | parsing input, applying policy, writing through `MetadataService` | import |

`ImportExportService` was the other candidate and is the wrong shape: **reset is
neither an import nor an export**, and it is the operation that most needs a
home where a reviewer will look for it. The noun all three share is the app's
state; the verbs differ enough to separate.

The shared vocabulary stays in one place regardless — `AppStateService` decides
what "the hashes" and "the config" *are*, and the other two ask it. That was the
point of one service in v1.3, and it survives the split.

Value objects and a parser, because this is where the bugs will be:

- `HashRecord` — `(storageId, path, algo, hash, updatedAt)`, readonly.
- `ImportPolicy` — merge/replace, stamp, allow-stale, strict; readonly.
- `ImportReport` — `written / skippedOutdated / skippedExisting / unknownPath /
  overwritten / markerCleared`.
- `HashRecordReader` — one implementation per format (json, csv, sum), each
  yielding `HashRecord`s. **Path anchoring lives here** and gets its own tests:
  a mis-anchored import silently attaches hashes to the wrong files, which is
  worse than importing nothing.

### The rest, unchanged from v1.3

Reset marks rather than rewriting one document per file; the drain clears, or an
import arriving first makes that unnecessary. A stale file is excluded from
every scan the moment it is marked — one shared predicate, carried by the
existing `f_meta_index (file_id, meta_key, meta_value_string)` as an index-only
lookup. The per-file sidebar still shows a disowned file's hashes, labelled,
because telling someone their file has no checksums would be a different lie.

Import refuses `--status` (a sweep reconstructs the queue; restoring one
enqueues work against rules that have since changed) and is careful with
timestamps, because freshness is `updated_at >= mtime` and a hash stamped later
than the content it describes is invisible to every correction path the app has.

| `--stamp=` | Stores | When it is right |
|---|---|---|
| `source` *(default where input carries timestamps)* | the imported timestamp; **skips entries older than the file's mtime** | restoring a backup |
| `mtime` *(default for a sumfile)* | the file's mtime — the claim `backfillHashes()` already makes | a `sha1sum` run from just now |
| `now` | the import moment. **Warns**: such a hash can never be corrected | rarely |

`--allow-stale` imports what `source` would skip, warning per entry.

## Analysis

### What the app owns

- **appconfig**, app id `file_checksum_search`: the ten keys in
  `ConfigLexicon.php`, plus `process_pending_interval` and
  `pending_batch_limit`, read but undeclared (`ProcessPendingUpdates.php:49,97`).
- **metadata**: `files_metadata_index` and the `files_metadata` document, keys
  prefixed `file-checksum-`, spanning both index columns:

| Column of `file-checksum-updated_at` | Holds | Slice |
|---|---|---|
| `meta_value_int` | the freshness stamp | `--hashes` |
| `meta_value_string` | `pending:<mode>`, `stale:eroded`, `stale:reset` | `--status` |

- **Nothing else.** `oc_filecache.checksum` is Nextcloud's, shared with other
  apps; no command here may write it.

Records are keyed by **(storage id, internal path)** — file ids are meaningless
across instances. Import never creates a file: an unknown path is counted and
skipped, or fails under `--strict`.

## Implementation Plan

Each block is one commit unless noted. Blocks 1–3 are **inert on their own** —
they add states, exclusions and services that nothing yet invokes — which is
what lets them be reviewed separately without leaving the app half-changed.

### Block 0 — Declare the two undeclared keys · `[FIX]`

`process_pending_interval` and `pending_batch_limit` are read from appconfig but
absent from the lexicon, whose strictness is `WARNING`: every read logs.

Add both `Entry` rows (INT, 60 and 50, `FLAG_INTERNAL`); `ConfigLexiconTest`
count 10 → 12 plus two `assertContains`.

**Verification:** `composer test:unit --filter ConfigLexiconTest`; the warnings
leave `data/nextcloud.log` after `occ …:status`.

### Block 1 — The `stale:` namespace · `[TASK]`

- `STATE_STALE_PREFIX = 'stale:'`, `STATE_ERODED = 'stale:eroded'`,
  `STATE_RESET = 'stale:reset'`, `STALE_LIKE = 'stale:%'`.
- `countEroded()` → `countByState()` / `countStale()`; the repair step migrates
  literal `eroded` rows (one `UPDATE`).
- Prose fix: the computed sense of "stale" becomes "out of date" in README, FAQ
  and the mode table.

**Verification:** `MetadataServiceTest` — `stale:%` never matches
`PENDING_LIKE`, so queue statistics ignore it, as they already do for erosion;
a repair test asserting `eroded` → `stale:eroded` against seeded rows.

### Block 2 — Scans ignore `stale:%` · `[TASK]`

One private `andWhereNotStale( IQueryBuilder $qb, string $alias )` in
`MetadataService`, applied by `queryByHash()`, the duplicates grouping query and
`findSameHash()` — every path that *scans*. `getHashes()` /
`getHashesByFileId()` do not apply it.

**Verification:** integration — hash two identical files, mark one
`stale:reset`, assert it vanishes from search and from the duplicate group
**before any job runs**, while the sidebar still returns its hashes.

### Block 3 — `markStale()` and the drain · `[TASK]`

`markStale(array $fileIds)` / `markAllStale()` write the string half only — one
`UPDATE`, no document rewrite. `ProcessPendingUpdates` takes a stale batch
alongside its pending batch: clear the file's app metadata, drop the marker,
and if an enabled `include` rule governs it, mark `pending:<mode>` so the
existing machinery recomputes.

**Verification:** integration — mark, run, assert gone and (under a governing
rule) back on the next pass; mark, import, run, assert the imported hashes
survive untouched.

### Block 4 — `AppStateService` · `[TASK]`

Inventory, counts, and the destructive operations. No command yet.

### Block 5 — `StateExportService` + `fcias:backup` · `[TASK]`

`--config` / `--status` / `--hashes` (none = all), `-o|--output`, `--pretty`.
Header carries schema version, app version, instance id, timestamp and the
slices present, so a partial backup cannot pass for a whole one. Exit 1 on an
unwritable path, checked **before** exporting.

### Block 6 — `fcias:reset` · `[TASK]`

`--config` / `--status` / `--hashes`, `--force`, `--backup=<path>`, `--now`.
Reports and changes nothing without `--force`; `--backup` exports first and
aborts if that fails; `--force` logs one audit line at **warning** level naming
actor and slices.

**Verification:** the central assertion is that a default run calls no mutating
method at all.

### Block 7 — `StateImportService` + parsers · `[TASK]`

`HashRecordReader` per format, the policies, the report. No command yet, so the
parsing and anchoring can be reviewed on their own.

### Block 8 — `fcias:import` · `[TASK]`

`--merge` | `--replace` (**one required**), `--config` / `--hashes`
(`--status` refused, with the reason), `--format`, `--algo`, `--user` |
`--storage`, `--stamp`, `--allow-stale`, `--strict`, `--dry-run`. Writing
acceptable hashes clears any `stale:%` marker.

**Verification:** live round trip on instance 34 — backup, `reset --hashes
--force`, import, counts restored — plus a `sum` import anchored with `--user`.

### Block 9 — Status surface · `[TASK]`

Untrusted files on the status page and in `occ …:status`: one total from
`stale:%`, broken down by reason.

### Block 10 — Fixtures and the e2e reset · `[TASK]`

`cy.resetFciasState()` (reset `--force --now` + `maintenance:repair`) and
`cy.importFciasFixture(name)`; a `duplicates.json` fixture so that spec stops
depending on uploads accumulating. **Depends on `AP_E2ETests_v1.1`; lands with
that work.**

### Block 11 — Documentation · `[TASK]`

README (three commands, count 12 → 15; *Backing up, resetting and importing*),
FAQ ("How do I start over?", "I already have checksums — can I load them?").
Each command's own documentation ships **in its own commit**, per
`COMMIT.md` §5; this block is what remains: the cross-cutting section and the
FAQ entries.

### CHANGELOG

Blocks 0–3 and 9 each carry their own bullet. Blocks 5, 6 and 8 form one
entry: the first writes it, the later two amend it, per `COMMIT.md` §4.3 —
a release block reads as the net change, not as a commit log.

## Proposed commit message *(Block 6, the reset command — the others follow the same shape)*

```
[TASK] occ reset: take the app's state away, survivably

The app owns three slices of state and could not take any of them away
safely: a shell mistake could empty rule_definitions with nothing to
restore from, and the e2e suite had no way to start from a known state.

fcias:reset removes config, queue state and hashes — but reports what it
would remove and changes nothing until --force, and can take a backup
first and refuse to continue if that fails. Removing hashes does not
rewrite one metadata document per file: it marks each file stale:reset,
and the background job does the clearing, which leaves a window in which
an import can arrive and make the work unnecessary. Nothing findable
outlives the decision to disown it — a stale file left every scan the
moment it was marked, two commits ago.
```

## Change History

| Version | Date | Change |
|---------|------|--------|
| v1.4 | 2026-08-29 | Adopts the `stale:%` namespace (user's proposal): `eroded` becomes `stale:eroded`, reset marks `stale:reset`, and the scan exclusion becomes one `NOT LIKE` correct for both and for any future reason to distrust a hash — at the cost of migrating existing rows and renaming the *computed* sense of "stale" in prose. Splits the one service three ways — `AppStateService` (what the app owns, and the destructive operations), `StateExportService`, `StateImportService` — because reset is neither import nor export and should not be homeless; `ImportExportService` was the rejected alternative. Adds a block-to-commit plan: blocks 1–3 are inert alone, so each is reviewable without leaving the app half-changed. |
| v1.3 | 2026-08-29 | Stale files excluded from index scans the moment they are marked, via one shared predicate carried by the existing `f_meta_index`. The per-file view deliberately keeps showing them, labelled. `--now` demoted to a convenience. |
| v1.2 | 2026-08-29 | Reset marks files stale rather than rewriting metadata documents; the drain clears them unless an import arrives first. |
| v1.1 | 2026-08-29 | Adds `fcias:import` and fixtures; restates the slice boundary across the two index columns; `--stamp` policies with `source` as the safe default. |
| v1.0 | 2026-08-29 | Initial plan: backup and reset. |
