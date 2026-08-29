# AP BackupReset v1.0: occ backup and reset commands

*Preliminary — not yet approved.*

## Discussion

Two things asked for this at once. The e2e analysis found that
`tests/e2e/support/e2e.js` has **no state reset of any kind**, and named an
occ-based reset the single highest-value missing helper: it is what let one
spec's uploads accumulate to 145 files until the rate limiter killed the
suite. And on 2026-08-29 a shell pipeline in a maintenance session set
`rule_definitions` to an empty string, wiping an instance's rules with no way
back — the app owns state that is easy to destroy and impossible to recover.

So the pair is deliberate: **backup makes reset survivable**, and reset makes
the test suite repeatable. Neither is worth much alone.

Three slices of state, addressed by the same three flags on both commands:

| Flag | What it covers |
|------|----------------|
| `--config` | The app's own appconfig keys — the ten declared in `ConfigLexicon.php`, `rule_definitions` first among them |
| `--status` | The pending queue: `file-checksum-updated_at` rows carrying `pending:%` or `eroded` |
| `--hashes` | The whole metadata tree for this app's keys: every `file-checksum-*` row |

With no flag, both commands act on **all three** — the common case is "the
whole app", and making the caller enumerate it would only invite a partial
backup mistaken for a complete one.

**Reset defaults to a dry run.** It prints what it would remove, counted per
slice, and changes nothing until `--force`. The inverse default — act unless
told otherwise — is how the incident above happened.

## Analysis

### What the app owns

- **appconfig**, app id `file_checksum_search`: `rule_definitions`,
  `rule_processing_interval`, `idle_banner_ack`, `stats_rule_sweep_last_run`,
  `stats_rule_sweep_last_counts`, `stats_pending_drain_last_run`,
  `stats_pending_drain_last_counts`, `rule_editors_all_users`,
  `rule_editors_groups`, `rule_editors_users` (`lib/Config/ConfigLexicon.php`).
  Two more are read but undeclared — `process_pending_interval` and
  `pending_batch_limit` (`ProcessPendingUpdates.php:49,97`) — see Block 0.
- **metadata**, table `files_metadata_index` plus the `files_metadata` JSON
  document: keys prefixed `file-checksum-` — one per algorithm, plus
  `file-checksum-updated_at` which doubles as the queue state
  (`pending:<mode>`, `eroded`) and the freshness stamp
  (`MetadataService.php:49-63`).
- **Nothing else.** The app writes no table of its own; `oc_filecache.checksum`
  is Nextcloud's, shared with other apps, and must never be touched by either
  command. State that a reset must not remove is as much a part of the
  contract as what it removes.

### Why the metadata half is not a plain DELETE

`files_metadata_index` is an index over `files_metadata`, whose `json` column
holds the document Nextcloud reads. Deleting index rows alone would leave the
documents behind and the two out of step. The reset therefore goes through
`MetadataService` (which owns both sides) per file id, in batches, exactly as
`clearMetadata()` already does — not through raw SQL.

### Format

JSON, one document, on stdout by default so it composes
(`occ … backup > f.json`), or to `--output=<path>`. It carries a header —
schema version, app version, instance id, timestamp, which slices are present
— because a restore that cannot tell a partial backup from a whole one is a
trap. File ids are meaningless across instances, so the hashes slice records
`(storage id, internal path, algo, hash, updated_at)` and a restore resolves
ids at load time; ids alone would silently reattach hashes to unrelated files.

**Restore is out of scope for v1.0** and named here so the format does not
foreclose it: this AP delivers backup + reset, and the header is what lets a
later `restore` refuse a file it cannot honour.

## Implementation Plan

### Block 0 — Declare the two undeclared keys *(prerequisite)*

`process_pending_interval` and `pending_batch_limit` are read from appconfig
but absent from `ConfigLexicon.php`, whose strictness is `WARNING` — every
read logs. A backup that enumerates the lexicon would also miss them.

- Add both `Entry` rows (INT, defaults 60 and 50, `FLAG_INTERNAL`).
- Update `ConfigLexiconTest` (count 10 → 12, plus the two `assertContains`).

**Verification:** `composer test:unit --filter ConfigLexiconTest`; run
`occ file-checksum-search:status` and confirm the lexicon warnings are gone
from `data/nextcloud.log`.

### Block 1 — A shared state service

Neither command should know SQL, and both need the same three slices; the
service is what keeps `backup --hashes` and `reset --hashes` from drifting
into different definitions of "the hashes".

New `lib/Service/AppStateService.php` (not `readonly` — TESTING.md §6.1: the
command tests double it):

```php
/** @return array<string, string> every appconfig key this app owns */
public function exportConfig(): array

/** @return array{pending: int, eroded: int} counts, for the dry run */
public function countQueueState(): array

/** @return \Generator<array{storageId,path,algo,hash,updatedAt}> keyset-paged */
public function exportHashes( int $batchSize = 500 ): \Generator

public function clearConfig(): int          // deleteKey per lexicon entry
public function clearQueueState(): int      // pending:% and eroded rows
public function clearHashes( int $batchSize = 500 ): int  // via MetadataService
```

`exportHashes()` joins `files_metadata_index` to `filecache`/`storages` the
way `FilecacheService::pageStorageFiles()` already does, so a hash carries the
identity that survives a restore rather than a bare file id.

**Verification:** new `tests/Unit/Service/AppStateServiceTest.php` with the
established query-builder mocks (`FciasUnitTestCase::setUpQueryBuilderMock()`)
— exports cover every lexicon key; `clearHashes()` routes through
`MetadataService` rather than raw deletes; an empty instance yields empty
structures, never null.

### Block 2 — `file-checksum-search:backup`

`lib/Command/BackupState.php`, alias `fcias:backup`.

| Option | Effect |
|--------|--------|
| `--config` / `--status` / `--hashes` | Which slices; none given means all three |
| `-o, --output=<path>` | Write to a file instead of stdout |
| `--pretty` | `JSON_PRETTY_PRINT`, for a backup a human will read |

Shape:

```json
{
  "fcias_backup": 1,
  "app_version": "0.19.0",
  "instance_id": "oc1a2b3c",
  "created_at": "2026-08-29T12:00:00+00:00",
  "slices": ["config", "status", "hashes"],
  "config": { "rule_definitions": "[…]", "idle_banner_ack": "1" },
  "status": { "pending": [ { "storage": "home::alice", "path": "files/a.txt", "state": "pending:auto" } ] },
  "hashes": [ { "storage": "home::alice", "path": "files/a.txt", "algo": "sha1", "hash": "…", "updated_at": "1787859929" } ]
}
```

Exit codes: `0` written; `1` output path unwritable (checked **before**
exporting, so a long export does not die at the last step).

**Verification:** `tests/Unit/Command/BackupStateTest.php` — every slice
present by default; a single flag narrows `slices` and omits the others;
`--output` to an unwritable path fails before the service is touched. Live:
`occ fcias:backup --pretty | python3 -m json.tool` on instance 34, and
`occ fcias:backup --config` compared against `occ config:app:list`.

### Block 3 — `file-checksum-search:reset`

`lib/Command/ResetState.php`, alias `fcias:reset`.

| Option | Effect |
|--------|--------|
| `--config` / `--status` / `--hashes` | Which slices; none given means all three |
| `--force` | Actually do it. **Without it the command only reports** |
| `--backup=<path>` | Write a backup first and refuse to proceed if that fails |

Dry-run output names counts per slice and says plainly that nothing changed:

```
Would remove:
  config   10 app configuration keys (rule_definitions, idle_banner_ack, …)
  status   66 queued files, 4 eroded markers
  hashes   760 stored hashes across 328 files
Nothing was changed. Re-run with --force to apply.
```

`--force` prints the same tally in the past tense and logs one audit line at
**warning** level naming the actor and the slices — this is the most
destructive thing the app can do to itself, and the log is what makes it
attributable afterwards.

`--backup` runs Block 2's export first; a failure there aborts before any
delete. That is the pairing that makes the incident this AP was written after
non-fatal.

**Verification:** `tests/Unit/Command/ResetStateTest.php` — the default run
calls **no** clearing method (the central assertion: `expects($this->never())`
on all three); `--force` calls exactly the selected ones; `--backup` with a
failing writer aborts before clearing; the audit line is emitted at warning
level with the actor. Live on instance 34: dry run, then
`--backup=/tmp/x.json --force`, then `maintenance:repair` to bring the shipped
defaults back, confirming a reset instance is one repair away from usable.

### Block 4 — Wire the reset into the e2e suite

The reset exists partly so the Cypress suite can start from a known state.

- `tests/e2e/support/e2e.js`: add `cy.resetFciasState()` wrapping
  `occ fcias:reset --force` followed by `occ maintenance:repair`, using the
  existing `CYPRESS_occ` env indirection.
- Call it from a `before()` in the specs that mutate state (`rules`,
  `duplicates`, `checksums`).
- Update `tests/e2e/README.md`, whose "repeated local runs simply accumulate
  files rather than collide" claim (`:117-120`) the analysis showed to be
  false.

**Depends on** `AP_E2ETests_v1.1`; this block should land with that work rather
than ahead of it.

**Verification:** run `duplicates.cy.js` twice in a row and see it pass both
times — the failure the analysis recorded was exactly this.

### Block 5 — Documentation

- README: both commands in the CLI reference (command count 12 → 14), and a
  short *Backing up and resetting* section under Troubleshooting saying what
  each slice covers and that reset reports unless forced.
- `docs/FAQ.md`: "How do I start over?" — reset, then `maintenance:repair` for
  the shipped defaults.
- CHANGELOG: one `### Added` bullet covering both commands.

**Verification:** `composer lint`; the anchor check from the Block 7 pass; and
every command in the README run once against instance 34.

## Proposed commit message

```
[TASK] occ commands to back up and reset the app's own state

The app owns three slices of state — its appconfig keys, the pending
queue, and the stored hashes — and until now could neither hand them to
you nor take them away safely. A shell mistake could empty
rule_definitions with nothing to restore from, and the e2e suite had no
way to start from a known state, which is how one spec's uploads
accumulated until the rate limiter stopped the run.

fcias:backup writes any or all slices as one JSON document, identifying
files by storage and path rather than by file id, so a backup means
something on the instance it is restored to. fcias:reset removes the
same slices — but only reports what it would remove until --force is
given, and can take a backup first and refuse to continue if that
fails.

Both go through one AppStateService, so "the hashes" cannot come to mean
one thing to the exporter and another to the eraser.
```

## Change History

| Version | Date | Change |
|---------|------|--------|
| v1.0 | 2026-08-29 | Initial plan. Written after the e2e analysis named an occ state reset the highest-value missing test helper, and after a maintenance session wiped an instance's `rule_definitions` with no backup to recover from. |
