# AP TeamFolderAcl v1.1: reach honours team-folder ACLs

## TL;DR

Reach takes a team folder's advanced permissions into account:
a file they deny every account in reach is out of reach,
on the per-file routes and in every listing.

- Rows in a team folder with ACLs → opened through the accounts' own folders,
  as Nextcloud does; all other rows → SQL, as now
- Several accounts in reach → one that may read the file is enough
- Sudoer → not limited; group admin → per member
- Listings → a page may come back short; `next` from the last row read
- The missing-hashes listing → covered too, which v1.0 missed

### Blocks

1. The check, and the per-file routes
2. The listings
3. The API reference

## Discussion

- **The gap.**
  A team folder with advanced permissions can deny an account a subfolder.
  Nextcloud applies that in the account's view of the folder
  (`groupfolders`' `ACLCacheWrapper` and `ACLStorageWrapper`),
  so whatever resolves a file through the account's folder — `getById()` — never sees it.
  This app resolves reach in SQL, as a mount: a storage and a root.
  A denied subfolder is inside the mount.
  Found by the review of the hash listing; settled with the user, 2026-10-04,
  to plan it on its own.
- **Decided, 2026-10-19:**
  1. **Approach:**
     only the rows in a team folder with ACLs on are opened through the
     accounts' folders (*Options*, C).
  2. **Sudoers:**
     a sudoer's reach is the whole instance, and ACLs do not narrow it.
     A group admin's reach is their members', and is checked member by member.
  3. **Several accounts in reach:**
     a file is in reach when any of them may read it, as a share is.
- **Found since v1.0:**
  - `groupfolders` asks for read **and share** when it opens a file for an
    account other than the session's,
    in a web request (`MountProvider::getGroupFolderStorage()`, `$inShare`).
    A group admin is therefore shown a member's file in such a folder
    only where the member may read and share it.
    It errs on the closed side, and the API reference says so.
  - The missing-hashes listing (`listMissing()`, `GET /hashes/missing`),
    added after v1.0, filters by the same mounts and has the same gap.

## Analysis

- **Where reach is decided in SQL, and so misses ACLs:**

  | Surface | Path |
  |---|---|
  | `GET /file/{id}/hashes`, `/duplicates`, `/recalc`, their `/sudo/` twins, `recalcMany` | `ReachResolver::contains()` |
  | `SudoScope::mayReachFile()`, which admits a group admin to the `/sudo/` per-file routes | `contains()` |
  | The duplicates listing, own and `/sudo/`, and `occ fcias:duplicates --user` | `HashIndexService::batchLookupFilecachePaths()` with accounts |
  | `openable` on the `/sudo/` answers | the same, with the viewer |
  | The hash listing, own and `/sudo/`, and `iterateHashes()` | `MetadataService::pageListedFiles()` with the views' areas |
  | The missing-hashes listing, own and `/sudo/` | `FilecacheService::pageUnhashedFiles()` with the views' areas |

- **Where it does not:**
  `lookup` scoped to accounts,
  the duplicates `findSameHash()` lists for a reference file,
  the unified search — each opens rows through `getById()`,
  which the ACL wrappers filter.
  The reference file itself is the gap there.
- **Options:**
  - **A, every row through `getById()`:**
    exact, and one node lookup per row:
    a page of 1000 is 1000 lookups per account in reach.
  - **B, `groupfolders`' forbidden paths in the SQL:**
    one query, and it couples to `groupfolders`' internal classes,
    which change between its majors.
  - **C, only rows in a team folder with ACLs through `getById()`:**
    exact, and costs lookups only where ACLs are in use.
    Decided.
- **Which rows.**
  `group_folders` names a folder's root node (`root_id`,
  since `groupfolders` 20,
  so on every supported Nextcloud) and whether its ACLs are on (`acl`).
  Joined to `filecache`, each root is a storage and a path,
  the shape of a mount.
  A row below one is checked.
  Where the table cannot be read while the app is enabled, every row is checked:
  slower, never wider.
- **How.**
  A row is opened through the folder of each account in reach that holds it,
  by the mount cache, until one can.
  An account whose folder fails to open is one that cannot.
- **Paging.**
  Rows dropped after `LIMIT` make a page come back short.
  The keyset stays correct as long as `next` is the last row *read*,
  not the last returned, which is how both listings already compute it.
  `estimated_total` stays approximate, and may count a file the pages leave out.
- **Harness.**
  `groupfolders` 22.0.6 is installed on the NC 34 instance;
  the others have none.
  The integration tests skip without it.

## Implementation Plan

### Block 1: the check, and the per-file routes

1. `GroupFolderService::aclRoots()`:
   the roots of the team folders with ACLs on, as storage and path;
   none without the app, null where its table cannot be read.
2. `TeamFolderAccess`:
   given accounts and rows (file id, storage, path),
   the rows in such a folder that no account in reach may open through its own folder.
3. `ReachResolver::reaches()`:
   `contains()` for a set of accounts, and the check;
   null accounts reach everything, as now.
   The callers of `contains( mountsFor( … ) )` move to it:
   `getHashesByFileId()`, `findSameHash()`'s reference file, `recalcFile()`,
   `SudoScope::mayReachFile()`.
4. Unit tests for the three; integration tests over HTTP on NC 34,
   with a team folder whose ACLs deny one member a subfolder.

**Verification:**
PHPUnit unit and integration on NC 33 and 34, ECS, Psalm.
**CHANGELOG:** Security, naming the per-file routes.

### Block 2: the listings

1. `ReachResolver::withoutDenied()`:
   rows keyed by file id, without the ones the check drops.
2. Through it: `HashIndexService::batchLookupFilecachePaths()` with accounts
   (the duplicates listings, `occ fcias:duplicates --user`, `openable`),
   and `HashListingService`'s `page()`, `iterate()` and `missingPage()`,
   each after the page is cut, so `next` stays the last row read.
3. The docblocks that say the listing does not apply ACLs lose that.
4. Integration tests: a denied file is in no listing;
   a page whose rows were all denied carries `next`;
   `openable` follows the viewer's ACLs.

**Verification:**
as Block 1.
**CHANGELOG:** the Security bullet names the listings.

### Block 3: the API reference

1. `docs/api-v1.md`, *Method Reference*: reach honours advanced permissions,
   a sudoer's aside, and a group admin's read-and-share.
2. The listings' paging: a page may come back short.
3. The OpenAPI file's descriptions, where they state reach.

**Verification:**
`tools/check`; the OpenAPI file parses.
**CHANGELOG:** the Security bullet gains the reference.

## Proposed commit messages

1. `[SECURITY] The per-file routes honour team-folder advanced permissions`
2. `[SECURITY] The listings honour team-folder advanced permissions`
3. `[TASK] The API reference says reach honours team-folder advanced permissions`

## Change History

- v1.0 (2026-10-04): first version.
- v1.1 (2026-10-19): the three questions decided (C; sudoers not limited,
  group admins per member; any account suffices);
  the missing-hashes listing and `groupfolders`' read-and-share for another account added;
  Blocks 1 and 2 merged, since the check alone changes nothing;
  the file renamed to the one-file form.
