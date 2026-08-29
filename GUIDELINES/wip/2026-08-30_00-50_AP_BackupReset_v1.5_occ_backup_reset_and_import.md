# AP BackupReset v1.5: occ backup, reset and import

*Preliminary — not yet approved. Supersedes v1.4–v1.0; all stay where they are.*

## Discussion

Three commands over the state the app owns: **backup**, **reset**, **import**.

v1.5 settles the naming and the ownership: which class owns what, and which
word means what.

### `outdated` — the word yes, the state no

"Outdated" is the better word for a hash older than the file it describes, and
it replaces "stale" in that sense throughout the prose. Whether it should also
become a stored state, `stale:outdated`, is the interesting part — and the
answer is no, for a structural reason:

**`meta_value_string` holds one value, and the states compete for it.** When a
covered file is written, the listener marks `pending:<mode>` so the drain
recomputes it (`FileListener.php:174,191,206,246,262,286`). From that moment the
stored hash *is* outdated — both facts are true, one slot. Marking
`stale:outdated` instead would lose the mode the drain needs; marking it
afterwards would erase the queue entry.

There is a narrow case where the two do not collide — a file governed by a
`missing`-mode rule, which has no event branch, so a write leaves the hash
outdated and unqueued until the sweep. But a state that can only sometimes be
recorded, because another state may hold the slot, is not a state: it is an
accident. Outdatedness stays what it is — **computed**, from
`updated_at < mtime`, the same comparison the sweep, the drain and `applyRule`
already make.

So the namespace holds exactly the two states that mean *the stored hashes are
not to be trusted, and nothing is coming to fix them by itself*:

| State | The hashes are | Scans must |
|-------|----------------|------------|
| `stale:eroded` | **gone** — `markEroded()` calls `removeStartsWith()` | nothing; there is nothing to hide |
| `stale:reset` | **still stored**, disowned, awaiting the drain | exclude them |

*Open, deliberately deferred:* whether scans should also hide hashes that are
merely outdated. They are wrong too — a modified file keeps returning its old
hash to search and to duplicate groups until the drain catches up. Hiding them
needs a **computed** exclusion (join `filecache.mtime` against the stamp) rather
than a marker, which is a heavier predicate on every scan and a separate
decision. Named here so it is not mistaken for an oversight.

### Services: no "State" in the names, and metadata stays with `MetadataService`

v1.4 proposed an `AppStateService` holding counts, clearing and marking. That
was wrong on the user's reading, and the reason is ownership: **`MetadataService`
already owns that table** — `markPending()`, `markEroded()`, `clearMetadata()`,
`countEroded()`, `getPendingStats()`, `backfillHashes()`, `upsertUpdatedAtString()`.
Adding a second class that also writes `files_metadata_index` would split one
owner in two, which is the thing this codebase avoids elsewhere (one write gate
for rules, one verdict path for files).

So the marking and clearing go where they belong, and `AppStateService`
disappears:

| Class | Gains | Owns |
|-------|-------|------|
| `MetadataService` *(exists)* | `markStale()`, `markAllStale()`, `countByState()`, `fetchStaleBatch()`, `clearQueueState()`, `clearHashesNow()`, `andWhereNotStale()`, `exportHashes()` | everything in `files_metadata*` |
| `AppConfigService` *(new, small)* | `ownedKeys()` (from the lexicon), `export()`, `import()`, `clear()` | this app's appconfig keys |
| `ExportService` *(new)* | composes the two into a document | the backup format |
| `ImportService` *(new)* | parses, applies policy, writes through the two above | the import policies |

And the names lose "State": within `OCA\FileChecksumSearch\Service\` there is
nothing else to import or export, so `ExportService` and `ImportService` are
unambiguous. The verbs are the classes; the nouns already have owners.

### One format layer, both directions

`HashRecordFormat` handles **reading and writing** — so every format the import
accepts, the backup can produce:

```php
interface HashRecordFormat {
    /** @return \Generator<HashRecord> */
    public function read( $stream, FormatOptions $options ): \Generator;
    /** @param iterable<HashRecord> $records */
    public function write( iterable $records, $stream, FormatOptions $options ): void;
}
```

`JsonFormat`, `CsvFormat`, `SumFormat`. `--format` then means the same thing on
both commands, and a round trip through any of them is one test rather than two
unrelated ones.

Three asymmetries the plan must state rather than discover:

- **`sum` is lossy.** `<hash>  <path>` carries no algorithm, no timestamp, no
  storage id. Exporting it requires `--algo` (one algorithm per file) and the
  result cannot be round-tripped without `--stamp=mtime` and an anchor. The
  command says so when it writes one.
- **`csv` carries everything but the header.** Storage, path, algo, hash,
  updated_at — round-trippable, but with no schema version or instance id, so a
  restore cannot check what it is loading.
- **Only `json` is a backup.** It alone carries the header (schema version, app
  version, instance id, timestamp, slices present) that lets a later restore
  refuse a file it cannot honour. `--config` is meaningless in the other two, so
  `--format=csv|sum` implies hashes-only and refuses `--config` with that reason.

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

Records are keyed by **(storage id, internal path)**; file ids are meaningless
across instances. Import never creates a file — an unknown path is counted and
skipped, or fails under `--strict`.

### The timestamp is the dangerous part

Freshness is `updated_at >= mtime`, so **a hash stamped later than the content it
describes is invisible to every correction path the app has**.

| `--stamp=` | Stores | When it is right |
|---|---|---|
| `source` *(default where input carries timestamps)* | the imported timestamp; **skips entries older than the file's mtime** | restoring a backup |
| `mtime` *(default for a sumfile)* | the file's mtime — the claim `backfillHashes()` already makes | a `sha1sum` run from just now |
| `now` | the import moment. **Warns**: such a hash can never be corrected | rarely |

`--allow-stale` imports what `source` would skip, warning per entry.
`backfillHashes()` (`:579-618`) is already the `--merge` primitive: only-absent,
stamps only when unset.

## Implementation Plan

Each block is one commit unless noted. Blocks 1–4 are **inert alone** — states,
exclusions and services nothing yet invokes — which is what makes them
separately reviewable without leaving the app half-changed.

### Block 0 — Declare the two undeclared keys · `[FIX]`

Add `process_pending_interval` and `pending_batch_limit` to `ConfigLexicon.php`
(INT, 60/50, `FLAG_INTERNAL`); `ConfigLexiconTest` 10 → 12 keys.
**Verification:** `--filter ConfigLexiconTest`; the per-read warnings leave
`data/nextcloud.log`.

### Block 1 — The `stale:` namespace · `[TASK]`

`STATE_STALE_PREFIX = 'stale:'`, `STATE_ERODED = 'stale:eroded'`,
`STATE_RESET = 'stale:reset'`, `STALE_LIKE = 'stale:%'`; `countEroded()` →
`countByState()`; repair migrates literal `eroded` rows (one `UPDATE`). Prose:
the computed sense becomes **outdated** in README, FAQ and the mode table.
**Verification:** `stale:%` never matches `PENDING_LIKE`; a repair test over
seeded legacy rows.

### Block 2 — Scans ignore `stale:%` · `[TASK]`

`andWhereNotStale( IQueryBuilder $qb, string $alias )` in `MetadataService`,
applied by `queryByHash()`, the duplicates grouping query and `findSameHash()`;
**not** by `getHashes()` / `getHashesByFileId()`, where a file the user opened
shows what is stored, labelled.
**Verification:** integration — mark one of two identical files `stale:reset`,
assert it leaves search and the duplicate group **before any job runs**, while
the sidebar still returns its hashes.

### Block 3 — Marking and clearing, in `MetadataService` · `[TASK]`

`markStale()` / `markAllStale()` (string half only — one `UPDATE`, no document
rewrite), `fetchStaleBatch()`, `clearQueueState()`, `clearHashesNow()`.

### Block 4 — The drain acts on `stale:reset` · `[TASK]`

`ProcessPendingUpdates` takes a stale batch beside its pending batch: clear the
file's app metadata, drop the marker, and if an enabled `include` rule governs
it, mark `pending:<mode>`. An import that already wrote acceptable hashes
cleared the marker, so the drain never sees it — that case is the absence of
work.

### Block 5 — `AppConfigService` + `HashRecordFormat` · `[TASK]`

The small config owner, the format interface and its three implementations, and
the value objects (`HashRecord`, `ImportPolicy`, `ImportReport`, `FormatOptions`).
**Path anchoring lives in the readers and gets its own tests** — a mis-anchored
import silently attaches hashes to the wrong files, which is worse than
importing nothing.

### Block 6 — `ExportService` + `fcias:backup` · `[TASK]`

`--config` / `--status` / `--hashes` (none = all), `--format=json|csv|sum`,
`-o|--output`, `--pretty`. Exit 1 on an unwritable path, checked **before**
exporting. `csv`/`sum` refuse `--config`, and `sum` requires `--algo`.

### Block 7 — `fcias:reset` · `[TASK]`

`--config` / `--status` / `--hashes`, `--force`, `--backup=<path>`, `--now`.
Reports and changes nothing without `--force`; `--backup` exports first and
aborts if that fails; `--force` logs one audit line at **warning** level naming
actor and slices.
**Verification:** the central assertion is that a default run calls no mutating
method at all.

### Block 8 — `ImportService` + `fcias:import` · `[TASK]`

`--merge` | `--replace` (**one required**), `--config` / `--hashes`
(`--status` refused, with the reason), `--format`, `--algo`, `--user` |
`--storage`, `--stamp`, `--allow-stale`, `--strict`, `--dry-run`. Writing
acceptable hashes clears any `stale:%` marker.
**Verification:** live round trip on instance 34 through **each** format —
backup, `reset --hashes --force`, import, counts restored.

### Block 9 — Status surface · `[TASK]`

Untrusted files on the status page and in `occ …:status`: one total from
`stale:%`, broken down by reason.

### Block 10 — Fixtures and the e2e reset · `[TASK]`

`cy.resetFciasState()` (reset `--force --now` + `maintenance:repair`) and
`cy.importFciasFixture(name)`; a `duplicates.json` fixture so that spec stops
depending on accumulating uploads. **Depends on `AP_E2ETests_v1.1`; lands with
it.**

### Block 11 — Documentation · `[TASK]`

README (three commands, 12 → 15; *Backing up, resetting and importing*), FAQ
("How do I start over?", "I already have checksums — can I load them?"). Each
command's own reference ships in its own commit; this is the cross-cutting
remainder.

### CHANGELOG

Blocks 0–4 and 9 carry their own bullets. Blocks 6, 7 and 8 share one entry:
the first writes it, the later two amend it (`COMMIT.md` §4.3).

## Proposed commit message *(Block 7, reset — the others follow the same shape)*

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
moment it was marked, five commits ago.
```

## Change History

| Version | Date | Change |
|---------|------|--------|
| v1.5 | 2026-08-30 | "Outdated" replaces "stale" for the computed sense (user), but does **not** become a state: `meta_value_string` holds one value, and `pending:<mode>` already occupies it whenever a written file is queued, so `stale:outdated` could only sometimes be recorded. Whether scans should also hide merely-outdated hashes is named as a deferred, separate decision needing a computed exclusion. `AppStateService` is dropped (user): marking, clearing and counting go to `MetadataService`, which already owns that table, leaving a small `AppConfigService` plus `ExportService` and `ImportService` — no "State" in the names, since there is nothing else to import or export. `HashRecordFormat` gains `write()`, so backup can produce every format import accepts, with the three asymmetries (sum is lossy, csv has no header, only json is a backup) stated. |
| v1.4 | 2026-08-29 | Adopted the `stale:%` namespace and split one service three ways; added the block-to-commit plan. |
| v1.3 | 2026-08-29 | Stale files excluded from index scans the moment they are marked. |
| v1.2 | 2026-08-29 | Reset marks files stale rather than rewriting metadata documents. |
| v1.1 | 2026-08-29 | Adds `fcias:import`, fixtures, and the `--stamp` policies. |
| v1.0 | 2026-08-29 | Initial plan: backup and reset. |
