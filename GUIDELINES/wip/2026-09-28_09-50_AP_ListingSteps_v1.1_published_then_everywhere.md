# AP ListingSteps v1.1: published, then everywhere

> **Status: proposal, 2026-09-28.** The `listed` job waits for the store and
> for every mirror in one step, so the run's page shows one outcome for two
> different facts. This plan gives each its own step, both run by the one
> `watch` command with flags.

## Discussion

The maintainer's request: the two waits become two steps, each saying one
thing on the run's page.

1. **The store lists the upload**: publishing is done. The first host that
   lists this upload has it, and its entry says what the installs before the
   upload tested.
2. **Every host lists it**: clients can update, whichever host the store
   sends them to.

v1.1 follows the maintainer's syntax: one `watch` command with two flags
rather than two subcommands, and the comment posted by the script itself.

## Analysis

1. **`watch` without `--mirrors`** waits for the first host that lists this
   upload and compares that host's entry. Exit codes: 0 when it matches, 1
   when no host lists the upload in time, 2 when it differs.
2. **`watch --mirrors`** waits until every host seen lists this upload and
   compares each host's entry, not only the first. Exit codes: 0 once all
   match, 1 at the timeout, 2 as soon as one differs. It needs nothing from
   the first step, so no state is passed between them. A green step means
   clients can update wherever the store sends them; a timeout turns it
   red. This replaces the earlier rule that convergence is reported, not
   gated. The upload itself stays good, and the first step says so.
3. **`--comment MENTION`** posts the outcome on the release commit,
   mentioning MENTION. It uses the variables a GitHub run provides:
   `GITHUB_REPOSITORY`, `GITHUB_SHA`, `GITHUB_SERVER_URL`, `GITHUB_RUN_ID`,
   and `GH_TOKEN` for the API. The workflow's Python heredoc and its
   `--result` file go.
   - Both steps take the flag. The first comments "published" or why not;
     the second comments "every host" or which host is missing or differs.
     That is two e-mails per release, each sent when its fact becomes true.
   - Where one is enough, the first step drops the flag, and a failure there
     is left to GitHub's own mail about a failed run.
4. **One `--timeout` in minutes** (default 30) replaces `--first-timeout`
   and `--all-timeout`. The workflow passes 30 to the first step and 300 to
   the second.
5. **Times count from the upload.** With `--since`, the log, the summary and
   the comment report minutes since the upload, not since the step started,
   so the two steps' numbers line up and say how long clients waited.
   Without `--since`, in a run by hand, they count from the step's start.
6. **The workflow.** The `listed` job's steps are:
   - "The store lists the upload": `watch --comment`;
   - "Every host lists it": `watch --mirrors --comment`, only after the
     first succeeds.

   Each step writes its own summary; GitHub keeps one per step.

## Implementation Plan

1. **[TASK] The store's listing is awaited in two steps: published, then
   everywhere.**
   - `tests/e2e/store/appstore.py`: `--mirrors`, `--comment`, `--timeout`,
     and times since the upload, as in analysis 1 to 5. Finding the store's
     redirect hosts and asking one host whether it lists this upload become
     two functions. The header's usage and the `--help` texts follow.
   - `.github/workflows/publish.yml`: the two steps of analysis 6. The
     comment step and its heredoc go.
   - `docs/app-store-publishing.md`: the paragraph and the Mermaid node
     name the two steps.
   - CHANGELOG: exempt, CI only.

   **Verification:**
   - `actionlint` on the workflows.
   - Against the live store, for the 0.20.3 upload of the run in progress,
     once approved and with its upload time: `watch` exits 0 on the first
     host, and `watch --mirrors` exits 0 once every host matches. Both
     report minutes since the upload.
   - With a time after every upload, `watch` exits 1 at a short timeout.
   - `--comment` with a stub for the API: the text for each outcome.
   - In CI: the next release.

## Proposed commit message

`[TASK] The store's listing is awaited in two steps: published, then everywhere`

In full at the commit gate.

## Open decisions

- **Folding it into 0.20.3.** Proposed: no. The run in progress is the first
  CI run of `--since`; moving the tag again would restart it and re-post
  0.20.3 a fourth time. The split lands on master after the release.
- **One comment or two** (analysis 3). Proposed: two.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.0    | 2026-09-28 | Initial plan: the `listed` job's single wait split into "the store lists the upload" and "every host lists it". |
| v1.1    | 2026-09-28 | The maintainer's syntax: `watch` with `--mirrors` (compare every host, not only the first) and `--comment` (the script posts the comment) instead of two subcommands; no state passed between the steps; one `--timeout`. |
