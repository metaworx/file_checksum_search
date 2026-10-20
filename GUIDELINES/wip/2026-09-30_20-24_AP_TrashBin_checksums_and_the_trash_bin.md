# AP TrashBin v1.1: checksums and the trash bin

## TL;DR

A file keeps its checksums in the trash and comes back with them,
and search finds it there, so that it can be restored.
Today the move to the trash clears them,
and a restore brings the file back unhashed while its versions kept theirs.

- move to the trash → checksums kept (D2-A); today cleared
- restore → heard, both trashes; checksums kept where the ETag proves them,
  else queued
- results → `trash://` rows, reached by the owner or the team folder; marked
- duplicates → a copy in the trash marked; a group whose others are all
  in the trash not listed (D3-A)
- versions in the trash → placed through their trashed file
- emptying, expiry → the row goes, and Nextcloud drops the metadata

### Blocks

1. Measure: delete, restore, empty and expire, on 33 and 34.
2. Keep and restore: checksums through the trash and back.
3. Results: files in the trash under `trash://`.
4. Versions in the trash.
5. Documentation.

## Discussion

- **The ask.**
  The user, 2026-09-30: support the trash.
  Not scheduled then.
- **Picked up, 2026-10-20,** after AP FileVersions;
  moved to the one-file form.
- **Decided, 2026-10-20 (v1.1):**
  - **D1-A:**
    all four of what v1.0 named support: search, duplicates, restore,
    clean-up; and the versions in the trash.
  - **D2-A:**
    a file keeps its checksums while it is in the trash: it is still there,
    still its owner's, and can be restored; they go when the trash does.
  - **D3-A:**
    a copy in the trash is shown in its duplicate group, marked;
    a group whose other members are all in the trash is not listed,
    as a file with only its own versions is not.
- **The path scheme** is `trash://`, decided 2026-10-06:
  a path in a files area begins with `/`, so neither is taken for the other.

## Analysis

### Today, from the code (Nextcloud 33 and 34)

- **The app** leaves the trash out:
  `FilecacheService::UNGOVERNED_PREFIXES` names `files_trashbin/`,
  and `FileLocation` gives its rows no relative path, so no rule governs them.
- **Moving to the trash** fires `NodeDeletedEvent` with the file still in the filecache;
  `FileListener::onDelete()` then clears the app's metadata,
  as "content the user removed keeps none".
- **A home's trash** (`files_trashbin` `Trashbin::move2trash()`):
  `moveFromStorage()` and the updater's `renameFromStorage()`, so the row moves
  to `files_trashbin/files/<name>.d<time>` with its file id.
  The versions move by the same calls to
  `files_trashbin/versions/<name>.v<mtime>.d<time>`,
  ids and checksums with them.
- **A team folder's trash** (groupfolders `TrashBackend::moveToTrash()`):
  `moveFromStorage()` and `moveFromCache()` into the folder's `trash/`,
  the file id kept, except where the storage scanned the target first.
  Its versions stay at `versions/<file id>/`, and go when the trash item does
  (`deleteAllVersionsForFile()`).
- **Restoring** renames from outside `files/`:
  `View::rename()` emits hooks only for a source inside it, so no create or
  write event fires.
  `files_trashbin` dispatches `NodeRestoredEvent` (`AbstractNodesEvent`);
  groupfolders emits a legacy hook, its event to be seen.
- **Emptying and expiry** remove the filecache row;
  Nextcloud's `MetadataDelete` drops the metadata on `CacheEntryRemovedEvent`.

### To measure (Block 1)

1. A home file with checksums and a version with checksums: file ids, paths,
   ETags and the app's metadata after the move to the trash, after a restore,
   after emptying.
2. The same in a team folder, on 34.
3. The events each step dispatches, and what the app's listener does with them.
4. Whether a restore leaves the file's ETag as it was in the trash,
   so that its checksums are still proved.

### Design (preliminary; Block 1 confirms or revises it)

2. **Keep and restore.**
   `onDelete()` leaves a file moved into the trash as it is.
   The listener hears `NodeRestoredEvent`, by class name,
   and the team folder's restore;
   a restored file keeps its checksums where its stamp still vouches for it,
   and is treated as written where not.
3. **Results.**
   `andWhereGoverned()` admits the trash's rows;
   a file in the trash is placed as `trash://` and its path before the deletion,
   with `trash: {deletedAt, …}`, reached by its owner,
   or by the team folder's members as its permissions say.
   The duplicates mark it and drop a group with no live member but one.
4. **Versions in the trash**
   take their trashed file as their file:
   governed by nothing, placed through it.
5. **Documentation:**
   the README, the user guide, the API reference and OpenAPI.

## Implementation Plan

### Block 1: measure

1. A scratch script in `GUIDELINES/temp/`,
   run in the harness container on 33 and on 34: a test account, a file hashed,
   a version handed its checksums, then the trash, a restore, the trash again,
   emptying; the rows, ETags, metadata and events at each step.
   A team folder too, on 34.
2. A NOTE with the measured facts,
   and the design's points they confirm or change.

**Verification:**
the NOTE answers each point of *To measure*.
The run stops at a gate here, before Block 2.

### Block 2: keep and restore

As the design says; tests through `files_trashbin` and groupfolders.

### Block 3: results

As the design says; tests per result and per reach.

### Block 4: versions in the trash

As the design says.

### Block 5: documentation

As the design says.

## Proposed commit messages

- `[UPDATE] APs: +NOTE TrashBin v1.1`
  (Block 1)
- `[TASK] A file keeps its checksums through the trash`
  (Block 2)
- `[TASK] Files in the trash appear in search, listings and duplicates`
  (Block 3)
- `[TASK] Versions in the trash are placed through their file`
  (Block 4)
- `[TASK] The documents describe the trash`
  (Block 5)

## Change History

- v1.0 (2026-09-30): first version.
- v1.1 (2026-10-20): picked up; D1-A, D2-A and D3-A decided;
  the code read; the design and five blocks, the first a measurement;
  moved to the one-file form.
