# AP PageSelection v1.0: select across the page

## TL;DR

The Duplicates page's selection moves from one group to the page.
A folder filter and a selection pattern choose rows across every group,
and one confirmation deletes them.

- Folder filter beside *Hash*: a group with at least one file under it;
  *Mine* and *Others*; in the address
- Selection mode for the page, on *Mine*: a checkbox on every group's rows;
  a bar with a pattern field, *Select versions*, *Select none*,
  *Delete selected (n)*, *Cancel*; "n selected" on a group's header
- A pattern that would take a group's every copy outside the trash
  leaves that group alone, flagged (D1-A)
- One confirmation for the page: the way, a summary, the rows by group;
  versions → the only, else the most recently changed, copy that stays (D2-A)
- Server: many groups per delete request, many rows per restore request;
  Undo restores in one request
- Previous/Next → *Load more*, the selection spanning all that is loaded (D4-A)

### Blocks

1. [FIX] Paging follows the server's own offset: no group shown twice
2. Folder filter: `path` on both listings, the field, the address
3. *Load more* in place of Previous/Next
4. Batch API: `groups[]` on delete, `rows[]` on restore; Undo in one request
5. Page selection: the state in the listing, the bar, the pattern, the guard
6. Page confirmation: the summary, the versions rule, the outcome
7. Documentation and the e2e spec

## Discussion

- **Origin.**
  On 2026-10-21, after AP DuplicateActions v2.0's selection within a group,
  the user asked for one across the page:
  every duplicate that is a version, or every one under a path,
  through a path filter and a second pattern that pre-selects.
- **Asked:**
  whether a *Load more* exists.
  It does not: the page has Previous and Next, and *Per page* from 1 to 500.
- **Decided on 2026-10-21:**
  - D1-A: a pattern or *Select versions* that would take every copy of a
    group outside the trash leaves that group's selection as it was,
    and flags it; rows chosen by hand are not limited.
  - D2-A: the deleted files' versions go to the copy that stays,
    the most recently changed where several do; the confirmation says which,
    and offers *None* for the page.
  - D3-A: one pattern syntax for the filter and the selection (Analysis,
    *Patterns*).
  - D4-A: *Load more* replaces Previous and Next.

## Analysis

**Paging today:**
- `HashIndexService::listDuplicatesForUser()` reads raw groups from `offset`,
  drops what the caller cannot see or what is no duplicate,
  and stops at `limit` kept groups.
  The page's next offset is `offset + limit`, counted in kept groups.
- Every raw group dropped before the page's last one shifts the next page back by one:
  groups repeat.
  On an instance with several accounts, most raw groups are someone else's.
- The answer's `pagination` echoes `offset` and `limit` only;
  the PHP API (`ChecksumApi::findDuplicates()`) and both routes share it.
- A scan stops after 10,000 raw groups, kept or not:
  a narrow filter may come back short with more behind it.

**Fix:**
the answer says where the next page starts, `pagination.next_offset`,
the raw offset after the last group it holds, or null once nothing is left.
A short page with a `next_offset` is not the end.

**Deleting and restoring today:**
- `POST /api/v1/duplicates/delete` takes one group: `{fileIds, algo, hash,
  forGood, dryRun, mergeInto}`, at most 500 rows.
- `POST /api/v1/duplicates/restore` takes one row.
- Both allow 20 requests a minute per account.
  Undo restores row by row, so it stops partway past 20 rows,
  for a single group too.

**Selection today:**
each `DuplicateGroup` holds its own `selecting` and `selected`;
`DuplicateListing` asks, confirms and reports per group.

**Patterns**
(D3-A), the same in PHP and TypeScript, held to one by a shared table of cases:
- `*` matches within a name, `**` across folders, `?` one character;
- a pattern without `/` matches a name anywhere: a file of that name,
  or a folder of that name and everything in it;
- a pattern with `/` matches from the root: the path, or a folder on it
  and everything in it; a leading `/` is optional;
- a trailing `/` matches folders only;
- case is ignored.
- A version or a trashed row is matched by the path it shows, less its
  `versions://` or `trash://`: a version of `/Backup/a.txt` is under `/Backup`.
- Examples: `Backup` (any folder named so, and its contents),
  `/Photos/2019` (that folder), `*.bak`, `/Archive/**/*.pdf`.

**Where the filter runs:**
in PHP, on the rows as the caller sees them,
beside the existing per-account filter:
a path is known only once the filecache rows are rendered through a view,
which differs per mount.

## Implementation Plan

1. **[FIX] Paging follows the server's offset.**
   - `listDuplicatesForUser()` tracks the raw index of each kept group and
     returns `pagination.next_offset`: after the last kept group,
     where the scan stopped when it hit its bound, null when exhausted.
   - The page: *Next* goes to `next_offset`;
     *Previous* to the offset it came from,
     or the first page where it came from the address.
     `hasMore` is `next_offset !== null`.
   - `docs/api-v1.md` (route 5, its sudo twin,
     the PHP API) and the OpenAPI file.
   - Tests: integration, a raw group dropped between two pages repeats
     nothing and skips nothing; a scan stopped at its bound says where to go on;
     Vitest, *Next* and *Previous*.
2. **Folder filter.**
   - `PathPattern`
     (PHP) and `pathPattern.ts`: D3-A,
     tested on one table of cases in `tests/fixtures/`.
   - `path` on `GET /api/v1/duplicates`, its sudo twin and the PHP API:
     a group stays where at least one of its rows matches.
   - A *Folder* field beside *Hash*, with help; reloads as typed, after a pause;
     written to the address and read back.
   - API docs.
   - Tests: the cases; integration through the route, home and team folder;
     Vitest, the field and the address.
3. ***Load more*.**
   - The listing appends the next page from `next_offset`;
     *Per page* is how many each load adds.
   - `offset` leaves the address; one still in an old link is ignored.
   - Tests: Vitest, appending, the end, a filter change starting over;
     the Duplicates e2e spec.
4. **Batch API.**
   - `POST /api/v1/duplicates/delete` also takes
     `{groups: [{algo, hash, fileIds, mergeInto}], forGood, dryRun}`,
     at most 500 rows in all, and answers `{groups: [{algo, hash, results}]}`.
   - `POST /api/v1/duplicates/restore` also takes `{rows: [{fileId, algo, hash}], as}`,
     at most 500, and answers `{results}`.
   - The single forms stay as they are.
   - Undo, on a group as on the page, restores in one request per 500 rows.
   - API docs.
   - Tests: integration, several groups in one request,
     a bad group refused alone, the limit; HTTP through both routes; Vitest,
     Undo.
5. **Page selection.**
   - Selection state moves from `DuplicateGroup` to the listing:
     one mode for the page, rows by group.
   - A group's menu: *Select* starts the mode and opens the group; in it,
     *Select all*,
     *Invert selection* and *Delete selected* stay the group's own,
     and *Cancel* ends the mode.
   - The selection bar, on *Mine*, in the mode:
     a pattern field and *Select matching*, *Select versions*,
     *Select none*, *Delete selected (n)*, *Cancel*;
     "n rows in m groups"; a group left out under D1-A counted and flagged.
   - A group's header: "n selected"; a flagged group says why.
   - Tests: Vitest, the mode, the pattern, versions, the guard, the counts.
6. **Page confirmation.**
   - *Delete selected (n)* dry-runs the selection through the batch route,
     500 rows a request, and shows: the way, a summary (rows, groups,
     versions kept or going along, groups with no copy left outside the trash),
     and the rows by group, folded.
   - The versions rule (D2-A), with *None* for the page.
   - On confirmation: the same requests, not dry; one report;
     Undo for what went to the trash.
   - Tests: Vitest, the summary, the rule, the requests split at 500,
     a refused row reported.
7. **Documentation and the e2e spec.**
   - README, user guide; the API docs are in their blocks.
   - The Duplicates e2e spec: the folder filter, *Load more*,
     a page selection by pattern and of versions, deleted and undone.

**Verification**, per block: its tests; for Blocks 1, 2 and 4 the PHPUnit
suites on 33 and 34, ECS and Psalm; Vitest, `npm run typecheck`, ESLint,
stylelint, `scripts/l10n.sh check`, `GUIDELINES/shared/tools/check`;
from Block 3 on, the Duplicates e2e spec on 34.

## Proposed commit messages

Written per block: Block 1 `[FIX]`, the rest `[TASK]`,
each with its tests, translations and CHANGELOG bullet,
citing `AP PageSelection v1.0, Block <n>`.

## Change History

- v1.0 (2026-10-21): first revision, D1-A, D2-A, D3-A and D4-A decided.
