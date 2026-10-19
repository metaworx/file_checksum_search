# AP TeamFolderAcl v1.0: reach honours team-folder ACLs

## Discussion

- **The gap.** A team folder with advanced permissions can deny an account
  a subfolder. Nextcloud applies that in the account's view of the folder
  (`groupfolders`' `ACLCacheWrapper` and `ACLStorageWrapper`), so whatever
  resolves a file through the account's folder — `getById()` — never sees
  it. This app resolves reach in SQL as a mount, a storage and a root, and
  a denied subfolder is inside the mount. Found by the review of the hash
  listing; settled with the user, 2026-10-04, to plan it on its own.
- **Open questions for the user:**
  1. **Which approach** (Analysis, *Options*). Recommended: C.
  2. **Sudoers.** An account the *instance_view* permission names reads
     every account. Does an ACL that denies *it* a subfolder still hold
     on the cross-account routes? Recommended: no for a sudoer, whose grant
     is the whole instance; yes for a group leader, whose reach is their
     members' and is checked per member.
  3. **Several accounts in reach.** A file is in reach when *any* account
     in it may read it, as a share is. Recommended: the same for ACLs.

## Analysis

- **Where reach is decided in SQL, and so misses ACLs:**

  | Surface | Path |
  |---|---|
  | `GET /file/{id}/hashes`, `/duplicates`, `/recalc` and their twins, `recalcMany` | `ReachResolver::contains()` |
  | `SudoScope::mayReachFile()` | `contains()` |
  | The duplicates listing, own and `/sudo/`, `occ fcias:duplicates` | `FilecacheService::batchLookupFilecachePaths()` with mounts |
  | The hash listing, own and `/sudo/` | `MetadataService::whereListed()` with the views |
  | `openable` on the twins | `batchLookupFilecachePaths()` with the viewer's mounts |

- **Where it does not:** `lookup` scoped to accounts, `findSameHash()`'s
  rows, the unified search — each resolves rows through `getById()`, which
  the ACL wrappers filter.
- **Options:**
  - **A. Every row through `getById()`.** Exact, and one node lookup per
    row: a page of 1000 is 1000 lookups per account in reach.
  - **B. `groupfolders`' forbidden paths in the SQL.**
    `ACLManagerFactory::getACLManager( $user )->getForbiddenPaths( $storage, $prefix )`
    gives the paths a user may not read; excluded per mount, the query
    stays one query. It couples to `groupfolders`' internal classes, which
    are not OCP and change between its majors, and with several accounts
    in reach a path is excluded only when *every* account is denied it,
    which the SQL has to spell out per account.
  - **C. Only team-folder rows through `getById()`.** Rows whose storage is
    a team folder with ACLs on (`group_folders.acl`, read through
    `GroupFolderService`) are checked through the accounts' folders; home
    and share rows stay SQL-only. Exact, and costs lookups only where ACLs
    are in use. Recommended.
- **Paging under C.** Rows dropped after `LIMIT` make a page come back
  short. The keyset stays correct as long as `next` is the last row
  *scanned*, not the last returned; `estimated_total` stays approximate
  and says so already. A short page must not read as the end: `next` is
  null only when the scan itself ran out.
- **Harness.** `groupfolders` 22.0.6 is installed on the NC 34 instance; the
  others have none. Integration tests skip without it.

## Implementation Plan

### Block 1: the check

1. `GroupFolderService`: which storages are team folders with ACLs on.
2. A `TeamFolderAccess` service: given accounts and rows (file id,
   storage), drop the rows in an ACL team folder that no account may open
   through its folder; sudoers per question 2.
3. Unit tests; an integration test on NC 34 with a denied subfolder.

**Verification:** PHPUnit unit and integration on NC 33 and 34, ECS, Psalm.
**CHANGELOG:** Security, with Block 2's surfaces.

### Block 2: the per-file routes

1. `ReachResolver::contains()` callers, through the check, for a file in an
   ACL team folder.
2. Tests: a denied file is 404 on the own routes and 403 on the twins, as
   one outside the mount.

**Verification:** as Block 1.
**CHANGELOG:** the Security bullet names the per-file routes.

### Block 3: the listings

1. The duplicates listing, the hash listing and `openable` through the
   check; the hash listing's `next` from the last row scanned.
2. Tests: a denied file is in no listing; a page short of its limit
   carries `next`.

**Verification:** as Block 1; a listing over a team folder with a denied
subfolder through `curl`.
**CHANGELOG:** the Security bullet names the listings.

### Block 4: documents

1. `docs/api-v1.md`: reach honours team-folder ACLs; a page may be short.
2. The hash listing's class docblock and the API docs lose their caveat.

**Verification:** the OpenAPI file parsing.
**CHANGELOG:** none beyond the Security bullet.

## Proposed commit messages

1. `[SECURITY] Reach checks team-folder ACLs where a file lies in one`
2. `[SECURITY] The per-file routes honour team-folder ACLs`
3. `[SECURITY] The listings honour team-folder ACLs`
4. `[TASK] The API documents say reach honours team-folder ACLs`

## Change History

- v1.0 (2026-10-04): first version.
