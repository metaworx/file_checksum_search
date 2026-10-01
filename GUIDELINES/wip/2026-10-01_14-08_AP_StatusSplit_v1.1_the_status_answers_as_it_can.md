# AP StatusSplit v1.1: the status answers as it can

> **Status: proposal, 2026-10-01.** The admin status panel waits for its
> slowest count before showing anything, its version included. On jackal,
> `occ fcias:status` took 55.4 s of wall time for 0.31 s of CPU. v1.0's
> measuring step is done (Analysis 1), and it changes the remedy.

## Discussion

Decided with the user, 2026-10-01:

- **The panel must not wait on a count that a cold cache makes slow.**
- **Measured before deciding**, by plain SQL on jackal rather than a
  `--timings` build. v1.0's Block 1 is done that way and goes.

Proposed here, open at the gate:

- **A stored count instead of a split request.** Only one of the panel's
  counts is slow (Analysis 2). Stored, it leaves nothing on the panel that
  takes more than a fraction of a second cold, so splitting the request
  would buy nothing that justifies three routes and a loading state per
  row. v1.0's Block 2 goes.
- **Recounted hourly, beside the orphan purge.** The rule job already runs
  every five minutes and fits the daily purge in on its own interval and
  last-run key. The recount does the same, and is listed among the
  background jobs, so its time is in plain sight next to the count it
  produced.
- **`occ fcias:status` drops its two private counts by default.** The
  filecache count is the 52-second one. `--full` keeps them for whoever
  wants them.

## Analysis

1. **Jackal, 2026-10-01** (MariaDB 10.6.21, database `helioscloud`; the
   EXPLAINs were run in a database named `nextcloud`), each query run once
   and then again:

   | Query | Rows | First run | Warm |
   |---|---|---|---|
   | Hash rows, `meta_key LIKE 'file-checksum-hash-%'` (the panel's "Indexed checksums") | 406,419 | 8.41 s | — |
   | Queued, grouped, `LIKE 'pending:%'` | 16 | 0.20 s | — |
   | Untrusted, grouped, `LIKE 'stale:%'` | 0 | 0.00 s | — |
   | Stamp rows (`occ` only) | 305,869 | 7.28 s | — |
   | `COUNT(*)` over `oc_filecache` (`occ` only) | 2,308,282 | 52.53 s | 0.30 s |

   `EXPLAIN` shows the same plan for `COUNT(*)` and `COUNT(fileid)` on
   the filecache (`fs_mtime`, index only), and a range scan on the app's
   own index `oc_fcias_f_metadata_int_idx` for the hash count. The indexes
   are there and are used. The slow first runs are pages read from disk:
   the state an administrator meets after a quiet hour, which is when the
   panel gets opened.
2. **What that leaves on the panel.**
   - **Cheap:** version, database version (`SELECT VERSION()`), the jobs
     (app config), queued and untrusted (0.2 s cold together).
   - **Slow:** only "Indexed checksums". It is read in two places: the
     panel (`SettingsController::getStatus()`) and the API's admin status
     (`ChecksumApi::getStatus()`, `rowCount`). Both go through
     `StatusService::getHashRowCount()`.
3. **The pattern to follow.** `RuleProcessingJob` (a `TimedJob`, every
   `rule_processing_interval`, default 300 s) runs the orphan purge when
   `orphan_purge_last_run` is older than `orphan_purge_interval` (default
   86,400 s). It records the run through `JobStatsService`, which the
   panel and `occ fcias:status` list.

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
   time, and never counts. With no stored count yet, it counts once, then
   stores it, so a fresh install shows a number on first open rather than
   a blank.
5. **The API, `ChecksumApi::getStatus()`:** `rowCount` stays, now the
   stored value, and `rowCountAt` (Unix time) is added beside it. Adding a
   field is allowed within v1. `docs/api-v1.md` says so.
6. **The panel:** "Indexed checksums" shows the count, and "as of" its
   time, through `formatDateTime()`.
7. **Tests:**
   - the job counts when due and not before (unit);
   - the service never counts when a stored count exists (unit);
   - the API carries `rowCountAt` (unit, and the existing integration
     case);
   - the panel spec shows "as of".

**Verification:** phpunit `unit` and `integration` on harness instance 34;
vitest, lint, typecheck, stylelint; `status.cy.js` on 34 and 35;
`scripts/l10n.sh check`; Psalm, ECS.

### Block 2: `occ fcias:status`

1. The filecache count and the stamp count only with `--full`, in the
   text and JSON outputs alike.
2. "Indexed checksums" from the stored count with its time, in both
   outputs.
3. Tests: `ShowStatusTest`, both outputs, with and without `--full`.

**Verification:** phpunit `unit`; `occ fcias:status` and `--full` on
harness instance 34.

### Block 3: changelog

`CHANGELOG.md`, `[Unreleased]` → `### Changed`:

```text
- Admin status panel and `GET /api/v1/status`: the number of indexed
  checksums is counted hourly in the background and shown with its time
  (`rowCountAt`); opening the panel no longer waits for it.

- `occ file-checksum-search:status`: the filecache and stamp counts only
  with `--full`.
```

Not in scope:
- Splitting the status request (v1.0's Block 2). It is unneeded once the
  only slow count is stored.
- A recount on Refresh. Refresh rereads the stored count, and its time
  says how old it is.

## Proposed commit messages

Written per block at its gate. Block 1 is `[TASK] The indexed-checksum
count is kept in the background`, and Block 2 `[TASK] occ fcias:status
counts the filecache only when asked`.

## Change History

- v1.0 (2026-10-01): initial plan.
- v1.1 (2026-10-01): measured on jackal by SQL, which replaces Block 1.
  Only the hash count is slow on the panel, so a stored count, kept hourly
  by the rule job, replaces the split request. `occ fcias:status` drops
  its filecache and stamp counts unless asked with `--full`.
