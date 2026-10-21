# NOTE PageSelection v1.0: how the run went

Where the PLAN++ run of 2026-10-21 went differently from the plan's blocks.

- **Block 1, a second cause of repeated groups:**
  the duplicates query ordered groups by size alone,
  so pairs came in whatever order the database chose on each query.
  It now orders by algorithm and hash after the size.
- **Block 2:**
  the field is *Path*, not *Folder*: it takes a name and a pattern as well.
- **Block 2:**
  `[ $uid ],$algo` in `ChecksumApi::findDuplicates()`, a space lost in Block 1,
  is put back.
- **Block 2's field broke an e2e case**
  that counts the labelled controls; Block 2's checks ran no e2e, as planned.
  Block 3's commit counts five.
- **Block 4:**
  the batch forms loop the existing single forms in the controller;
  the service gained nothing.
  A group, or a row, that throws is answered as failed on its rows,
  and the others go on.
- **Block 4, not tested:**
  a group that throws.
  Nothing makes one throw on a live instance, and the controller's unit tests
  cannot hand it a body.
- **Block 4's commit was staged by hunk:**
  `DuplicateListing.vue` already held Block 5's first work.
- **Block 5:**
  the choice is one set of file ids for the page,
  so a file listed under two algorithms is chosen once,
  and the D1-A guard weighs the groups together.
- **Block 5:**
  D1-A counts a version as no copy, as the group's confirmation already did:
  versions expire.
- **Block 5:**
  a *Select* button beside *Refresh* starts choosing,
  besides a group's own *Select*.
- **Block 5:**
  a box ticked is reported as itself, not as the group's whole choice:
  two ticked in a row kept only the second.
- **Block 6, not Block 5:**
  *Delete selected (n)* came with the confirmation it opens.
- **Path patterns differ from rule globs:**
  a pattern's `*` stays within a name, a rule's crosses folders.
  The API docs say so.
