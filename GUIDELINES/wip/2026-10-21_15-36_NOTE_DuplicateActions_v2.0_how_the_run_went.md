# NOTE DuplicateActions v2.0: how the run went

Where the PLAN++ run of 2026-10-21 went differently from the plan's blocks.

- **Two defects fixed on the way, in commits of their own.**
  - The HTTP tests' seeding left a queued file's pending marker in the index,
    so two hash tests failed wherever a rule queues new files.
  - The Duplicates e2e spec's own-group helper had lost its hash filter
    when the password helper was added beside it.
- **Block 2:**
  a team folder's version is touched as its file goes to the trash,
  and its stamp then vouches no more.
  Such a version is listed, and deleted,
  only once the queue has hashed it again;
  the test hashes it as the queue would.
- **Block 2 and 3, not tested:**
  the refusal of `trash` with the trash app off,
  and a failed import refusing a deletion for good.
  Neither can be brought about on a live instance without switching an app
  off for every other test.
- **Block 4:**
  `TrashedVersion` also says it has no metadata.
  The home backend's importer writes a version's metadata only for one that has some,
  and its table takes no row without.
- **Block 5:**
  the confirmation is titled after its button, not as a question:
  French puts a no-break space before a question mark,
  which the edit tools cannot write.
- **Block 6:**
  the e2e case moves one copy of three, not two.
  A group with a single copy outside the trash is resolved and leaves the page,
  so restoring from the page is possible only while two copies stay;
  the user guide says so, and points to Undo and Files' trash.
- **Block 6:**
  the e2e case deletes its folder and empties the trash before each run:
  an overwrite on a re-run gives the files versions,
  and the dialog then rightly asks where they go.
