# NOTE FileVersions v1.2: how the run went

Where the PLAN++ run of Blocks 7 to 11 went differently from the plan.

- **Block 8:**
  the copy is tested in a home only;
  a team folder's copy has no integration test.
- **Block 10:**
  the pass over an account's versions is `HashCalculationService`'s,
  which `HashIndexService` passes through, as it does the files' pass.
  The command makes no pass where no enabled rule hashes versions
  and `--versions` is not given (`RuleService::anyRuleHashesVersions()`).
- **The Versions switch:**
  `js/` was rebuilt before Block 7, and the server serves the new rule dialog.
  A browser that showed the old one after a reload most likely kept the old
  bundle from its cache, which a hard reload bypasses; not confirmed.
