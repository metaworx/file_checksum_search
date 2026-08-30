# AP RepairCommand v1.2: a repair an administrator can aim

*Approved. Supersedes v1.1 and v1.0, which stay where they are.*

**Changed in v1.2.** The diagram had the filecache as a source only, and the
text claimed `import` was the only operation writing back to it. Both are wrong:
`MetadataService::saveMetadata()` calls `setHashes()` on **every** path, so
hashing and the drain mirror into `oc_filecache.checksum` exactly as the import
does. The relationship is two-way for everything, and the boundary sits below
the metadata, which is where an external file enters.

**Changed in v1.1.** v1.0 proposed `fcias:rebuild --from filecache|metadata` on
the reasoning that the filecache copy was an *import* and the index backfill a
*repair*. That premise was wrong: `oc_filecache.checksum` is written and trusted
by Nextcloud itself, so reading it crosses no boundary — it is internal state
this instance already holds, merely not searchable. With that corrected, the two
are the same kind of operation and belong together, which is where the user
placed them before v1.0 argued otherwise. `rebuild` is retired, its three phases
become steps, and `#[RepairStep]` attributes carry each step's description.

## Discussion

`occ maintenance:repair` runs this app's repair alongside every other app's, and
this app's repair is one opaque unit:

```php
// RepairQuietStart::run()
$this->ensureSelectorModel( $output );      // one-time: legacy scopes → selectors
$this->reregisterMetadataKeys( $output );   // ongoing: which keys Nextcloud indexes
$this->backfillHashIndex( $output );        // ongoing: index rows never written
$this->namespaceStaleStates( $output );     // one-time: `eroded` → `stale:eroded`
$this->purgeLegacyPendingNew( $output );    // one-time: retired queue state
$this->removeLegacySeedJob( $output );      // one-time: retired background job
```

Four problems follow, and only the last is about tests.

**They are not the same kind of thing.** Four are migrations that become no-ops
for ever once they have run. Two are maintenance an administrator might want
again — after fixing a broken key declaration, or after a restore. One unit says
they are equivalent; they are not.

**One is unbounded.** `backfillHashIndex` scans `oc_files_metadata` for
documents mentioning a hash: 1.5 s over 459 rows on instance 34, minutes over a
million files, on **every** repair, usually to discover there is nothing to do.
Nextcloud draws exactly this line for itself with
`maintenance:repair --include-expensive`; we cannot draw it while we are one step.

**A failed step cannot be re-run.** Each catches, warns and continues — right,
since one failure must not abandon the rest. But an administrator who fixes the
cause can only run all six again.

**And a spec cannot ask for just this app.** An e2e reset has to run every app's
repair, which is slow and couples the test to unrelated code. `cy.exec()` allows
60 s by default and a whole-instance repair has already exceeded it in practice.

### What belongs under repair, and what does not

The line is not the verb — it is whether the operation **crosses the instance
boundary**:

```
file content ─────────drain──────────────┐
                                         v
oc_filecache.checksum <─────────────> metadata ──> index        all internal
                                         ^
---------------------------------------- │ ------------------------------------
an external file ──import────────────────┴                      crosses in
```

Everything above the line reconciles state this instance already holds and
already trusts, differing only in where the chain is entered.
`oc_filecache.checksum` is Nextcloud's own column — written by core when a sync
client uploads with an `OC-Checksum` header, and served back over WebDAV
`PROPFIND` — so reading it is not acquisition. It is trusted data that happens
not to be searchable, which is the entire reason this app exists
(`README.md:5`: one unindexed TEXT column, `LIKE '%hash%'`, O(n)).

The metadata and the filecache are two-way for **every** operation, not only for
the import: `MetadataService::saveMetadata()` mirrors into
`oc_filecache.checksum` on every path, so a hash this app computes becomes
visible to sync clients and to WebDAV just as one arriving from them becomes
searchable here. The two stores hold the same claim in two shapes — one
indexed, one not.

Only `fcias:import` crosses the boundary, which is why it alone carries
`--merge`/`--replace`, `--stamp`, `--strict` and `--allow-stale`: it is the only
one whose input the instance has no way to check. Everything above the line is
already trusted, and the operations there differ only in where the chain is
entered.

So every internal reconciliation becomes a step of one command, and `import`
stays exactly as it is.

### `fcias:rebuild` retires

Its three phases are three points of entry into the same chain:

| Phase today | Becomes |
|---|---|
| copy `oc_filecache.checksum` into the metadata | `--step rebuild-from-filecache` |
| *(the repair already does this)* metadata → index | `--step rebuild-from-metadata` |
| drain the pending queue now | `--step drain-queue` |

The command therefore *is* `fcias:repair --step rebuild-from-filecache --step
rebuild-from-metadata --step drain-queue`, and keeping it would mean one name
that means three unrelated things — the smell that started this. Pre-`1.0.0`,
with `generate → hash` as precedent for retiring a command name.

## Analysis

### The steps, named consistently

Dashes throughout, source named where a step has one:

| Step | Kind | Expensive | Reads content |
|---|---|---|---|
| `selector-model` | one-time | no | no |
| `metadata-keys` | ongoing | no | no |
| `stale-states` | one-time | no | no |
| `legacy-pending` | one-time | no | no |
| `legacy-seed-job` | one-time | no | no |
| `rebuild-from-filecache` | ongoing | yes | no |
| `rebuild-from-metadata` | ongoing | yes | no |
| `drain-queue` | ongoing | yes | **yes** |

### Descriptions live on the method that implements them

A description's only job is to be true about *that* implementation, so anything
that lets the two sit apart lets them drift. A PHP attribute keeps them
together, and matches how this codebase already declares things
(`#[ApiRoute]`, `#[NoAdminRequired]`):

```php
#[RepairStep(
    name:        'rebuild-from-metadata',
    title:       'Index the stored hashes',
    description: 'Writes index rows for hashes the metadata documents already hold '
               . 'and the index does not. Reads no file content and changes no '
               . 'stored hash. Run this when a search finds nothing for a hash the '
               . 'file details page shows.',
    expensive:   true,
)]
private function backfillHashIndex( IOutput $output ): void
```

The step methods stay **private** — they are steps, not API. Reflection reaches
them: since PHP 8.1 `setAccessible()` is a no-op and `ReflectionMethod::invoke()`
works directly, and the project targets 8.2.

**Two descriptions have to do more work than the rest.** `rebuild-from-filecache`
and `rebuild-from-metadata` are where an administrator has to choose, and the
names alone do not say which fixes their symptom. Each names its source in terms
of where those checksums came from — the filecache one as *checksums Nextcloud
already holds because a sync client sent them on upload, visible over WebDAV but
not searchable*; the metadata one as *hashes this app already computed, present
in the file's details, absent from the search index*.

### The COUNT guard

The expensive backfill must stay in the automatic run: it is what carries the
index fix to instances that already exist, and an administrator who never types
`occ` still needs it. What it must stop doing is paging every document to learn
there is nothing to page.

```
A = COUNT(DISTINCT file_id) in files_metadata_index  WHERE meta_key LIKE 'file-checksum-%'
                                                       AND meta_key <> '…updated_at'
B = COUNT(*)                in files_metadata        WHERE json LIKE '%file-checksum-%'
```

`A = B` is the state after a completed backfill; `A < B` means work is
outstanding. **A false "nothing to do" is impossible for the population this
exists to fix** — a file with no index rows at all counts in `B` and not in `A`,
which is every file affected by the truncation defect.

Its limit, stated rather than buried: a file whose document holds *two*
algorithms and whose index holds *one* counts once on each side, so the guard
can call that complete. Such a file can only come from an interrupted backfill,
and `--include-expensive` is the answer to it — which is what that flag is for.

### DONE markers, and the one way they can do harm

One app-config key per step holding the completion timestamp. `--list` shows
when each last ran; deleting the key re-runs it; `--include-expensive` ignores
them entirely. Steps stay idempotent regardless: a marker is an optimisation and
never a correctness mechanism.

**They must not travel in a backup.** They are app-config, so
`fcias:backup --config` would carry them and `fcias:import --config` would write
them into another instance — telling it a migration has run when it never has.
A one-time step skipped that way never runs at all, silently and permanently:
the worst failure available in this design.

They are instance-local history, the same category as the `--status` slice —
recorded for the operator, never restored. So they are declared in the lexicon
(no undeclared-key warnings on every read) but excluded from
`AppConfigService::export()` and `import()`. That means `ownedKeys()` stops being
"everything the lexicon declares" and gains a notion of *portable* keys — a
change to code committed on 2026-08-30, and the reason Block 2 comes before
Block 4.

`fcias:reset --config` clearing them is correct: back to the shipped state means
the migrations run again, harmlessly.

## Implementation Plan

Each block is one commit unless noted.

### Block 1 — `#[RepairStep]`, and the six become seven · `[TASK]`

The attribute, and `RepairQuietStart::run()` iterating the steps reflection
finds, in declaration order. `rebuild-from-filecache` joins as the seventh (its
implementation moves from `RebuildIndex`); `drain-queue` waits for Block 5.
`maintenance:repair` behaves exactly as before.

**Verification:** `RepairQuietStartTest` passes unchanged — the point of the
block is that nothing observable moves. Plus a registry test: every name unique,
every description non-empty, and **no step method without the attribute**, which
is the failure that would otherwise be silent.

### Block 2 — Portable keys · `[FIX]`

`AppConfigService` gains the distinction between keys it owns and keys it
exports. The repair markers are owned, declared, and not portable.

**Verification:** a backup taken after a repair carries no `repair-done-*` key;
`reset --config` still clears them.

### Block 3 — The COUNT guard · `[FIX]`

`MetadataService::hashIndexIsComplete()`, checked before the metadata backfill
pages anything and skipped when the caller asks for the expensive pass.

**Verification:** a unit test per direction of the comparison; live, a second
repair issues two counts and no page queries where today it pages everything to
fix nothing.

### Block 4 — `fcias:repair` · `[TASK]`

```
fcias:repair                       # every step; guard honoured, markers honoured
fcias:repair --list                # name, title, expensive, last run, description
fcias:repair --step <name> …       # repeatable; an unknown name fails the run
fcias:repair --include-expensive   # ignores guard and markers
fcias:repair --dry-run             # what would run, and why each would or would not
```

An unknown `--step` fails rather than being ignored, as `hash --ignore-rule`
already treats an unknown id. Every run logs which steps it ran.

**Verification:** `--list` names all steps; `--step` runs one and no others;
`--dry-run` reaches no mutating method — the central assertion `fcias:reset`
already carries.

### Block 5 — `drain-queue`, and `rebuild` retires · `[TASK]`

The pending drain becomes a step; `fcias:rebuild` is removed from
`appinfo/info.xml` and deleted, with its help text's content carried into the
three steps' descriptions.

**Verification:** `occ list` no longer offers `rebuild`; the three steps in
sequence reproduce what it did, live on instance 34.

### Block 6 — Documentation · `[TASK]`

README's CLI reference (15 commands: `rebuild` out, `repair` in), the
troubleshooting entry that currently tells administrators to run `rebuild`, and
a FAQ answer for "the index looks wrong — which step do I want?" that answers by
naming where each source's truth comes from.

**Verification:** every command the docs name exists in `occ list`; every
documented example run verbatim against instance 34.

## Open questions for the user

1. **Is `drain-queue` a repair at all?** It reads file content and executes work
   the rules already intended, rather than reconciling state that drifted. The
   case for including it is that an administrator fixing a wrong index wants it
   in the same place as the rest; the case against is that a queue is pending
   work, not damage. Included here, marked expensive, and easy to drop.
2. **Should `--list` show a step's last run when it has never run?** "Never" is
   informative; an empty cell is quieter. A minor thing, but it is the column an
   administrator will read first.
