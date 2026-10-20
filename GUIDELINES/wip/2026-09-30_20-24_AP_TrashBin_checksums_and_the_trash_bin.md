# AP TrashBin v1.0: checksums and the trash bin

## Discussion

- **The ask.** The user, 2026-09-30: support the trash.
- **Not scheduled.** It is a brief plan, for design.

## Analysis

**Today:** a deleted file moves to `files_trashbin/`, through the
`files_trashbin` app, and team folders keep a trash of their own. The app
leaves the trash out on purpose: `FilecacheService::UNGOVERNED_PREFIXES`
names `files_trashbin/` among the areas "no rule governs and no listing
offers".

**What support could mean, to decide first:**
- **Search:** a checksum finds a file in the trash, so it can be restored.
- **Duplicates:** a group says that a copy sits in the trash, without
  counting it as a duplicate.
- **Restore:** a restored file keeps its checksums and needs no
  recalculation.
- **Clean-up:** the index forgets a file once the trash is emptied.

**What is to be measured before the decision:**
- **Deletion and restore today:** what happens to a file's index rows and
  metadata when it is deleted, and when it is restored. A restored file may
  keep its file id or get a new one.
- **Team folders:** their trash, on Nextcloud 33 to 35.
- **Expiry:** what the trash's expiry does to the rows.

## Implementation Plan

1. **Measure** what is named above, on a harness instance, and write the
   options.
2. **Decide** with the user, and write a new revision of this plan with its
   blocks.

## Proposed commit messages

None yet.

## Change History

- v1.0 (2026-09-30): first version.
