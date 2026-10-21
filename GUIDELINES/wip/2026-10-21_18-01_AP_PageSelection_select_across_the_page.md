# AP PageSelection v1.1: select across the page

## TL;DR

The Duplicates page's selection moves from one group to the page:
a path filter and a selection bar choose rows across every group,
and one confirmation deletes them.
Groups of the same rows under several algorithms become one.

- *Path* filter beside *Hash*: groups with a matching file;
  *Mine*, *Others*, the address
- Page selection, on *Mine*: boxes on every row; a bar: *By path*, *Versions*,
  *Select duplicates*, *Invert selection*, *Deselect all*, *Cancel*, *Delete*
- *Keep one copy*, on by default: a bulk choice never takes a group's best copy;
  off, every match (D7-A to D12-A)
- One confirmation: a summary, the rows by group;
  versions → the copy that stays (D2-A)
- Server: many groups per delete, many rows per restore
- Previous/Next → *Load more* (D4-A)
- Identical groups → one, a line per algorithm and hash (D13-A)

### Blocks

1. [FIX] Paging follows the server's own offset: no group shown twice — done
2. Path filter: `path` on both listings, the field, the address — done
3. *Load more* in place of Previous/Next — done
4. Batch API: `groups[]` on delete, `rows[]` on restore;
   Undo in one request — done
5. Page selection: the listing's state, the bar, the pattern, the guard — done
6. Page confirmation: the summary, the versions rule, the outcome — done
7. Documentation and the e2e spec — done
8. Identical groups merged on the page, their hashes in one header

## Discussion

- **Origin.**
  On 2026-10-21, after AP DuplicateActions v2.0's selection within a group,
  the user asked for one across the page:
  every duplicate that is a version, or every one under a path,
  through a path filter and a second pattern that pre-selects.
- **Decided on 2026-10-21:**
  - D1-A: choosing by rule leaves a group alone where it would take every copy,
    and flags it; rows chosen by hand are not limited.
    D7-A to D12-A refine it.
  - D2-A: the deleted files' versions go to the copy that stays,
    the most recently changed where several do; the confirmation says which,
    and offers *None* for the page.
  - D3-A: one pattern syntax for the filter and the selection (Analysis,
    *Patterns*).
  - D4-A: *Load more* replaces Previous and Next.
- **Decided after the run, on 2026-10-21 and 2026-10-22:**
  - D7-A: the guard protects a group's best copies, its files outside the trash,
    or, where it has none, its versions outside the trash.
    Beside a file, a version is no copy: it goes with its file, and expires.
  - D8-A: *Keep one copy* in the bar, on as choosing starts;
    off, choosing across the page takes every match,
    a content's last copy included:
    one holding a password must go everywhere.
    The confirmation still says in how many groups no copy stays,
    and the bar's note on groups left alone points to the switch.
    Rejected: *Min. files* at 1 as the switch,
    which mixes a listing filter with a deletion rule;
    and no guard at all, which lets one pattern empty groups unseen.
  - D9-A: the switch governs the page's bulk actions — by path, as versions,
    *Select duplicates*, *Invert selection* — and turning it on,
    which lets go of every group chosen whole.
    A group's own menu and its boxes stay unlimited.
  - D10-A: *Invert selection* for the page, guarded.
  - D11-C: *Select duplicates* while the switch is on, *Select all* while off.
  - D12-A: *Select duplicates* keeps each group's oldest copy,
    by when it last changed, the first path where two are as old;
    none where a copy that may not be deleted stays anyway;
    in a group of versions alone, the oldest version.
  - The bar: *By path* and *Versions*, each with *Select* and *Deselect*,
    the versions set apart, as the path plays no part in them;
    a status line, the rows chosen and the groups left alone;
    the choice's tools at the start of the bottom row,
    *Cancel* and *Delete* together at its end,
    the ends following the writing direction.
    *Select* and *Refresh* one size, at the end of the filter row.
  - D13-A: groups of exactly the same rows under several algorithms become one
    on the page, each algorithm and hash on a line of the header;
    the server and the API stay as they are.

## Analysis

**Paging, before Block 1:**
- `HashIndexService::listDuplicatesForUser()` reads raw groups from `offset`,
  drops what the caller cannot see or what is no duplicate,
  and stops at `limit` kept groups.
  The page's next offset was `offset + limit`, counted in kept groups,
  so groups repeated; and groups of one size came in no fixed order.
- The answer now says where the next page starts, `pagination.next_offset`.

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

**The guard on choosing across the page:**
one rule for every bulk action: a group it would empty of its best copies keeps
the choice it had, its files kept out wherever else they are listed,
and the groups are weighed again until none is emptied.
Two defects, found after the run and fixed:
- a group of versions alone was left alone by every pattern,
  as the guard counted a version as no copy everywhere (D7-A);
- a group sharing some files with another was emptied through the other.

**Identical groups (D13-A):**
- The listing pages by algorithm and hash, so with *All algorithms*,
  a file hashed in SHA-1 and MD5 is listed in two groups, row for row the same.
- On the page, groups whose rows are the same file ids become one,
  where the first of them was listed;
  its pairs follow the algorithm list's order.
- The group's actions and *Verify* use its first pair:
  the server holds every row to that hash,
  and either pair proves the same content.
- The merge runs over everything loaded, so a pair split across two loads
  joins after *Load more*; a load of 50 may then show fewer than 50 groups.
- Groups that differ by a row stay apart.

## Implementation Plan

1. **[FIX] Paging follows the server's offset** — done
2. **Path filter** — done
3. ***Load more*** — done
4. **Batch API** — done
5. **Page selection** — done
6. **Page confirmation** — done
7. **Documentation and the e2e spec** — done
8. **Identical groups merged on the page**
   - `mergeIdentical(groups, algorithms)`:
     groups of the same file ids become the first,
     with `hashes`, every algorithm and hash, in the algorithm list's order.
   - `useDuplicates`:
     the merge after every load, *Load more* and reload;
     reload counts the groups fetched, not those shown;
     an algorithm list that arrives late reorders the pairs.
   - The listing shows the merged groups;
     the page's choice, its guard and its deletion work on them.
   - `DuplicateGroup.vue`:
     each pair on a line of the header, in the algorithm and hash columns;
     a single pair as before.
   - The user guide and the README.
   - Tests: Vitest, the merge, its order, groups that differ kept apart,
     the header's pairs; the Duplicates e2e spec on a fixture hashed twice.

**Verification**, per block: its tests; for Blocks 1, 2 and 4 the PHPUnit
suites on 33 and 34, ECS and Psalm; Vitest, `npm run typecheck`, ESLint,
stylelint, `scripts/l10n.sh check`, `GUIDELINES/shared/tools/check`;
from Block 3 on, the Duplicates e2e spec on 34.

## Proposed commit messages

Written per block: Block 1 `[FIX]`, the rest `[TASK]`,
each with its tests, translations and CHANGELOG bullet,
citing `AP PageSelection v<X.Y>, Block <n>`.

## Change History

- v1.0 (2026-10-21): first revision, D1-A, D2-A, D3-A and D4-A decided.
- v1.1 (2026-10-22): Blocks 1 to 7 done; Block 8 added, D13-A.
  Folds in NOTE PageSelection v1.0: keep one copy (D7-A, D8-A)
  and NOTE PageSelection v1.0: selection bar (D9-A to D12-A, the layout).
