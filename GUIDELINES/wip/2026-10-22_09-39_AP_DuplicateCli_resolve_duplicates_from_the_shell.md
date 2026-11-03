# AP DuplicateCli v1.1: resolve duplicates from the shell

## TL;DR

An occ command deletes duplicates as the Duplicates page's *Select duplicates* does:
every copy of a group but the one that stays, through the page's own service,
for one account, several, or every one.

- `file-checksum-search:resolve-duplicates`, alias `fcias:`;
  `--user`, repeatable, or `--all` (D5); `--algo`, `--hash`, `--min-count`
- `--path`, repeatable: the copies that may go (D7);
  `--no-path`, repeatable, keeps copies; the more specific pattern wins (D8)
- Stays: `--keep=oldest|newest|first`, `oldest` by default (D1, D9);
  `--remove-last` keeps none
- Acts; `--dry-run` reports; on a terminal it asks first, unless `-y` (D2)
- Trash by default; `--permanent` for good (D3)
- Versions → the copy that stays; `--no-merge` lets them go (D4)
- Every row the account may delete but the copy that stays (D6)
- The choice rules in PHP, held to the TypeScript by a shared fixture

### Blocks

1. The command: selection, the choice, the report
2. Acting: the question, `--permanent`, the merge, `--remove-last`
3. Documentation

## Discussion

- **Origin.**
  The first plan for acting on duplicates, of 2026-08-22,
  had a command as its fourth block:
  `resolve-duplicates --user --algo --keep=first|newest|oldest
  [--merge-history] [--dry-run]`,
  a script always removing the whole rest of a group,
  and choosing a subset left to the interface.
  The page's actions were built without it,
  and on 2026-10-22 it became a plan of its own.
- **Not scheduled:**
  it is confirmed with the user before it runs.
- **Decided on 2026-10-22:**
  - D1-A: `--keep=oldest|newest|first`, `oldest` the default,
    the oldest by last change, the first path where two are as old,
    as the page's *Select duplicates* keeps it.
  - D2-A: it acts, and `--dry-run` reports,
    as occ commands and the app's own `import` do.
    On a terminal it asks first, unless `--yes` or `-y`,
    as `rules:delete` does; without a terminal the question answers no,
    and nothing goes.
  - D3-A: to the trash; `--permanent` for good.
  - D4-A: the deleted files' versions merged into the copy that stays;
    `--no-merge` lets them go.
  - D5-B: `--user`, repeatable, or `--all`, every account,
    which the page does not offer.
    Each account in turn, through its own reach, its own duplicates:
    copies in two accounts are not compared, as on the *Others* tab.
  - D6-A: what *Select duplicates* takes with *Keep one copy* on,
    every row the account may delete but the copy that stays.
    Where a best copy the account may not delete stays anyway,
    none needs keeping.
  - D7-A: `--path`, repeatable, chooses the copies that may go,
    as the page's *Select by path*; copies outside every one stay.
    Without one, every copy may go.
  - D8-A: `--no-path`, repeatable, keeps the copies it matches.
    Where both match, the pattern naming more folders wins:
    `/Backup/keep` over `/Backup`, either over `*.bak`;
    a tie goes to `--no-path`.
  - D9-A: where the chosen copies are every best copy of a group,
    `--keep` picks which of them stays.
    Without `--path`, that is *Select duplicates*.
  - `--remove-last` keeps no copy, as the page's *Keep one copy* off,
    and is refused beside `--keep`;
    the report and the question say how many groups keep none.
  - Rejected: `--force` for the question (D2-B),
    giving the app two confirmation flags;
    leaving a group alone where the paths take every copy (D9-B),
    the page's guard for *Select by path*.

## Analysis

**Today:**
- `file-checksum-search:find-duplicates` lists groups (`--algo`, `--user`,
  `--min-count`, `--output`, `--limit`); nothing deletes from the shell.
- `DuplicateActionService::delete()` is what the page's confirmation calls,
  per group: it opens each row through the account's own reach,
  re-checks the full hash and the permission,
  reports per row in a dry run with the versions that go along,
  and merges versions into a file that stays;
  a failed merge stops a deletion for good.
  A version and a trashed row go for good, having no trash to go to.
  The command calls it per group, as the batch route does.
- `HashIndexService::listDuplicatesForUser()` pages by `next_offset`,
  with one `PathPattern` filter.
  Several `--path` patterns are listed one by one, their groups joined by key;
  the command walks every page.

**The choice rules** are TypeScript today, in `pageSelection.ts`:
- a group's best copies are its files outside the trash,
  or, where it has none, its versions outside the trash;
- the copy that stays is the oldest best copy by last change,
  the first path where two are as old;
  there is none where a best copy may not be deleted, as it stays anyway.

The command needs them in PHP.
Two copies of one rule drift, so both are held to one table of cases,
`tests/fixtures/duplicate-choice.json`,
as `PathPattern` and `pathPattern.ts` are held to `path-patterns.json`.

**Specificity (D8):**
a pattern with a `/` names the folders before its last part;
one without names none, matching a name anywhere.
`PathPattern` says how many.

**Every account (D5):**
a file in a team folder, or shared, is listed for each account that reaches it.
The accounts are taken in turn, each listing read after the one before acted,
so a copy already gone is not counted twice;
the service refuses a row that changed or went in between.

**Output:**
a command's own text stays English (`/AGENTS.md`, contract §3.7);
a refusal from the service comes in the server's default language.
A table per group, or `--output=json`, as `find-duplicates` offers.
Exit 0 where every row went or would go, 1 where one was refused or the
question was answered no, 2 where the command could not run.

**Constraints:**
- the SQL stays backend-agnostic, through `IQueryBuilder`;
- the command takes no lock the page does not take;
  a row changed between the report and the run is refused by the service's own hash check.

## Implementation Plan

1. **The command: selection, the choice, the report.**
   - `Command\ResolveDuplicates`, registered in `appinfo/info.xml`,
     with its `fcias:` alias; `--user`, `--all`, the filters,
     `--path`, `--no-path`, `--keep`, `--remove-last`, `--dry-run`, `--output`.
   - `DuplicateChoice`, in PHP,
     for the best copies, the copy that stays and the paths;
     the same table of cases read by its PHPUnit test
     and by a Vitest spec of `pageSelection.ts`.
   - `PathPattern`, telling how many folders a pattern names.
   - Each group: the rows that go, then `delete()` as a dry run; the report.
   - Tests: the fixture on both sides; integration on planted groups,
     the report naming what would go and nothing changed.
2. **Acting: the question, `--permanent`, the merge, `--remove-last`.**
   - The question on a terminal, `-y` past it, no without a terminal (D2).
   - The deletion for good with `--permanent` (D3),
     the merge with `--no-merge` (D4), and `--remove-last`.
   - Tests: to the trash, for good, versions merged and not,
     every copy gone, a row changed since the listing refused,
     the question answered no, the exit codes.
3. **Documentation.**
   The README's command table and the admin guide.

**Verification**, per block: its tests; the PHPUnit suites on 33 and 34,
ECS and Psalm; for Block 1 Vitest; `GUIDELINES/shared/tools/check`.

## Proposed commit messages

Written per block: `[TASK]`, each with its tests and CHANGELOG bullet,
citing `AP DuplicateCli v<X.Y>, Block <n>`.

## Change History

- v1.0 (2026-10-22): first revision, D1 to D6 open.
- v1.1 (2026-10-22): D1-A to D9-A decided, D5-B;
  D2 acts, with `--dry-run` and a question;
  `--path` repeatable, `--no-path` and `--remove-last` added;
  D6-A put as the page's rule, which keeps no copy where one stays anyway.
