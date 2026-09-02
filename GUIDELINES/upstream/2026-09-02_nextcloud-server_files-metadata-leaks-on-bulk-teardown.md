# Upstream report draft — nextcloud/server

> **Status: draft, not yet filed.** Filing is an outward-facing action and is
> the maintainer's to take. Everything below was measured on a Nextcloud 34
> instance; paths and line numbers are from the v34 tree.

## Title

`files_metadata` and `files_metadata_index` rows survive user deletion and
other bulk filecache teardown — `CacheEntriesRemovedEvent` is never dispatched
on those paths

## Summary

`OC\FilesMetadata\Listener\MetadataDelete` is the only thing that removes a
file's metadata when the file goes, and it listens to
`OCP\Files\Cache\CacheEntriesRemovedEvent`. That event is dispatched only by
the per-entry removal paths in `lib/private/Files/Cache/Cache.php`
(`remove()`/`removeChildren()`, around lines 669 and 850). The bulk paths do
not dispatch it:

- `OC\Files\Cache\Storage::cleanByMountId()` (`lib/private/Files/Cache/Storage.php:162`)
  — `DELETE FROM filecache WHERE storage IN (…)` in a transaction, no events.
  Reached from `files_external` when a mount is removed
  (`apps/files_external/lib/Service/StoragesService.php:430`).
- `OC\Files\Cache\Cache::clear()` (`lib/private/Files/Cache/Cache.php:877`)
  — `DELETE FROM filecache WHERE storage = ?`, then the `storages` row, no events.

Deleting a user removes its filecache, storage, mount, account and preference
rows; every app's metadata for every file the user owned stays behind, keyed on
file ids that no longer exist.

`OC\User\BackgroundJobs\CleanupDeletedUsers` does not cover this: it only
finishes users whose deletion *failed* (it reads the `core/deleted` flag and
skips users still present in a backend). No periodic metadata cleanup exists;
the one metadata job, `UpdateSingleMetadata`, updates rather than deletes.

## Reproduction

1. Create a user, log in or write a file over WebDAV so a home storage exists.
2. Let any metadata be generated for a file — opening a photo (`photos-exif`)
   is enough; any app that calls `IFilesMetadataManager::saveMetadata()` will do.
3. Note the file id, then delete the user (`occ user:delete`, or the
   provisioning API).
4. `SELECT COUNT(*) FROM oc_filecache WHERE fileid = :id` → 0.
   `SELECT COUNT(*) FROM oc_files_metadata WHERE file_id = :id` → 1.
   `SELECT COUNT(*) FROM oc_files_metadata_index WHERE file_id = :id` → ≥ 1.

## Measured on one development instance

| | count |
|---|---|
| `oc_files_metadata` rows whose `file_id` has no filecache row | 1079 |
| … of which hold core `photos-exif` metadata | 595 |
| `oc_files_metadata_index` rows whose `file_id` has no filecache row | 924 |

So this is not one app's problem: 850 of the 1079 orphaned documents carried
nothing from the reporting app at all.

## Why it matters beyond disk

Index rows of deleted files still match queries against
`oc_files_metadata_index`. Any app that searches the index and then filters by
what the requesting user may open is filtering rows that can never resolve —
and if it applies a `LIMIT` in SQL before that filter, enough orphans hide a
live result. That is how this was found.

## Suggested fix

Either of:

1. Have the bulk paths dispatch `CacheEntriesRemovedEvent` for the removed
   entries (both already know the storage id; `Cache::clear()` could select the
   file ids before deleting, `cleanByMountId()` likewise), or call
   `IFilesMetadataManager::deleteMetadataForFiles()` directly with the ids.
2. A periodic job that removes `files_metadata` / `files_metadata_index` rows
   whose `file_id` has no `filecache` row — the anti-join needs no announcement
   and cannot run too early.

(1) is the correct fix; (2) would also collect what earlier versions have
already left behind.

## Affected

Every app using `IFilesMetadataManager`, including `photos`. Observed on
Nextcloud 34; the code paths are the same in 33.
