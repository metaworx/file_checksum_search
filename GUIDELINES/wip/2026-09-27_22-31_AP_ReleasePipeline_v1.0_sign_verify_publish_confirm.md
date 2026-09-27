# AP ReleasePipeline v1.0: sign, verify, publish, confirm

> **Status: proposal, 2026-09-27.** The first release through the new
> chain, 0.20.3, reached the store unsigned on GitHub and was verified
> against the wrong listing. This plan separates building from signing,
> proves a release installs before the store hears of it, and after the
> upload only confirms the store's distribution. It replaces the
> post-publish suite of AP StoreVerify v1.0 block 4, whose checks and
> specs it reuses.

## Discussion

**What 0.20.3 showed.**

- The build job ran without the key: `APPSTORE_KEY` had moved into the
  `appstore` environment, so the tarball was built unsigned and the
  GitHub release carries no `.signature`. The store job signed on its own
  and the store accepted the upload (HTTP 201).
- That store job signed whatever the public release asset held at the
  time: anyone able to edit releases could have swapped it between the
  two jobs.
- The post-publish suite waited on `api/v1/platform/<version>/apps.json`,
  which listed 0.20.3 at once, but a server reads `api/v1/apps.json`. The
  store answers that with redirects to mirrors (`garm2`, `garm3`, …), each
  with its own copy: at 20:20 UTC the store's own file (written 20:01)
  listed 0.20.3 and `garm3` still listed 0.20.2; by 20:30 `garm2` listed
  0.20.3. The Nextcloud 33 installs got 0.20.2 and failed their checks.

**Decisions taken in the discussion.**

- Signing happens on the bytes our build produced, in a job that never
  runs third-party code: `npm ci` runs install scripts of about a
  thousand packages, and a step's secrets are only safe from them in
  another job.
- Two environments, one secret each: `signing` holds the private key,
  `appstore` holds the store token. The publish job verifies with the
  public certificate and cannot sign.
- Both install variants run **before** the upload, against a fake store
  that lists the release: Nextcloud reads the store's address from
  `appstoreurl` and treats any store alike, so the server runs its real
  install path — listing, download from the GitHub release, certificate
  and signature checks, install steps — for `occ app:install` and for the
  Apps page.
- After the upload, a check waits until the store and every mirror a
  redirect leads to list the release, then an install from the real
  store confirms it.

## Analysis

1. **The build job gets no secrets and no write access.** With
   `contents: write`, a compromised dependency could use the job's token
   to change releases or tags. It builds and hands the tarballs on as an
   artifact of the run, nothing else.
2. **The sign job creates the release.** It checks out the tag (our code,
   the guidelines submodule for `changelog.sh notes`), takes the artifact,
   signs it with `package.sh --sign-only`, verifies, and creates the
   GitHub release with the tarballs, the signature and the notes. What is
   published is what was signed, so no hash comparison between jobs is
   needed.
3. **`package.sh --appstore` must stop signing.** Today it signs with the
   key it is given. It becomes: verify `<archive>.signature` against the
   certificate (`--verify`, usable on its own), then post it. A local
   release by hand is `--sign-only`, then `--appstore`.
4. **The fake store** is a static `api/v1/apps.json` and
   `api/v1/categories.json` served on `127.0.0.1`: the app's entry copied
   from the real listing (certificate, metadata) with one release — the
   version, the GitHub asset URL, the signature, `isNightly`, and the
   platform and PHP ranges from `appinfo/info.xml`. The test server needs
   `appstoreurl` pointed at it and `allow_local_remote_servers` set,
   since Nextcloud refuses local addresses by default; the tarball still
   comes from GitHub.
5. **The listing check** follows the store's redirects on fresh
   connections, remembers every host it was sent to, and uses each host's
   ETag so an unchanged 14 MB file costs a 304. It passes once every host
   seen lists the release on several consecutive rounds, fails after
   three hours, and writes the time it took into the run's summary: the
   interval is measured on every release.
6. **One reusable workflow for both install runs.** `post-publish.yml`
   becomes `install-check.yml`, called with the store to use (`fake` or
   `real`) and the modes to run; the nightly-only switches, the install
   check and the smoke test stay as they are.
7. **Environments on GitHub** are the maintainer's to set: `signing`
   with `APPSTORE_KEY`, `appstore` with `APPSTORE_TOKEN` only, reviewers
   where wanted. `APPSTORE_CERT` stays a repository secret or becomes a
   variable; it is public.

## Implementation Plan

1. **[TASK] `package.sh` verifies and posts a signature it did not make.**
   `--verify` checks `<archive>.signature` against the certificate and
   fails loudly; `--appstore` runs that check and posts the file, and no
   longer signs. `docs/app-store-publishing.md` and the script's header
   follow. CHANGELOG: exempt, the script is not shipped.
   **Verification:** locally with the maintainer's certificate and key:
   `--sign-only` on a built tarball, `--verify` passes, a flipped byte in
   the archive or the signature fails it; `--appstore` refuses an archive
   without a verified signature before any request.

2. **[TASK] Build, sign and publish are three jobs with separate
   secrets.** `publish.yml`: `build` (read-only, no secrets) uploads the
   tarballs as a run artifact; `sign` (environment `signing`,
   `contents: write`) signs them and creates the GitHub release with notes
   and signature; `store` (environment `appstore`) downloads tarball and
   signature from the release, verifies, posts. CHANGELOG: exempt.
   **Verification:** a dry tag `vX.Y.Z-rc1` with the `appstore`
   environment requiring review: the release carries a signature that
   verifies against the certificate, and the store job waits; rejected,
   nothing reaches the store.

3. **[TASK] A release installs from a fake store before the upload.**
   `tests/e2e/store/fake-store.py` writes and serves the listing;
   `post-publish.yml` becomes `install-check.yml` with a `store` input; the
   `nextcloud-e2e` action gains the `appstoreurl` and local-address
   settings; `publish.yml` runs the check after `sign` and makes `store`
   need it. CHANGELOG: exempt.
   **Verification:** the dry tag's run: four installs from the fake store
   — `occ` and the Apps page, Nextcloud 33 and 34 — with all nine install
   checks and the smoke test green.

4. **[TASK] After the upload, the store's listing is awaited on every
   mirror, then installed from.** A `listed` job in `publish.yml` as in
   analysis 5; then `install-check.yml` against the real store, `occ` on
   both servers. CHANGELOG: exempt.
   **Verification:** run by hand for 0.20.3, which the store has since
   distributed: the listing check passes at once and reports the hosts;
   both installs pass all nine checks.

5. **[RELEASE] The next patch.** The whole chain on a real release, when
   something is to be released; not cut for the pipeline's sake.

## Proposed commit messages

Block 1: `[TASK] package.sh verifies and posts a signature it did not make`
Block 2: `[TASK] Build, sign and publish run as three jobs with separate secrets`
Block 3: `[TASK] A release installs from a fake store before the upload`
Block 4: `[TASK] After the upload, every mirror's listing is awaited, then installed from`

Each in full at its commit gate.

## Open decisions

- **The v0.20.3 signature.** The GitHub release lacks it; the maintainer
  can add it by hand (`package.sh --sign-only` on the downloaded asset,
  then `gh release upload`), or leave 0.20.3 as the one release without.
- **Which modes run against the real store** after the upload: `occ` on
  both servers is proposed; the Apps page can be added.
- **AP StoreVerify v1.0** is complete but for its block 4, which this plan
  replaces; retiring it, and the two AP GitHubRelease files, is the
  maintainer's call.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.0    | 2026-09-27 | Initial plan from the 0.20.3 release: signing split from building, a pre-upload install from a fake store, a post-upload listing check across mirrors. |
