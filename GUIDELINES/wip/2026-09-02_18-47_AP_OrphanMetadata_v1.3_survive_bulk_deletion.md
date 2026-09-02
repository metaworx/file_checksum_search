# AP OrphanMetadata v1.3: metadata that outlives the file

> **Status: preliminary — not yet approved.** Supersedes v1.2. Only the purge
> changes: a document left empty after our keys are removed is deleted rather
> than kept as `{}`.

## Discussion

A user's own file stopped being findable by its hash. Two independent defects,
neither of them the test's.

### Defect A — the limit is applied before authorisation

`HashSearchProvider` asks the database for `$query->getLimit()` rows and *then*
drops the ones the caller cannot open. `SearchQuery::LIMIT_DEFAULT` is **5**, so
five files a caller cannot open, sharing a hash, make their own copy
unfindable. The empty file's sha1 is the example in our own OpenAPI spec.

Confirmed end to end: with 11 rows holding the fixture hash — the first five all
unopenable by the caller — the spec failed 3/3. With the orphan index rows
removed it passes 6/6. Nothing else changed.

### Defect B — metadata outlives the file it describes

Deleting a user leaves document and index rows behind; filecache, storages,
mounts, accounts and preferences all go. Nextcloud's cleanup, `MetadataDelete`
on `CacheEntriesRemovedEvent`, is dispatched only from the per-entry paths in
`Files/Cache/Cache.php`; the bulk paths (`Storage::cleanByMountId()`,
`Cache::clear()`) delete filecache rows in one statement and emit nothing.

**Not specific to us — belongs upstream.** On instance 34 there were 1079 orphan
documents, of which **850 held no FCIAS key at all** and 595 held `photos-exif`,
plus 924 orphan index rows across all apps.

**Not deferred to cron.** `CleanupDeletedUsers` is a 24h `TimedJob` that only
finishes users whose deletion *failed* — it reads the `core/deleted` flag and
skips users still present in a backend. A cleanly deleted user is never
revisited.

**Registered vs unregistered keys: open.** Ours are unregistered
(`"indexed": false`). `dropIndexForFiles( $fileIds, $key = '' )` filters on
`meta_key` only when a key is passed, and `MetadataDelete` passes none, so on
*that* path registration is irrelevant. Whether any other cleanup enumerates
registered keys is untested; this AP assumes nothing either way.

## Analysis

### Why a periodic sweep, not an event listener carrying state

- **Memory.** A fileid list captured at `BeforeUserDeletedEvent` is one entry per
  hashed file, unbounded — a million ids is ~80 MB. Nextcloud's `dav` app uses
  that paired-event pattern, but stashes calendars, which number in the tens.
- **Ordering.** Clearing at `UserDeletedEvent` assumes the filecache rows are
  already gone. Listener order is priority then registration, and registration
  follows app load order; what deletes the home storage was not found at all, so
  even code ordering cannot be claimed.
- **Coverage.** Group folder removal, external storage unmount and every other
  `cleanByMountId()` / `Cache::clear()` caller give no user event to hang on.

The sweep has none of these properties: an **anti-join** — our index rows whose
`file_id` has no filecache row — needs no list, no memory, and no assumption
about when the filecache went. Running too early finds nothing; the next run
finds it. Ordering becomes a latency question, not a correctness one.

### The purge

`clearMetadata()` is the wrong call for a dead file. It re-adds the stamp
deliberately —

```php
$metadata->removeStartsWith( self::KEY_FILE_CHECKSUM_PREFIX );
$metadata->setInt( self::KEY_FILE_CHECKSUM_UPDATED_AT, 0, true );
```

— which for a live file correctly records "considered, no hashes", but for a
deleted one leaves a key and an index row, so the sweep would never converge.

`purgeMetadata( int $fileId )` instead:

1. `removeStartsWith( KEY_FILE_CHECKSUM_PREFIX )`, **no stamp re-added**;
2. if `IFilesMetadata::getKeys() === []` — nothing but ours was ever in it —
   `IFilesMetadataManager::deleteMetadata( $fileId )`, which drops document and
   index together;
3. otherwise `saveMetadata()` and `pruneHashIndexRows()`, leaving the other
   app's keys untouched.

Step 2 is needed because Nextcloud does not do it: `saveMetadata()` encodes an
empty set to `{}` and stores it, so without an explicit delete every purged file
leaves a `{}` row behind. Deleting is safe precisely *because* the set is empty
— nothing of anyone else's is in it.

### Why index rows alone are not enough

`rebuild-from-metadata` rebuilds index rows from documents, so an index-only
deletion is undone by the next repair. Not hypothetical — it is the current
state of instance 34, below.

## Implementation Plan

### Block 1 — limit after authorisation · `[FIX]`

1. Narrow `queryByHash` by the caller's mounted storages before the limit:
   `f.storage IN (SELECT storage_id FROM oc_mounts WHERE user_id = ?)`, joined
   through `oc_filecache`, via `IQueryBuilder`.
   **`getById()` stays the authority.** The join only narrows: it cannot wrongly
   grant, and cannot wrongly deny, because anything visible is in one of the
   caller's mounts. Storage alone is *not* an authorisation test — a share of a
   subfolder mounts the owner's storage — which is why `getById()` is kept.
2. Regression test: a hash held by more than `LIMIT_DEFAULT` files the caller
   cannot open, plus one they can. Must fail on today's code.

### Block 2 — purge and sweep · `[TASK]`

3. `MetadataService::purgeMetadata( int $fileId )` as above, with a unit test per
   branch: empty document deleted, mixed document kept and stripped.
4. Repair step `orphaned-metadata`: anti-join our `file-checksum-%` index rows
   against `oc_filecache`, batched, `purgeMetadata()` each. Marked `expensive`.
5. Also reachable from the existing background job, so it runs unasked.

### Block 3 — make it prompt · `[TASK]`

6. `UserDeletedEvent` listener that requests a sweep run. No fileid list, no
   state across events, nothing to reverse if it never fires.

### Block 4 — report upstream · `[TASK]`

7. File against nextcloud/server: `MetadataDelete` listens only to
   `CacheEntriesRemovedEvent`, which the bulk teardown paths never dispatch, so
   `oc_files_metadata` and `oc_files_metadata_index` leak for every app on user
   deletion and on external-storage removal. Include the counts above and both
   code paths.

**Verification:** Block 1 by its regression test and the full e2e suite. Blocks
2–3 by an integration test that creates an account, hashes a file, deletes the
account, runs the sweep, and asserts both tables are clean.

## Instance 34, current state

- 74 orphan **index** rows deleted on 2026-09-02; the failing spec went 0/6 →
  6/6, the end-to-end confirmation of Defect A.
- **229 orphan documents still hold our keys**, all verified to hold *only*
  FCIAS keys. They are Block 2's first acceptance case. Until it runs, do not
  run `fcias:repair --step rebuild-from-metadata`: it would rebuild the index
  rows from those documents and re-break the search.

## Open decisions

None.

## Change History

- v1.0 (2026-09-02): marked rows at `BeforeUserDeletedEvent` on
  `inTransaction()`.
- v1.1 (2026-09-02): captured the fileid list at Before, cleared at After.
- v1.2 (2026-09-02): the sweep becomes the mechanism — the list does not scale,
  the After event's ordering cannot be relied on, and neither covers the paths
  with no user event. Added the full-removal variant and the upstream report.
- v1.3 (2026-09-02): purge deletes a document that holds nothing but our keys,
  rather than leaving `{}` — Nextcloud's `saveMetadata()` will not.
