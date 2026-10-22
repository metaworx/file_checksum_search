# AP DuplicateCli v1.0: resolve duplicates from the shell

## TL;DR

An occ command deletes an account's duplicates as the Duplicates page's
*Select duplicates* does: every copy of a group but the one that stays,
through the page's own service, reporting first and acting when asked.

- `file-checksum-search:resolve-duplicates`, alias `fcias:`;
  `--user`, `--algo`, `--hash`, `--path`, `--min-count`
- Stays: the oldest copy, by last change (D1)
- Report by default; `--force` acts (D2)
- Trash by default; `--permanent` for good (D3)
- Versions → the copy that stays; `--no-merge` lets them go (D4)
- One account at a time, its own reach (D5); the page's rows (D6)
- The choice rules in PHP, held to the TypeScript by a shared fixture

### Blocks

1. The command: selection, the choice, the report
2. Acting: `--force`, `--permanent`, the merge
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
- **Open:**
  - **D1, which copy stays:**
    - A (recommended): `--keep=oldest|newest|first`, `oldest` the default,
      the oldest by last change, the first path where two are as old,
      as the page's *Select duplicates* keeps it
    - B: `oldest` only, one rule and nothing to choose
  - **D2, what a bare run does:**
    - A (recommended): it reports what would go, and `--force` acts,
      as `scripts/fcias update` does
    - B: it acts, and `--dry-run` reports, as the first plan had it
  - **D3, where the deleted files go:**
    - A (recommended): to the trash, `--permanent` for good,
      the page's default way
    - B: for good only
  - **D4, the deleted files' versions:**
    - A (recommended): merged into the copy that stays,
      `--no-merge` letting them go, the page's default
    - B: let go unless `--merge-history`, as the first plan had it
  - **D5, whose files:**
    - A (recommended): `--user`, repeatable, each account's own reach,
      as the page's *Mine* tab
    - B: also `--all`, every account, which the page does not offer
  - **D6, which rows go:**
    - A (recommended): what *Select duplicates* takes with *Keep one copy* on,
      every row the account may delete but the copy that stays;
      a group whose best copy may not be deleted left alone, and said so
    - B: files outside the trash only,
      versions and trashed rows staying until they expire

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
  The command calls it per group, as the batch route does.
- `HashIndexService::listDuplicatesForUser()` pages by `next_offset`,
  with a `PathPattern` filter; the command walks every page.

**The choice rules** are TypeScript today, in `pageSelection.ts`:
- a group's best copies are its files outside the trash,
  or, where it has none, its versions outside the trash;
- the copy that stays is the oldest best copy by last change,
  the first path where two are as old;
  there is none where a best copy may not be deleted.

The command needs them in PHP.
Two copies of one rule drift, so both are held to one table of cases,
`tests/fixtures/duplicate-choice.json`,
as `PathPattern` and `pathPattern.ts` are held to `path-patterns.json`.

**Output:**
a command's own text stays English (`/AGENTS.md`, contract §3.7);
a refusal from the service comes in the server's default language.
A table per group, or `--output=json`, as `find-duplicates` offers.
Exit 0 where every row went or would go, 1 where one was refused,
2 where the command could not run.

**Constraints:**
- the SQL stays backend-agnostic, through `IQueryBuilder`;
- the command takes no lock the page does not take;
  a row changed between the report and `--force` is refused by the service's
  own hash check.

## Implementation Plan

1. **The command: selection, the choice, the report.**
   - `Command\ResolveDuplicates`, registered in `appinfo/info.xml`,
     with its `fcias:` alias; the options of D1, D5 and the filters.
   - `DuplicateChoice`, in PHP,
     for the best copies and the copy that stays;
     the same table of cases read by its PHPUnit test
     and by a Vitest spec of `pageSelection.ts`.
   - Each group: the rows that go, then `delete()` as a dry run; the report.
   - Tests: the fixture on both sides; integration on a planted group,
     the report naming what would go and nothing changed.
2. **Acting.**
   - The options that act: `--force` (D2), `--permanent` (D3),
     and the merge, with `--no-merge` (D4).
   - Tests: to the trash, for good, versions merged and not,
     a row changed since the report refused, the exit codes.
3. **Documentation.**
   The README's command table and the admin guide.

**Verification**, per block: its tests; the PHPUnit suites on 33 and 34,
ECS and Psalm; for Block 1 Vitest; `GUIDELINES/shared/tools/check`.

## Proposed commit messages

Written per block: `[TASK]`, each with its tests and CHANGELOG bullet,
citing `AP DuplicateCli v<X.Y>, Block <n>`.

## Change History

- v1.0 (2026-10-22): first revision, D1 to D6 open.
