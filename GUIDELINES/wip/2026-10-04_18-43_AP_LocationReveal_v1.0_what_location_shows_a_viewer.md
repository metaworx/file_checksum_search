# AP LocationReveal v1.0: what location shows a viewer

## Discussion

- **The finding.** `location` is a file's own place, `/<owner>/files/…`,
  from its filecache row. A viewer who holds the file through a share is
  shown the sharer's folders *above* the shared folder, which Nextcloud
  never shows them: alice, given `Projects/x` by bob, reads
  `/bob/files/Clients/Acme/Projects/x/a.txt`. Found by the review of the
  hash listing, which returns it for every shared file at once; `lookup`,
  the duplicates listing and the sidebar's duplicates do the same row by
  row. Settled with the user, 2026-10-04: `location` stays for now, and
  this is planned across every surface.
- **Open questions for the user:**
  1. **Which rule** (Analysis, *Options*). Recommended: B.
  2. **The cross-account routes.** A sudoer reads every account and may
     see every location whole; a group leader reads their members'. Does
     a leader see the members' locations whole, or as each member sees
     them? Recommended: whole for a sudoer, as the reach's own account sees
     it for a leader.
  3. **`owner`.** Naming the sharer is what Nextcloud does in the Files
     app's share indicator; recommended to keep it.

## Analysis

- **Where `location` reaches a viewer:**

  | Surface | Built in |
  |---|---|
  | `lookup` and `/sudo/lookup` | `ChecksumApi::withLocations()` |
  | `GET /duplicates`, `/sudo/duplicates`, `occ fcias:duplicates` | `FilecacheService::batchLookupFilecachePaths()` |
  | `GET /file/{id}/duplicates` and its twin, the sidebar | `withLocations()` |
  | `GET /hashes` and `/sudo/hashes` | `HashListingService` |
  | The Duplicates page, the sidebar, the settings pages | `src/fileLabel.ts`: the label is the location for any file not the viewer's own |

- **What depends on it.** `fileLabel()` tells apart several people's copies
  of `Templates/Certificate.odt` by their locations; the glyph before a
  label comes from the location's prefix (`home`, `groupfolder`,
  `storage`). Whatever replaces the part above a share has to keep both.
- **Options:**
  - **A. Keep.** The sharer's folder names stay visible to whoever holds
    a share.
  - **B. Cut at the viewer's mount.** For a file the viewer holds through
    a mount that is not the owner's home — a share, a team folder, an
    external storage mounted for them — the location starts at that
    mount: `/bob/files/…/x/a.txt`, the elision marked, so the owner and
    the shared folder stay and nothing above it does. A file the viewer
    holds through more than one mount takes the shortest cut. The views
    from `ReachResolver::filesViewsFor()` give the mount root per row
    without a lookup. Recommended.
  - **C. Owner and name only** on the own routes; the location whole on
    the cross-account routes. Simplest, and loses the place of a copy in
    the viewer's own shares.
- **Group folders and external storages** are already described by their
  own area (`groupfolder:<id>/…`, `storage:<id>/…`), with nothing of an
  account's above them; B cuts them only where the viewer's mount is a
  subfolder of the area.
- **The command line** is the administrator's, and keeps the location
  whole.

## Implementation Plan

### Block 1: the rule

1. `FileLocation::describeFor( $viewRoot )`: the location from a mount
   root on, the elision marked.
2. A helper that picks, per row, the viewer's mount that holds it, from the
   views; whole for a sudoer per question 2.
3. Unit tests: a share, a share of a share's subfolder, a team folder
   subfolder, two mounts holding one file, the owner's own file.

**Verification:** PHPUnit unit on NC 33 and 34, ECS, Psalm.
**CHANGELOG:** Security, with Block 2's surfaces.

### Block 2: every surface

1. `withLocations()`, `batchLookupFilecachePaths()`'s callers on the own
   routes, `HashListingService`: the cut location for a viewer.
2. `src/fileLabel.ts`: the glyph reads the kind from a cut location too.
3. Tests: per route over HTTP, a recipient reads no folder above the share;
   the Vitest specs for the label and the glyph.

**Verification:** PHPUnit unit and integration on NC 33 and 34, Vitest,
`npm run typecheck`, ESLint, the e2e duplicates spec on NC 34.
**CHANGELOG:** the Security bullet names the routes.

### Block 3: documents

1. `docs/api-v1.md` and the OpenAPI file: what `location` holds for a file
   reached through a share, on the own and the cross-account routes.

**Verification:** the OpenAPI file parsing.
**CHANGELOG:** none beyond the Security bullet.

## Proposed commit messages

1. `[SECURITY] A location can start at the viewer's mount`
2. `[SECURITY] No route shows a viewer the folders above their share`
3. `[TASK] The API documents say what location holds for a share`

## Change History

- v1.0 (2026-10-04): first version.
