# AP DuplicateActions v1.0: act on duplicates

## Discussion

- **Origin.** Carried over from AP SuggestedFeatures v1.5, which designed
  it on 2026-08-22, at the user's request of 2026-09-30.
- **Not scheduled.** It is re-confirmed with the user before it runs.
- **Decisions already made by the user (2026-08-22):**
  - **Which file to keep is chosen by hand**, one per group; no automatic
    heuristic in the interface.
  - **Delete, not move.** The user checks any subset of the other files for
    removal. At least one file, the kept one, always remains, and a partial
    resolution is valid.
  - **Merging means version history.** Before a duplicate is deleted, its
    earlier versions are imported into the kept file's history.
  - **Deletion goes to the trash bin** (`Node::delete()`), which is already
    reversible; the app has no undo of its own.
  - **The Mine tab only,** in a first version. Acting on other accounts'
    files is a later decision.
  - **Choosing needs more than a path:** each row gets its date and size, and
    a preview.

## Analysis

**Today:**
- The Duplicates page shows groups of identical files: the viewer's own on
  the Mine tab, and everyone's on the Others tab for those allowed to look
  across accounts.
- A file can be verified, and opened through core's `/f/{fileid}`. Empty
  files are hidden by default.
- A row carries no date and no size, and nothing can be done to a duplicate
  but look at it.

**Merging version history** uses the `files_versions` app's public API:
- `OCA\Files_Versions\Versions\IVersionManager`, whose backend may implement
  `IVersionsImporterBackend::importVersionsForFile()` (`@since` 29).
- It is an optional dependency. Where the app is absent, or the storage's
  backend cannot import, the merge is skipped and reported for that file,
  and deletion still goes ahead.

**The preview** goes through Nextcloud's viewer (`OCA.Viewer.open`). That is
public but unversioned: check its signature on Nextcloud 33 to 35 before
relying on it, and fall back to the file link without it.

**Constraints:**
- every new text is translatable, in every language kept here;
- the new route takes a `#[UserRateLimit]`;
- `npm run typecheck` stays at zero errors.

## Implementation Plan

1. **Date and size on duplicate rows.** The filecache lookup that already
   serves the listing adds `mtime` and `size`, carried through the service,
   the API and its documentation. Tests: known values come back unchanged.
2. **`VersionMergeService`.** It wraps `files_versions`: is the app there,
   does the backend support import, list the source's versions, import
   them. Every failure is a typed "skipped" result, never an exception.
   Tests: import succeeds; app absent; backend unsupported; import throws.
3. **Resolving a group.** `resolveGroup(uid, keep, remove[], mergeHistory)`:
   - re-checks on the server that every file still shares the full hash
     and is the caller's own;
   - rejects an empty remove set, or one that holds the kept file;
   - merges (best effort), deletes, and returns the outcome per file.

   Route: `POST /api/v1/duplicates/resolve`, scoped to the caller. Tests:
   delete only; delete and merge; merge skipped but delete done; hash
   mismatch rejected; foreign file rejected; invalid sets rejected.
4. **CLI.** `resolve-duplicates --user --algo --keep=first|newest|oldest
   [--merge-history] [--dry-run]`. A script always removes the whole rest of
   a group; choosing a subset is for the interface only. Tests: each
   strategy, `--dry-run`, merge on and off.
5. **Interface.**
   - Each row shows its date and size, and a preview.
   - Keep is a radio, remove a checkbox per other file. The form refuses
     "nothing to remove", and refuses the kept file.
   - A merge-history switch, off by default.
   - Resolving asks for confirmation, then reports each file's outcome, and
     the group re-renders with what is left.
   - Specs for each of these.
6. **Documentation.** The README, the user guide, `docs/api-v1.md` and its
   OpenAPI file, and the CLI reference.

**Verification**, per block: its tests, Vitest, `npm run typecheck`, ESLint,
the PHPUnit suites, `scripts/l10n.sh check`, and the Duplicates e2e spec.

## Proposed commit messages

Written per block at its gate: `[TASK]`, with tests and translations in the
same commit.

## Change History

- v1.0 (2026-09-30): first version, carried over from AP SuggestedFeatures
  v1.5.
