# AP ReviewFixes v1.1: Fixes from the release review

## TL;DR

The 0.25.0 review's findings, each checked against the code: the queue can
stall or never finish, metadata generation overwrites a file's state, gone
files come back wrong or not at all, the MIME features cost more than they
say. Seven blocks, after AP EtagStamp.

- 0.25.0's sweep bullet → what shipped (`[FIX]`, GOVERNANCE §3.1 overruled
  by the user); the tag message follows, locally
- Disallowed rule algorithm → dropped at the drain; the rest hashed
- Sweep cap → new files only; `lazy` → low priority, hashed by the sweep
- Metadata generation → keeps a file's state, follows the rule's mode
- Gone → checksum column cleared; ETag in the gone stamp; two "file not
  found" answers while the storage's root answers
- Drain → `stamped` where nothing was read; every outcome shown; escaped
- MIME → features from initial state, `GET /api/v1/features` as fallback;
  live count while deleting; one read; refusals by action
- Disowned → revoked: `occ fcias:revoke`, `stale:revoked`, old names kept

### Blocks

1. Queue progress: the 0.25.0 bullet, allowed algorithms, the sweep, `lazy`
2. Metadata generation keeps a file's state
3. Gone files
4. Drain reporting
5. MIME types: features and the status count
6. MIME types: one read, deletion, refusals, docs
7. Disowned becomes revoked

## Discussion

A read-only review ranked its findings; a second pass checked each 0.25.0
one at HEAD. Worse than reported: H3 (any later write loses the hashes;
since 0.24.0 for `missing` and `force`), M11b (undoes a revocation).
Overstated: M15 (the count falls on Refresh or after an hour). Runs after AP
EtagStamp: both change `processFile()`, and Block 2 reuses EtagStamp Block
9's helper for a file's queue mode.

Decided:

- **0.25.0's bullet** says "the first sweep after upgrading queues every
  such file"; the shipped sweep queues 100 per rule and run. The user
  overruled GOVERNANCE §3.1 for a `[FIX]` commit that says what shipped.
  `changelog.sh tag 0.25.0 update --force` then rewrites the tag message
  in place; `move` would put the unreleased work into `v0.25.0`. Pushing
  the tag and the GitHub release notes are the user's, outside any run.
- **A disallowed algorithm** is dropped at the drain; the rule is not
  edited. A rule left with none drops the file, as no rule would.
- **`lazy` is low priority:** a write empties the hashes at once, and the
  sweep queues the file as `auto` later, 100 per rule and run. The
  translated texts ("a later run recalculates them") stay true; README
  *Modes* says it plainly.
- **The metadata listener is corrected**, not removed.
- **Gone needs the file not found, not the storage.** A failed read asks
  `file_exists()` of the file and of the storage's root. Root there, file
  missing: counted. Root missing too: an ordinary failure. Gone on two
  counted answers in a row. Gone stamps from 0.25.0 carry no ETag; the
  sweep takes them for stale once.
- **Features:** the Files and Duplicates pages get them as initial state
  (`@nextcloud/initial-state`). `GET /api/v1/features` is the fallback,
  cheap, for any account: `features`, the allowed algorithms with the
  default, and the app version.
- **`fcias:status -o json`** always carries every drain key.
- **Revoked** replaces disowned everywhere: `occ fcias:revoke`;
  `fcias:reset --hashes` a deprecated alias; `stale:reset` →
  `stale:revoked` by a repair step; a backup's old value still imports;
  repair step `clear-revoked`, `clear-disowned` its alias; drain JSON key
  `revoked`, `disowned` kept beside it. Last, so the run's other renames
  fold in.
- **Kept:** `deleteMimeTypeOf()` deletes the row on a failed save (by
  design, for gone files); `markForMimeTypeAfter()`'s race, healed by the
  sweep, documented; gone detection cannot work on object-store primary
  storage, FAQ says so.

## Analysis

HCS `HashCalculationService`, MDS `MetadataService`, FCS
`FilecacheService`, RS `RuleService`; checked at HEAD.

- **H3.** `processFile()` takes `$rule['algos']` unfiltered;
  `recalcHashes()` fails a disallowed one and reads for the rest;
  `applyBatchResults()` returns `failed`, nothing saved; `failedOrGone()`
  keeps an existing file. `RS::wantedAlgos()` and `asTheQueueWould()`
  filter; `setAllowlist()` never touches rules.
- **M12.** `processRule()` marks ≤ 100 files per rule and run, from id 0; a
  queued file (stamp row 0) is stale to `pageStorageFiles()`, so it is
  marked again and counted.
- **M11a.** A `lazy` file is emptied and stamped 0 with no state; the
  sweep marks it `pending:lazy` again. Nothing is ever hashed.
- **L6.** The sweep's `held` counts rows, not keys.
- **M11b.** `MetadataListener` writes `pending:missing` over any state:
  `stale:reset`, `stale:gone`, `stale:eroded`, `pending:lazy`,
  `pending:force`. `GenerateMetadataJob` reaches every file. A never-seen
  file gets a stamp row of 0. `MetadataListenerTest` asserts the defect.
- **M9.** `markGone()` leaves `oc_filecache.checksum`; the next upgrade's
  backfill republishes it; recalc copies it and answers `existed: true`.
- **M13.** The gone stamp is ≥ mtime with no ETag; the sweep, Reapply and
  the daily walk all pass the file by. A file restored with its old mtime
  stays gone.
- **M10.** `failedOrGone()` counts every failure kind; a storage not yet
  flagged unavailable can answer `file_exists()` false.
- **L2–L4.** The gone hint's `occ files:scan` cannot reach a file below a
  folder whose row is gone; object-store `file_exists()` reads the
  filecache; a write between `file_exists()` and `markGone()` is lost.
- **Drain.** `hashed` where all existed; the admin line shows four of eight
  outcomes; JSON leaves zero keys out; `Drain.php` prints reasons
  unescaped; single counts use `t()`; README's re-dispatch wording.
- **M14.** `src/features.ts` reads `GET /api/v1/status`: for an admin, the
  whole snapshot and maybe a recount; the sidebar and Duplicates page wait.
- **M15, L12.** A deletion never retakes the MIME count; stopped runs
  record nothing; `isActive()` ignores a requested deletion.
- **L9–L11, L14, L16.** A second open for the type's head; deletion pages
  from the start; refusals by state, `LogicException` → 500, three
  `mimeTypeRefusal`; docs on `delete`, `@return` shapes, route 4.
- **Disowned** appears in code, help texts, docs, a repair step, the drain
  JSON and one admin text.

## Implementation Plan

### Block 1: Queue progress

1. `[FIX]` commit: 0.25.0's bullet says what shipped (draft):
   `` - `README.md`, *Modes*: `auto` rules hash files without checksums. The``
   `  sweeps after upgrading queue them, up to 100 per rule and run.`
   Then `changelog.sh tag 0.25.0 update --force`, local only.
2. `RuleService::computableAlgos(array $rule)`: lower-cased, allowed.
   `processFile()`, `asTheQueueWould()`, `wantedAlgos()` use it; none left:
   as for a file no rule maintains.
3. The sweep's stale filter (not Reapply) leaves out queued files; README
   *How hashing happens*: up to 100 new files per rule and run.
4. `processRule()` queues `pending:auto` for a `lazy` rule; README *Modes*:
   `lazy` is low priority.
5. `held` counts distinct keys.
6. CHANGELOG, Fixed (drafts):
   `- README.md, *Modes*: a rule naming an algorithm the server no longer`
   `  allows has its files hashed for the others, not queued for ever.`
   `- README.md, *How hashing happens*: the sweep counts only files not`
   `  queued yet, so a file that keeps failing no longer stalls it.`
   `- README.md, *Modes*, lazy: an emptied file is hashed later, at low`
   `  priority; it was emptied again on every sweep.`

**Verification:** `changelog.sh check --released` after step 1. Unit
`HashCalculationServiceTest` (disallowed algorithm: the rest hashed; none:
dropped), `RuleServiceTest` (`lazy` → `auto`); integration `HashStampTest`
(narrowed allowlist; one queued failing file and one unqueued: the sweep
marks one; `lazy` empties, then hashes; duplicate row, missing key: swept).
Both suites on 33 and 34, ECS, Psalm, check.

### Block 2: Metadata generation keeps a file's state

1. `MetadataListener`: nothing for a file with a state row, no include
   rule, or hashes that vouch; otherwise queued in the rule's mode.
2. CHANGELOG, Fixed (draft):
   `- Nextcloud's metadata generation no longer queues every file as`
   `  missing; a revoked, queued or gone file stays as it is.`

**Verification:** unit `MetadataListenerTest`, the defect's test replaced:
revoked untouched, `pending:lazy` kept, `auto` rule → `pending:auto`, no
rule → nothing, vouched → nothing. Integration: a revoked file stays
revoked through the event. Both suites, lint, check.

### Block 3: Gone files

1. `markGone()` clears `oc_filecache.checksum` first and stamps the file's
   ETag; `pageFileidChecksums()` skips `stale:gone`.
2. The sweep takes a gone file whose ETag moved, or has none, for stale;
   Reapply follows.
3. `failedOrGone()`: a failed read asks the file and the storage's root;
   file missing under an answering root is counted in a self-indexed row,
   cleared by a found answer, a read or leaving the queue; gone on two in
   a row.
4. An ETag moved since the read means failed, not gone.
5. The gone hint: "where it can reach the file", and the FAQ; App.vue's
   text through `scripts/l10n.sh update`, eleven languages. FAQ:
   object-store primary storage. README, FAQ, drain help, docblocks on
   what makes a file gone.
6. CHANGELOG, Fixed (drafts):
   `- docs/FAQ.md, gone files: their checksums leave oc_filecache too, and`
   `  a file that comes back with another ETag is queued again.`
   `- docs/FAQ.md, gone files: two "not found" answers from a storage that`
   `  answers, not two failures of any kind.`

**Verification:** integration `HashStampTest`, the gone test continued:
column empty, backfill writes no rows, recalc reads; recreated with its
old mtime and scanned, the sweep queues it; root missing: no count; one
storage-down and one not-found: still queued. Unit: ETag moved → failed.
`npm test`, `scripts/l10n.sh check`; both suites, lint, check.

### Block 4: Drain reporting

1. `stamped`, not `hashed`, where every result existed.
2. Admin line: `typed`, `stamped`, `emptied`, `gone` where not zero; single
   counts through `n()`; l10n update, eleven languages, `js/` rebuilt.
3. `fcias:status -o json` carries every drain key; README lists them.
4. `Drain.php` escapes each outcome.
5. README: no re-dispatch when no file left the queue.
6. CHANGELOG, Changed (draft):
   `- README.md, *Repair Commands*: the drain's JSON always carries typed,`
   `  stamped, emptied and gone, and the admin page shows them.`

**Verification:** unit `HashCalculationServiceTest` (all existed →
`stamped`), `QueueDrainTest` (`</error>` printed as is),
`ProcessPendingUpdatesTest` (zero keys present); `App.spec.ts`; `npm test`,
lint, typecheck, l10n check; both suites, lint, check.

### Block 5: MIME types: features and the status count

1. `GET /api/v1/features`, any account, no snapshot: `features`, the
   allowed algorithms and the default, the app version. `docs/api-v1.md`,
   OpenAPI.
2. The same answer as initial state on the Files pages
   (`BeforeTemplateRenderedListener`) and the Duplicates page
   (`PageController`); `src/features.ts` reads it with
   `@nextcloud/initial-state`, else fetches `/features`;
   `mimeTypeSwitchedOff()` refetches.
3. `getMimeTypeStatus()` counts live while a deletion is requested or
   running.
4. A stopped refresh or deletion records its run; `isActive()` false while
   a deletion is requested; `MimeTypeSection.vue`'s docblock.
5. CHANGELOG (drafts):
   Added `- GET /api/v1/features: what the server offers, its algorithms`
   `  and its version, without the status snapshot.`
   Fixed `- The files sidebar and the Duplicates page no longer wait for`
   `  the admin status, and its checksum recount, before their request.`
   Fixed `- docs/FAQ.md, *How are MIME types detected?*: the count of`
   `  stored types falls as a deletion goes.`

**Verification:** `features.spec.ts` (initial state → no fetch; missing →
`/features`); unit `BeforeTemplateRenderedListenerTest`,
`PublicApiControllerTest` (`/features`), `StatusServiceTest` (deleting →
live), `MimeTypeRefreshTest`, `MimeTypeDeleteTest`; integration
`PublicApiTest` (`/features` as a plain user); `npm test`, lint,
typecheck; both suites, lint, check.

### Block 6: MIME types: one read, deletion, refusals, docs

1. Single-algorithm local path: one open; a failed head read leaves the
   hash standing.
2. `deleteMimeTypes()` pages by file id within a run.
3. Refusals by action; a `LogicException` between check and act is a
   refusal, not a 500; one name for the mapping per class. l10n update,
   eleven languages.
4. `markForMimeTypeAfter()`'s docblock: the race, healed by the sweep.
5. Docs: README on `fcias:mimetype:delete`; `@return` of
   `getHashesByFile()`, `getHashesByPath()`; route 4's type with
   `existed: true` in `docs/api-v1.md` and OpenAPI.
6. CHANGELOG, Fixed (drafts):
   `- docs/FAQ.md, *How are MIME types detected?*: a file is opened once`
   `  for its hash and its type, also on a local storage.`
   `- fcias:mimetype:* and the settings: a refusal names the action, and`
   `  a race between check and change no longer ends in an error 500.`

**Verification:** unit `HashCalculationServiceTest` (one open, type set),
`SettingsControllerTest` (refusal per action; the race), MIME command
tests; integration `MimeTypeTest` (deletion across pages); l10n check;
both suites, lint, check.

### Block 7: Disowned becomes revoked

1. `occ fcias:revoke` revokes the hashes; `fcias:reset --hashes` calls it,
   with a deprecation line; `fcias:reset` keeps `--config` and `--status`.
2. `stale:reset` → `stale:revoked`: a repair step moves the rows; the
   backup import maps the old value.
3. Repair step `clear-revoked`, `clear-disowned` its alias; drain JSON
   `revoked`, with `disowned` beside it.
4. Code names, help texts, logs, README, FAQ, `docs/api-v1.md`; the admin
   page's text through l10n, eleven languages.
5. CHANGELOG, Changed (draft):
   `- occ fcias:revoke, stale:revoked: revoked replaces disowned;`
   `  fcias:reset --hashes and clear-disowned remain as aliases.`

**Verification:** unit `ResetTest`, a `RevokeTest`, `RepairQuietStartTest`
(the step; the alias), `ImportServiceTest` (old value); integration: a
revoked file before and after the repair; `grep -ri disown` finds only
aliases. l10n check; both suites, lint, check.

## Proposed commit message

Registered with AP EtagStamp, in one commit:

```text
[UPDATE] APs: +EtagStamp v1.2-1.4, +ReviewFixes v1.0-1.1

File-revision-v1: <one line per revision>
Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

Each block's commits are proposed at its own gate, or made under `EXEC++`.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.0    | 2026-10-12 | Initial AP |
| v1.1    | 2026-10-12 | The 0.25.0 bullet corrected and its tag message updated; `lazy` low priority; gone on "file not found" under an answering root; `GET /api/v1/features` with algorithms and version as the fallback to initial state; Block 7, revoked |
