# AP StatusSplit v1.3: the status answers as it can

> **Status: proposal, 2026-10-01.** The admin status panel waits for its
> slowest count before showing anything, its version included. On jackal,
> `occ fcias:status` took 55.4 s of wall time for 0.31 s of CPU. Measured
> since: every count is fast warm and slow cold (Analysis 1). Block 1 is
> done: the count is stored and the rule job retakes it hourly.

## Discussion

Decided with the user, 2026-10-01:

- **The panel must not wait on a count that a cold cache makes slow.**
- **Measured by plain SQL on jackal**, not with a `--timings` build.
- **The information first, the counts after it** (the user, on v1.1). The
  panel asks for the version, the database version, the jobs and the
  stored count first. It asks for the live counts only once that request
  has answered. Fired together, a count that warms a cold cache competes
  for the same disk and delays the information it does not depend on.
- **No hourly count by default** (the user, after Block 1). The panel is
  opened for a diagnosis, rarely; a count every hour costs the database
  around the clock to save a few seconds then. The background count stays,
  off by default, for an instance that wants it.
- **Both in Tunables** ("Feineinstellungen", Advanced tab): a switch for the
  background count, saved on click, and the interval, a field with a Save
  button of its own, as the picker's prefill limit has.

Proposed here, open at the gate:

- **One interval for both ways of counting:** how old the stored count may
  get. With the switch on, the rule job keeps it that fresh; with it off,
  the status counts when it is asked and the stored count is older.
- **The interval in minutes** in the interface, seconds in the config,
  bounded to 5 minutes (the rule job's own period) and 7 days.
- **`occ fcias:status` drops its two private counts by default.** The
  filecache count is the 52-second one. `--full` keeps them.

## Analysis

1. **Jackal, 2026-10-01** (MariaDB 10.6.21, database `helioscloud`), each
   query run once:

   | Query | Rows | First run |
   |---|---|---|
   | Hash rows, `meta_key LIKE 'file-checksum-hash-%'` (the panel's "Indexed checksums") | 406,419 | 8.41 s |
   | Queued, grouped, `LIKE 'pending:%'` | 16 | 0.20 s |
   | Untrusted, grouped, `LIKE 'stale:%'` | 0 | 0.00 s |
   | Stamp rows (`occ` only) | 305,869 | 7.28 s |
   | `COUNT(*)` over `oc_filecache` (`occ` only) | 2,308,282 | 52.53 s; 0.30 s run again |

   The indexes are there and are used; the slow first runs are pages read
   from disk (NOTE StatusSplit v1.2, the plans on helioscloud).
2. **What Block 1 left** (`[TASK] The indexed-checksum count is kept in the
   background`):
   - `StatusService::getHashRowCount()` gives the stored count and its
     time, from the `checksum_count` job's record, and counts only when
     none is stored; `recountHashRows()` counts and records.
   - `RuleProcessingJob::countChecksumsIfDue()` recounts when the record is
     older than `checksum_count_interval` (3,600 s), always.
   - `GET /settings/status` and `GET /api/v1/status` give `rowCount` and
     `rowCountAt`.
3. **Tunables today** (`TunablesSection.vue`): one field, the prefill
   limit, loaded from `GET /settings/global` and saved through
   `PUT /settings/global`, which leaves absent fields alone. The section's
   hint reads "Numbers that shape how the interface behaves", which a
   switch for a background job does not fit. The section has no spec.

## Implementation Plan

### Block 1: the stored count — done

Committed as `[TASK] The indexed-checksum count is kept in the background`.

### Block 1b: the count when asked, the background count a switch

1. **`ConfigLexicon`:** `checksum_count_background`, BOOL, default false.
   `checksum_count_interval` keeps its key and default; its description
   says it bounds the stored count's age either way.
2. **`RuleProcessingJob::countChecksumsIfDue()`** returns at once while the
   switch is off.
3. **`StatusService::getHashRowCount()`** counts when none is stored or the
   stored one is older than the interval; otherwise it gives the stored
   one. With the switch on, the job keeps it fresh and this never counts.
4. **`SettingsController`:**
   - `getAdminOptions()` gives `checksumCountBackground` and
     `checksumCountInterval` (seconds);
   - `saveAdminOptions()` takes either, the interval bounded to 300 and
     604,800 s, as the prefill limit is bounded.
5. **`TunablesSection.vue`:**
   - the hint: "Settings that shape how the app behaves. The defaults suit
     most servers.";
   - a switch (`NcCheckboxRadioSwitch`), "Count the indexed checksums in
     the background", with a help popover; it saves on click, and on a
     failure goes back and says so;
   - a field, "Checksum count: renew after (minutes)", with its own Save
     button, as the prefill limit has, and a help popover saying what the
     interval does with the switch on and off.
6. **Texts:** the hint, the two labels, the two help texts, in every
   language kept here.
7. **Docs:** `docs/api-v1.md` (`rowCount` is at most the interval old);
   `docs/FAQ.md`, where it names the tunables.
8. **Tests:**
   - the job does not count with the switch off, and counts when due with
     it on (unit);
   - the service counts a stale count, and not a fresh one (unit);
   - the controller gives and takes both settings, the interval bounded
     (unit);
   - `TunablesSection.spec.ts`, new: the switch saves on click and goes
     back on a failure; the interval saves on its button only, in seconds.

**Verification:** phpunit `unit` and `integration` on harness instance 34;
vitest, lint, typecheck, stylelint; `scripts/l10n.sh check`; Psalm, ECS.

### Block 2: the information first, the counts after

1. **Two admin-only routes:**
   - `GET /settings/status`: version, database version, jobs, banner flag.
     Nothing in it reads more than app config and `SELECT VERSION()`.
   - `GET /settings/status/queues`: queued by mode, untrusted by reason,
     and the hash count with its time, which is where a stale count is
     taken (Block 1b, step 3).
2. **`useAdminSettings.loadStatus()`:**
   - asks for `/settings/status`, shows what it brings, then asks for
     `/settings/status/queues`;
   - the count rows show a small loading mark until their answer lands;
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
     version is already shown and the count rows still load.
5. **Docs:** the user guide, if it describes the panel.

**Verification:** vitest, lint, typecheck, stylelint; phpunit `unit`;
`status.cy.js` on harness instances 34 and 35.

### Block 3: `occ fcias:status`

1. The filecache count and the stamp count only with `--full`, in the
   text and JSON outputs alike.
2. "Indexed checksums" through `getHashRowCount()`, with its time, in both
   outputs.
3. Tests: `ShowStatusTest`, both outputs, with and without `--full`.

**Verification:** phpunit `unit`; `occ fcias:status` and `--full` on
harness instance 34.

### Block 4: changelog

Each block amends the `[Unreleased]` bullet Block 1 wrote, so that it reads
as the net change. The end state, `### Changed`:

```text
- Admin status panel: the information shows at once, the counts after.
- `GET /api/v1/status`: `rowCountAt`, the time of `rowCount`.
- Tunables: the checksum count's interval, and a switch to take it in
  the background.
- `occ file-checksum-search:status`: the filecache and stamp counts only
  with `--full`.
```

Not in scope:
- A recount on Refresh beyond the interval. The count's time says how old
  it is.

## Proposed commit messages

Written per block at its gate:

- Block 1b: `[TASK] The checksum count is taken when asked, in the background only if switched on`
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
- v1.3 (2026-10-01): Block 1 done. Block 1b added: the hourly count off
  by default and a switch for it in Tunables, saved on click; the interval
  editable there, with a Save button of its own; the status counts when
  asked and the stored count is older than the interval. Block 2 takes the
  hash count into the second request. The changelog is amended per block.
