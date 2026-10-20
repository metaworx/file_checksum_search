# AP FileVersions v1.0: checksums and file versions

## Discussion

- **The ask.** The user, 2026-09-30: support file versions.
- **Not scheduled.** It is a brief plan, for design.

## Analysis

**Today:** Nextcloud keeps a file's older versions under `files_versions/`,
through the `files_versions` app, and team folders version their own. The
app leaves them out on purpose: `FilecacheService::UNGOVERNED_PREFIXES`
names `files_versions/` among the areas "no rule governs and no listing
offers". A version has no checksum, and a search never finds one.

**What support could mean, to decide first:**
- **Search:** a checksum finds a file whose older version had it, "this
  content was once this file".
- **Sidebar:** a version shows its checksum in the Versions tab, or in the
  Checksums tab next to it.
- **Verification:** on a mismatch, say which earlier version the stored
  checksum matches.
- **Duplicates:** AP DuplicateActions merges a removed duplicate's versions
  into the kept file; checksums for versions would let it skip versions the
  kept file already holds.

**What is to be measured before the decision:**
- **The cost:** how many versions an account holds, and the hashing and the
  index rows they would add.
- **The interfaces:** `IVersionManager` and the version backends on
  Nextcloud 33 to 35; team folders' own versioning.
- **What a version is to the app:** its own file id, or only a version of
  one.

## Implementation Plan

1. **Measure** what is named above, and write the options with their cost.
2. **Decide** with the user, and write a new revision of this plan with its
   blocks.

## Proposed commit messages

None yet.

## Change History

- v1.0 (2026-09-30): first version.
