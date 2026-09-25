# AP GitHubRelease v1.1: testing and publishing on GitHub

> **Status: proposal, 2026-09-25.** GitHub becomes the only host that
> runs pipelines: the test matrix it already runs, and the build, signing
> and store upload that GitLab does today. GitLab stays the code host,
> the issue tracker and the mirror's source, with CI/CD switched off.
> v1.1 drops the screenshot block and keeps `publish.yml`.

## Discussion

GitLab's minutes are billed and its Cypress jobs are the bulk of them,
while GitHub's minutes are free here and its matrix is the wider one.

**The screenshots are not this plan's to fix.** The store's proxy has
failed every screenshot URL first published since 2026-09-20, whatever
the host: its sync treats the store's already-proxied URL as the source
and encodes it a second time, so nothing is ever cached under the key the
store links to. The maintainer's upstream fix is drafted
(`nextcloud/usercontent.apps.nextcloud.com`, branch
`fix/unwrap-proxied-screenshot-urls`). Moving the images to GitHub would
change nothing; the manifest keeps its GitLab URLs.

**The app's absence from the Apps page is not GitLab's doing either.**
The server's app fetcher drops nightly releases unless the instance's
update channel is `daily` or `git`, or `app:install --allow-unstable` is
given; `beta` admits `-suffix` pre-releases but not nightlies
(`AppFetcher::fetch()`, stable34, lines 66-67). Every `v0.*` tag is a
nightly by this project's rule, so the listing is empty until 1.0.0 on
any host.

**A release created by a workflow triggers no other workflow.** GitHub
withholds events for what `GITHUB_TOKEN` does, so a `release: published`
trigger never fires for a release the tag run creates. That is why
`publish.yml` has never run and why its trigger, not the file, has to
change: it runs on `workflow_run` of the test workflow, once that has
succeeded for a tag. The tests and the publishing stay two files with
two jobs to read.

## Analysis

1. **What GitLab does that GitHub does not:** `build` on every push, and
   on a tag `release` (a GitLab Release with artifact links) and
   `publish_appstore` (registry upload, `package.sh --appstore`).
2. **Secrets.** GitLab holds `APPSTORE_KEY_B64`, `APPSTORE_CERT` and
   `APPSTORE_TOKEN` as protected variables, with `v*` tags protected.
   GitHub needs `APPSTORE_KEY` (plain PEM; a secret may hold newlines),
   `APPSTORE_CERT`, `APPSTORE_TOKEN` and the variable `APPSTORE_PUBLISH`.
   `package.sh` reads them as they are.
3. **Ordering.** `publish.yml` triggers on `workflow_run` of "Nextcloud
   App Testing Matrix", `types: [completed]`, and its job runs only when
   the run's conclusion is success and its `head_branch` is a `v*` tag.
   It checks out `head_sha`, so it builds the tagged tree. The GitHub
   Release is created or updated by `softprops/action-gh-release` with
   `tag_name`, the tarballs and the signature as assets and
   `changelog.sh notes X.Y.Z` as its body; the store is told last, with
   the versioned asset's URL, since it fetches on being told.
   `workflow_dispatch` stays for a run by hand.
4. **The mirror already carries tags** (`v0.20.2` is on GitHub), so a
   tag pushed to `origin` starts the test run within a minute and the
   publish run after it. The release procedure is unchanged:
   `changelog.sh cut`, then `changelog.sh tag … --push --push-commits`.
5. **What goes.** `.gitlab-ci.yml`, its line in `.nc.publish.ignore`, the
   GitLab section of `docs/app-store-publishing.md`. GitLab's CI/CD is
   disabled in the project settings by hand, so no future file spends a
   minute. The contract's v2.4.0 history line stays as history.

## Implementation Plan

1. **[TASK] `publish.yml` runs after the tests of a tag.** Its trigger
   becomes `workflow_run` on the test workflow plus `workflow_dispatch`;
   the job is conditioned on success and a `v*` `head_branch`, checks
   out `head_sha`, keeps its steps (Node 24, PHP 8.2, `npm ci`, lint,
   `bash package.sh` with the secrets, the tag classified as today), and
   creates the release itself with the notes from `changelog.sh notes`
   before it uploads the assets and posts to the store when
   `vars.APPSTORE_PUBLISH == 'true'`. `docs/app-store-publishing.md`: the
   GitHub section rewritten to this order.
   CHANGELOG: exempt, CI only.
   **Verification:** the workflow parses; the next push of `master`
   starts the test run only. Live: with `APPSTORE_PUBLISH` unset on
   GitHub, a tag builds, signs and creates the GitHub Release and stops
   short of the store.

2. **[TASK] GitLab keeps no pipeline.** `.gitlab-ci.yml` deleted and its
   `.nc.publish.ignore` line with it; `docs/app-store-publishing.md`
   without the GitLab section, with a note that GitLab is the code host
   and the mirror's source; the contract's CI mentions checked.
   CHANGELOG: exempt. You disable CI/CD in the GitLab project settings.
   **Verification:** a push to `origin` starts no GitLab pipeline; the
   mirror still updates GitHub.

3. **[RELEASE] 0.20.3, or the next cut.** The first release published
   from GitHub. On your word at its own gate; nothing in `[Unreleased]`
   asks for one yet.
   **Verification:** the test run then the publish run green; the store
   lists the release with the GitHub asset as its download;
   `occ app:install --allow-unstable file_checksum_search` on the
   harness's other instance installs it.

## Proposed commit messages

Block 1: `[TASK] Publishing runs on GitHub once a tag's tests pass`
Block 2: `[TASK] GitLab keeps no pipeline`
Block 3: `[RELEASE] vX.Y.Z`

Each in full at its commit gate.

## Open decisions

- **A dry tag for block 1**, such as `v0.20.3-rc1` with `APPSTORE_PUBLISH`
  unset on GitHub, proves both runs without a store upload; the tag and
  its GitHub Release are deleted after, or kept. The alternative is to
  let the next real release be the first run.
- **The GitLab Releases** v0.13 to v0.20.2 and their packages stay.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.1    | 2026-09-25 | The screenshot block dropped: the proxy's failure is upstream and host-independent, with the maintainer's fix drafted. `publish.yml` kept and re-triggered on `workflow_run`, since a release made with `GITHUB_TOKEN` fires no `release` event. |
| v1.0    | 2026-09-25 | Initial plan: publishing joins the test workflow on GitHub, screenshots move host, GitLab CI goes, 0.20.3 as the first release from GitHub. |
