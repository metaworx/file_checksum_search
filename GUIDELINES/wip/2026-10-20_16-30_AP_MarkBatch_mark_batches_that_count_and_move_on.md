# AP MarkBatch v1.0: mark batches that count and move on

## TL;DR

`fcias:hash --mark --batch-size` counts each marked file twice,
and repeated runs mark the same first files again.
The budget is spent once, and a batch is spent on files that need hashing,
so the next run starts where the last one stopped.

- double count → the budget passed by value, spent by the caller once
- the batch → only files the drain will hash: none a rule leaves,
  none already queued, under `missing` none whose checksums are current
- excluded files → no longer use up the search's limit
- versions under `--mark` → the same filters
- found in → AP FileVersions' run, 2026-10-20; shipped since v0.7.0

### Blocks

1. The double count: the budget spent once.
2. A batch that moves on: only files that need hashing are queued.

## Discussion

- **The ask.**
  The user, 2026-10-20: plan the double count's fix (PLAN), then **D1-B**:
  the fix, and `--mark` batches that move on.

## Analysis

- **The double count.**
  `HashFiles::markFolder()` takes `?int &$remaining` and lowers it for each file it marks;
  `executeMarkOnly()` then subtracts the count again.
  The reference dates from 2026-08-06, when `markFolder()` walked folders
  recursively and shared one budget down the walk.
  Today it makes one `RuleService::searchFilesByGlob()` call,
  and the reference serves nothing.
  With `--batch-size 10` and a first account marking 3 files,
  the next gets 4, not 7; since 0.27.0 the first account's versions too.
- **The search's limit.**
  `searchFilesByGlob()` returns the first `$limit` files the glob matches;
  `markFolder()` then drops those a rule leaves.
  A batch marks fewer than it may, and the run ends with *Done.*
  while matching files remain.
  The search also stops after scanning five times the limit,
  which would end a filtered batch early.
- **Repeated runs.**
  Nothing filters a file already queued, or one whose checksums are current,
  so each run finds and marks the same first files again.
  The direct path does not: `HashCalculationService::collectFilesForUser()`
  walks the account's folders, judges each file by its rule,
  and under `missing` leaves a file holding its rule's algorithms, fresh
  (`hasAllAndFresh()`), collecting until the batch is full.
  It does not look at the queue.
- **The drain** computes, for `pending:missing`, the rule's algorithms
  a file lacks or holds outdated;
  for a file with none to compute it reads nothing.
  So a file it would leave as it is need not be queued.
- **Versions.**
  `HashCalculationService::markVersions()` queues every version whose rule
  says `versions`; `freshAmong()` already tells the current ones.
- **The queue marker** is the `file-checksum-state` row;
  `MetadataService::getMarker()` reads one file's.
  There is no read of many.

### Design

1. **The budget, once.**
   `markFolder()` takes the budget by value and counts down its own copy;
   `executeMarkOnly()` subtracts what it marked.
2. **A batch that moves on (D1-B).**
   - `--mark` collects as the direct path does,
     through `collectFilesForUser()` with each file's rule's algorithms,
     and leaves a file already queued besides: `MetadataService::queuedAmong()`,
     one query a listing.
     Under `force` only the queued are left.
   - `HashCalculationService::markMissingHashes()` collects and queues,
     and `HashFiles` calls it through `HashIndexService`;
     `markFolder()` goes.
   - `markVersions()` leaves a queued version, and under `missing`
     a current one (`freshAmong()`).
   - A file whose rule supplies no algorithm is left:
     the drain would compute nothing.

## Implementation Plan

### Block 1: the double count

1. `HashFiles::markFolder()`:
   the budget by value.
2. Tests: unit for `--mark --batch-size` over two accounts,
   the second searched with what the first left.
3. `CHANGELOG.md`, `### Fixed`.

**Verification:**
unit and integration suites on 33 and 34; ECS, Psalm, `tools/check`;
a mutation check.

### Block 2: a batch that moves on

1. `MetadataService::queuedAmong()`.
2. `HashCalculationService::collectFilesForUser()`:
   the queued left where asked;
   `markMissingHashes()`; `markVersions()` with the same filters.
3. `HashIndexService::markMissingHashes()`;
   `HashFiles::executeMarkOnly()` through it; `markFolder()` removed.
4. `README.md`, *`hash` and the rules*:
   what `--mark` queues.
5. Tests: unit for the service and the command;
   integration for `queuedAmong()` and for two `--mark --batch-size` runs,
   the second marking the files after the first's.
6. `CHANGELOG.md`, `### Changed`.

**Verification:**
as Block 1.

## Proposed commit messages

- `[FIX] fcias:hash --mark counts each marked file once against the batch`
  (Block 1)
- `[TASK] fcias:hash --mark queues only files that need hashing`
  (Block 2)

## Change History

- v1.0 (2026-10-20): first version.
