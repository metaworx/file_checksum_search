# AP IterateHashes v1.0: the whole listing in process

## Discussion

- **The ask.** An app calling the PHP API in process wants every hash in
  reach without paging itself: `listHashes()` gives at most 1000 files a
  call, and `limit = 0` is the count.
- **Settled with the user, 2026-10-04:**
  - a generator, `ChecksumApi::iterateHashes()`, not `limit = -1`: a second
    special value on one parameter, and one array the size of the instance;
  - built on the listing's keyset pages, not one query read row by row: on
    MySQL and PostgreSQL the whole result reaches PHP when the query runs,
    the hashes and paths are read in batches beside it anyway, and an
    unbuffered result would forbid the caller's own queries between yields;
  - one batch in memory at a time, 500 files; loading the next batch while
    the caller works on the current one would need a thread or an async
    query, which Nextcloud's database layer does not offer, and in one
    process it only moves the wait;
  - the REST route keeps its pages.

## Analysis

- `HashListingService::page()` resolves the reach's views on every call;
  the generator resolves them once and asks the page query batch by batch,
  `file_id > after`, until a batch comes back short.
- It takes no count: a caller wanting the total asks `listHashes()` with
  `limit = 0`.
- Entries are those of `listHashes()`'s `files`, keyed by file id; `$after`
  resumes a walk that was interrupted.

## Implementation Plan

### Block 1: `ChecksumApi::iterateHashes()`

1. `HashListingService::iterate( ?array $reachUids, ?string $algo, ?int $since,
   bool $withLocalPath, int $after ): Generator`, batches of 500; `page()`
   and it share the normalising of `algo` and `after`.
2. `ChecksumApi::iterateHashes()` returns it.
3. Unit tests: the batches, the stop on a short batch and on an empty one,
   the views resolved once, no count, the keys. Integration: the walk is
   the pages put together; the caller's own queries between yields; a
   resumed walk.
4. `docs/api-v1.md`: the method.

**Verification:** PHPUnit unit and integration on NC 33 and 34, ECS, Psalm.
**CHANGELOG:** the Added bullet names `iterateHashes()`.

## Proposed commit messages

1. `[TASK] ChecksumApi iterates every hash in reach`

## Change History

- v1.0 (2026-10-04): first version.
