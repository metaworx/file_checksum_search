# AP DuplicateActions v2.0: act on duplicates

## TL;DR

The Duplicates page acts on what it lists, on the *Mine* tab:
one row at a time or a whole group, deleting and restoring from the trash.

- Rows: + date, size; one preview per group; the actions the viewer may take
- File → trash or for good; a version, a trashed row → for good;
  the warning lists the versions that go with a file
- Trashed file → its place: parents created, renamed when taken
- Trashed version → into the file now at its path, or a new file beside it;
  any version → a new file
- Group: a checkbox per row, *Invert*, *All*;
  the deleted files' versions → one remaining file, or none
- Server re-checks each row: full hash, reach, permission; outcome per row
- Undo toast after a move to the trash

### Blocks

1. Rows: date, size, one preview per group
2. Delete route: files, versions, trashed rows; a dry run lists what goes along
3. Version merge into a remaining file, on the delete route
4. Restore route: trashed files and versions, versions as new files
5. Interface: a row's actions, the confirmation, the undo toast
6. Interface: a group's selection and the versions' target
7. Documentation

## Discussion

- **Origin.**
  v1.0, carried over from AP SuggestedFeatures v1.5 on 2026-09-30,
  resolved a group in one step.
  On 2026-10-21 the user widened it to single rows, deletion for good
  and restoring from the trash.
- **Kept from 2026-08-22:**
  - which file stays is chosen by hand, with no heuristic;
  - merging means version history;
  - the *Mine* tab only;
  - choosing needs date, size and a preview.
- **Changed on 2026-10-21:**
  - single rows can be deleted, a version or a trashed row too;
  - deletion for good is offered beside the trash;
  - a trashed file is restored, and a trashed version into a file or as one;
  - a group may lose every file (D1-A): the confirmation says that no
    copy stays outside the trash;
  - one preview per group: files sharing a full hash share their content,
    and so their preview.
- **Decided on 2026-10-21:**
  - D1-A: *All* is allowed.
  - D2-A: "its existing file" is the file now at the trashed file's original path;
    the action is offered only where one is there.
  - D3: one thumbnail per group, from core's preview routes.
  - D4-A: v1.0's command (`resolve-duplicates --keep=…`) is not in this plan.
    A plan of its own takes it, registered before this one retires.
  - D5-A: an undo toast; *Save as new file* for any version; no delete
    on a received share's root; the warning marks named versions.

## Analysis

**Today:**
- A group lists files, versions (`versions://`) and trashed rows (`trash://`),
  each by its own file id, with `version` or `trash` on it.
  A version of a trashed file carries both.
- The *Mine* listing holds what the session's account reaches (`ReachResolver`):
  its home, received shares, team folders.
- Nothing can be done to a row but open it and verify it.

**Core's APIs, the same on 33 to 35:**
- **Trash:**
  `ITrashManager` lists the trash (`listTrashRoot()`, `listTrashFolder()`),
  restores, removes,
  pauses (`pauseTrash()`/`resumeTrash()`) and opens a node by id.
  An `ITrashItem` knows its original location.
- **Home restore:**
  a missing parent sends the file to the root,
  and so does every item inside a trashed folder;
  a name taken gets ` (restored)`.
- **Team-folder restore** (team folders 22.0.6):
  a missing parent sends it to the folder's root;
  a name taken inside a subfolder builds a wrong path,
  `a/b/file.txt` → `a/bfile (1).txt`.
- **So a restore** goes through the trash's own API, then finds the
  file by its id and moves it to its place: parents created, the name
  made unique with `Folder::getNonExistingName()`.
- **Versions:**
  `IVersionManager` lists, opens and deletes (`IDeletableVersionBackend`).
  Importing is `IVersionsImporterBackend::importVersionsForFile()`,
  which both the home and the team-folder backend implement.
  It **copies** versions; a version of the same second as one of the
  target's is refused by the home backend's unique key and silently
  dropped by the team folders', so the app skips and reports it.
  The source's current version is never imported: its content is the target's.
- **A trashed version** in a home has no backend that lists it.
  A small adapter of the app's (`IVersion` with its backend) hands its
  file to the importer; both interfaces are unchanged on 33 to 35.
- **For good:**
  a file is deleted with the trash paused, in a `finally`;
  a trashed file is removed through its `ITrashItem`;
  a trashed version's own file is deleted.
- **Whose:**
  every row is opened through the session's own reach:
  the account's folder for a file and a version's file,
  its trash for a trashed one, and `isDeletable()` / `isUpdateable()` decide.
  A received share's root offers no delete: Files would only leave the share,
  and nothing is freed.

**The index** follows without help: a trashed file keeps its id,
so its row moves to `trash://`;
a deleted file's metadata goes with its filecache row;
a restore is `FileListener`'s `NodeRestoredEvent`.

**Per-row actions cost a node per row,** so the listing computes them
only on request (`actions=1`), and only for one's own files.

**Constraints:**
- every new text is translatable, in every language kept here;
- each new route takes a `#[UserRateLimit]`, and, being a POST,
  no `#[NoCSRFRequired]`;
- `npm run typecheck` stays at zero errors; queries through `IQueryBuilder`.

## Implementation Plan

1. **Rows: date, size, one preview per group.**
   - The filecache lookup adds `mtime` and `size`; the listing (own and
     across accounts) passes them on each row.
   - A row shows its date and size; a group's header shows one thumbnail:
     core's preview of its first file the viewer can open,
     else the trash's of its first trashed one, else none.
     A preview that fails to load is hidden.
   - Tests: values come back unchanged (integration);
     the row and the thumbnail render, and the fallbacks hold (Vitest).
2. **Delete route.**
   - `DuplicateActionService::delete()`, per row: kind (file, version,
     trashed file, trashed version),
     the same full hash under the group's algorithm, reach, permission;
     then to the trash, or for good.
   - A dry run acts on nothing and returns, per row, what would happen
     and the versions that go with a file, named ones marked.
   - `POST /api/v1/duplicates/delete`
     `{fileIds, algo, hash, forGood, dryRun}`, the caller's own files.
   - The own listing with `actions=1` gives each row `actions`:
     `trash`, `delete`.
   - Tests, integration, home and team folder:
     each kind to the trash and for good; the dry run; refused: hash changed,
     a row out of reach, read-only, a share's root,
     the trash app off for `trash`.
3. **Version merge.**
   - `mergeInto` on the delete route: before each file is deleted, its
     earlier versions are imported into that file, which must stay,
     be in the group and be updateable.
   - Same-second versions are skipped and counted; a backend that cannot import,
     or an import that fails, leaves a file deleted for good **undeleted**,
     and one going to the trash goes, its versions with it.
   - The dry run says what would be imported and skipped.
   - Tests: home → home, home → team folder, team folder → home; the skips;
     the refusals.
4. **Restore route.**
   - `POST /api/v1/duplicates/restore` `{fileId, algo, hash, as}`:
     - a trashed file, `as: original`: restored, then moved to its place;
     - a trashed version, `as: version`: imported into the file at the
       trashed file's original path;
     - any version, `as: file`:
       its content in a new file beside its file's path (for a trashed one,
       the original path, parents created),
       named `<name> (<Y-m-d H-i-s>).<ext>`, made unique.
   - The listing's `actions` gain `restore`, `restoreAsVersion`,
     `saveAsFile`.
   - Tests: each, home and team folder, with the parent gone and the name taken.
5. **Interface: a row's actions** (the *Mine* tab).
   - A menu per row with the actions it carries.
   - The confirmation runs the dry run and lists what goes along;
     *Delete permanently* says it cannot be undone.
   - After a move to the trash, a toast with *Undo*, which restores it.
   - The listing reloads, and reports each row's outcome.
   - Specs for each.
6. **Interface: a group.**
   - *Select*:
     a checkbox per row, *Invert*, *All*, *Delete selected…*.
   - The confirmation adds the versions' target:
     preselected where one file stays, chosen where several do,
     *None* always offered; and, where no file stays outside the trash, says so.
   - Outcome per row, then the listing reloads.
   - Specs, and the Duplicates e2e spec: a group resolved, a row restored.
7. **Documentation.**
   The README, the user guide, `docs/api-v1.md` and its OpenAPI file.

**Verification**, per block: its tests, the PHPUnit suites on 33 and 34, Vitest,
`npm run typecheck`, ESLint, ECS, Psalm, `scripts/l10n.sh check`,
`GUIDELINES/shared/tools/check`; from Block 5 on, the Duplicates e2e spec.

## Proposed commit messages

Written per block: `[TASK]`, with its tests, translations, bundle and
CHANGELOG bullet in the same commit.

## Change History

- v2.0 (2026-10-21): replaces v1.0, which resolved a group in one step.
  Carries over its decisions of 2026-08-22, the date, size and preview,
  and the version merge; adds actions on single rows, deletion for
  good and restoring from the trash.
  v1.0's command-line block is left for a plan of its own.
