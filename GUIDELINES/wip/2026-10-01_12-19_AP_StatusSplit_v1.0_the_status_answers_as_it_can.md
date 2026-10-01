# AP StatusSplit v1.0: the status answers as it can

> **Status: proposal, 2026-10-01.** The admin status panel waits for its
> slowest count before showing anything, its version included. On jackal,
> `occ fcias:status` took 55.4 s of wall time for 0.31 s of CPU.

## Discussion

Decided with the user, 2026-10-01:

- **The cheap parts arrive first.** The version, the database version and
  the jobs need not wait for counts over hundreds of thousands of rows.
- **Split requests, not a stream.** Nextcloud's responses are not built
  to stream, and PHP-FPM and proxies buffer a response anyway. Several
  plain requests fired in parallel get the same effect with ordinary
  endpoints.
- **Measure before caching.** A stored count, kept by a background job
  and shown "as of" its time, is the remedy if a count is slow even on
  its own. Which count is slow is not known yet, so caching is not in
  this plan.

## Analysis

1. **Jackal, 2026-10-01** (Nextcloud on MariaDB 10.6, app 0.21.0):
   `sudo -u www-data time ./occ fcias:status` took 55.43 s elapsed for
   0.31 s user and 0.08 s system CPU. The process spent almost all of it
   waiting on the database. It reported 2,308,279 filecache entries,
   305,867 stamp rows, 13 pending and 0 untrusted.
2. **What each view counts:**

   | Count | Query | Panel | `occ` |
   |---|---|---|---|
   | Indexed checksums | `COUNT(*)` over `files_metadata_index`, `meta_key LIKE 'file-checksum-hash-%'` (`MetadataService::countHashEntries()`) | yes | no |
   | Queued, by mode | grouped `COUNT` over the stamp key, value `LIKE 'pending:%'` (`getPendingStats()`) | yes | yes |
   | Untrusted, by reason | the same over `LIKE 'stale:%'` (`getStaleStats()`) | yes | yes |
   | Filecache entries | `COUNT(*)` over `oc_filecache` (`ShowStatus::getFilecacheCount()`) | no | yes |
   | Stamp rows | `COUNT(*)` over the stamp key (`ShowStatus::getMetadataCount()`) | no | yes |

   Version, database version (`SELECT VERSION()`), the jobs (app config)
   and the banner flag cost milliseconds.
3. **Which count is slow is not known.** Each of the index queries can be
   served by the app's index `(meta_key, meta_value_string, file_id)`, if
   the migration created it there. `COUNT(*)` over 2.3 million filecache
   rows is a full scan on InnoDB, but it does not explain 55 s on its own.
   The measurement in Block 1 says which it is.
4. **One endpoint today:** `SettingsController::getStatus()`
   (`GET /settings/status`) builds everything in one response;
   `useAdminSettings.loadStatus()` shows it all once the whole response
   is in, and Refresh repeats it.

## Implementation Plan

### Block 1: measure

1. `occ fcias:status --timings`: each part's time on stderr, after the
   report. The report itself is unchanged.
2. The user runs it on jackal, and the result goes into a NOTE beside this
   plan.

**Verification:** a unit test that `--timings` names every part. On
harness instance 34, the timings add up to the command's wall time.

### Block 2: split

1. **Three routes, all admin-only**, as `getStatus()` is today:
   - `GET /settings/status`: version, database version, jobs, banner.
   - `GET /settings/status/counts`: the indexed checksums.
   - `GET /settings/status/queues`: queued and untrusted.

   The response shapes stay what they were, divided between the three.
2. `useAdminSettings`: the three requests in parallel. Each row shows its
   value when its answer lands, and a small loading mark until then.
   Refresh refetches all three. "Last updated" is when the last answer
   landed.
3. If Block 1 finds a slow count that only `occ` makes, the command
   leaves it out by default and shows it with a `--full` option. The
   filecache count is the candidate.
4. Tests:
   - controller unit tests for the three routes;
   - the composable's spec: rows fill independently, and Refresh;
   - `status.cy.js`: the panel shows the version before the counts
     answer, by delaying the counts route with `cy.intercept`.
5. `docs/api-v1.md`, if it names the route; the user guide, if it
   describes the panel.

**Verification:** vitest, lint, typecheck, stylelint; phpunit `unit` and
`integration`; `status.cy.js` on harness instances 34 and 35; Psalm, ECS.

### Block 3: changelog

1. `CHANGELOG.md`, `[Unreleased]` → `### Changed`: the status panel shows
   each part as it is ready. If Block 2's item 3 applies, also the `occ`
   change.

Not in scope:
- A stored, background-kept count (Discussion), unless Block 1 shows a
  single count slow enough to need it. That would be a revision.
- The personal settings page, which shows no counts.

## Proposed commit messages

Written per block at its gate. Block 1 is `[TASK] occ fcias:status
--timings says where its time goes`, and Block 2 `[TASK] The status panel
shows each part as it is ready`.

## Change History

- v1.0 (2026-10-01): initial plan.
