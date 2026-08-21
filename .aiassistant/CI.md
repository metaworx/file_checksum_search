# CI Conventions (v1.1.0)

Nextcloud‑specific CI conventions for the `nextcloud/setup-server-action@v0.5.0` action.

## Contents

1. Setup‑Server‑Action
2. Database Configuration
3. App Injection
4. PHPUnit in CI
5. Signing & Publishing
6. Nightly Releases
7. Document Governance
8. Document History

## 1. Setup‑Server‑Action

- Action: `nextcloud/setup-server-action@v0.5.0` (official, under `nextcloud` org)
- Creates server in `nextcloud/` subdirectory — NOT workspace root
- All app paths must target `nextcloud/apps/<app_id>/`
- Use `working-directory` instead of inline `cd`

## 2. Database Configuration

- Default: `database: sqlite`
- For MariaDB/MySQL apps, set `database: mysql`
- Action hardcodes DB credentials: user `nextcloud`, password `nextcloud`, host `127.0.0.1`
  → MariaDB service MUST provide `MYSQL_USER: nextcloud`, `MYSQL_PASSWORD: nextcloud`
- Do NOT pass `database-host`, `database-name`, `database-user`, `database-pass` — these are not recognized inputs by the action

## 3. App Injection

- Use `rsync -a --exclude='.git' --exclude='nextcloud' ./ nextcloud/apps/<app_id>/`
- The `--exclude='nextcloud'` prevents recursive copy into the server directory
- Run `php occ app:enable <app_id>` after injection to run migrations (both PHPUnit and Cypress jobs)

## 4. PHPUnit in CI

- Run from app directory: `working-directory: nextcloud/apps/<app_id>`
- Command: `./vendor/bin/phpunit -c tests/phpunit.xml --display-warnings`
- On PHP 8.4 with PHPUnit 10.5: suppress `E_STRICT` deprecations via `php -d error_reporting='E_ALL & ~E_DEPRECATED'`
- See also: [`.aiassistant/TESTING.md`](TESTING.md) §1 for `--display-warnings` rationale

## 5. Signing & Publishing

- All signing and App Store publishing logic lives in `package.sh` — CI files MUST call it, never re-implement key
  decoding, signing, or the `apps.nextcloud.com` publish request themselves.
- `package.sh` resolves `APPSTORE_KEY` / `APPSTORE_CERT` in this order: (1) an existing file path (GitLab file-type
  variables), (2) raw PEM content (GitHub secrets, which allow multi-line values), (3) base64-encoded PEM (required
  for GitLab masked variables, which reject whitespace/newlines).
- `bash package.sh` (no flags) signs automatically whenever a key is configured and silently skips signing otherwise
  — there is no separate "signing enabled" toggle. Use `bash package.sh --sign-only` to (re-)sign an existing
  archive, and `bash package.sh --appstore [--nightly]` to publish.
- `--sign-only` and `--appstore` only require `bash`/`openssl` (plus `curl` for `--appstore`) — they do not need PHP,
  npm, composer, rsync, or tar. This lets a publish job run from a minimal image (e.g. `alpine` + `apk add bash
  openssl curl`).
- Never echo any slice of key/cert content to CI logs, not even the PEM header line via `head -n1` — classify the
  input format only (e.g. "base64-encoded PEM") if a debug line is needed.
- The App Store publish API (`POST /api/v1/apps/releases`) has no "signed archive" file format — it always downloads
  a plain `.tar.gz` from `download` and verifies it against the separately-transmitted `signature` field.
  `sign_archive()`'s `<archive>-signed.tar.gz` output is a byte-identical convenience copy for manual web-UI
  uploads only; never point `DOWNLOAD_URL` at it. `DOWNLOAD_URL` MUST reference the exact same **versioned** archive
  (`<app_id>-<version>.tar.gz`) that `package.sh --appstore` signs (`$VERSIONED_ARTIFACT`) — CI files upload and link
  that versioned file, not the unversioned `<app_id>.tar.gz` copy.

## 6. Nightly Releases

- The App Store release channel is determined purely by the git tag; there are no push-triggered nightlies.
  - `vX.Y.Z` (strict semver) → stable release (`package.sh --appstore`).
  - `vX.Y.Z-<suffix>` (e.g. `-beta.1`, `-rc1`) → nightly release (`package.sh --appstore --nightly`).
  - Any other tag → the publish step does not run.
- GitLab: expressed as `rules:` regexes on `$CI_COMMIT_TAG` (`^v\d+\.\d+\.\d+$` / `^v\d+\.\d+\.\d+-.+$`) on the
  `publish_appstore` job, mirrored on the `release` job so stray tags don't create a GitLab Release either.
- GitHub: expressed as a bash regex match against `github.event.release.tag_name` in a "Classify release tag" step,
  gating the publish/asset-upload steps on the result.

## 7. Document Governance

- Version updates follow `.aiassistant/CHANGELOG.md` rules.

## 8. Document History

| Version | Date       | Changes                                                        | Agent Impact                                                                 |
|---------|------------|-----------------------------------------------------------------|-------------------------------------------------------------------------------|
| v1.1.0  | 2026-08-22 | Added §5 Signing & Publishing (incl. DOWNLOAD_URL/versioned-archive rule) and §6 Nightly Releases. | CI files must call `package.sh` for signing/publishing; `DOWNLOAD_URL` must reference the versioned archive; nightly channel is tag-pattern based, no push-triggered nightlies. |
| v1.0.0  | 2026-08-05 | Initial document.                                              | Baseline `setup-server-action` conventions.                                    |
