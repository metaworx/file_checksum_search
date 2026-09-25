# AP GitHubRelease v1.0: testing and publishing on GitHub

> **Status: proposal, 2026-09-25.** GitHub becomes the only host that
> runs pipelines: the test matrix it already runs, and the build, signing
> and store upload that GitLab does today. GitLab stays the code host,
> the issue tracker and the mirror's source, with CI/CD switched off.

## Discussion

Two things drove this. GitLab's minutes are billed and its Cypress jobs
are the bulk of them, while GitHub's minutes are free here and its
matrix is the wider one. And the store's image proxy does not serve
gitlab.com: every screenshot of 0.20.2 decodes to a file GitLab serves
correctly, and the proxy answers "File not found" for all seven, as it
does for codeberg.org, while it serves the thousand-odd screenshots
hosted on GitHub. No app on the store hosts screenshots on GitLab.

The app's absence from the Apps page and from a plain `occ app:install`
is **not** GitLab's doing. The server's app fetcher drops nightly
releases unless the instance's update channel is `daily` or `git`, or
`app:install --allow-unstable` is given; `beta` admits pre-release
versions with a `-suffix` but not nightlies (`AppFetcher::fetch()`,
stable34, lines 66-67). Every `v0.*` tag is a nightly by this project's
own rule, so the listing shows nothing until 1.0.0, whichever host the
tarball sits on. The tarball itself is fetched from GitLab's package
registry without trouble; after this plan it is a GitHub release asset.

## Analysis

1. **What GitLab does that GitHub does not:** `build` on every push, and
   on a tag `release` (a GitLab Release with artifact links) and
   `publish_appstore` (registry upload, `package.sh --appstore`). GitHub's
   `publish.yml` does the same on a *published GitHub Release*, which
   nothing creates, so it has never run.
2. **Secrets.** GitLab holds `APPSTORE_KEY_B64`, `APPSTORE_CERT` and
   `APPSTORE_TOKEN` as protected variables, with `v*` tags protected.
   GitHub needs `APPSTORE_KEY` (plain PEM; no base64 needed, secrets may
   hold newlines), `APPSTORE_CERT`, `APPSTORE_TOKEN` and the variable
   `APPSTORE_PUBLISH`. `package.sh` reads them as they are.
3. **Ordering on GitHub.** Publishing must follow the tests of the same
   tag run, so it is a job in `test.yml` with `needs:` on the three test
   jobs, not a second workflow. The store fetches the download URL when
   the release is posted, so the assets are uploaded before the store is
   told. `softprops/action-gh-release` creates the release when the tag
   has none, and `changelog.sh notes X.Y.Z` prints its body.
4. **The mirror already carries tags** (`v0.20.2` is on GitHub), so a
   tag pushed to `origin` runs GitHub's tag pipeline within a minute.
   Nothing in the release procedure changes: `changelog.sh cut`, then
   `changelog.sh tag … --push --push-commits`.
5. **Screenshots** move to `raw.githubusercontent.com/metaworx/file_checksum_search/<tag>/docs/Screenshots/…`
   in the manifest; the `RELEASE-PIN` block pins the version whatever the
   host. The README and the two documents keep their GitLab links, which
   render where they are shown.
6. **What goes.** `.gitlab-ci.yml`, its line in `.nc.publish.ignore`, the
   GitLab section of `docs/app-store-publishing.md`; the contract's v2.4.0
   history line stays as history. GitLab's CI/CD is disabled in the
   project settings by hand, so no future file spends a minute.

## Implementation Plan

1. **[TASK] The tag run on GitHub builds, signs, releases and publishes.**
   `.github/workflows/test.yml`: a `publish` job, `if: github.ref_type ==
   'tag'`, `needs: [lint, phpunit, integration-chrome]`, `permissions:
   contents: write`: Node 24 and PHP 8.2, `npm ci`, `bash package.sh` with
   the key and certificate secrets, the tag classified as today (v0.* and
   suffixed tags nightly, vX.Y.Z stable, else skip), the GitHub Release
   created or updated with the tarballs and the signature as assets and
   `changelog.sh notes` as its body, then `package.sh --appstore
   [--nightly]` with the versioned asset's URL when `vars.APPSTORE_PUBLISH
   == 'true'`. `.github/workflows/publish.yml` deleted.
   `docs/app-store-publishing.md`: the GitHub section rewritten to this.
   CHANGELOG: exempt, CI only.
   **Verification:** the workflow parses; a push of `master` runs the
   tests only. Live: a tag with `APPSTORE_PUBLISH` unset on GitHub builds,
   signs and creates the GitHub Release without touching the store.

2. **[FIX] The store's proxy can fetch the screenshots.**
   `appinfo/info.xml`: the seven `<screenshot>` URLs on
   `raw.githubusercontent.com`, inside the pinned block.
   CHANGELOG: a Fixed bullet, the listing's images.
   **Verification:** each URL answers 200 with an image type; a dry-run
   cut pins the block.

3. **[TASK] GitLab keeps no pipeline.** `.gitlab-ci.yml` deleted and its
   `.nc.publish.ignore` line with it; `docs/app-store-publishing.md`
   without the GitLab section, with a note that GitLab is the code host
   and the mirror's source; the contract's CI mentions checked.
   CHANGELOG: exempt. You disable CI/CD in the GitLab project settings.
   **Verification:** a push to `origin` starts no GitLab pipeline; the
   mirror still updates GitHub.

4. **[RELEASE] 0.20.3.** The first release published from GitHub, with
   the listing's images. On your word at its own gate.
   **Verification:** the tag run green through `publish`; the store page
   shows the slideshow and the six stills; `occ app:install
   --allow-unstable file_checksum_search` on the harness's other
   instance installs 0.20.3 from the GitHub asset.

## Proposed commit messages

Block 1: `[TASK] A tag on GitHub builds, signs, releases and publishes`
Block 2: `[FIX] The store's proxy can fetch the screenshots`
Block 3: `[TASK] GitLab keeps no pipeline`
Block 4: `[RELEASE] v0.20.3`

Each in full at its commit gate.

## Open decisions

- **A dry tag for block 1**, such as `v0.20.3-rc1` with `APPSTORE_PUBLISH`
  unset on GitHub, proves the run without a store upload; the tag stays
  as a GitHub Release and is deleted after, or kept. The alternative is
  to trust the workflow and let 0.20.3 be its first run.
- **The GitLab Release** stops with block 3; the v0.13 to v0.20.2
  releases and packages there stay.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.0    | 2026-09-25 | Initial plan: publishing joins the test workflow on GitHub, screenshots move host, GitLab CI goes, 0.20.3 as the first release from GitHub. |
