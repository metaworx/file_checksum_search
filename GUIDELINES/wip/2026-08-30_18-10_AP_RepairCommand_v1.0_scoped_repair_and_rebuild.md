# AP RepairCommand v1.0: a repair an administrator can aim

*Preliminary — not yet approved.*

## Discussion

`occ maintenance:repair` runs this app's repair alongside every other app's and
core's, and this app's repair is one opaque unit:

```php
// RepairQuietStart::run()
$this->ensureSelectorModel( $output );      // one-time: legacy scopes → selectors
$this->reregisterMetadataKeys( $output );   // ongoing: which keys Nextcloud indexes
$this->backfillHashIndex( $output );        // ongoing: index rows never written
$this->namespaceStaleStates( $output );     // one-time: `eroded` → `stale:eroded`
$this->purgeLegacyPendingNew( $output );    // one-time: retired queue state
$this->removeLegacySeedJob( $output );      // one-time: retired background job
```

Four problems follow from that shape, and only the last is about tests.

**They are not the same kind of thing.** Four are migrations that become no-ops
for ever once they have run. Two are maintenance an administrator might
legitimately want again — after fixing a broken key declaration, or after a
restore. Running all six as one unit says they are equivalent, and they are not.

**One of them is unbounded.** `backfillHashIndex` scans `oc_files_metadata` for
documents mentioning a hash. Measured at 1.5 s over 459 rows on instance 34;
over a million files it is minutes, on **every** repair, to discover there is
nothing to do. Nextcloud draws exactly this line for itself with
`maintenance:repair --include-expensive`; we cannot draw it while we are one
step.

**A failed task cannot be re-run.** Each catches, warns and continues — right
for a repair, since one failure must not abandon the rest. But an administrator
who then fixes the cause has no way to run that one task again, only all six.

**And a spec cannot ask for just this app.** An e2e spec resetting FCIAS state
has to run every app's repair, which is slow, and couples the test to
unrelated code. `cy.exec()` allows 60 s by default and the whole-instance
repair has already exceeded it in practice.

### `rebuild` and the backfill are two answers to one question

`fcias:rebuild` copies checksums **from `oc_filecache.checksum`** into the
metadata. `backfillHashIndex` writes index rows **from the metadata documents**.
Different sources, the same felt purpose — "my index is wrong, fix it" — and an
administrator would reasonably try either. They become siblings under one
command rather than two names to choose between:

```
fcias:rebuild --from filecache    # what rebuild does today
fcias:rebuild --from metadata     # what the repair's backfill does
```

and both are reachable as repair steps, so the repair and the command are the
same code with two front doors.

## Analysis

### The six, classified

| Step name | Kind | Cost | What it does |
|---|---|---|---|
| `selector-model` | one-time | one config write | Canonicalises stored rules; recreates the two shipped defaults if absent |
| `metadata-keys` | ongoing | a few config writes | Restates which keys Nextcloud may index — the declaration that keeps long hashes out of its hands |
| `rebuild_from_metadata` | ongoing | **expensive** | Writes index rows for hashes the documents hold and the index does not |
| `stale-states` | one-time | one `UPDATE` | `eroded` → `stale:eroded` |
| `legacy-pending` | one-time | one `DELETE` | Removes a retired queue state |
| `legacy-seed-job` | one-time | one job removal | Removes a retired background job |

`rebuild_from_filecache` joins them as a seventh step — today it is only
reachable through `fcias:rebuild`, and it belongs in the same list.

### The guard that keeps the expensive step automatic

The backfill must stay in the automatic run: it is what carries the index fix
to instances that already exist, and an administrator who never runs `occ` by
hand still needs it. What it must stop doing is paging every document to learn
that there is nothing to page.

Two counts answer that:

```
A = COUNT(DISTINCT file_id) in files_metadata_index  WHERE meta_key LIKE 'file-checksum-%'
                                                       AND meta_key <> '…updated_at'
B = COUNT(*)                in files_metadata        WHERE json LIKE '%file-checksum-%'
```

`A = B` means every file whose document mentions a hash already has at least one
index row — the state after a completed backfill. `A < B` means work is
outstanding. The guard is conservative in the right direction: it can say "work
to do" when there is none (a file whose document holds *two* algorithms and the
index only one still counts once on both sides — the full pass then finds and
fixes it), and it cannot say "nothing to do" when there is. **A false negative
is impossible; a false positive costs one wasted pass.**

`--include-expensive` skips the guard and always makes the full pass, for the
case where the counts agree but the rows are wrong.

### Where the code already is

- `RepairQuietStart` holds the six as private methods with a shared `warn()`.
- `MetadataService::reindexHashes()` does the metadata-side backfill and already
  reports what it fixed.
- `HashIndexService::backfillFromFilecache()` does the filecache side.
- `RebuildIndex` wraps the filecache side plus a pending drain — the drain is a
  third thing the command does and stays where it is, out of the repair.

## Implementation Plan

### Block 1 — Name the steps · `[TASK]`

Turn the six private methods into named steps behind one map: name → title,
description, expensive flag, callable. `run()` iterates the map, so
`maintenance:repair` behaves exactly as before. `rebuild_from_filecache` joins
as the seventh.

**Verification:** the existing `RepairQuietStartTest` passes unchanged — the
point of this block is that nothing observable moves.

### Block 2 — The COUNT guard · `[FIX]`

`MetadataService::hashIndexIsComplete(): bool` from the two counts, checked by
the metadata backfill before it pages anything, and skipped when the caller asks
for the expensive pass.

**Verification:** a unit test for each direction of the comparison; live, a
second repair on instance 34 issues two counts and no page queries, where today
it pages every document to fix nothing.

### Block 3 — `fcias:repair` · `[TASK]`

```
fcias:repair                          # every step, guard honoured
fcias:repair --list                   # names, one-line description, expensive flag
fcias:repair --step <name> …          # repeatable; unknown name fails the run
fcias:repair --include-expensive      # full pass, guard skipped
fcias:repair --dry-run                # what would run, and why each is or is not skipped
```

An unknown `--step` fails rather than being ignored, the way `hash --ignore-rule`
already treats an unknown id. Every run logs which steps it ran.

**Verification:** `--list` names all seven; `--step` runs one and no others;
`--dry-run` reaches no mutating method — the same central assertion `fcias:reset`
carries.

### Block 4 — `fcias:rebuild --from` · `[TASK]`

`--from=filecache|metadata`, defaulting to `filecache` so no existing invocation
changes meaning, plus `--dry-run`. The help says what the two sources are and
which fixes what. `rebuild` keeps its pending drain; the repair steps do not.

**Verification:** both directions live on instance 34 — reset the hashes, rebuild
from filecache, then reset the *index* alone and rebuild from metadata.

### Block 5 — Documentation · `[TASK]`

README's CLI reference (15 → 16 commands, a repair row, `--from` on rebuild) and
a FAQ entry: "the index looks wrong — which rebuild do I want?", answering it
with where each source's truth comes from.

## Open questions for the user

1. **Should `fcias:repair` refuse to run one-time steps that have already run?**
   They are idempotent, so re-running is harmless, and detecting "already ran"
   means storing a marker per step. Cheapest honest answer is to let them run and
   report "nothing to do" — but a `--list` that shows *when each last ran* would
   need that marker, and might be worth it.
2. **Does `--step` belong on `maintenance:repair` too?** Nextcloud has no
   mechanism for it, so this would mean our step reading an environment variable
   or app-config to narrow itself — which is the kind of hidden coupling that is
   worse than the problem. Named here to be dismissed rather than rediscovered.
