# AP DriftAlerts v1.0: notify when a checksum drifts

## Discussion

- **Origin.** Carried over from AP SuggestedFeatures v1.5 (2026-08-22), at
  the user's request of 2026-09-30.
- **Not scheduled.** It is re-confirmed with the user before it runs.

## Analysis

**What drift means.** A recalculation or a verification finds the stored
checksum wrong: the content changed by a path the app's listeners did not
see. A new duplicate is not drift, and is not notified: it is not in itself
a problem.

**Today:** there is no notifier. A mismatch shows only where someone looks,
as a verification's "now: …" line.

**Design:**
- **A notifier.** An `INotifier`, registered through
  `registerNotifierService()`, links into Files.
- **Where drift is noticed.** In `HashCalculationService`, where
  recalculation and verification have the old and the new value side by
  side. A `force` run is not drift: it recalculates by request.
- **No storms.** A run that touches thousands of files sends one summary per
  owner, not one notification per file. A collector gathers the drift events
  during a job, a command or a request, and flushes them at its end.
- **Opt-out.** A personal setting next to the preferred algorithm
  (`PreferenceSection`), on by default.

**Constraints:**
- every new text is translatable, in every language kept here;
- `npm run typecheck` stays at zero errors.

## Implementation Plan

1. **Notifier and collector,** with its flush at the end of a job, a command
   or a request.
2. **Drift reported from recalculation and verification.** Tests: drift
   gives an event; `force` gives none; no earlier value gives none.
3. **The personal setting,** stored as a user preference.
4. **Documentation.** The FAQ, the README and the user guide.

**Verification**, per block: its tests, the PHPUnit suites, Vitest,
`npm run typecheck`, ESLint and `scripts/l10n.sh check`.

## Proposed commit messages

Written per block at its gate: `[TASK]`, with tests and translations in the
same commit.

## Change History

- v1.0 (2026-09-30): first version, carried over from AP SuggestedFeatures
  v1.5.
