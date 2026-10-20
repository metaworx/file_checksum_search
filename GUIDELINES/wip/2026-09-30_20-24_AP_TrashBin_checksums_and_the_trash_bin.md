# AP TrashBin v1.2: checksums and the trash bin

## TL;DR

A file keeps its checksums in the trash and comes back with them,
search finds it there, so that it can be restored,
and a rule, or `fcias:hash --trash`, can hash what the trash holds without.
Today the move to the trash clears them,
and a restore brings the file back unhashed while its versions kept theirs.

- move to the trash → checksums kept (D2-A); the id and ETag survive,
  so they stay proved through a restore
- restore → a file without current checksums queued (D4-B, homes)
- identity → `<name>.d<time>` and its path before the deletion,
  from the trash's own tables; governed by the rule of that path
- results → `trash://` rows, reached by the owner or the team folder; marked
- duplicates → a copy in the trash marked; a group with fewer than two
  live files not listed (D3-A)
- versions in the trash → placed through their trashed file
- hashing → a rule's *Trash* switch, and `fcias:hash --trash` (D5-A+B)

### Blocks

1. Measure: delete, restore, empty, on 33 and 34.
   *Done.*
2. Keep and restore: checksums through the trash and back.
3. Trash identity: a trashed file's path before the deletion, and its rule.
4. Results: files in the trash under `trash://`.
5. Versions in the trash.
6. A rule's *Trash* switch: the sweep hashes the trash.
7. `fcias:hash --trash`.
8. Documentation.

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
- **Measured, 2026-10-20:**
  [NOTE v1.1: measured](2026-10-20_18-22_NOTE_TrashBin_v1.1_measured.md).
- **Decided, 2026-10-20 (v1.2):**
  - **D4-B:**
    a restored file without current checksums is queued at once,
    on `NodeRestoredEvent`; a team folder's restore fires none,
    and leaves such a file to the sweep.
  - **D5-A+B:**
    the trash is hashed where a rule says so (*Trash*, beside *Versions*),
    and on request, `fcias:hash --trash`, whatever the rule says of the trash.
    A trashed file is judged by the rule of its path before the deletion.
- **The path scheme** is `trash://`, decided 2026-10-06:
  a path in a files area begins with `/`, so neither is taken for the other.

## Analysis

### Measured (NOTE v1.1)

- **The move to the trash** keeps the file's id and ETag,
  in a home (`files_trashbin/files/<name>.d<time>`) and a team folder
  (`trash/<name>.d<time>`);
  `FileListener::onDelete()` clears the app's metadata, and nothing else does.
- **Versions** keep their ids, ETags and checksums:
  a home's move to `files_trashbin/versions/<name>.v<mtime>.d<time>`,
  a team folder's stay at `versions/<file id>/<mtime>`.
- **A restore** keeps the id and the ETag.
  A home's fires `NodeRestoredEvent` and `NodeTouchedEvent`, no create or write;
  a team folder's fires no node event.
- **Deleting from the trash** removes the rows.
  On 34 a home version's metadata stayed, which the orphan purge collects;
  groupfolders leaves the version's row of a deleted trash item.

### The trash's own records

- **A home:**
  `files_trash` (`id` the name, `user`, `timestamp`,
  `location` the folder it was in, `.` for the top); keyed by user,
  name and timestamp.
- **A team folder:**
  `group_folders_trash` (`name`, `deleted_time`, `folder_id`, `file_id`,
  `original_location` below the folder's root).
- **Inside a trashed folder** a row is
  `files_trashbin/files/<folder>.d<time>/<sub path>`
  or `trash/<folder>.d<time>/<sub path>`: the record is the folder's,
  and the path continues below it.
- **The legacy layout** keeps a team folder's trash at
  `__groupfolders/trash/<id>/` in the root storage.

### The app today

- `FilecacheService::UNGOVERNED_PREFIXES` names `files_trashbin/`,
  and `FileLocation` gives every trash row no relative path:
  no rule governs it, no listing offers it.
- AP FileVersions did the same for versions, and its path is the model:
  `VersionOf` on the location, located in one query per batch,
  governed by its file's rule, placed through its file in every result.

### Design

2. **Keep and restore.**
   `onDelete()` leaves a file moved into the trash as it is:
   only a file deleted for good leaves, and Nextcloud takes its metadata.
   The listener hears `NodeRestoredEvent`, by its class name as for versions;
   a restored file whose checksums are not current is treated as written
   (`RuleService::contentChanged()`), one with current checksums is left.
3. **Trash identity.**
   `FileLocation::$trash` (`TrashOf`): the item's name and deletion time,
   and the sub path below a trashed folder, read from the row;
   in the three layouts.
   `FilecacheService::originsOfTrash()` reads the trash's records for a batch,
   one query per table, and gives each row the location it had:
   the same storage, its internal path below `files/`.
   `RuleService` judges a trashed file at that location.
   The drain leaves a trashed file unless its rule hashes the trash (Block 6);
   until then, never.
4. **Results.**
   `andWhereGoverned()` admits a trashed file's rows,
   `trash://` and its path before the deletion as its path and location,
   with `trash: {deletedAt}`.
   Reached by a home's owner, and by a team folder's members as the folder's
   permissions say for its path before the deletion; not through a share.
   The duplicates mark it, and list a group only where it holds two files
   neither of which is in the trash.
   The unified search names it *in the trash* and opens the trash.
5. **Versions in the trash**
   take their trashed file as their file:
   a home's by name, `files_trashbin/versions/<name>.v<mtime>.d<time>` to
   `files_trashbin/files/<name>.d<time>`;
   a team folder's by id, as before.
   Placed through it, `versions://trash://…`.
6. **The *Trash* switch.**
   An include rule's `trash` (default off) has the sweep page the trash's rows too,
   and queue those it governs that hold no checksum; the queue hashes them.
   The editor, `occ fcias:rules` and the rules API carry it;
   the missing listing counts a trashed file where its rule hashes the trash.
7. **`fcias:hash --trash`.**
   After the files and the versions, an account's trash:
   those without checksums whose rule says *Trash*,
   or all under `--trash` that the run may take;
   `--trash` with `--mark` refused.
8. **Documentation:**
   the README, the user guide, the API reference and OpenAPI.

## Implementation Plan

### Block 1: measure

Done: [NOTE v1.1: measured](2026-10-20_18-22_NOTE_TrashBin_v1.1_measured.md).

### Block 2: keep and restore

1. `FileListener`:
   `onDelete()` clears nothing; `NodeRestoredEvent`, by name.
2. `psalm.xml`:
   the event's class named.
3. `README.md`:
   a section on the trash.
4. Tests: integration through `files_trashbin` and groupfolders:
   the checksums kept through the trash and a restore, still current;
   a restored file without them queued under an include rule.

**Verification:**
unit and integration suites on 33 and 34; ECS, Psalm, `tools/check`;
a mutation check.

### Block 3: trash identity

1. `TrashOf`; `FileLocation` reads it in the three layouts.
2. `FilecacheService::originsOfTrash()`.
3. `RuleService`:
   a trashed file governed by the rule of its path before the deletion;
   `HashCalculationService::processFile()` leaves it.
4. Tests: unit for the layouts;
   integration for a home's and a team folder's item,
   a file inside a trashed folder, and the drain leaving one.

**Verification:**
as Block 2.

### Block 4: results

1. `FilecacheService`, `ReachResolver`, `HashListingService`, `ChecksumApi`,
   `DuplicateService` and `HashIndexService`, `HashSearchProvider`.
2. The duplicates page and the unified search mark a file in the trash.
3. `docs/api-v1.md`, `docs/api-v1-openapi.yaml`: `trash`.
4. Tests: unit per service; integration per result and per reach;
   Vitest for the page.

**Verification:**
as Block 2, with `npm test`, lint and typecheck; `js/` rebuilt.

### Block 5: versions in the trash

1. `FileLocation`:
   a home version in the trash names its file.
2. Placement through the trashed file.
3. Tests: integration for a home's and a team folder's.

**Verification:**
as Block 2.

### Block 6: the *Trash* switch

1. The rule's `trash`: validation, storage, `RulesController`,
   `occ fcias:rules`, the editor (`src/`), translations.
2. The sweep yields trash rows for a rule that has it;
   `processFile()` hashes them; the missing listing counts them.
3. Tests: unit for the option and the sweep; integration for a home's and
   a team folder's item hashed by the queue.

**Verification:**
as Block 4.

### Block 7: `fcias:hash --trash`

1. `HashFiles`:
   `--trash`, refused with `--mark`;
   the pass over an account's trash after its versions.
2. Tests: unit for the options; integration for an item hashed and one left.

**Verification:**
as Block 2.

### Block 8: documentation

1. `README.md`, `docs/user-guide.md`:
   the trash, the switch, `--trash`.

**Verification:**
`tools/check`, `reflow.sh --check`.

## Proposed commit messages

- `[UPDATE] APs: +NOTE TrashBin v1.1`
  (Block 1)
- `[TASK] A file keeps its checksums through the trash`
  (Block 2)
- `[TASK] A file in the trash is governed by the rule of its path before`
  (Block 3)
- `[TASK] Files in the trash appear in search, listings and duplicates`
  (Block 4)
- `[TASK] Versions in the trash are placed through their file`
  (Block 5)
- `[TASK] A rule can hash the trash`
  (Block 6)
- `[TASK] fcias:hash hashes the trash`
  (Block 7)
- `[TASK] The documents describe the trash`
  (Block 8)

## Change History

- v1.0 (2026-09-30): first version.
- v1.1 (2026-10-20): picked up; D1-A, D2-A and D3-A decided;
  the code read; the design and five blocks, the first a measurement;
  moved to the one-file form.
- v1.2 (2026-10-20): measured (NOTE v1.1); D4-B and D5-A+B decided;
  Block 2 reduced; trash identity from the trash's records;
  Blocks 6 and 7, the *Trash* switch and `fcias:hash --trash`;
  Block 1 marked done.
