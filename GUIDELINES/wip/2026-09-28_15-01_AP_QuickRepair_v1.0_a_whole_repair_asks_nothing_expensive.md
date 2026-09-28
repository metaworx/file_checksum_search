# AP QuickRepair v1.0: a whole repair asks nothing expensive

> **Status: proposal, 2026-09-28.** Measured on jackal (Nextcloud 33,
> `oc_files_metadata_index` 448,449 rows, a filecache copy in progress),
> where enabling the installed app from the Apps page took about two
> minutes. Nothing timed out and the app was enabled; the time is the
> repair's, and it is paid on every enable, upgrade and
> `occ maintenance:repair`.

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

So one step is nearly the whole cost, and it is the *question* that costs,
not the work: `rebuild-from-metadata` found nothing to do and still took a
minute. Its docblock says an expensive step "can ask first — two counts, an
empty page — and skip itself in a millisecond"; at jackal's size the two
counts are the minute.

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
   the filecache, looking for missing files, 50 at a time. Cheaper, but it
   still reads the hash rows to find none.
3. Every other step of a whole repair is an indexed query or a job-list
   lookup.
4. **The pattern already exists.** `rebuild-from-filecache` became a queued
   job that works for 30 seconds per cron run, keeps a cursor, and runs
   inline only when named at the console or asked for with
   `--include-expensive`. The two steps above can follow it; the web
   request then asks nothing that grows with the instance.
5. **The double run** stops mattering once both passes are a handful of
   indexed queries. A separate, smaller class for `<install>` would halve
   that too, but would split the repair into two lists to keep in step, and
   a fresh install needs most of the whole repair anyway (the shipped
   default rules, the key declarations, the queued copy). Not worth it on
   the numbers; left out.

## Implementation Plan

1. **[FIX] The hash-index check runs in the background.** A queued job,
   `HashIndexCheck`, asks `hashIndexIsComplete()` and, when the answer is
   no, walks the stamped documents with `reindexHashes()`'s page callback
   for up to 30 seconds per cron run, keeping its place in
   `hash_index_check_after` and queueing itself again until the walk is
   through — the shape of `FilecacheBackfill`. `rebuild-from-metadata`
   queues it in a whole repair and runs inline when named or with
   `--include-expensive`, as `rebuild-from-filecache` does;
   `MetadataService::walkHashDocuments()` takes a start id and a stop check
   for that. The docblock's "skip itself in a millisecond" is corrected to
   what a count costs.
   CHANGELOG: one Fixed bullet, enabling or upgrading on a large instance.
   Tests: the step queues in a whole repair and runs when named; the job
   asks, walks, resumes, hands over, finishes and clears its cursor, and
   does not walk when the answer is yes; the walk starts past a cursor and
   stops when told.
   **Verification:** PHP unit and integration suites; locally, a whole
   repair queues the job and returns, a forced cron run repairs a document
   whose index rows were removed by hand. On jackal: `time occ fcias:repair`
   and `--step rebuild-from-metadata` again.

2. **[FIX] The orphan purge runs in the background.** `orphaned-metadata`
   queues a job, `OrphanPurge`, in a whole repair; the job purges batch
   after batch for up to 30 seconds per cron run and queues itself again
   while a batch came back full. Named, or with `--include-expensive`, it
   runs inline as today. CHANGELOG: amends block 1's bullet rather than
   adding one.
   Tests: queues in a whole repair, runs when named; the job stops on an
   empty batch and hands over on a full one.
   **Verification:** suites; on jackal, a whole repair under ten seconds,
   and an enable from the Apps page after `occ app:disable`.

## Proposed commit messages

Block 1: `[FIX] A whole repair queues the hash-index check instead of asking it in the request`
Block 2: `[FIX] A whole repair queues the orphan purge instead of running it in the request`

Each in full at its commit gate.

## Open decisions

- **One job or two.** Both blocks could share one "deferred repairs" job
  running named steps in turn; two jobs mirror the existing backfill and
  keep each cursor with its own work. Proposed: two.
- **The measurement's print order.** In the whole run the wait appeared
  after "no disowned files", which the per-step timings do not bear out;
  the steps' own timings are taken as the measure. Worth a look only if
  the numbers after block 1 still disagree with the order.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.0    | 2026-09-28 | Initial plan from the jackal timings: the hash-index check and the orphan purge move to queued jobs; the double registration stays. |
