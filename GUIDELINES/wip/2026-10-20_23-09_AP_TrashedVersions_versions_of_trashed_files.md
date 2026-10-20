# AP TrashedVersions v1.0: versions of trashed files

## TL;DR

A version of a file in the trash is read where its rule hashes both versions
and the trash, as 0.29.0 says and does not do:
a team folder's is dropped unread by the queue, and queued again by every sweep;
a home's is in neither of `fcias:hash`'s passes.

- team folder → the drain opens the version through the trashed file's trash node,
  asked as the account that deleted it
- home → `fcias:hash`'s trash pass pages `files_trashbin/versions` too,
  taking a version where versions and the trash are both asked (D1-A);
  `--mark` likewise
- one `[FIX]`, its bullet under `### Fixed`

### Blocks

1. The fix: the team folder's open, the home's pass, tests, documents.

## Discussion

- **The ask.**
  The user, 2026-10-20: a follow-up for what the 0.29.0 completion gate left uncovered,
  a `[FIX]` only.
- **Decided, 2026-10-20:**
  - **D1-A:**
    a version of a trashed file needs both switches, *Versions* and *Trash*,
    or both options,
    as the sweep, the drain and the missing listing already judge it.

## Analysis

### Measured

A scratch probe on 33 and 34, 2026-10-20:
a file written, overwritten once, its version unhashed, the file deleted;
then an include rule with `versions` and `trash` over its place.

| | Home | Team folder (34) |
|---|---|---|
| The version after the deletion | `files_trashbin/versions/<name>.v<mtime>.d<time>`, placed through its trashed file | stays at `versions/<file id>/<mtime>`, its file at `trash/<name>.d<time>` |
| The sweep | queues it | queues it |
| The drain | reads and hashes it | drops it unread: `getFile()` cannot open it |
| `fcias:hash --versions --trash` | leaves it: the passes page `files_versions` and `files_trashbin/files` | (homes only) |

`IVersionManager::getVersionFile()` opens the team folder's version,
given the trashed file's node from `ITrashManager::getTrashNodeById()`:
the version's own id, its content.

Nextcloud's trash app moves a home's versions when the account deleting the file owns it;
otherwise it copies them into the owner's trash,
under new ids and without their checksums (`Trashbin::retainVersions()`).
Nothing here changes that.

### The code today

- `FilecacheService::versionNode()` opens a version through the versions app,
  given its file's node from the root folder,
  which does not reach a team folder's trash;
  `trashNode()` opens a team folder's trashed file, as its deleter.
- `HashCalculationService::trashInHome()` pages `files_trashbin/files`,
  `versionsInHome()` `files_versions`;
  `hashTaken()` and `markTaken()` hash and queue what either yields.

### Design

- **Team folder:**
  `versionNode()` takes its file's node from `trashNode()` where the root folder has none,
  and asks the versions app as that file's deleter.
- **Home:**
  `trashInHome()` pages `files_trashbin/versions` after `files_trashbin/files`;
  a version is placed through its trashed file to the place that file had,
  and taken where its rule says `versions` and `trash`,
  or where `--versions` and `--trash` say so for whichever it does not.
  `fcias:hash` runs the trash pass with its versions where versions are asked too;
  `--mark` queues them where the rule says both.

## Implementation Plan

### Block 1: the fix

1. `FilecacheService::versionNode()` takes the trashed file's node through `trashNode()`.
2. `HashCalculationService::trashInHome()` takes the trashed versions,
   given whether versions are asked;
   `generateTrashHashes()`, `markTrash()` and `HashFiles` pass that.
3. Tests: unit for the pass and the open;
   `TrashHashingTest` for a home's trashed version hashed by `fcias:hash` and one left,
   and a team folder's hashed by the queue.
4. `README.md` and `docs/api-v1.md` say what a version of a trashed file gets;
   `CHANGELOG.md` takes a bullet under `### Fixed`.

**Verification:**
unit and integration on 33 and 34, ECS, Psalm, a mutant on each verdict,
`tools/check`.

## Proposed commit messages

- `[FIX] A version of a trashed file is hashed where its rule says so`
  (Block 1)

## Change History

- v1.0 (2026-10-20): first version; D1-A decided.
