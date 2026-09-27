# AP StoreVerify v1.0: publish behind review, verify from the store

> **Status: proposal, 2026-09-27.** Enabling 0.20.2 from the Apps page on
> jackal (Nextcloud 33.0.5) spun without end. The log says why, and the
> cause is the app's. This plan fixes it, puts the store upload behind a
> reviewed GitHub environment, and adds a suite that installs the
> published release from the store, by occ and through the Apps page,
> before running the e2e suite against it.

## Discussion

**What happened on jackal**, from its audit and Nextcloud logs:

- 22:37:12 `occ app:install --allow-unstable --keep-disabled` took 16
  seconds; it downloads and unpacks, and runs no install steps.
- 22:38:33 the Apps page's enable request (`POST /settings/apps/enable`,
  user `mdr`) started. Nextcloud runs `Installer::installApp` in that
  request: migrations, then the app's post-migration repair step.
- 22:38:36 to 22:54:25 the request read, for 298,691 files one at a time,
  the metadata document and the checksum stamp: the pair of queries
  `MetadataService::backfillHashes()` makes per file. That is the
  `rebuild-from-filecache` repair step, walking every filecache row that
  carries a sync client's checksum. It is marked expensive but not
  manual-only, so it runs on every install and every enable.
- 22:55:35 the app was enabled and the audit log recorded it. The reverse
  proxy's `proxy_read_timeout` is 240 seconds, so the browser had lost
  the response thirteen minutes earlier and the button never stopped
  spinning. The app has been enabled since.

The instance also logs at level 0, so that one request wrote 597,404
debug lines; that slows every request and is the server's to change.

**Decisions taken at the evaluation:** the store upload waits for a
reviewer in a GitHub environment rather than on a variable; the server
setup for CI becomes a composite action of our own in the same plan,
which also removes the last Node 20 warning (`setup-php` pinned inside
`nextcloud/setup-server-action`).

## Analysis

1. **The repair step's contract.** Everything a web request can trigger
   must be bounded. `clear-disowned` and `orphaned-metadata` take a batch
   limit, `unindexed-hashes` is manual-only, `rebuild-from-metadata` runs
   its cheap probe unless asked; `rebuild-from-filecache` alone walks the
   whole filecache inline. The backfill is still wanted on a fresh
   install — the checksums clients already sent become searchable — so it
   moves to a queued background job that pages through under cron.
2. **The store job.** Today the store post is a step of the release job,
   skipped green when `APPSTORE_PUBLISH` is unset. As its own job with
   `environment: appstore`, it waits visibly for approval and needs no
   variable. It downloads the versioned asset from the release — the URL
   the store will fetch — signs it and posts it; `package.sh --appstore`
   already fails on a refusal.
3. **The post-publish suite has to wait.** After 0.20.2 was accepted, the
   store's listing named 0.20.1 for a while. The suite polls the platform
   listing for the version, then asserts the installed version.
4. **Nightlies are hidden on the stable channel.** The occ variant uses
   `app:install --allow-unstable`; the web variant sets
   `updater.release.channel` to `daily`, or the Apps page does not list
   the app. The CI setup turns the app store off; this suite leaves it on.
5. **What the suite proves, and what not.** It tests the shipped tarball
   — built `js/`, no dev packages, the store's signature check — which no
   job does now, and the Apps page path that failed on jackal. A CI
   instance holds a handful of files, so it would not have reproduced the
   hang; block 1 carries its own test for that.

## Implementation Plan

1. **[FIX] Enabling the app no longer walks the filecache in the request.**
   `lib/Migration/RepairQuietStart.php`: `rebuild-from-filecache`, run as
   part of a whole repair, queues a one-off job and returns; named on its
   own (`occ fcias:repair --step rebuild-from-filecache`) it still runs
   inline. A new `lib/BackgroundJob/FilecacheBackfill.php` (`QueuedJob`)
   copies one page per run and re-queues itself with the last file id
   until a page comes back empty; `HashIndexService::backfillFromFilecache()`
   gains the start id and a limit. Tests: the whole repair queues and does
   not call the backfill; the job pages and re-queues; the step by name
   still runs inline. CHANGELOG: a Fixed bullet.
   **Verification:** PHP unit and integration suites; locally, the Apps
   page enable returns at once and `occ background-job:list` shows the
   job; on jackal, if you like, `occ fcias:repair --list` shows the step.

2. **[TASK] CI sets up its Nextcloud with a composite action of its own.**
   `.github/actions/nextcloud/action.yml`: clone the server branch,
   `shivammathur/setup-php@v2`, database service, `maintenance:install`,
   the e2e settings as inputs. `test.yml`'s PHPUnit and Cypress jobs use
   it; `nextcloud/setup-server-action` goes. CHANGELOG: exempt.
   **Verification:** the test run green on both server versions with no
   Node 20 annotation.

3. **[TASK] The store upload is its own job, behind the `appstore`
   environment.** `publish.yml`: the release job keeps build, signing and
   the GitHub release; a `store` job `needs` it, carries
   `environment: appstore`, checks out the tag, downloads the versioned
   asset into `build/`, and runs `package.sh --appstore [--nightly]`.
   `APPSTORE_PUBLISH` is no longer read. `docs/app-store-publishing.md`
   follows. You create the environment with yourself as required
   reviewer, and may move `APPSTORE_KEY` and `APPSTORE_TOKEN` into it.
   CHANGELOG: exempt.
   **Verification:** a dry tag shows the store job waiting for review;
   rejecting it leaves the GitHub release and the store untouched.

4. **[TASK] A post-publish suite installs from the store and runs e2e.**
   `.github/workflows/post-publish.yml`: on `workflow_run` of the publish
   workflow's success for a tag, and on `workflow_dispatch` with a
   version. A first job polls the store's platform listing until the
   version appears (30 minutes at most). Then a matrix of Nextcloud 33
   and 34 by `occ` and `web`: the instance from block 2 with the app
   store on; `occ app:install --allow-unstable file_checksum_search`, or
   the channel set to `daily` and a new spec,
   `tests/e2e/store/install-from-apps-page.cy.js`, that searches the Apps
   page, clicks Download and enable, passes the password dialog and waits
   for the app to show as enabled; the installed version asserted; then
   the e2e suite, without its screenshot spec, against the installed copy.
   CHANGELOG: exempt.
   **Verification:** run by hand against 0.20.2 on the store now: the occ
   variant green; the web variant is expected green on CI's small
   instance, and its failure there would be a second defect.

5. **[RELEASE] The next patch.** Carries block 1's fix; the first release
   through review, store and post-publish suite. On your word.

## Proposed commit messages

Block 1: `[FIX] Enabling the app no longer walks the filecache in the request`
Block 2: `[TASK] CI sets up its Nextcloud with a composite action of its own`
Block 3: `[TASK] The store upload waits for a reviewer`
Block 4: `[TASK] A post-publish suite installs from the store and runs e2e`
Block 5: `[RELEASE] vX.Y.Z`

Each in full at its commit gate.

## Open decisions

- **Block 1's alternative:** make the step manual-only instead of queuing
  it. Simpler, but a fresh install then never copies the clients'
  checksums unless someone runs the step.
- **Where the web variant runs the suite:** after the Apps page install,
  the full suite, or only a smoke subset (sidebar, duplicates, search).
  The full suite is proposed; it doubles the Cypress time, which is free.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.0    | 2026-09-27 | Initial plan: the jackal enable hang traced to `rebuild-from-filecache` in the install path; store upload behind an environment; a post-publish suite by occ and by the Apps page; CI's own server setup. |
