# AP QuickRepair v1.1: a whole repair asks nothing expensive

> **Status: proposal, 2026-09-28.** Measured on jackal (Nextcloud 33,
> `oc_files_metadata_index` 448,449 rows, a filecache copy in progress),
> where enabling the installed app from the Apps page took about two
> minutes. Nothing timed out and the app was enabled; the time is the
> repair's, and it is paid on every enable, upgrade and
> `occ maintenance:repair`. v1.1: the orphan purge reuses the daily job
> that already exists, and the background jobs report to both status views.

## Discussion

`RepairQuietStart` is registered under both `<post-migration>` and
`<install>`, and has to be: Nextcloud runs `install` steps only on a first
install and `post-migration` steps only when an earlier version is on
record, so a fresh install needs the first and an upgrade the second.
`RepairQuietStartTest` holds the manifest to both.

Enabling an app that is already installed runs both lists in one request
(`Installer::installAppLastSteps()`, stable33, lines 537–574), so the whole
repair runs twice. That was judged harmless on the grounds that every step
is idempotent and cheap once the filecache copy became a queued job. Jackal
says otherwise about cheap:

| Measured on jackal | Time |
|---|---|
| `occ fcias:repair`, whole | 52 s, then 80 s |
| `--step rebuild-from-metadata` | 63.9 s, answering "every stored hash is indexed" |
| `--step orphaned-metadata` | 4.2 s, answering "nothing left behind" |
| `--step clear-disowned`, `--step key-namespace` | 0.4 s each, about `occ`'s own start-up |

One step is nearly the whole cost, and it is the *question* that costs:
`rebuild-from-metadata` found nothing to do and still took a minute. Its
docblock says an expensive step "can ask first — two counts, an empty page
— and skip itself in a millisecond"; at jackal's size the two counts are
the minute.

The maintainer asked that the jobs this moves work into be shown where the
others are, with their last run: the admin settings' status table and
`occ file-checksum-search:status`.

## Analysis

1. **`hashIndexIsComplete()`** is two aggregates:
   `COUNT(DISTINCT file_id)` over the index rows whose key is `LIKE
   'file-checksum-%'`, and a count of `oc_files_metadata` documents whose
   file has a stamp row (an `IN` subquery over the index) and whose JSON
   matches `LIKE '%"file-checksum-…%'`. The second reads the JSON of every
   stamped document; the filecache copy stamps files as it goes, so the
   question grows with the copy, up to the ~300,000 files jackal's clients
   have checksummed.
2. **`fetchOrphanedFileIds()`** is a `LEFT JOIN` of the index's hash rows to
   the filecache, 50 at a time. **A daily purge already runs it**:
   `RuleProcessingJob` purges batch after batch once its interval has
   passed since `orphan_purge_last_run`, books the day only when the
   backlog is empty, and records its run as `orphan_purge` for the status
   table. The repair step duplicates it, in the request.
3. Every other step of a whole repair is an indexed query or a job-list
   lookup.
4. **The pattern for a long step exists.** `rebuild-from-filecache` became
   `FilecacheBackfill`: a queued job, 30 seconds per cron run, a cursor,
   inline only when named at the console or asked for with
   `--include-expensive`.
5. **What the status views show today.** `JobStatsService` stores each
   job's last run and counts in app config; `SettingsController` hands
   `lastRuns()` to the admin page, whose "Background Jobs" row lists
   `rule_sweep`, `pending_drain` and `orphan_purge` with translated labels.
   `FilecacheBackfill` records nothing, so a copy in progress is invisible;
   `occ file-checksum-search:status` shows no job at all.
6. **The double run** stops mattering once both passes are a handful of
   indexed queries. A separate install step would halve that and split
   the repair into two lists to keep in step; left out.

## Implementation Plan

1. **[FIX] The hash-index check runs in the background.** A queued job,
   `HashIndexCheck`, asks `hashIndexIsComplete()` and, when the answer is
   no, walks the stamped documents for up to 30 seconds per cron run,
   keeping its place in `hash_index_check_after` and queueing itself again
   until the walk is through — the shape of `FilecacheBackfill`.
   `MetadataService::walkHashDocuments()` takes a start id and a stop check
   for that. `rebuild-from-metadata` queues the job in a whole repair and
   runs inline when named or with `--include-expensive`. The job records
   each run as `hash_index_check` (`repaired`, `done`). The docblock's
   "skip itself in a millisecond" is corrected.
   CHANGELOG: one Fixed bullet, enabling or upgrading on a large instance.
   Tests: the step queues in a whole repair and runs when named; the job
   asks, walks, resumes, hands over, finishes, clears its cursor, records
   its run, and does not walk when the answer is yes; the walk starts past
   a cursor and stops when told.
   **Verification:** PHP suites; locally, a whole repair queues the job and
   returns, and a forced cron run repairs a document whose index rows were
   removed by hand.

2. **[FIX] A whole repair leaves the orphan purge to the daily job.** In a
   whole repair `orphaned-metadata` clears `orphan_purge_last_run`, so the
   next `RuleProcessingJob` run purges, and says so; named or with
   `--include-expensive` it purges inline as today. No new job.
   CHANGELOG: amends block 1's bullet.
   Tests: a whole repair clears the clock and purges nothing itself; a
   named run purges.
   **Verification:** PHP suites.

3. **[TASK] Both status views list every background job with its last
   run.** `FilecacheBackfill` records its runs as `filecache_backfill`
   (`copied`, `done`); `JobStatsService::JOBS` names the two new keys; the
   admin page's `JOB_LABELS` gains "Checksum copy" and "Hash index check",
   through `t()` and `scripts/l10n.sh update`, every kept language filled;
   `occ file-checksum-search:status` prints a "Background jobs" block —
   each job's last run, or "never ran yet", and its counts — and its JSON
   output gains `jobs`, the same shape the page reads.
   CHANGELOG: one Added bullet.
   Tests: the backfill records; `lastRuns()` lists five jobs; the command's
   text and JSON name them; the page's spec shows the two new labels.
   **Verification:** PHP suites, Vitest, `npm run lint`, `scripts/l10n.sh
   check`, a built bundle.

4. **On jackal, after a release:** `time occ fcias:repair` under ten
   seconds; `occ app:disable` then an enable from the Apps page in
   seconds; the status views show the two jobs' progress.

## Proposed commit messages

Block 1: `[FIX] A whole repair queues the hash-index check instead of asking it in the request`
Block 2: `[FIX] A whole repair leaves the orphan purge to the daily job`
Block 3: `[TASK] The status views list every background job with its last run`

Each in full at its commit gate.

## Open decisions

- **Decided:** two jobs, not one "deferred repairs" job — the hash-index
  check beside the existing filecache copy; the orphan purge needs none.
- **The measurement's print order.** In the whole run the wait appeared
  after "no disowned files", which the per-step timings do not bear out;
  the steps' own timings are taken as the measure.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.1    | 2026-09-28 | Block 2 reuses `RuleProcessingJob`'s daily purge instead of a new job; block 3 added: the filecache copy and the hash-index check report their last run to the admin status table and `occ …:status`; two jobs decided. |
| v1.0    | 2026-09-28 | Initial plan from the jackal timings: the hash-index check and the orphan purge move to queued jobs; the double registration stays. |
