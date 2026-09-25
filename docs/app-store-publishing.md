# Nextcloud App Store Publishing

This document describes how FCIAS (`file_checksum_search`) is built, signed, and
published to the Nextcloud App Store.

## Pipeline overview

Publishing is driven by:

- [`package.sh`](../package.sh) — builds and packages the app, and signs the
  archive when a certificate is available.
- [`.github/workflows/publish.yml`](../.github/workflows/publish.yml) — GitHub
  Actions release pipeline.
- [`.gitlab/gitlab-ci.yml`](../.gitlab/gitlab-ci.yml) — the GitLab CI pipeline, parked (see below).

Both CI platforms produce the same artifacts:

- `build/file_checksum_search.tar.gz` — unversioned archive.
- `build/file_checksum_search-<version>.tar.gz` — versioned archive.
- `build/file_checksum_search-<version>.tar.gz.signature` — SHA-512 signature
  (only produced when signing is configured).

## Signing

The App Store requires a SHA-512 signature of the archive, generated with:

```bash
openssl dgst -sha512 -sign ~/.nextcloud/certificates/file_checksum_search.key \
  file_checksum_search-<version>.tar.gz | openssl base64
```

`package.sh` performs this automatically. The signing key is resolved in order:

1. `APPSTORE_KEY` / `APPSTORE_CERT` environment variables (PEM content, or a
   path to a file — GitLab file-type variables set the path).
2. `~/.nextcloud/certificates/<app_id>.key` and `.crt` (local development).

The signature is written next to the archive as `<archive>.signature`, printed
to stdout for pasting into the upload form, and verified against the
certificate. If a key is available but no matching certificate is found,
signing now **fails loudly** rather than silently skipping verification —
either provide both, or provide neither to fall back to an unsigned build
(Option B).

## Publishing to the App Store (REST API)

`package.sh --appstore` publishes the built release via the App Store REST API
(`POST /api/v1/apps/releases`). It requires:

- The archive hosted at a public HTTPS URL (`DOWNLOAD_URL` env var).
- A signing key (resolved as described above).
- An App Store API token: `API_TOKEN` env var or `~/.nextcloud/API_TOKEN.txt`.

Add `--nightly` to publish the release as a nightly:

```bash
DOWNLOAD_URL=https://example.com/file_checksum_search.tar.gz \
  bash package.sh --appstore --nightly
```

> Note: the app id must already be registered on the App Store (one-time,
> `POST /api/v1/apps`) before the first release can be published.

## GitHub Actions

[`publish.yml`](../.github/workflows/publish.yml) runs when the test workflow
has finished for a tag, on `workflow_run`, or by hand with
`workflow_dispatch` and a tag name. It does not run on `release:
published`: a release made with `GITHUB_TOKEN` fires no `release` event, so
a workflow waiting for one would wait for a release the tag's own run
creates. One job, when the test run succeeded for a `v*` tag:

1. Checks out the tag's tree with the guidelines submodule, and installs
   Node.js 24 and PHP 8.2.
2. Reads the version from `appinfo/info.xml` and refuses a tag that does not
   name it.
3. Runs `npm ci`, then `npm run lint` and `npm run stylelint` — a release is
   the one build that cannot be taken back, so it is gated as a push is.
4. Runs `bash package.sh` with the `APPSTORE_KEY`/`APPSTORE_CERT` secrets in
   the environment: builds the frontend, packages, and signs the versioned
   archive when both are set (unsigned when neither is).
5. Classifies the tag: `v0.Y.Z` and any `-suffix` tag are nightlies,
   `vX.Y.Z` with X ≥ 1 is stable, anything else publishes nothing.
6. Creates the GitHub release for the tag, or updates it, with the changelog
   section as its body (`changelog.sh notes X.Y.Z`) and both tarballs and
   the signature as its assets; a nightly is marked as a pre-release.
7. If `APPSTORE_PUBLISH=true`, posts the **versioned** tarball's release-asset
   URL and its signature to the App Store (`POST /api/v1/apps/releases`).
   The assets are up before the store is told, since it fetches the URL on
   being told.

Signing requires:

- Secrets `APPSTORE_CERT` (certificate PEM) and `APPSTORE_KEY` (private key
  PEM, as it is — a secret may hold newlines). Both must be the same
  certificate/key pair registered for the app in the portal — a mismatch
  causes the store to reject the upload.

Publishing (step 7) additionally requires:

- Secret `APPSTORE_TOKEN` (App Store API token).
- Variable `APPSTORE_PUBLISH` = `true`.

The tag reaches GitHub through GitLab's push mirror, so the release procedure
is `changelog.sh cut`, then `changelog.sh tag X.Y.Z create --push
--push-commits` against `origin`; the test run starts on the mirrored tag
within a minute and the publish run after it.

## GitLab CI

Parked since 2026-09-25: the pipeline file sits in
[`.gitlab/`](../.gitlab/README.md), where GitLab does not read it, because
its minutes are billed and GitHub runs the wider matrix for free. GitLab
stays the code host and the source of the push mirror that carries every
commit and tag to GitHub, where the workflows above run. The README beside
the file says how to run it again.

## Manual upload (Option B fallback)

When no signing key is configured, the pipeline still produces a valid unsigned
tarball. To publish manually:

1. Download `file_checksum_search.tar.gz` from the release.
2. Go to the Nextcloud App Store developer portal
   (`https://apps.nextcloud.com/developer/`).
3. Upload the archive and paste its signature (see "Signing" above). Generate
   the signature locally if signing is not configured in CI.
4. Track the release status in the app store dashboard.

## Local packaging

```bash
bash package.sh
```

Place your certificate in `~/.nextcloud/certificates/file_checksum_search.{crt,key}`
to have `package.sh` sign the archive locally. The signature is written to
`build/file_checksum_search-<version>.tar.gz.signature`.

To publish a release directly from the command line (archive must already be
hosted at a public HTTPS URL):

```bash
DOWNLOAD_URL=https://example.com/file_checksum_search.tar.gz bash package.sh --appstore
```
