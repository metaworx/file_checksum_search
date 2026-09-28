# AP ListingSteps v1.2: published, then everywhere

> **Status: proposal, 2026-09-28.** The `listed` job waits for the store and
> for every mirror in one step, so the run's page shows one outcome for two
> different facts. This plan gives each its own step: the store's own
> database first, then every copy of the listing clients read.

## Discussion

The maintainer's request: two steps, each saying one thing on the run's page.

1. **The store has the upload**: publishing is done.
2. **Every host lists it**: clients can update, whichever host the store
   sends them to.

v1.1 took step 1 from the first host whose `apps.json` named the upload.
Observed since, for the 07:50 upload of 0.20.3:

- The store's releases page (`/apps/<id>/releases`) is rendered from its
  database and showed the upload at once. It shows, per platform, the
  version and channel, the update time, the certificate, the signature, the
  Nextcloud and PHP ranges, and the download link.
- Every JSON listing is cached on its own schedule:
  - `apps.json` on the store took 10.8 minutes, on `garm2` and `garm3` 40.5.
  - `platform/33.0.0/apps.json` had the upload after 35 minutes;
    `platform/34.0.0` still carried the 06:59 upload
    (`last-modified 07:26:04`).

So the releases page is the only immediate view of what the store accepted,
and `apps.json` on every host is what clients read. The maintainer also asked
for the description to be verified.

## Analysis

1. **`watch` reads the store's releases page.** One request per round covers
   every platform: the page has a section per Nextcloud version.
   - For each platform the manifest allows (from `min-version` to
     `max-version`: 33 and 34), the section must list this version and
     channel, updated at or after the upload.
   - Each section's entry is compared: download, signature, certificate,
     and the Nextcloud and PHP ranges.
   - The page is HTML, so its parsing is anchored on what it shows: the
     section anchors, the release titles, and the table rows by label. A
     layout change fails the step and does not pass it wrongly.
   - The update time is to the minute (`Sept. 28, 2026, 7:50 a.m.`, UTC),
     which the two-minute clock margin covers.
2. **`watch --mirrors` reads `apps.json`** on the store and every host it
   redirects to, as in v1.1.
   - Each host's entry is compared in full, now including name, summary and
     description against the manifest, with whitespace normalised; the
     store trims it.
   - The first scan makes 20 HEAD requests (a missed host about once in
     3,000 scans, against once in eleven with 6), and a host a listing
     request is redirected to is added.
3. **The steps run independently.** "Every host lists it" runs whether or
   not "The store has the upload" passed, so a change to the store's page
   cannot hide whether clients can update. Both need the signature and
   certificate step. Timeouts: 10 minutes for the page, 300 for the hosts.
4. **Output.**
   - The first line names what is watched: the platforms, or the hosts
     found before any listing is requested.
   - Every round prints each platform or host still to check, prefixed with
     the UTC time and the minutes since the upload. One that matched drops
     out.
   - A match reads "`<platform or host>`: version and signature match the
     upload".
5. **One comment, on success only,** from `--comment` on the second step.
   GitHub mails about a failed run on its own.
6. **From v1.1, unchanged:** one `--timeout`, minutes counted from the
   upload, and no state passed between the steps.

## Implementation Plan

1. **[TASK] The store's listing is awaited in two steps: published, then
   everywhere.**
   - `tests/e2e/store/appstore.py`:
     - `watch` reads the releases page (analysis 1), `--mirrors` reads
       every host (analysis 2);
     - one comparison for both, each field normalised as the source
       writes it;
     - the output of analysis 4, and `--comment` of analysis 5;
     - the header's usage and the `--help` texts follow.
   - `.github/workflows/publish.yml`: "The store has the upload" and
     "Every host lists it", independent, after the signature step
     (analysis 3).
   - `docs/app-store-publishing.md`: the two steps, and the Mermaid node.
   - CHANGELOG: exempt, CI only.

   **Verification:**
   - `actionlint` on the workflows.
   - Against the live store, for the 07:50 upload of 0.20.3:
     - `watch` passes for Nextcloud 33 and 34;
     - `watch --mirrors` passes on every host, including the texts;
     - with a time after every upload, both fail at a short timeout.
   - A tampered expectation (another signature) fails both with exit 2,
     naming the field.
   - `--comment` against a local stub for the API: one POST, on success
     only.
   - In CI: the next release.

## Proposed commit message

`[TASK] The store's listing is awaited in two steps: published, then everywhere`

In full at the commit gate.

## Open decisions

- **Folding it into 0.20.3.** Proposed: no, as in v1.1.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.0    | 2026-09-28 | Initial plan: the `listed` job's single wait split into "the store lists the upload" and "every host lists it". |
| v1.1    | 2026-09-28 | The maintainer's syntax: `watch` with `--mirrors` (compare every host, not only the first) and `--comment` (the script posts the comment) instead of two subcommands; no state passed between the steps; one `--timeout`. |
| v1.2    | 2026-09-28 | Step 1 reads the store's releases page, the only immediate view of what it accepted, per platform; `apps.json` and the platform listings are cached. Name, summary and description compared on every host. The steps run independently. Only hosts and platforms still to check are printed each round, and one comment is posted, on success only. |
