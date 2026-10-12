# NOTE ReviewFixes v1.1: The run completed

Where Blocks 3–7 went differently from the plan. Corrects *How the run
went* on M13.

## Block 3

- **M13 is fixed**, by the commit *The daily changed-file walk queues a
  gone file its storage has again*: the walk that finds files changed
  unheard also asks each gone file's storage, and queues one found again
  in its rule's mode, or drops its mark where no rule covers it. A file
  put back under its old mtime no longer stays gone.

## Block 4

- The admin page shows every outcome, zeros included, in one text rather
  than the non-zero ones alone. No `n()`: each count follows its word.

## Block 5

- The listener that provides the features covers the Duplicates page as
  well; `PageController` is unchanged.
- A refresh stopped by a deletion is recorded as done, with the count it
  had marked before.

## Block 6

- `ChecksumApi::mimeTypeRefusal()` is public API and keeps its name; the
  controller's private one is renamed.
- Kept by design: the type row deleted on a failed save (L12). Documented:
  the race in `markForMimeTypeAfter()` (L15).

## Block 7

- No mapping on import: a backup's status slice is never imported, so a
  backup carrying `stale:reset` needs none.
- `fcias:reset` with no slice named still revokes the hashes with the
  others; only `--hashes` is deprecated.
- The rename is a step of its own, `revoked-state`, before
  `clear-revoked`, rather than a part of `stale-states`, which runs after
  it.
- `markStale()`, `markAllStale()` and `fetchStaleBatch()` are renamed to
  `markRevoked()`, `markAllRevoked()` and `fetchRevokedBatch()` as well.
