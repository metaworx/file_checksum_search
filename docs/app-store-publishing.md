# Nextcloud App Store Publishing

This document describes how FCIAS (`file_checksum_search`) is built, signed, and
published to the Nextcloud App Store.

## Pipeline overview

Publishing is driven by:

- [`package.sh`](../package.sh) — builds and packages the app, and signs the
  archive when a certificate is available.
- [`.github/workflows/publish.yml`](../.github/workflows/publish.yml) — GitHub
  Actions release pipeline.
- [`.gitlab-ci.yml`](../.gitlab-ci.yml) — GitLab CI build/release pipeline.

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
certificate when one is present.

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

On `release: published` (or manual `workflow_dispatch`):

1. Checks out the code and installs Node.js 24 and PHP 8.2.
2. Runs `npm ci` and `bash package.sh` (produces the unsigned tarballs).
3. If `APPSTORE_SIGNING_ENABLED=true`, runs `bash package.sh --sign-only` with
   the `APPSTORE_KEY`/`APPSTORE_CERT` secrets (signs the versioned archive).
4. Uploads the tarballs and (when signed) the signature as release assets.
5. If `APPSTORE_PUBLISH=true`, signs the unversioned tarball and posts the
   release to the App Store (`POST /api/v1/apps/releases`) using the GitHub
   release download URL.

Option A (signing) requires:

- Secrets `APPSTORE_CERT` (certificate PEM) and `APPSTORE_KEY` (private key
  PEM). Both must be the same certificate/key pair registered for the app in
  the portal — a mismatch causes the store to reject the upload.
- Variable `APPSTORE_SIGNING_ENABLED` = `true`.

Publishing (step 5) additionally requires:

- Secret `APPSTORE_TOKEN` (App Store API token).
- Variable `APPSTORE_PUBLISH` = `true`.

## GitLab CI

The [`test`](../.gitlab-ci.yml) job installs a Nextcloud server, injects the
app, and runs the PHPUnit unit suite. The [`build`](../.gitlab-ci.yml) job
builds, packages, and signs the app (using the `APPSTORE_KEY_B64`/
`APPSTORE_CERT` CI variables when defined) and exposes the artifacts. The
`release` job runs on Git tags and publishes a GitLab Release with links to the
artifacts.

The `publish_appstore` job runs on tags when `APPSTORE_PUBLISH=true`: it uploads
the unversioned tarball to the GitLab generic package registry, signs it, and
posts the release to the App Store (`POST /api/v1/apps/releases`).

Add the CI variable `APPSTORE_KEY_B64` (Base64-encoded private key, masked —
GitLab masked variables cannot contain whitespace, so the PEM must be
Base64-encoded; the job accepts both value-type and file-type) and
`APPSTORE_CERT` (FILE-type variable pointing at the public certificate — it is
public data and does not need masking) to enable signing. Publishing requires
the additional CI variables `APPSTORE_TOKEN` (API token) and `APPSTORE_PUBLISH`
(`true`).

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
