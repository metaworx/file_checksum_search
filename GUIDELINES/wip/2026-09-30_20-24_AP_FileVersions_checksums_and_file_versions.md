# AP FileVersions v1.1: checksums and file versions

## TL;DR

A file's older versions get checksums, and appear in search, listings,
duplicates and the sidebar, each marked as an earlier version of its file.
Most cost no read: the checksums a file holds when it is overwritten pass to
the version Nextcloud keeps of it.

- version → its own filecache row, governed by its file's rule
- overwrite → the file's current checksums handed to the new version; no read
- older versions → hashed where a rule says `versions`; off by default
- results → path `versions://` + the file's path,
  `version: {fileid, timestamp}`; reach = the file's
- duplicates → versions in groups, marked;
  one file and its own versions → no group
- sidebar → the file's versions with their checksums; an outdated checksum →
  the version holding it

Measured on a real instance: versions ≈ a fifth of the files by count,
a ninth by size; ≈ 10,000 new a month;
three in four files hold current checksums.

### Blocks

1. Version identity: version rows recognised, governed by their file's rule.
2. Capture: an overwritten file's checksums handed to its new version.
3. Older versions: the rule option `versions`, swept and hashed.
4. Results: lookup, unified search, listings and duplicates show versions.
5. Sidebar and verification: the file's versions in the Checksums tab.
6. Documentation: API reference, OpenAPI, README.

## Discussion

- **The ask.**
  The user, 2026-09-30: support file versions.
- **Decided, 2026-10-20:**
  - **D1-C:**
    search, sidebar, verification and duplicates;
    a version takes part in duplicate groups, marked as one.
  - **D2-A:**
    versions get the checksums their file held when it was overwritten;
    older versions are hashed only where a rule opts in.
  - **D3-B:**
    measured read-only on a real instance (Analysis).
  - **D5-A:**
    its numbers stay out of this plan, which the public mirror carries;
    the plan states their shape.
  - **D4-A:**
    the plan is one file, revised in place.
- **Why versions are not kept out of listings and duplicate groups** (the user's
  question). v1.0's gate kept them out because a file saved unchanged makes a
  version identical to itself, and the two would read as a duplicate.
  That calls for a narrower rule, not for keeping every version out:
  a group made only of one file and its own versions is no duplicate.
  Everywhere else a version is a result like a file, marked: its path is
  `versions://` and the path of its file, as decided 2026-10-06, so that it
  can never be mistaken for a path in a files area, which starts with `/`.
- **The trash.**
  A deleted file's versions move to `files_trashbin/versions/` with their file ids,
  and so with their checksums.
  The trash stays governed by nothing and out of every listing (AP TrashBin);
  a restored file's versions come back with their checksums.
- **Out of scope:**
  merging a removed duplicate's versions into the kept file (AP DuplicateActions);
  version labels and authors; the Versions tab, which offers no place to extend.

## Analysis

### Measured

A real instance, 2026-10-20: a read-only count of its filecache by area.
Its numbers stay out of this plan (D5-A); their shape:

- **Count and size:**
  versions are about a fifth of the files by count, and a ninth by size.
- **Per file:**
  a versioned file has one or two versions on average; a few dozen at most.
- **Per account:**
  a few accounts hold nearly all of them.
- **Rate:**
  about 10,000 versions written a month: what capture hands on without a read.
- **Coverage:**
  three in four files hold a current stamp,
  so most overwrites find checksums to hand on.
- **Team folders:**
  none of their versions there.
- **Cost of hashing every version (D2-B, not chosen):**
  one read of about a ninth of the files' size,
  and an index row per version and algorithm.

### Nextcloud 33 to 35

files_versions 1.26.0, 1.27.0 and 2.0.0-dev; groupfolders 22.0.6 on instance 34.
The interfaces are the same in all three.

- **A home version** is `files_versions/<path>.v<mtime>` in the account's home storage,
  a filecache row with a file id of its own.
  `<mtime>` is the file's modification time before the write;
  the version holds the content before the write;
  the filecache's `checksum` column is not copied to it.
- **Created** in files_versions' `BeforeNodeWrittenEvent` listener,
  through a view outside `/<uid>/files`: no node event fires for the version,
  only `CacheEntryInsertedEvent`.
  No version is made of an empty file.
  Two writes in one second overwrite `.v<mtime>`, whose etag then changes.
- **A team-folder version** is `versions/<fileId>/<mtime>` in the folder's
  own storage (`local::…/__groupfolders/<id>/`), or, in the legacy layout,
  `__groupfolders/versions/<id>/<fileId>/<mtime>` in the root storage.
- **Its file:**
  renamed or moved, the versions follow, the file id stable; deleted,
  they go to the trash with it; copied, they are copied, with new file ids.
- **Expiry** removes the version's filecache row; Nextcloud drops its metadata,
  and the app's orphan purge what is left.
- **Reading one:**
  a home version resolves through the root folder by file id.
  A team-folder version does not,
  being outside the folder's `files` jail that accounts mount;
  `IVersionManager::getVersionFile()` opens it.
  `IVersionManager` is files_versions' own interface, not OCP's, so it is
  used only where the app is enabled.
- **No checksum** is kept for a version anywhere in Nextcloud.

### The app today

- **One metadata document per file id,** its hashes overwritten in place:
  no history of a file's earlier checksums.
- **Versions are kept out twice:**
  `FileLocation` gives every row outside a files area no relative path,
  so no rule governs it;
  and `FilecacheService::andWhereGoverned()` keeps `files_versions/` out of the listings,
  the duplicates and the lookup with no reach.
  Team-folder versions fall under the first only.
- **On a write** the listener hears only `NodeWrittenEvent`.
  By then the filecache holds the new etag,
  so nothing can prove the hashes it still holds were the replaced content's.
  Before the write they can be proved, by the same test the queue uses.
- **Reading a file** needs a node (`FilecacheService::getFile()`, the root
  folder by file id).

### Design

1. **A version's identity.**
   `FileLocation` gains a `version`: its file,
   by internal path (a home's `files/<path>`) or by file id (a team folder's),
   and its timestamp.
   Its relative path stays null,
   so nothing that reads it takes a version for its file.
   Its address keeps the `..` form.
2. **Governed by its file's rule.**
   `RuleService` locates a version's file (one query for a batch) and returns
   the rule that governs the file.
   The queue, the revocation and the erosion then treat a version as its file's rule says:
   a rule that stops maintaining a file stops maintaining its versions.
3. **Capture.**
   On `BeforeNodeWrittenEvent`,
   a file whose rule maintains hashes and whose hashes are current,
   tested against its filecache row as it still stands,
   has them kept in memory with its mtime.
   On `NodeWrittenEvent` the version Nextcloud made under that mtime is looked
   up by its path, and the hashes are saved on it, stamped against its own etag:
   overwritten in the same second, it reads as outdated.
   No version found — versioning off, an empty file — and nothing is saved.
4. **Older versions.**
   A rule's `versions` (default off) has the sweep page its storages' version rows too,
   and queue those whose file it governs; the queue hashes them,
   opening a team-folder version through `IVersionManager`.
   The rule editor, `occ fcias:rules` and the rules API carry the option.
5. **Results.**
   `andWhereGoverned()` admits version rows; the trash stays out.
   A version found in a page is placed through its file:
   reached where its file is reached,
   shown at `versions://` and the path its file has for the viewer,
   with `version: {fileid, timestamp}`.
   A page may come back shorter, as with team-folder permissions.
   A duplicate group whose members are one file and its own versions is dropped.
   The unified search names a version "an earlier version of" its file and opens the file.
6. **Sidebar and verification.**
   The per-file route returns the file's versions with their checksums;
   the Checksums tab lists them.
   Where a recalculation finds the stored checksum outdated,
   the result names the version holding it, and the duplicates page says so.

## Implementation Plan

### Block 1: version identity

1. `FileLocation`:
   a `version` (file id or file path, timestamp) read from the three layouts;
   `withVersionFile()` for the located file.
2. `FilecacheService`:
   locate the files of a batch of versions in one query per layout.
3. `RuleService::governingRuleForLocation()` and `governingRulesForFileIds()`:
   a version takes its file's rule.
4. Tests: `FileLocationTest` for the three layouts and names holding `.v`;
   `RuleServiceTest`; an integration test that a version's rule is its file's,
   and that the trash's versions stay governed by nothing.

**Verification:**
unit and integration suites on 33 and 34; ECS, Psalm, `tools/check`.

### Block 2: capture

1. `FileListener`:
   `BeforeNodeWrittenEvent` keeps the current hashes of a file its rule maintains;
   `NodeWrittenEvent` hands them to the version.
2. `MetadataService`:
   save handed-over hashes on a file id, stamped against its etag,
   with their index rows.
3. Integration tests through files_versions on 33 and 34:
   an overwrite hands the hashes on; outdated hashes are not handed on;
   an excluded file hands nothing; a team folder on 34.

**Verification:**
as Block 1; a mutation check that the tests fail without the capture.

### Block 3: older versions

1. The rule's `versions` option: validation, storage, `RulesController`,
   `occ fcias:rules`, the rule editor (`src/`).
2. The sweep yields version rows for a rule that has it;
   `processRule` queues them.
3. `FilecacheService::getFile()`:
   a team-folder version through `IVersionManager::getVersionFile()`,
   as an account holding the folder.
4. Tests: unit for the option and the sweep; integration for a home and a
   team-folder version hashed by the queue.

**Verification:**
as Block 1, with the frontend's `npm test`, lint and typecheck.

### Block 4: results

1. `FilecacheService::andWhereGoverned()`:
   version rows admitted.
2. A version placement shared by the listings, the lookup and the duplicates:
   its file located, its reach and path its file's, `versions://`, `version`.
3. `HashListingService`, `ChecksumApi` (lookup, per-file routes),
   `DuplicateService` / `HashIndexService` (groups; a file with only its
   own versions dropped), `HashSearchProvider`.
4. The duplicates page marks a version and opens its file.
5. Tests: unit per service; integration for a version in each result, for
   reach through a share and a team folder, and for the dropped group.

**Verification:**
as Block 3.

### Block 5: sidebar and verification

1. The per-file route: `versions` with their timestamps and checksums.
2. A recalculation whose stored checksum was outdated names the version holding it.
3. The Checksums tab lists the versions; the duplicates page's Verify says
   which version still holds the old content.
4. Tests: unit and integration for the route; Vitest for the tab.

**Verification:**
as Block 3.

### Block 6: documentation

1. `docs/api-v1.md` and `docs/api-v1-openapi.yaml`: version rows,
   the `version` field, `versions://`, the rule option,
   the per-file route's `versions`.
2. `README.md`:
   what is hashed, and what versions cost.

**Verification:**
`tools/check`, `reflow.sh --check`.

## Proposed commit messages

- `[TASK] A file's versions are governed by its rule`
  (Block 1)
- `[TASK] An overwritten file's checksums pass to its version`
  (Block 2)
- `[TASK] A rule can hash the older versions of its files`
  (Block 3)
- `[TASK] Versions appear in search, listings and duplicates`
  (Block 4)
- `[TASK] The sidebar lists a file's versions with their checksums`
  (Block 5)
- `[TASK] The API reference and README describe file versions`
  (Block 6)

## Change History

- v1.0 (2026-09-30): first version.
- v1.1 (2026-10-20): measured; D1-C, D2-A, D3-B, D4-A, D5-A decided;
  the design and six blocks; renamed to the one-file form.
