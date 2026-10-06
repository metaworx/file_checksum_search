# AP LocationReveal v1.2: what location shows a viewer

## Discussion

- **The finding.** `location` is a file's own place, `/<owner>/files/…`,
  from its filecache row. A viewer who holds the file through a share is
  shown the sharer's folders *above* the shared folder, which Nextcloud
  never shows them: alice, given `Projects/x` by bob, reads
  `/bob/files/Clients/Acme/Projects/x/a.txt`. `lookup`, both duplicates
  listings, the sidebar's duplicates and the hash listing all do. The
  duplicates listing's `path` shows the same folders: it is the
  filecache path, `files/Clients/Acme/Projects/x/a.txt`.
- **Settled with the user, 2026-10-06:**
  - **One address syntax**, `<area>//<path>`, for every place a file can
    be, the same on the rules' side: `home:bob//Clients/x.pdf`,
    `groupfolder:3//Payroll/x.pdf`, `storage:<raw id>//docs/x.pdf`, and
    for a viewer who holds a file through a share, `share:42//a.txt`.
    Bigger than a cut, and bought for symmetry.
  - **A share is addressed by its id**, from its own root: nothing of the
    sharer's above it, not even the shared folder's name. A file shared
    on its own is `share:43//`. An administrator resolves the id; the
    recipient knows it already.
  - **Storage ids lose their trailing slashes** in an address, so a local
    or SMB storage does not read `///` or `////`.
  - **`path` is the viewer's own**, with a leading slash, everywhere; the
    duplicates listing drops its `files/…` form.
  - **The label** a page shows for a file not the viewer's own is its
    owner and the viewer's path: `bob: My Projects/Bob-x/a.txt`.
  - **`owner` stays.**
  - **A sudoer** sees the canonical address; **a group leader** sees what
    the member sees.
  - **The rules migrate** to the same syntax (the user, mid-execution).
  - **Later:** trash and versions, once supported, get `trash://` and
    `versions://` paths.
- **Open questions for the user:**
  1. **The rules' shape.** Recommended: `selector` and `path` stay two
     fields in storage, the API and the forms, in their canonical
     spellings; the combined `<selector>//<glob>` is how a rule's scope is
     shown — in both rules lists and `occ fcias:rules:list` — and what
     `occ` takes as `--scope`. The alternative is one `scope` field
     everywhere, which every client and the forms would have to split
     again: the selector decides a rule's band and the forms pick it from
     a list.
  2. **External storages.** Their files may be governed by no rule at
     all (Analysis). Recommended: verify on the harness, and if so fix it
     here, first, since the address of a storage file and a `storage:`
     rule's glob both rest on it.

## Analysis

- **The syntax.** `<area>//<path>`: `<area>` names one place, `<path>` is
  below its top, without a leading slash, empty for the top itself.
  A path never holds `//` — Nextcloud keeps no empty segment and no name
  holds `/` — so the **last** `//` separates, whatever the area's id
  holds: an SMB id carries `//` of its own
  (`smb::<user>@<host>//<share>/<root>/`, Nextcloud's spelling).
  - `home:<uid>`, `groupfolder:<id>`, `storage:<raw id>` are rule
    selectors already; `share:<id>` is the viewer's side and no selector.
  - A raw storage id longer than 64 characters is its MD5, as
    `oc_storages` holds it.
  - Trailing slashes trimmed: two storages whose ids differ only there
    would collide; Nextcloud's own storages normalise their ids, so none
    is known. A `storage:` selector matches with both sides trimmed.
  - A row outside a files area (the trash, the versions) is in no
    listing; in a log line it reads `<area>//../<its internal path>`,
    `..` being nothing a path holds.
- **Why ids, not names.** Each kind names its place by the identity its
  owner keys on: the account's uid, the Team Folders app's folder id, the
  share's id, Nextcloud's own id for the storage. None is a name someone
  renames; a raw storage id changes only when the storage does — another
  host, share or root — and Nextcloud then sees a new storage itself.
- **What reaches a viewer, per surface:**

  | Surface | `path` | `location` |
  |---|---|---|
  | `lookup`, `/sudo/lookup` for named accounts | the viewer's | `ChecksumApi::withLocations()` |
  | `GET /duplicates`, `/sudo/duplicates` | the filecache's, `files/…` | `FilecacheService::batchLookupFilecachePaths()` |
  | `GET /file/{id}/duplicates`, its twin, the sidebar | the viewer's | `withLocations()` |
  | `GET /hashes`, `/sudo/hashes` | the viewer's, from the views | `HashListingService` |
  | `lookup` for every account, `occ fcias:duplicates` | the filecache's | canonical |

- **The share's id.** `ReachResolver::filesViewsFor()` reads the viewer's
  mounts; those the sharing app provides are shares, rooted at the shared
  node. One query over `oc_share` for those roots, per request, gives the
  ids; where Nextcloud merged a user and a group share into one mount,
  the id is the one it mounts by. No lookup per file.
- **The label.** `src/fileLabel.ts` shows the location for a file not
  the viewer's own, and its glyph reads the location's kind: both change,
  the glyph gaining `share`. The label's form is a translated text.
- **External storages.** An external storage's filecache paths start at
  its root (`2024/scan.pdf`); only homes and team folders have a `files/`
  area. `FileLocation::fromRow()` gives such a row no relative path, and
  `RuleService` skips a row without one, so no rule would govern a file
  on an SMB or local external storage, and a `storage:` rule matches
  nothing. The unit test that says otherwise builds an SMB row as
  `files/report.xlsx`, which no external storage holds. Unverified on a
  real mount.
- **The rules.** Rules hold `selector` and `path`; `path` is a glob read
  with or without a leading slash (`PathUtil::matchesRelativeGlob()`).
  The `selector-model` repair step already resaves every rule in its
  canonical spelling, and carries the migration.
- **The command line** stays the administrator's: canonical addresses.

## Implementation Plan

### Block 1: external storages are governed

Only if the harness confirms the finding (question 2); dropped otherwise.

1. On the harness, a local external storage with a file under a `storage:`
   rule: governed or not.
2. `FileLocation`: an external storage's relative path from its root;
   the instance's root storage keeps none.
3. Tests: unit for the row; integration for a rule governing a file on a
   local external storage.

**Verification:** PHPUnit unit and integration on NC 33 and 34, ECS,
Psalm.
**CHANGELOG:** Fixed.

### Block 2: the address

1. `FileLocation`: the address, `<area>//<path>`, for every namespace,
   trailing slashes trimmed from storage ids; `..` for a row outside a
   files area.
2. Everything that shows it moves with it: `location` on every route,
   `occ fcias:duplicates`, the import's messages, `rules:apply`'s lines.
3. Tests: unit for every namespace, an SMB and a long id, the root of an
   area; the API's `location` in the integration tests.

**Verification:** as Block 1.
**CHANGELOG:** Changed: `location`'s new form, and what a caller reading
it must change.

### Block 3: the viewer's view

1. `ReachResolver`: per file, the view that holds it and, for a share,
   the share's id; the share address from it.
2. The own routes and a leader's: `location` the share address where the
   viewer holds the file through a share; `path` the viewer's, the
   duplicates listing's included, without `files/`. A sudoer's routes
   keep the canonical address.
3. Tests: per route over HTTP, a recipient of a subfolder share reads no
   folder of the sharer's, in `path` or `location`; a merged user and
   group share; an own file reads as before; the leader's routes.

**Verification:** as Block 1.
**CHANGELOG:** Security: the routes; Changed: the duplicates listing's
`path`.

### Block 4: the label

1. `src/fileLabel.ts`: the owner and the viewer's path for a file not the
   viewer's own; the kind read from the address, `share` added;
   `LocationIcon` gains its glyph. The label's text translated into the
   eleven languages kept here.
2. Vitest specs for the label and the glyph.

**Verification:** Vitest, `npm run typecheck`, ESLint, stylelint,
`scripts/l10n.sh check`, the e2e duplicates spec on NC 34.
**CHANGELOG:** amends Block 3's bullet if it says it; else Changed.

### Block 5: the rules

1. `Selector`: a storage id without trailing slashes, matching with both
   sides trimmed; a rule's glob canonical without a leading slash.
2. The `selector-model` repair step migrates stored rules to both.
3. The combined `<selector>//<glob>` per question 1: the two rules lists,
   `occ fcias:rules:list`, and `occ fcias:rules:add` and `:modify`.
4. Tests: unit for the spellings and the migration; integration for a
   migrated rule still governing its files; Vitest for the lists.

**Verification:** PHPUnit unit and integration on NC 33 and 34, Vitest,
typecheck, ESLint, ECS, Psalm.
**CHANGELOG:** Changed: the spellings and the combined form.

### Block 6: documents

1. `docs/api-v1.md` and the OpenAPI file: the address syntax, `location`
   and `path` per route, the share address; `README.md`'s selector table
   and `docs/FAQ.md`.

**Verification:** the OpenAPI file parsing.
**CHANGELOG:** none beyond the blocks'.

## Proposed commit messages

1. `[FIX] External storages are governed by their rules`
2. `[TASK] A location is an address: the area, two slashes, the path`
3. `[SECURITY] No route shows a viewer the folders above their share`
4. `[TASK] A file not the viewer's own is labelled by its owner and the viewer's path`
5. `[TASK] Rules speak the address syntax`
6. `[TASK] The documents describe the address syntax`

## Change History

- v1.0 (2026-10-04): first version.
- v1.1 (2026-10-06): the duplicates listing's `path` shows the sharer's
  folders too, and is planned with `location`; the label's glyph needs
  specs, not code.
- v1.2 (2026-10-06): one address syntax, `<area>//<path>`, for every
  location and the rules, a share addressed by its id, storage ids
  without trailing slashes; `path` the viewer's everywhere; the label the
  owner and the viewer's path; the rules' migration; external storages
  possibly governed by no rule, verified and fixed first.
