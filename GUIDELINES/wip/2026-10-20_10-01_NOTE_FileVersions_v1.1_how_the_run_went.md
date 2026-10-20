# NOTE FileVersions v1.1: how the run went

Where the PLAN++ run of Blocks 1 to 6 went differently from the plan.

- **Block 1:**
  `FileLocation::withVersionFile()` came in Block 3,
  where the sweep first carries a version's file.
- **Block 2:**
  the integration suites act as an account (`DatabaseTestCase::actAs()`).
  The versions app finds the file it versions through the process's default
  filesystem view,
  which a test process sets up for the first account it touches:
  in the full suite, no version was made.
  Acting as nobody clears the session's account,
  or files_lock fails every test after.
- **Block 2, found:**
  a file on an external storage has its versions in the account's home,
  not beside it; they are taken to no file, and stay out of every result.
- **Block 3:**
  the rules table shows `+ versions` beside the mode,
  and the Mode column's help says so, in every kept language.
- **Block 4:**
  a version is not read again by Verify, on the Duplicates page or elsewhere:
  nothing writes to one, and the per-file routes take files.
- **Block 4:**
  in the missing files, a version under an include rule that does not hash
  versions is as a file an `ignore` rule matches.
- **Block 5, step 2, not built:**
  a recalculation naming the version that holds an outdated checksum,
  and the Duplicates page's Verify saying so.
  An overwritten file leaves its duplicate group until it is hashed again,
  so the page never holds a stale file beside its own version.
  The sidebar's Versions list shows which version holds a checksum the
  file no longer does.
- **Block 5:**
  the French "+ versions" help of Block 3 had a plain space before its colon;
  it has the no-break space now.
- **Behaviour the tests held:**
  HashStampTest expected the old hash to find nothing after a write,
  and UngovernedAreasTest a version in no result; both now expect the version.
