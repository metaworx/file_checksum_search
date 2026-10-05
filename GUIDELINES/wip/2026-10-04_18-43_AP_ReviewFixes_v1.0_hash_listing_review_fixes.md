# AP ReviewFixes v1.0: hash listing review fixes

## Discussion

- **The review.** Two read-only reviewers went over the commits from
  `[UPDATE] APs: -StatusSplit` to
  `[FIX] A deleted file is given nothing of this app's afterwards`: one the
  code and its comments, one the documents, translations and commit messages.
  No high-severity defect.
- **Settled with the user, 2026-10-04:**
  - the examples use generic names (`alice`, `/Photos/…`), not a client's;
  - the listing honouring team-folder ACLs is planned on its own, as AP
    TeamFolderAcl v1.0;
  - `location` stays as it is, and what it reveals is planned on its own,
    across every surface, as AP LocationReveal v1.0;
  - the commit messages already made are not reworded;
  - this plan is not registered, so no commit names one of its blocks.
- **Not done:** remembering mount roots for the length of a request. A
  shared folder can be moved while a long background job runs, and a stale
  root would give a wrong reach; the query it saves is one small IN per call.
- **Not done:** `SudoScope::resolveSet()` returning the account's own uid.
  `mountsFor()` looks accounts up case-insensitively already; only the
  listing builds paths from the name, and it is fixed there.

## Analysis

- **`algo` case.** The selection lowercases through `getHashKey()`;
  `MetadataService::listedHashes()` compares the name as given, so
  `algo=SHA256` lists the right files with `hashes: {}`.
- **Account case.** `ReachResolver::filesViewsFor()` builds `/<uid>/` from
  the name the client sent; Nextcloud's database backend finds `Alice` for
  `alice`, and no mount point matches, so the listing is empty.
- **`since` and a zero stamp.** The filter is `>= since` on the stamp row;
  a stamp of 0 is reported as `null` but passes `since=0`. Every `since`
  should leave a file without a stamp out, as the docs will say.
- **`since` parsing.** `2025-02-30` rolls over to March; RFC 3339's
  lowercase `t` and `z` are refused.
- **What the stamp is.** Not when the hashes were written: when this app
  last held them current. Computed hashes stamp the time, copies from
  Nextcloud's filecache the file's mtime, imports their own value, and a
  recalculation by hand nothing. `since` is a filter, not a change feed.
- **Order.** The listing sorts each file's algorithms; the per-file route
  keeps the document's order, which the sidebar shows.
- **The page query.** `SELECT DISTINCT` over the file and its stamp: a
  second stamp row would list a file twice, and every hash row is
  de-duplicated per page. `GROUP BY` the file with `MAX()` of the stamp
  says what is meant.
- **Bind count.** `andWhereWithin()` spends three placeholders per mount, so
  a group of thousands nears PostgreSQL's 65 535 and SQLite's 32 766.
  Mounts sharing a root take one `IN` per thousand storages instead.
- **Inline docs.** Two docblocks detached from their methods
  (`countByFileId()`, the integration test's `createTestFile()`); a stale
  comment in `fetchOrphanedFileIds()`; "0 asks for the count alone" holds
  only from the start; a paragraph after `getHashesByFileId()`'s `@param`
  tags; `listHashes()` without `@throws`; "the files a lookup would find"
  overclaims while team-folder ACLs are not applied.
- **Small consistency.** The purge looks up each orphan it was handed as
  gone; the `users[]`/`groups[]` scoping is written out twice;
  `localPaths()` repeats a branch of `batchLookupFilecachePaths()`;
  `UNGOVERNED_PREFIXES` sits between methods.
- **Tests.** The trash test passes without the trash app; the local-path
  test accepts any 403; nothing covers `localPaths()` under encryption, a
  hash with an index row and no document value, an empty reach, or the two
  case fixes.
- **Documents.** `since` and `updated_at` described as written times; the
  count only from the start; an unknown `algo` an empty 200, not 400; an
  out-of-range `limit` answered in Nextcloud's own error format; "`path` is
  the first account …"; half-rewrapped lines; "unix" lower case; the
  CHANGELOG bullets against COMMIT.md §5.3; the Japanese and Dutch wording.

## Implementation Plan

### Block 1: the listing reads algorithms, accounts and stamps as the app does

1. `HashListingService::page()`: `algo` lowercased.
2. `ReachResolver::filesViewsFor()`: the account's own uid from the user
   manager.
3. `MetadataService::whereListed()`: with `since`, a stamp of at least
   `max( since, 1 )`.
4. `PublicApiController::sinceFrom()`: `t`/`z` in either case; a date that
   does not read back as itself is refused.
5. `MetadataService::listedHashes()`: the document's order.
6. `MetadataService::pageListedFiles()`: `GROUP BY` the file, `MAX()` of
   the stamp.
7. `FilecacheService::andWhereWithin()`: mounts grouped by root, one `IN`
   per thousand storages.
8. Tests: each of the above, unit; the two case fixes on a real database.

**Verification:** PHPUnit unit and integration on NC 33 and 34, ECS, Psalm.
**CHANGELOG:** the Added bullet stays accurate; none.

### Block 2: inline docs, one lookup fewer per purge, shared scoping

1. Docblocks: the two detached ones back on their methods; the stale
   comment; the count from the start only; the `getHashesByFileId()`
   paragraph above its tags; `@throws` on `listHashes()`; the stamp as a
   freshness stamp; the class docblock's claim narrowed until ACLs apply.
2. `MetadataService::purgeMetadata()`: told by its caller when the file is
   known gone.
3. `PublicApiController`: one helper for `users[]`/`groups[]` scoping.
4. `FilecacheService`: `batchLookupFilecachePaths()` through `localPaths()`;
   `UNGOVERNED_PREFIXES` under the constants.
5. Tests: the trash test asserts the file is in the trash; the local-path
   test asserts the refusal's text; `localPaths()` under encryption; a hash
   with an index row and no document value; an empty reach.

**Verification:** PHPUnit unit and integration on NC 33 and 34, ECS, Psalm.
**CHANGELOG:** the purge bullet stays accurate; none.

### Block 3: the documents say what `since` and the stamp are

1. `docs/api-v1.md` and the OpenAPI file: the stamp and `since` as above,
   not a change feed; the count from the start; an unknown `algo`; an
   out-of-range `limit`; `path`'s wording; generic examples on
   `nc.example.com`; "Unix"; the method's `**Throws:**` and its clamping;
   the two half-rewrapped lines.
2. CHANGELOG: the Changed bullet says what a caller changes, with its
   reference; the purge, listener and Security bullets per §5.3.
3. Translations: Japanese `ISO 8601形式` and `Unix秒`; Dutch
   `ISO 8601-notatie`; `scripts/l10n.sh build`.

**Verification:** the OpenAPI file parsing, `changelog.sh check`,
`scripts/l10n.sh check`.
**CHANGELOG:** the bullets themselves.

## Proposed commit messages

1. `[FIX] The hash listing reads algorithms, accounts and stamps as the app does`
2. `[TASK] Listing docblocks corrected, one lookup fewer per purge, scoping shared`
3. `[TASK] The hash listing's documents say what since and the stamp are`

Each in full at its gate, or in the report where the user waived it.

## Change History

- v1.0 (2026-10-04): first version.
