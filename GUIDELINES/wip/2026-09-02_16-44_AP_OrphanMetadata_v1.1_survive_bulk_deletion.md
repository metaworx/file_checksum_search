# AP OrphanMetadata v1.1: metadata that outlives the file

> **Status: preliminary — not yet approved.** Supersedes v1.0 (same day), whose
> user-deletion design marked rows at `BeforeUserDeletedEvent`. That is a veto
> hook, and marking there was wrong. Blocks 1 and 4 are unchanged from v1.0.

## Discussion

A user's own file stopped being findable by its hash. The test was right and
neither defect is the test's.

### Defect A — the limit is applied before authorisation

`HashSearchProvider` asks the database for `$query->getLimit()` rows and *then*
drops the ones the caller cannot open. `SearchQuery::LIMIT_DEFAULT` is **5**, so
once five files a caller cannot open share a hash, that caller cannot find their
own copy. Nothing exotic triggers it: the empty file's sha1 is the example in
our own OpenAPI spec.

Measured: 11 rows hold the fixture hash on instance 34; the first five are one
live file plus four whose users are deleted. The searching account's own file
sorts 11th.

### Defect B — metadata outlives the file it describes

Measured on an account deleted for the purpose: filecache, storages, mounts,
accounts and preferences all gone; **document and index rows survive**.
Instance-wide, 74 orphan `file-checksum-%` index rows across 30 files, every one
with a document, zero index-only.

Nextcloud's cleanup — `MetadataDelete` on `CacheEntriesRemovedEvent`, which drops
document *and* index — is dispatched only from the per-entry paths in
`Files/Cache/Cache.php`. Bulk teardown bypasses it: `Storage::cleanByMountId()`
and `Cache::clear()` both delete filecache rows in one statement and emit
nothing. Our own `FileListener::onDelete` is bypassed for the same reason.

**Not deferred to cron.** `CleanupDeletedUsers` is a 24h `TimedJob`, but it only
finishes users whose deletion *failed*: it reads the `core/deleted` flag and
skips users still present in a backend. A cleanly deleted user is never
revisited, and no other periodic metadata cleanup exists in the v34 tree.

**Registered vs unregistered keys: open.** Ours are unregistered — the document
records `"indexed": false`. `IndexRequestService::dropIndexForFiles( $fileIds,
$key = '' )` filters on `meta_key` only when a key is passed and `MetadataDelete`
passes none, so *on that path* registration is irrelevant. Whether any other or
future cleanup enumerates registered keys is untested. This AP assumes nothing
either way.

## Analysis

### Why the marking design in v1.0 was wrong

`BeforeUserDeletedEvent` exists to answer *may this be deleted* — it fires at
`User.php:262`, before `backend->deleteUser()` at `:271`, which can still return
false. Writing state there means writing it for a deletion that may not happen.
It also collides with machinery that is already in place:

- `fetchPendingBatch()` selects `pending:%` and
  `parseMode( 'pending:user_delete' )` returns `'user_delete'`, which the drain
  hands to `processFile()` as a mode — it would hash the files of a user
  mid-deletion.
- Marking overwrites the one state column, destroying a queued `pending:auto`
  that an aborted deletion could not restore.

### What the After event gives instead

`UserDeletedEvent` is dispatched at `User.php:326`, past the `return false` at
`:275`, so it fires **only on success**. That turns "after the filecache entries
are indeed gone" from something to infer into something guaranteed — no
transaction needed, and nothing to undo.

Its one limit is that it cannot say which fileids were the user's: our index
rows do not record a user, and the `home::<uid>` storage row is gone by then.
Before can, as a **read**:

| | |
|---|---|
| `BeforeUserDeletedEvent` | Select the fileids on that user's home storage that carry a `file-checksum-%` index row. Stash in a service. **No writes.** |
| `UserDeletedEvent` | Deletion confirmed. `clearMetadata()` each stashed id. |

Both fire from the same `User::delete()` call in one process, so a service
holding the list between them is sound. The join against our own index rows
keeps the list to files we actually hashed rather than the whole home. If the
deletion aborts, After never fires and the list is dropped with the request.

### What may be removed from a document

Only our own keys. `clearMetadata()` already does exactly that —
`removeStartsWith( KEY_FILE_CHECKSUM_PREFIX )`, then saves through Nextcloud's
manager — so another app's metadata in the same document survives. The document
is never deleted, even when nothing of ours is left: that is Nextcloud's to reap.

### Why deleting only our index rows is wrong

`rebuild-from-metadata` rebuilds index rows from documents, so an index-only
deletion is undone by the next repair. The document keys must go with them.

### To verify during implementation

Whether the filecache rows are already gone when `UserDeletedEvent` fires. The
probe measured the state *after* `delete()` returned, which does not isolate it.
A temporary logging listener answers it. If they are not yet gone, clearing at
After is still correct but early, and the sweep is what actually guarantees the
outcome.

## Implementation Plan

### Block 1 — limit after authorisation · `[FIX]`

1. Narrow `queryByHash` by the caller's mounted storages before the limit:
   `f.storage IN (SELECT storage_id FROM oc_mounts WHERE user_id = ?)`, joined
   through `oc_filecache`, via `IQueryBuilder`.
   **`getById()` stays the authority.** The join only narrows: it cannot wrongly
   grant, because `getById()` still decides, and cannot wrongly deny, because
   anything visible is in one of the caller's mounts. Storage alone is *not* an
   authorisation test — a share of a subfolder mounts the owner's storage — which
   is why `getById()` is kept.
2. Regression test: a hash held by more than `LIMIT_DEFAULT` files the caller
   cannot open, plus one they can. Must fail on today's code.

### Block 2 — capture, then clear · `[TASK]`

3. A listener registered for both events, in the app's existing
   `X::register( $context )` style.
4. Before: read the fileids, stash them. After: `clearMetadata()` each.
5. Chunked, and it must never throw into the deletion path — a failure here
   leaves an orphan for Block 4, which is the acceptable outcome.

### Block 3 — sweep what no hook catches · `[TASK]`

6. Repair step `orphaned-metadata`: index rows keyed `file-checksum-%` whose
   `file_id` has no filecache row → `clearMetadata()` each. Covers what predates
   the listener, what a failed listener left, and the paths with no user event at
   all — group folder removal, external storage unmount, any `cleanByMountId()`
   or `Cache::clear()` caller.
7. Batched, marked `expensive`, and run from the existing background job as well
   as on demand.

**Verification:** Block 1 by its regression test and the full e2e suite. Blocks
2–3 by an integration test that creates an account, hashes a file, deletes the
account, and asserts both tables are clean. Instance 34's 74 orphans are Block
3's acceptance case.

## Open decisions

1. **Instance 34 has 74 orphans now** and the e2e suite stays red until they go.
   Clear them by hand to unblock the suite, or leave them as Block 3's
   acceptance case?

## Change History

- v1.0 (2026-09-02): written from measurement; marked rows at
  `BeforeUserDeletedEvent`, with `stale:`/`pending:` chosen on
  `inTransaction()`.
- v1.1 (2026-09-02): the Before hook is a veto, not a mutation point. Capture at
  Before as a read, clear at After, which fires only on success — no marker, no
  collision with the drain, nothing to reverse on an aborted deletion.
