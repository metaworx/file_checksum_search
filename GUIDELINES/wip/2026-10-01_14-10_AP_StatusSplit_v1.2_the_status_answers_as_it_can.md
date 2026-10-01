# AP StatusSplit v1.2: the status answers as it can

> **Status: proposal, 2026-10-01.** The admin status panel waits for its
> slowest count before showing anything, its version included. On jackal,
> `occ fcias:status` took 55.4 s of wall time for 0.31 s of CPU. Measured
> since: every count is fast warm and slow cold (Analysis 1).

## Discussion

Decided with the user, 2026-10-01:

- **The panel must not wait on a count that a cold cache makes slow.**
- **Measured by plain SQL on jackal**, not with a `--timings` build.
- **The information first, the counts after it** (the user, on v1.1). The
  panel asks for the version, the database version, the jobs and the
  stored count first. It asks for the live counts only once that request
  has answered. Fired together, a count that warms a cold cache competes
  for the same disk and delays the information it does not depend on.

Proposed here, open at the gate:

- **The hash count stored, recounted hourly beside the orphan purge.** The
  rule job already runs every five minutes and fits the daily purge in on
  its own interval and last-run key. The recount does the same, and is
  listed among the background jobs, so its time is in plain sight next to
  the count it produced.
- **`occ fcias:status` drops its two private counts by default.** The
  filecache count is the 52-second one. `--full` keeps them.

## Analysis

1. **Jackal, 2026-10-01** (MariaDB 10.6.21, database `helioscloud`; the
   EXPLAINs were run in a database named `nextcloud`), each query run
   once:

   | Query | Rows | First run |
   |---|---|---|
   | Hash rows, `meta_key LIKE 'file-checksum-hash-%'` (the panel's "Indexed checksums") | 406,419 | 8.41 s |
   | Queued, grouped, `LIKE 'pending:%'` | 16 | 0.20 s |
   | Untrusted, grouped, `LIKE 'stale:%'` | 0 | 0.00 s |
   | Stamp rows (`occ` only) | 305,869 | 7.28 s |
   | `COUNT(*)` over `oc_filecache` (`occ` only) | 2,308,282 | 52.53 s; 0.30 s run again |

   `EXPLAIN` shows the same plan for `COUNT(*)` and `COUNT(fileid)` on the
   filecache (`fs_mtime`, index only), and a range scan on the app's own
   index `oc_fcias_f_metadata_int_idx` for the hash count. The indexes are
   there and are used. The slow first runs are pages read from disk: the
   state an administrator meets after a quiet hour, which is when the
   panel gets opened.
2. **On the panel:**
   - **Cheap:** version, database version (`SELECT VERSION()`), the jobs
     (app config) and the banner flag.
   - **Live counts:** queued and untrusted, cheap here at 0.2 s cold, but
     still index reads that a cold cache slows.
   - **Slow:** "Indexed checksums". It is read in two places: the panel
     (`SettingsController::getStatus()`) and the API's admin status
     (`ChecksumApi::getStatus()`, `rowCount`). Both go through
     `StatusService::getHashRowCount()`.
3. **One request today:** `GET /settings/status` builds everything in one
   response, and `useAdminSettings.loadStatus()` shows it all once the
   whole response is in.
4. **The pattern for the recount.** `RuleProcessingJob` (a `TimedJob`,
   every `rule_processing_interval`, default 300 s) runs the orphan purge
   when `orphan_purge_last_run` is older than `orphan_purge_interval`
   (default 86,400 s). It records the run through `JobStatsService`, which
   the panel and `occ fcias:status` list.

## Implementation Plan

### Block 1: the stored count

1. **`ConfigLexicon`:**
   - `status_count_interval` (default 3,600 s);
   - `status_count_last_run`;
   - `status_hash_rows`, the stored count.
2. **`RuleProcessingJob::run()`**, after the orphan purge, the same way:
   when the last count is older than the interval, call
   `countHashEntries()`, store it with its time, and record
   `JobStatsService::JOB_STATUS_COUNT` with `{rows}`.
3. **`JobStatsService`:** the new job in `JOBS` and in `LABELS`
   ("Status count"). The admin panel's `JOB_LABELS` gains it too, a new
   wrapped text, in every language kept here.
4. **`StatusService::getHashRowCount()`** returns the stored count and its
   time, and never counts when one is stored. With none stored yet, it
   counts once and stores it, so a fresh install shows a number on first
   open rather than a blank.
5. **The API, `ChecksumApi::getStatus()`:** `rowCount` stays, now the
   stored value, and `rowCountAt` (Unix time) is added beside it. Adding a
   field is allowed within v1. `docs/api-v1.md` says so.
6. **Tests:**
   - the job counts when due and not before (unit);
   - the service never counts when a stored count exists (unit);
   - the API carries `rowCountAt` (unit, and the existing integration
     case).

**Verification:** phpunit `unit` and `integration` on harness instance 34;
`scripts/l10n.sh check`; Psalm, ECS.

### Block 2: the information first, the counts after

1. **Two admin-only routes:**
   - `GET /settings/status`: version, database version, jobs, banner flag,
     and the stored hash count with its time. Nothing in it reads more
     than app config, `SELECT VERSION()` and one stored value.
   - `GET /settings/status/queues`: queued by mode, untrusted by reason.
2. **`useAdminSettings.loadStatus()`:**
   - asks for `/settings/status`, shows what it brings, then asks for
     `/settings/status/queues`;
   - the queue rows show a small loading mark until their answer lands;
   - a failure of either says which part failed and leaves the other's
     rows standing;
   - Refresh repeats the sequence, aborting any request still running;
   - "Last updated" is when the second answer landed.
3. **The panel:** "Indexed checksums" shows the count and "as of" its time,
   through `formatDateTime()`.
4. **Tests:**
   - controller unit tests for both routes;
   - the composable's spec: the second request is made only after the
     first answers, a failure of either, and Refresh aborting a sequence
     in flight;
   - `status.cy.js`: delaying the queues route with `cy.intercept`, the
     version is already shown and the queue rows still load.
5. **Docs:** the user guide, if it describes the panel.

**Verification:** vitest, lint, typecheck, stylelint; phpunit `unit`;
`status.cy.js` on harness instances 34 and 35.

### Block 3: `occ fcias:status`

1. The filecache count and the stamp count only with `--full`, in the
   text and JSON outputs alike.
2. "Indexed checksums" from the stored count with its time, in both
   outputs.
3. Tests: `ShowStatusTest`, both outputs, with and without `--full`.

**Verification:** phpunit `unit`; `occ fcias:status` and `--full` on
harness instance 34.

### Block 4: changelog

`CHANGELOG.md`, `[Unreleased]` → `### Changed`:

```text
- Admin status panel: the version and the jobs show at once, and the
  queue counts follow; the number of indexed checksums is counted hourly
  in the background and shown with its time, as `GET /api/v1/status`
  gives it (`rowCountAt`).

- `occ file-checksum-search:status`: the filecache and stamp counts only
  with `--full`.
```

Not in scope:
- A recount on Refresh. Refresh rereads the stored count, and its time
  says how old it is.

## Proposed commit messages

Written per block at its gate:

- Block 1: `[TASK] The indexed-checksum count is kept in the background`
- Block 2: `[TASK] The status panel shows its information before its counts`
- Block 3: `[TASK] occ fcias:status counts the filecache only when asked`

## Change History

- v1.0 (2026-10-01): initial plan.
- v1.1 (2026-10-01): measured on jackal by SQL, which replaces Block 1.
  Only the hash count is slow on the panel, so a stored count, kept hourly
  by the rule job, replaces the split request. `occ fcias:status` drops
  its filecache and stamp counts unless asked with `--full`.
- v1.2 (2026-10-01): the split returns, in sequence. The information
  first, the live counts once it has answered, so a cold cache warmed by a
  count cannot delay the version. The stored count stays.
