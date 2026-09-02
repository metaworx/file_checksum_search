# AP OrphanMetadata v1.0: metadata that outlives the file

> **Status: preliminary — not yet approved.** Found by `rules-personal.cy.js`
> failing after the e2e suite moved to ephemeral accounts (`4a1f01b`). Two
> independent defects; Block 1 stands alone and could ship first.

## Discussion

A user's own file stopped being findable by its hash. The test was right and
neither defect is the test's.

### Defect A — the limit is applied before authorisation

`HashSearchProvider` asks the database for `$query->getLimit()` rows and *then*
drops the ones the caller cannot open:

```php
$rows = confirmFullHash( queryByHash( $hash, $algo, $query->getLimit() ), $hash );
foreach ( $rows as $row ) { if ( empty( $userFolder->getById( $fileId ) ) ) continue; … }
```

`SearchQuery::LIMIT_DEFAULT` is **5**. So once five files a caller cannot open
share a hash, that caller cannot find their own copy — the query never returns
their row. Nothing exotic is needed to hit it: the empty file's sha1 is the
example in our own OpenAPI spec.

Measured on instance 34: 11 rows hold the fixture hash; the first five are one
live file plus four whose users are deleted. The searching account's own file
sorts 11th.

### Defect B — metadata outlives the file it describes

Deleting a user leaves this app's rows behind. Measured on an account deleted
for the purpose:

| | after `user:delete` |
|---|---|
| filecache, storages, mounts, accounts, preferences | 0 — all gone |
| `oc_files_metadata` (document) | **1 — survives** |
| `oc_files_metadata_index` | **2 — survive** |

Instance-wide: 74 orphan `file-checksum-%` index rows across 30 files, **every
one with a document**; zero index-only orphans.

**Why.** Nextcloud's cleanup is `OC\FilesMetadata\Listener\MetadataDelete`,
which listens to `CacheEntriesRemovedEvent` and drops document *and* index. That
event is dispatched only from the per-entry paths in `Files/Cache/Cache.php`.
Bulk teardown bypasses it — `Storage::cleanByMountId()` runs a plain
`DELETE FROM filecache WHERE storage IN (…)` inside a transaction, no events.
Our own `FileListener::onDelete` (`NodeDeletedEvent`) is bypassed for the same
reason, which is why ordinary file deletion is clean and this stayed invisible.

**Will Nextcloud clean it up later?** `OC\User\BackgroundJobs\CleanupDeletedUsers`
is a `TimedJob` on a 24h interval, but it only finishes users whose deletion
*failed* partway: it reads the `core/deleted` flag and skips users still present
in a backend. A cleanly deleted user is never revisited. No other periodic
metadata cleanup exists in the v34 tree — the only metadata job,
`UpdateSingleMetadata`, updates rather than deletes.

**Registered vs unregistered keys.** Our keys are unregistered — the document
records `"indexed": false` and we write index rows ourselves.
`IndexRequestService::dropIndexForFiles( array $fileIds, string $key = '' )`
filters on `meta_key` **only when a key is passed**, and `MetadataDelete` passes
none — so on the path that does run, registration is irrelevant and our rows
would go too. Whether some other or future cleanup enumerates registered keys is
**not established**, and this AP does not assume it either way.

## Analysis

### Why deleting only our index rows is wrong

It looks like the minimal fix — Defect A needs the index row gone, because
`queryByHash` INNER JOINs the document — but `rebuild-from-metadata` reads
documents and rebuilds index rows from them. Deleting the index while leaving
our keys in the document means the next repair puts the orphan back. The
document keys have to go with it.

### What may be removed from a document

Only our own keys. `clearMetadata()` already does exactly this —
`removeStartsWith( KEY_FILE_CHECKSUM_PREFIX )` then saves through Nextcloud's
manager — so another app's metadata in the same document is untouched. The
document itself is never deleted, even when nothing of ours remains: that is
Nextcloud's to reap.

Timing constraint: keys may be stripped **inside a transaction covering the
deletion, or after the filecache entries are confirmed gone** — never
speculatively on an announcement that might not happen.

### Where the announcement sits

`BeforeUserDeletedEvent` is the only hook that fires while the fileids are still
enumerable. In Nextcloud's own path it is **not** inside a transaction and the
deletion has not happened yet:

| `User.php` | |
|---|---|
| 262 | `dispatchTyped( new BeforeUserDeletedEvent( … ) )` |
| 271 | `$this->backend->deleteUser( … )` — can still fail |
| 309–316 | a transaction, but only around preference deletion |

So the marker must be provisional: `pending:user_delete`. The `stale:` branch is
for a caller that has wrapped the deletion in its own transaction, which
`IDBConnection::inTransaction()` reports at runtime.

### The marker collides with the queue

Two hazards, both from the state string being one column:

1. `fetchPendingBatch()` selects on `pending:%`, and
   `parseMode( 'pending:user_delete' )` returns `'user_delete'`, which the drain
   would hand to `processFile()` as a mode. Left alone, the drain would hash the
   files of a user being deleted. The batch query must exclude the marker and
   route it instead.
2. Marking overwrites whatever the row held, so a queued `pending:auto` is lost.
   For a user being deleted that is free; for a deletion that then **aborts** it
   is not recoverable. Accepted cost — the file is re-queued from its rules on
   the next sweep — but stated rather than discovered.

### The terminal state already has a processor

`stale:` files are already drained ahead of queued ones, and
`clearDisownedFiles()` does the right thing per file: `clearMetadata()`, then
re-queue only if a rule still maintains it — which, for a file whose user is
gone, it will not. So marking correctly is most of the work; the sweep reuses
existing machinery.

## Implementation Plan

### Block 1 — limit after authorisation · `[FIX]`

1. Narrow `queryByHash` by the caller's mounted storages before applying the
   limit: `f.storage IN (SELECT storage_id FROM oc_mounts WHERE user_id = ?)`,
   joined through `oc_filecache`, via `IQueryBuilder`.
   **`getById()` stays the authority.** The join is a narrowing pre-filter only:
   it cannot wrongly grant, because `getById()` still decides; and it cannot
   wrongly deny, because anything a user can see is in one of their mounts.
   Storage alone is *not* an authorisation test — a share of a subfolder mounts
   the owner's storage — which is exactly why `getById()` is kept.
2. Regression test: a hash held by more than `LIMIT_DEFAULT` files the caller
   cannot open, and one they can. Must fail on today's code.

### Block 2 — announce the deletion · `[TASK]`

3. Listener on `BeforeUserDeletedEvent`: enumerate the user's fileids from their
   home storage, then mark — `stale:user_deleted` when
   `IDBConnection::inTransaction()`, otherwise `pending:user_delete`.
4. A bulk `markPending()`; the current one is per-file, and a home folder is not.

### Block 3 — process each state · `[TASK]`

5. `fetchPendingBatch()` excludes `pending:user_delete`; a separate path takes
   it: filecache entry gone → promote to `stale:user_deleted`; still present →
   the deletion aborted, drop the marker and let the rules re-queue.
6. `stale:user_deleted` needs no new processor — `clearDisownedFiles()` already
   strips our keys and leaves the document.

### Block 4 — sweep what is already orphaned · `[TASK]`

7. Repair step `orphaned-metadata`: index rows with key `file-checksum-%` whose
   `file_id` has no filecache row → `clearMetadata()` each. Covers what predates
   the hook and what no hook can catch — group folder removal, external storage
   unmount, any `cleanByMountId()` caller.
8. Batched and marked `expensive`, and run from the existing background job as
   well as on demand.

**Verification:** Block 1 by the new regression test plus the full e2e suite;
Blocks 2–4 by an integration test that creates an account, hashes a file,
deletes the account and asserts both tables are clean. Instance 34's 74 orphans
are the acceptance case for Block 4.

## Open decisions

1. **Instance 34 has 74 orphans now**, and the e2e suite stays red until they
   are cleared. Clear them with Block 4's step once written, or by hand first to
   unblock the suite?

## Change History

- v1.0 (2026-09-02): written from measurement — a deliberately deleted probe
  account, the row counts above, and the Nextcloud paths named. The
  registered-vs-unregistered question is recorded as open rather than resolved.
