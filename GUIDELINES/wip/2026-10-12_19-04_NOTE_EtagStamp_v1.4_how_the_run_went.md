# NOTE EtagStamp v1.4: How the run went

Where Blocks 6–12 went differently from the plan. Block 13 was not run:
the user left it for later.

## Block 6

- `MetadataService::isCurrent()` became public and takes the filecache's
  state; `HashCalculationService` asks it rather than keeping its own test.
  The unit tests of hashing stub it from the stamp they set.
- The recalculation test needed the file loaded again after the change: a
  node keeps the ETag it was loaded with, and its read then looks written
  to meanwhile.

## Block 7

- Only the NULL `fc.etag` and the README, as v1.3 planned.

## Block 8

- The empty state `stat` gives a file the filecache no longer has became
  one constant, `ChecksumApi::NO_STATE`, here rather than in Block 12.
- The Duplicates page's query was timed on the harness only: 132 hash rows,
  the time occ's own start.

## Block 9

- Adoption asks a file's queue mode per file, and only for a file it
  queues (`RuleService::queueModeFor()`), rather than per page: most files
  walked are adopted, not queued.
- The stats slots needed two lexicon entries each, and a third per job
  that `JobStatsService::JOBS` generates.
- The changed-file walk's admin line reuses *queued, finished* and *queued,
  not finished yet*; the stamp check's translator comment no longer says
  "of those".

## Block 10

- The files area is kept by `FileLocation`'s own test, a row without a
  files-area path skipped, rather than by the storage's id in SQL: it is
  the test every listing and verdict already uses.

## Block 12

- `FilecacheService::stateOf()` gives the size too, as `statesOf()` does.
- `deleteState()` goes through `unsetSelfIndexed()` only without a
  pattern; with one, it is a conditional delete of its own.
