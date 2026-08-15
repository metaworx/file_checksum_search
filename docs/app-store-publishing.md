# Nextcloud App Store Publishing

This document describes how FCIAS (`file_checksum_search`) is built, signed, and
published to the Nextcloud App Store.

## Pipeline overview

Publishing is driven by [`.github/workflows/publish.yml`](../.github/workflows/publish.yml)
and the [package.sh](../package.sh) packaging script.

Two options are supported:

| Option | Description | Required secrets |
|--------|-------------|------------------|
| **A**  | Automated signing with `appstore-sig` in CI | `APPSTORE_CERT`, `APPSTORE_KEY` |
| **B**  | Unsigned tarball uploaded as a GitHub release asset, then manually uploaded to the store | none |

The workflow always runs Option B. Option A is an additional signing step that
is enabled by setting the repository variable `APPSTORE_SIGNING_ENABLED=true`.

## What the workflow does

On `release: published` (or manual `workflow_dispatch`):

1. Checks out the code.
2. Installs Node.js 24 and PHP 8.2.
3. Installs frontend dependencies (`npm ci`).
4. Runs `bash package.sh`, which:
   - builds the frontend assets into `js/` and `css/`,
   - copies the app to `build/file_checksum_search/`,
   - removes dev-only files listed in [`.nc.publish.ignore`](../.nc.publish.ignore)
     (`.git`, `.github`, `tests`, `src`, `vendor-bin`, `node_modules`, `docs`,
     `.aiassistant`, ...),
   - runs `composer install --no-dev`,
   - creates `build/file_checksum_search.tar.gz` and
     `build/file_checksum_search-<version>.tar.gz`.
5. If `APPSTORE_SIGNING_ENABLED=true`, signs the versioned archive with
   `appstore-sig` using the configured certificate/key, then verifies the
   signature before upload.
6. Uploads the tarball(s) as GitHub release assets.

## Configuring Option A (signing)

1. Obtain an app certificate from the Nextcloud App Store developer portal
   (see the "Certificate" section of the app store developer account).
2. Add the following repository secrets:
   - `APPSTORE_CERT`: the certificate (`appstore.crt`) PEM content.
   - `APPSTORE_KEY`: the private key PEM content.

   > **Important:** both must be the **same** certificate/key pair registered
   > for the app in the portal. A mismatch causes the store to reject the upload.
3. Add the repository variable:
   - `APPSTORE_SIGNING_ENABLED` = `true`.
4. Optionally pin the signing tool image via the repository variable
   `APPSTORE_SIG_IMAGE` (defaults to `ghcr.io/nextcloud/appstore-sig:latest`).
   Pinning a specific tag or digest is recommended for reproducible builds.

## Option B — manual upload (fallback)

When signing is not configured, the workflow still produces a valid, unsigned
tarball and attaches it to the GitHub release. To publish manually:

1. Download `file_checksum_search.tar.gz` from the GitHub release.
2. Go to the Nextcloud App Store developer portal (`https://apps.nextcloud.com/developer/`)
   and open the upload page for the `file_checksum_search` app.
3. Select the downloaded archive and submit it for review.
4. Track the release status in the app store dashboard.

## Local packaging

Run the packaging script from the repo root:

```bash
bash package.sh
```

Artifacts are written to `build/`. The archive contains a single top-level
`file_checksum_search/` directory, as required by the Nextcloud App Store.
