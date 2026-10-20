# AP FileVersions v1.2: checksums and file versions

## TL;DR

A file's older versions get checksums, and appear in search, listings,
duplicates and the sidebar, each marked as an earlier version of its file.
Most cost no read: checksums pass between a file and its versions wherever
Nextcloud copies content between them. v1.2 closes the gaps the user's test found: copy,
restore, a file no rule maintains, and `fcias:hash`.

- version → its own filecache row, governed by its file's rule
- overwrite → the file's current checksums to the new version; no read;
  any file not excluded (D6-A), not only a maintained one
- copy → each version's checksums to the copy's version
- restore → the version's checksums to the file; the replaced content's to the
  version made of it
- proof → a version's row carries the file's ETag: checksums pass only where
  their stamp vouches for the receiving row
- older versions → hashed where a rule says `versions`; `fcias:hash` too (D7-A)
- results → `versions://` + the file's path, `version`; reach = the file's

Measured on a real instance: versions ≈ a fifth of the files by count,
a ninth by size; ≈ 10,000 new a month;
three in four files hold current checksums.

### Blocks

1. Version identity: version rows recognised, governed by their file's rule.
   *Done.*
2. Capture: an overwritten file's checksums handed to its new version.
   *Done.*
3. Older versions: the rule option `versions`, swept and hashed.
   *Done.*
4. Results: lookup, unified search, listings and duplicates show versions.
   *Done.*
5. Sidebar and verification: the file's versions in the Checksums tab.
   *Done.*
6. Documentation: API reference, OpenAPI, README.
   *Done.*
7. Capture for every file no rule excludes.
8. Copy: the versions' checksums to the copy's versions.
9. Restore: checksums both ways between the file and its versions.
10. `fcias:hash` hashes versions.
11. Documentation of 7 to 10.

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
- **The user's test, 2026-10-20, after v1.1's six blocks** (instance 33):
  a hashed file edited, its edit hashed, copied, a version of the copy restored;
  no version held a checksum, no duplicate showed,
  and the rule dialog had no Versions switch.
  - The switch: the frontend bundle had not been rebuilt (the run tested `src/`,
    never built `js/`); rebuilt.
  - No hand-over: the edits came before any rule maintained the file, and
    v1.1 handed checksums on only under such a rule.
  - `fcias:hash` hashes files, never versions.
  - Copy and restore: two gaps, below.
- **Decided, 2026-10-20 (v1.2):**
  - **D6-A:**
    the hand-over at overwrite runs for every file no rule excludes:
    the checksums are right for the content, and cost nothing.
  - **D7-A:**
    `fcias:hash` hashes the versions of the accounts' files where their
    rule says `versions`; `--versions` asks for them regardless.

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
- **A version's row carries its source's ETag:**
  `Cache::copyFromCache()` copies `etag` with the rest of the row,
  for the version made at a write, for one made at a restore,
  and for each version copied with its file.
  So checksums stamped against an ETag describe the content of any row that
  carries that ETag:
  the test every writer uses (`MetadataService::isCurrent()`),
  asked of the receiving row, proves a hand-over.
- **Copy:**
  files_versions' `NodeCopiedEvent` listener copies the versions,
  `.v<mtime>` beside the copy; a listener of lower priority runs after it.
- **Restore:**
  `Storage::rollback()` copies the file's content to a version first,
  then the version's content into the file, and touches it:
  no write event fires;
  `OCA\Files_Versions\Events\VersionRestoredEvent` (Nextcloud 33 to 35) does,
  after it, with the version restored.

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
   (Not built: NOTE v1.1, how the run went.)
7. **Capture for every file no rule excludes (D6-A).**
   `onBeforeWrite` asks the rule's verdict, not whether it maintains:
   nothing for `exclude`, the hand-over otherwise.
   The write then erodes the file's own hashes where nothing maintains them,
   as before; the version keeps what was right for it.
8. **Copy.**
   On `NodeCopiedEvent`, after files_versions' listener,
   each of the source's versions hands its checksums to the copy's version of
   the same timestamp, where the copy's rule does not exclude it,
   by the ETag proof.
9. **Restore.**
   On `VersionRestoredEvent`, in this order:
   the file's checksums, stamped against the ETag it had, pass to the version
   carrying that ETag, which the restore made of the replaced content;
   then the restored version's pass to the file, stamped against its new ETag.
   Where the restored version holds none, the file is treated as written
   (`RuleService::contentChanged()`): queued or eroded at once, not at the
   daily ETag walk.
   The listener names the event's class as a string, so the app loads
   without the versions app.
10. **`fcias:hash` (D7-A).**
    After the files, for each account in scope:
    the versions in its home without checksums whose rule says `versions`,
    or all under `--versions` that its rule does not exclude,
    hashed by the same recalculation the sidebar uses.
    With `--mark`, those whose rule says `versions` are queued;
    `--versions` with `--mark` is refused, as the queue honours the rule.

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

### Block 7: capture for every file no rule excludes

1. `FileListener::onBeforeWrite()`:
   the hand-over unless the file's rule excludes it.
2. `VersionCapture`:
   a hand-over helper by the ETag proof (`MetadataService::isCurrent()` against
   the receiving row), used by Blocks 8 and 9.
3. Tests: unit for the verdicts; integration for a file no rule governs and
   one an exclude rule governs.

**Verification:**
unit and integration suites on 33 and 34; ECS, Psalm, `tools/check`;
a mutation check.

### Block 8: copy

1. `FileListener`:
   `NodeCopiedEvent` at a lower priority than files_versions' listener;
   the versions' hand-over beside the existing copy of the file's checksum.
2. `VersionCapture::copied()`:
   the source's versions matched to the copy's by timestamp.
3. Tests: integration through files_versions, a home and a team folder.

**Verification:**
as Block 7.

### Block 9: restore

1. `FileListener`:
   `VersionRestoredEvent`, by class name.
2. `VersionCapture::restored()`:
   the replaced content's checksums to its version,
   then the restored version's to the file; else the file treated as written.
3. `psalm.xml`:
   the event's class named.
4. Tests: integration through `IVersionManager::rollback()`,
   a home and a team folder.

**Verification:**
as Block 7.

### Block 10: `fcias:hash` and versions

1. `HashFiles`:
   `--versions`; after the files, the accounts' home versions as Design 10 says;
   with `--mark`, queued.
2. `HashIndexService`:
   the pass over an account's versions.
3. Tests: unit for the options;
   integration for a version hashed and one left alone.

**Verification:**
as Block 7.

### Block 11: documentation

1. `README.md` and `docs/user-guide.md`: copy, restore,
   a file no rule maintains, `fcias:hash --versions`.
2. `docs/api-v1.md`:
   the checksums' hand-over on copy and restore.

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
- `[TASK] A version gets its file's checksums wherever no rule excludes it`
  (Block 7)
- `[TASK] A copied file's versions keep their checksums`
  (Block 8)
- `[TASK] Restoring a version restores its checksums`
  (Block 9)
- `[TASK] fcias:hash hashes versions`
  (Block 10)
- `[TASK] The documents describe the checksums' hand-over on copy and restore`
  (Block 11)

## Change History

- v1.0 (2026-09-30): first version.
- v1.1 (2026-10-20): measured; D1-C, D2-A, D3-B, D4-A, D5-A decided;
  the design and six blocks; renamed to the one-file form.
- v1.2 (2026-10-20): the user's test; D6-A and D7-A decided; the ETag proof;
  Blocks 7 to 11: capture for every file not excluded, copy, restore,
  `fcias:hash`, documentation; Blocks 1 to 6 marked done.
