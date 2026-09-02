# AP OrphanMetadata v1.2: metadata that outlives the file

> **Status: preliminary — not yet approved.** Supersedes v1.1. The periodic
> sweep becomes the mechanism rather than the backstop; the user-deletion
> listener becomes an optimisation that carries no state. Block 1 unchanged.

## Discussion

A user's own file stopped being findable by its hash. Two independent defects,
neither of them the test's.

### Defect A — the limit is applied before authorisation

`HashSearchProvider` asks the database for `$query->getLimit()` rows and *then*
drops the ones the caller cannot open. `SearchQuery::LIMIT_DEFAULT` is **5**, so
five files a caller cannot open, sharing a hash, make their own copy
unfindable. The empty file's sha1 is the example in our own OpenAPI spec.

Confirmed end to end: with 11 rows holding the fixture hash — the first five all
unopenable by the caller — the spec failed 3/3. With the orphans removed it
passes 6/6. Nothing else changed.

### Defect B — metadata outlives the file it describes

Deleting a user leaves document and index rows behind; filecache, storages,
mounts, accounts and preferences all go. Nextcloud's own cleanup,
`MetadataDelete` on `CacheEntriesRemovedEvent`, is dispatched only from the
per-entry paths in `Files/Cache/Cache.php`; the bulk paths
(`Storage::cleanByMountId()`, `Cache::clear()`) delete filecache rows in one
statement and emit nothing.

**This is not specific to us, and belongs upstream.** On instance 34 there were
1079 orphan documents, of which **850 held no FCIAS key at all** and 595 held
`photos-exif`, plus 924 orphan index rows across all apps.

**Not deferred to cron.** `CleanupDeletedUsers` is a 24h `TimedJob` that only
finishes users whose deletion *failed* — it reads the `core/deleted` flag and
skips users still present in a backend. A cleanly deleted user is never
revisited.

**Registered vs unregistered keys: open.** Ours are unregistered
(`"indexed": false`). `dropIndexForFiles( $fileIds, $key = '' )` filters on
`meta_key` only when a key is passed and `MetadataDelete` passes none, so on
*that* path registration is irrelevant. Whether any other cleanup enumerates
registered keys is untested; this AP assumes nothing either way.

## Analysis

### Why the sweep is the mechanism, not the backstop

v1.0 marked rows at `BeforeUserDeletedEvent`; v1.1 captured a fileid list there
and cleared at `UserDeletedEvent`. Both are worse than a periodic anti-join:

- **Memory.** The list is one entry per hashed file, unbounded — a million ids
  is ~80 MB. Nextcloud's `dav` app uses the paired-event pattern, but it stashes
  calendars, which number in the tens. `php://temp` would bound the memory at
  the cost of a spill file held across an event boundary that may never arrive.
- **Ordering.** Clearing at the After event assumes the filecache rows are
  already gone. Listener order is priority then registration, and registration
  follows app load order; worse, what deletes the home storage was not found at
  all, so even code ordering cannot be claimed. A test showing "gone" would show
  one run's luck.
- **Coverage.** Group folder removal, external storage unmount and every other
  `cleanByMountId()` / `Cache::clear()` caller give no user event to hang on.

The sweep has none of these properties. It finds orphans by an **anti-join** —
our index rows whose `file_id` has no filecache row — so it needs no list, no
memory, and no assumption about when the filecache went. Running it too early
finds nothing; the next run finds it. Ordering stops being a correctness
question and becomes a latency one.

The user-deletion listener therefore carries **no state**: at most it asks for
the sweep to run soon, so cleanup is prompt rather than up to a day late.

### `clearMetadata()` is the wrong call for a dead file

It re-adds the stamp deliberately:

```php
$metadata->removeStartsWith( self::KEY_FILE_CHECKSUM_PREFIX );
$metadata->setInt( self::KEY_FILE_CHECKSUM_UPDATED_AT, 0, true );
```

For a live file that is right — the stamp records "considered, no hashes". For a
deleted one it leaves one of our keys and one index row, so the sweep would
never converge. It needs a full-removal variant. (v1.1 said `clearMetadata()`
would do; it will not.)

### What may be removed from a document

Only our own keys, via `removeStartsWith( KEY_FILE_CHECKSUM_PREFIX )`, saved
through Nextcloud's manager. The document row is never deleted, even when
nothing of ours remains: that is Nextcloud's to reap.

### Why index rows alone are not enough

`rebuild-from-metadata` rebuilds index rows from documents, so an index-only
deletion is undone by the next repair. This is not hypothetical — it is the
current state of instance 34, see below.

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

### Block 2 — the sweep · `[TASK]`

3. `MetadataService::purgeMetadata( int $fileId )`: `removeStartsWith` with **no**
   stamp re-added, save, then `pruneHashIndexRows()`.
4. Repair step `orphaned-metadata`: anti-join our `file-checksum-%` index rows
   against `oc_filecache`, batched, `purgeMetadata()` each. Marked `expensive`.
5. Also reachable from the existing background job, so it runs without anyone
   asking.

### Block 3 — make it prompt · `[TASK]`

6. `UserDeletedEvent` listener that requests a sweep run. No fileid list, no
   state across events, and nothing to reverse if it never fires.

### Block 4 — report upstream · `[TASK]`

7. File against nextcloud/server: `MetadataDelete` listens only to
   `CacheEntriesRemovedEvent`, which the bulk teardown paths never dispatch, so
   `oc_files_metadata` and `oc_files_metadata_index` leak for every app on user
   deletion and on external-storage removal. Include the counts above and the
   two code paths.

**Verification:** Block 1 by its regression test and the full e2e suite. Blocks
2–3 by an integration test that creates an account, hashes a file, deletes the
account, runs the sweep, and asserts both tables are clean — the unit test the
sweep needs anyway.

## Instance 34, current state

- 74 orphan **index** rows deleted on 2026-09-02; the failing spec went from
  0/6 to 6/6, which is the end-to-end confirmation of Defect A.
- **229 orphan documents still hold our keys.** All 229 were verified to hold
  *only* FCIAS keys. Blanking them was refused by the permission classifier and
  is left to the user. Until it is done, `fcias:repair --step
  rebuild-from-metadata` will rebuild the index rows and re-break the search —
  which is Block 2's acceptance case, and a reason to write it before running
  that step again.

## Open decisions

None.

## Change History

- v1.0 (2026-09-02): marked rows at `BeforeUserDeletedEvent` on
  `inTransaction()`.
- v1.1 (2026-09-02): captured the fileid list at Before, cleared at After.
- v1.2 (2026-09-02): the sweep becomes the mechanism — the list does not scale,
  the After event's ordering cannot be relied on, and neither covers the paths
  with no user event. Adds the missing full-removal variant, and the upstream
  report.
