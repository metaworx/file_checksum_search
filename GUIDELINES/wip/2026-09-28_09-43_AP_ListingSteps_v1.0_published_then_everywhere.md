# AP ListingSteps v1.0: published, then everywhere

> **Status: proposal, 2026-09-28.** The `listed` job waits for the store and
> for every mirror in one step, so the run's page shows one outcome for two
> different facts. This plan gives each its own step.

## Discussion

The maintainer's request: the two waits become two steps, each saying one
thing on the run's page.

1. **The store lists the upload**: publishing is done. The first host that
   lists this upload has it, and its entry says what the installs before the
   upload tested.
2. **Every host lists it**: clients can update, whichever host the store
   sends them to.

## Analysis

1. **Two subcommands instead of `watch`.** `appstore.py listed` waits for the
   first host and compares, then exits: 0 when the entry matches, 1 when no
   host lists the upload in time, 2 when it differs. `appstore.py mirrored`
   continues from the result file `listed` wrote: the hosts seen so far,
   and which of them list the upload. It keeps following the store's
   redirects, and exits 0 once every host seen lists the upload, 1 at its
   timeout. Each takes one `--timeout` in minutes; `--first-timeout` and
   `--all-timeout` go.
2. **Not converging fails the second step.** The note that accompanied AP
   ReleasePipeline v1.0 had convergence reported, not gated. With a step of
   its own, a green "Every host lists it" has to mean clients can update, so
   a timeout turns it red. The upload itself stays good, and the first step
   says so.
3. **Times count from the upload.** With `--since`, both steps report the
   minutes since the upload, not since the step started, so their numbers
   line up and say how long clients waited. Without `--since`, a run by
   hand, they count from the step's start.
4. **The summary and the comment** are written in two parts: the comparison
   after the first step, the hosts table after the second. The comment on
   the release commit says which of the two was reached.
5. **Shared code.** Finding the store's redirect hosts and asking one host
   whether it lists this upload become two functions that both subcommands
   use. The ETags stay per step; the second step's first request to each
   host downloads the full listing once.

## Implementation Plan

1. **[TASK] The store's listing is awaited in two steps: published, then
   everywhere.**
   - `tests/e2e/store/appstore.py`: `listed` and `mirrored` replace
     `watch`, as in analysis 1 to 3 and 5; the header's usage and the
     `--help` texts follow.
   - `.github/workflows/publish.yml`: the `listed` job runs "The store
     lists the upload" and "Every host lists it" (the latter only after the
     former succeeds), then the comment step as now. Timeouts: 30 and 300
     minutes.
   - `docs/app-store-publishing.md`: the paragraph and the Mermaid node
     name the two steps.
   - CHANGELOG: exempt, CI only.

   **Verification:**
   - `actionlint` on the workflows.
   - Against the live store, for the 0.20.3 upload of the run in progress:
     `listed` with its upload time exits 0 once a host lists it, and
     `mirrored` exits 0 once every host does, both reporting minutes since
     the upload.
   - `listed` with a time after every upload exits 1 at a short timeout.
   - The comment text for each outcome, rendered locally.
   - In CI: the next release.

## Proposed commit message

`[TASK] The store's listing is awaited in two steps: published, then everywhere`

In full at the commit gate.

## Open decisions

- **Folding it into 0.20.3.** Proposed: no. The run in progress is the first
  CI run of `--since`; moving the tag again would restart it and re-post
  0.20.3 a fourth time. The split lands on master after the release.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.0    | 2026-09-28 | Initial plan: the `listed` job's single wait split into "the store lists the upload" and "every host lists it". |
