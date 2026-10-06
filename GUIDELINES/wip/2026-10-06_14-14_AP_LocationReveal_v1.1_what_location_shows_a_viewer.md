# AP LocationReveal v1.1: what location shows a viewer

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
- **Found on review, 2026-10-06:** the duplicates listing's `path` is the
  file's filecache path, which for a shared file is the sharer's:
  `files/Clients/Acme/Projects/x/a.txt`. The same folders, in the other
  field.
- **Open questions for the user:**
  1. **Which rule** (Analysis, *Options*). Recommended: B.
  2. **The cross-account routes.** A sudoer reads every account and may
     see every location whole; a group leader reads their members'. Does
     a leader see the members' locations whole, or as each member sees
     them? Recommended: whole for a sudoer, as the reach's own account sees
     it for a leader.
  3. **`owner`.** Naming the sharer is what Nextcloud does in the Files
     app's share indicator; recommended to keep it.
  4. **The duplicates listing's `path`.** Recommended: the viewer's path,
     in the `files/…` form that listing documents, so an own file's reads
     exactly as before.

## Analysis

- **What reaches a viewer, per surface:**

  | Surface | `path` | `location` |
  |---|---|---|
  | `lookup`, and `/sudo/lookup` for named accounts | the viewer's, through its folder | `ChecksumApi::withLocations()`, whole |
  | `GET /duplicates`, `/sudo/duplicates` | the filecache's, `files/…` | `FilecacheService::batchLookupFilecachePaths()`, whole |
  | `GET /file/{id}/duplicates`, its twin, the sidebar | the viewer's, through its folder | `withLocations()`, whole |
  | `GET /hashes`, `/sudo/hashes` | the viewer's, from the views | `HashListingService`, whole |
  | `lookup` for every account, `occ fcias:duplicates` | the filecache's | whole, and stays so |

- **What depends on it.** `src/fileLabel.ts` shows the location for any
  file not the viewer's own, which tells apart several people's copies of
  `Templates/Certificate.odt`; the glyph before it reads only the
  location's prefix (`/`, `groupfolder:`, `storage:`). A cut keeps the
  prefix, so the frontend needs no change of code, only specs.
- **Options:**
  - **A. Keep.** The sharer's folder names stay visible to whoever holds
    a share.
  - **B. Cut at the viewer's mount.** For a file the viewer holds through
    a mount rooted below the area's top — a share, a team folder's
    subfolder, an external storage's — the location starts at that
    mount's root, the elision marked: `/bob/files/…/x/a.txt`,
    `groupfolder:3/…/Payroll/x.pdf`. The owner and the shared folder stay,
    nothing above it does. A file held through more than one mount takes
    the one rooted highest. The views from
    `ReachResolver::filesViewsFor()`, which already give the hash
    listing its `path`, hold each mount's root, so no lookup per file.
    Recommended.
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

1. `FileLocation`: the location from a subtree root on, the elision
   marked.
2. `ReachResolver`: per file, the view rooted highest that holds it, and
   the location cut at that view's root; whole where the view is the
   area's top, and where there are no views.
3. Unit tests: a share, a share of a share's subfolder, a team folder's
   subfolder, two views holding one file, the owner's own file, a file
   shared as itself.

**Verification:** PHPUnit unit on NC 33 and 34, ECS, Psalm.
**CHANGELOG:** Security, with Block 2's surfaces.

### Block 2: every surface

1. The own routes and a leader's: `withLocations()`, the duplicates
   listing (its `location`, and its `path` the viewer's in the `files/…`
   form) and `HashListingService`, from the reach's views. A sudoer's
   routes keep the location whole, per question 2.
2. Tests: per route over HTTP, a recipient of a subfolder share reads no
   folder above it, in `path` or `location`; an own file reads as before;
   the Vitest specs for the label and the glyph of a cut location.

**Verification:** PHPUnit unit and integration on NC 33 and 34, Vitest,
`npm run typecheck`, ESLint, the e2e duplicates spec on NC 34.
**CHANGELOG:** the Security bullet names the routes.

### Block 3: documents

1. `docs/api-v1.md` and the OpenAPI file: what `location` holds for a file
   reached through a share, on the own and the cross-account routes, and
   whose `path` the duplicates listing gives.

**Verification:** the OpenAPI file parsing.
**CHANGELOG:** none beyond the Security bullet.

## Proposed commit messages

1. `[SECURITY] A location can start at the viewer's mount`
2. `[SECURITY] No route shows a viewer the folders above their share`
3. `[TASK] The API documents say what location and path hold for a share`

## Change History

- v1.0 (2026-10-04): first version.
- v1.1 (2026-10-06): the duplicates listing's `path` shows the sharer's
  folders too, and is planned with `location` (question 4); the label's
  glyph needs specs, not code.
