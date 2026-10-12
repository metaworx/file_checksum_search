# AP ReviewFixes v1.0: Fixes from the release review

## TL;DR

The review of v0.24.0..v0.25.0, each finding checked against the code: the
queue can stall or never finish, Nextcloud's metadata generation overwrites
a file's state, gone files come back wrong or not at all, and the MIME type
features cost more than they say. Six blocks fix them, after AP EtagStamp.

- Rule algorithm no longer allowed → dropped at the drain; the rest hashed
- Sweep cap → counts new files only; `lazy` → the sweep queues `auto`
- Metadata generation → keeps a file's state, follows the rule's mode
- Gone → the checksum column cleared; ETag in the gone stamp, so a file
  that comes back is queued; gone on two "not found" answers, not two
  failures of any kind
- Drain → `stamped` where nothing was read; every outcome in the admin
  line and the JSON; output escaped
- MIME → features from initial state; the count live while deleting;
  one read; refusals by action

### Blocks

1. Queue progress: allowed algorithms, the sweep's cap, `lazy`
2. Metadata generation keeps a file's state
3. Gone files: the checksum column, a file that comes back, "not found"
4. Drain reporting
5. MIME types: the status count and the features
6. MIME types: one read, deletion, refusals, docs

## Discussion

A read-only review of both ranges ranked its findings, and a second pass
checked each 0.25.0 one against HEAD. Two are worse than reported: H3
loses a file's hashes on any later write, not only on an upgrade, and goes
back to 0.24.0 for `missing` and `force`; M11b undoes a reset. Two are
overstated: the MIME count does fall, on Refresh or after an hour, and
`fcias:mimetype:delete` both works and queues. AP EtagStamp v1.3 takes the
unreleased findings; this plan runs after it, since both change
`HashCalculationService::processFile()`, and Block 2 reuses the helper
EtagStamp Block 9 adds for a file's queue mode.

Decided here, with the alternative:

- **A rule's disallowed algorithm is dropped at the drain**, and the file
  is hashed for the others; a rule left with none drops the file, as a
  file no rule maintains is dropped. The rules are not edited: narrowing
  the allowlist and widening it again leaves them as they were.
- **`lazy` stays what the docs say:** a write empties the file, and the
  sweep's trickle hashes it later, queued as `auto`. Redefining it as
  "never by the queue" would change three translated texts.
- **The metadata listener is kept and corrected** rather than removed: it
  queues a file Nextcloud's metadata generation reaches and the app has
  not hashed, in the rule's mode, and leaves alone a file with a state or
  with hashes that still vouch. The file events and the sweep would catch
  those files later.
- **"Not found" is counted apart** from other failures: a file goes gone
  on two such answers in a row. A storage that is held down, busy or
  flapping no longer counts toward it. Only rewording the docs was the
  alternative; but a file marked gone wrongly, and unchanged, is never
  queued again, M13's fix included.
- **Gone stamps from 0.25.0** carry no ETag; the sweep takes them for
  stale once, and they come back gone with one, or hashed.
- **Features come as initial state**, through `@nextcloud/initial-state`.
  Reading the kept count without recounting was the alternative; it
  changes what `rowCount` means in the API.
- **`fcias:status -o json` always carries every drain key**, zeros
  included: a consumer then needs no list of optional keys.
- **Kept as they are:** `deleteMimeTypeOf()` deletes the row when the
  document save fails, by design for gone files; the race in
  `markForMimeTypeAfter()` is healed by the sweep, and its docblock says
  so. Gone detection on object-store primary storage cannot work, since
  `file_exists()` reads the filecache there; the FAQ says so.

## Analysis

Checked against HEAD; HCS is `HashCalculationService`, MDS
`MetadataService`, FCS `FilecacheService`, RS `RuleService`.

- **H3.** `processFile()` takes `$rule['algos']` unfiltered;
  `recalcHashes()` fails a disallowed algorithm and reads the file for the
  rest; `applyBatchResults()` returns `failed` and the document is not
  saved; `failedOrGone()` keeps a file that exists. `RS::wantedAlgos()`
  and `HCS::asTheQueueWould()` filter through `catalogue->isValid`;
  `AlgorithmCatalogue::setAllowlist()` never touches the rules.
- **M12.** `processRule()` marks at most 100 files per rule and run, from
  file id 0; a queued file is stale to `pageStorageFiles()` (its stamp row
  is 0), so it is marked again and counted. 0.25.0's bullet, "the first
  sweep after upgrading queues every such file", is wrong, and stays as
  released (GOVERNANCE §3.1).
- **M11a.** `processFile()` empties a `lazy` file and stamps 0 with no
  state; the sweep finds it stale and marks it `pending:lazy` again. Under
  a `lazy` rule nothing is ever hashed.
- **L6.** The sweep's `held` counts rows, so a duplicate row of one key
  masks a missing one; duplicates are transient.
- **M11b.** `MetadataListener` calls `markPending($id, 'pending:missing')`,
  which replaces any state row: `stale:reset`, `stale:gone`,
  `stale:eroded`, `pending:lazy`, `pending:force`. Nextcloud's
  `GenerateMetadataJob` reaches every file. A reset file under an include
  rule is re-stamped and published; under none, its mark is dropped and
  its hash rows are found again. A file the app never considered gets a
  stamp row of 0. `MetadataListenerTest::testHandleMarksPendingMissing`
  asserts the defect.
- **M9.** `markGone()` leaves `oc_filecache.checksum`; the backfill the
  next upgrade queues copies it back, and `isCurrent()` publishes it while
  the state stays `stale:gone`. On demand, `recalcHashes()` copies the
  column and answers `existed: true`.
- **M13.** The gone stamp is at least the mtime and carries no ETag;
  `FileLocation::isFresh()` and the sweep's filter take the file for
  settled, Reapply skips it under `auto` and `missing`, and the daily walk
  needs hash rows. A file restored with its old mtime stays gone.
- **M10.** `failedOrGone()` compares the shared attempts count, which
  storage-down, busy, changed-during-read and drain exceptions all raise.
  README, FAQ, the drain's help and a docblock say "two failed reads".
- **L2–L4.** The gone hint names `occ files:scan`, which cannot reach a
  file below a folder whose row is gone. `ObjectStoreStorage::file_exists()`
  reads the filecache. A write between `file_exists()` and `markGone()` is
  overwritten by `stale:gone`.
- **Drain.** `hashed` is reported where every result existed; the admin
  line shows hashed, dropped, failed and cleared only; the JSON leaves out
  zero `typed`, `stamped`, `emptied` and `gone`; `Drain.php` prints reasons
  unescaped; single counts in the admin page use `t()`; README says a
  batch is not re-dispatched when no file "could be hashed", where the
  code asks whether any left the queue.
- **M14.** `src/features.ts` reads `GET /api/v1/status`, which for an
  admin builds the whole snapshot and recounts the hash rows when the kept
  count is older than `checksum_count_interval`; the sidebar and the
  duplicates page wait for it first.
- **M15, L12.** A deletion never retakes the MIME count; a stopped refresh
  or deletion records no final run; `isActive()` ignores a requested
  deletion; `MimeTypeSection.vue`'s docblock says it asks for a recount.
- **L9–L11, L14, L16.** The single-algorithm local path opens the file a
  second time for the type's head, and a failure there fails the hash.
  `deleteMimeTypes()` sorts from the start every page and saves each
  document alone. Refusal texts are chosen by state, not action, and a
  `LogicException` between check and act reaches the client as a 500;
  three methods are called `mimeTypeRefusal`. Docs: README on `delete`
  and on re-dispatch, the `processFile()` docblock, two `@return` shapes
  with `mimetype?`, "read in the same pass" for route 4.

## Implementation Plan

### Block 1: Queue progress

1. `RuleService::computableAlgos(array $rule)`: the rule's algorithms,
   lower-cased, that the catalogue allows. `processFile()`,
   `asTheQueueWould()` and `wantedAlgos()` use it; with none left,
   `processFile()` does what it does for a file no rule maintains.
2. The sweep's stale filter (`pageStorageFiles()`, sweep only, not
   Reapply) leaves out files already queued; README *five paths* item 3:
   up to 100 new files per rule and run.
3. `processRule()` queues `pending:auto` for a `lazy` rule.
4. `held` counts distinct keys.
5. CHANGELOG, Fixed (drafts):
   `- README.md, *Modes*: a rule naming an algorithm the server no longer`
   `  allows has its files hashed for the others, not queued for ever.`
   `- README.md, *How hashing happens*: the sweep counts only files not`
   `  queued yet, so a file that keeps failing no longer stalls it.`
   `- README.md, *Modes*, lazy: an emptied file is hashed again later, as`
   `  documented; it was emptied again on every sweep.`

**Verification:** unit `HashCalculationServiceTest` (a rule with a
disallowed algorithm: the rest asked for, `hashed`; with none: dropped),
`RuleServiceTest` (`lazy` queues `auto`); integration `HashStampTest`
(allowlist narrowed, file hashed; a queued failing file and an unqueued
one: the sweep marks one; `lazy` empties, then hashes; duplicate row,
missing key: swept). Both suites on 33 and 34, ECS, Psalm, check.

### Block 2: Metadata generation keeps a file's state

1. `MetadataListener`: nothing for a file with a state row, a file no
   include rule governs, or one whose hashes still vouch; otherwise queued
   in the rule's mode, through EtagStamp's helper.
2. CHANGELOG, Fixed (draft):
   `- Nextcloud's metadata generation no longer queues every file as`
   `  missing; a reset, a queue mode and a gone file stay as they are.`

**Verification:** unit `MetadataListenerTest`, replacing the test that
asserts the defect: `stale:reset` untouched, `pending:lazy` kept, no state
and an `auto` rule → `pending:auto`, no rule → nothing, vouched → nothing.
Integration: a reset file stays reset through the event. Both suites,
lint, check.

### Block 3: Gone files

1. `markGone()` clears the file's `oc_filecache.checksum` before its own
   writes, and stamps the file's ETag; `pageFileidChecksums()` skips
   `stale:gone`.
2. The sweep takes a gone file whose ETag moved, or whose stamp has none,
   for stale, and Reapply follows it.
3. "Not found" counted apart: `failedOrGone()` asks `file_exists()` on
   each failed read; a self-indexed row counts the answers in a row, and
   goes with a found answer, a read or the file leaving the queue. Gone on
   two.
4. Before `markGone()`, an ETag moved since the read means failed, not
   gone.
5. The gone hint: "where it can reach the file", and the FAQ; App.vue's
   text through `scripts/l10n.sh update` and the eleven languages. FAQ:
   object-store primary storage. README, FAQ, the drain's help and the
   docblocks of `failedOrGone()` and `processFile()` on what makes a file
   gone.
6. CHANGELOG, Fixed (drafts):
   `- docs/FAQ.md, gone files: their checksums leave oc_filecache too, and`
   `  a file that comes back with another ETag is queued again.`
   `- docs/FAQ.md, gone files: gone on two "not found" answers in a row,`
   `  not on two failures of any kind.`

**Verification:** integration `HashStampTest`, the gone test continued:
the column empty, the backfill writes no hash rows, recalc reads; the file
recreated with its old mtime and scanned is queued by the sweep; a
storage-down attempt and one "not found" leave it queued. Unit: the ETag
moved before `markGone()` → failed. `npm test`, `scripts/l10n.sh check`;
both suites, lint, check.

### Block 4: Drain reporting

1. `stamped`, not `hashed`, where every result existed.
2. The admin line names `typed`, `stamped`, `emptied` and `gone` where not
   zero; single counts through `n()`; `scripts/l10n.sh update`, the eleven
   languages, `build`, `js/` rebuilt.
3. `fcias:status -o json` carries every drain key; README documents them.
4. `Drain.php` escapes each outcome for the console.
5. README: a batch is not re-dispatched when no file left the queue.
6. CHANGELOG, Changed (draft):
   `- README.md, *Repair Commands*: the drain's JSON always carries typed,`
   `  stamped, emptied and gone, and the admin page shows them.`

**Verification:** unit `HashCalculationServiceTest` (all existed →
`stamped`), `QueueDrainTest` (a reason with `</error>` printed as is),
`ProcessPendingUpdatesTest` (zero keys present); `App.spec.ts`; `npm test`,
lint, typecheck, `scripts/l10n.sh check`; both suites, lint, check.

### Block 5: MIME types: the status count and the features

1. Features as initial state on the Files pages
   (`BeforeTemplateRenderedListener`) and the Duplicates page
   (`PageController`); `src/features.ts` reads it and fetches only where
   it is missing; `@nextcloud/initial-state` added.
2. `StatusService::getMimeTypeStatus()` counts live while a deletion is
   requested or running.
3. A stopped refresh or deletion records its run; `isActive()` is false
   while a deletion is requested; `MimeTypeSection.vue`'s docblock.
4. CHANGELOG, Fixed (drafts):
   `- The files sidebar and the Duplicates page no longer wait for the`
   `  admin status, and its checksum recount, before their own request.`
   `- docs/FAQ.md, *How are MIME types detected?*: the count of stored`
   `  types falls as a deletion goes.`

**Verification:** `src/features.spec.ts` (initial state, no fetch); unit
`BeforeTemplateRenderedListenerTest`, `StatusServiceTest` (deleting →
live count), `MimeTypeRefreshTest`, `MimeTypeDeleteTest`; `npm test`,
lint, typecheck; both suites, lint, check.

### Block 6: MIME types: one read, deletion, refusals, docs

1. The single-algorithm local path reads the head from the stream it
   hashes: one open; a failed head read leaves the hash standing.
2. `deleteMimeTypes()` pages by file id within a run.
3. Refusals chosen by the action refused; a `LogicException` between
   check and act answers as a refusal, not a 500; one name for the
   refusal mapping in each class. New texts through `scripts/l10n.sh
   update` and the eleven languages.
4. `markForMimeTypeAfter()`'s docblock: the race and the sweep healing it.
5. Docs: README on `fcias:mimetype:delete`; `@return` shapes of
   `getHashesByFile()` and `getHashesByPath()`; `docs/api-v1.md` and the
   OpenAPI document on route 4's type with `existed: true`.
6. CHANGELOG, Fixed (drafts):
   `- docs/FAQ.md, *How are MIME types detected?*: a file is opened once`
   `  for its hash and its type, also on a local storage.`
   `- fcias:mimetype:* and the settings: a refusal names the action, and`
   `  a race between check and change no longer ends in an error 500.`

**Verification:** unit `HashCalculationServiceTest` (one open, type set),
`SettingsControllerTest` (refusal per action; the race), the MIME command
tests; integration `MimeTypeTest` (deletion across pages);
`scripts/l10n.sh check`; both suites, lint, check.

## Proposed commit message

Registered with AP EtagStamp v1.2-1.3, in one commit:

```text
[UPDATE] APs: +EtagStamp v1.2-1.3, +ReviewFixes v1.0

File-revision-v1: <EtagStamp v1.2's line>
File-revision-v1: <EtagStamp v1.3's line>
File-revision-v1: <ReviewFixes v1.0's line>
Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

Each block's commits are proposed at its own gate, or made under `EXEC++`.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.0    | 2026-10-12 | Initial AP |
