# File Checksum Index & Search (FCIAS)

A Nextcloud app that indexes file checksums for fast reverse hash lookups and duplicate detection.

Nextcloud's `oc_filecache` stores checksums as space-delimited `algo:hash` pairs in a single unindexed TEXT column. Searching for files by hash therefore requires a full-table `LIKE '%hash%'` scan — O(n) and unusable at scale.

FCIAS stores checksums in Nextcloud's built-in **files metadata index** (`oc_files_metadata` / `oc_files_metadata_index`) and mirrors them back into the `filecache` checksum column. It adds composite indices to the built-in metadata index, enabling fast indexed reverse hash lookups without any custom tables.

## Features

- **Duplicate file browser** — a standalone page (`/duplicates`) and files sidebar integration for finding files with identical hashes
- **Files sidebar "Checksums" tab** — shows the selected file's checksums with recalculate and find-duplicates actions
- **Unified Search provider** — type a hash directly into Nextcloud's search bar
- **Rule-based hash generation** — `auto`, `missing`, `force`, and `lazy` modes, configurable in admin and personal settings
- **Admin settings page** — status overview, rule management, rule-editing permissions, and a crontab snippet generator
- **Personal settings page** — users can view, create, and edit rules subject to permissions; per-rule `admin_enforced` locks
- **Automatic index maintenance** — Nextcloud file event listeners and background jobs
- **Lazy & deferred hash recalculation** — a pending queue drained by a background job
- **Public API v1** — HTTP REST and PHP surfaces with an OpenAPI spec
- **7 CLI commands** for search, administration, and maintenance
- **FAQ & user help** — served in-app to all authenticated users

## Requirements

| Component | Minimum Version |
|-----------|----------------|
| Nextcloud | 33 (up to 34) |
| PHP | 8.2 |
| Database | Any database supported by Nextcloud |
| Node.js | 24 (build only, not needed at runtime) |

> **Note:** FCIAS uses Nextcloud's built-in files metadata index and adds composite indices on it. No custom tables are required.

## Installation

```bash
# Clone into your Nextcloud apps directory
cd /var/www/nextcloud/apps
git clone https://gitlab.com/metaworx/open-source/nextcloud/file_checksum_search.git

# Install JS dependencies and build frontend assets
cd file_checksum_search
npm install
npm run build

# Enable the app
cd /var/www/nextcloud
php occ app:enable file_checksum_search
```

During the enable step the app registers its checksum metadata keys and seeds the metadata index for existing files.

## CLI Reference

FCIAS provides 7 `occ` commands. Run them as `php occ <command>`.

### Core Commands

| Command | Description |
|---------|-------------|
| `file-checksum-search:search <query>` | Search files by hash value or `algo:hash` pair |
| `file-checksum-search:generate [options]` | Generate checksums for user files, or mark them for background processing |
| `file-checksum-search:find-duplicates [options]` | Find files with duplicate hash values |
| `file-checksum-search:rebuild [--batch-size=<n>]` | Backfill the hash index from existing filecache checksums |
| `file-checksum-search:test-perf` | Benchmark indexed lookup vs unindexed LIKE scan |

### Status & Configuration Commands

| Command | Description |
|---------|-------------|
| `file-checksum-search:status [--output=<fmt>]` | Display app version, row counts, and pending stats |
| `file-checksum-search:show-config [--output=<fmt>]` | Display all app config key/value pairs |

`--output` accepts `plain` (default), `json`, or `json_pretty`.

### Examples

```bash
# Search for a SHA-1 hash
php occ file-checksum-search:search da39a3ee5e6b4b0d3255bfef95601890afd80709

# Search with explicit algorithm
php occ file-checksum-search:search sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855

# Generate SHA-1 hashes for all PDFs of a user
php occ file-checksum-search:generate --user=alice --path="**/*.pdf"

# Generate SHA-256 hashes for all files of a user
php occ file-checksum-search:generate --user=alice --algo=sha256

# Mark files as pending instead of hashing immediately
php occ file-checksum-search:generate --user=alice --mark

# Find SHA-1 duplicates (min 2 files per group) and verify from content
php occ file-checksum-search:find-duplicates --algo=sha1 --min-count=2 --verify

# Show app config as JSON
php occ file-checksum-search:show-config --output=json_pretty

# Check status
php occ file-checksum-search:status

# Rebuild the checksum metadata index from filecache
php occ file-checksum-search:rebuild
```

## Hash Generation Rules

FCIAS reacts to file events (create, write, copy, delete) according to **hash generation rules** configured in **Administration settings → File Checksum Index & Search** (and, for permitted users, in **Personal settings**).

Rules are evaluated in order — the first matching rule handles a file. Each rule combines:

| Field | Description |
|-------|-------------|
| `enabled` | Whether the rule is active |
| `userScope` | `all` users or a single user ID |
| `path` | A path glob (Symfony Finder `**` syntax), e.g. `/` or `**/*.pdf` |
| `algos` | One or more of `sha1`, `md5`, `sha256`, `sha512`, `sha3-256`, `sha3-512`, `crc32`, `adler32` |
| `mode` | How stale hashes are handled (see below) |
| `admin_enforced` | Whether users may edit the rule (admin-only lock) |

### Modes

| Mode | Description |
|------|-------------|
| `auto` | Recalculate existing hashes only when stale |
| `missing` | Recalculate stale hashes and fill in missing ones |
| `force` | Clear all hashes and recalculate immediately |
| `lazy` | Clear hashes and defer recalculation to the background queue |

### Rule-editing permissions (`admin_enforced`)

There is a single global list of rules. Administrators edit all rules and can lock individual rules with the **admin-enforced** flag. Rule-editing permission is configured in admin settings via three options:

- **Allow all users to edit rules**
- **Groups** — group IDs allowed to edit rules
- **Users** — user IDs allowed to edit rules

Users may create and edit rules when they are in an enabled group/user list (or editing is enabled for everyone) **and** the rule's path is in a folder they can write to. Rules marked `admin_enforced` are shown to users as read-only. The `admin_enforced` and `userScope` fields are never trusted from user requests.

## Pending Hash Queue

When a rule's mode is `lazy` (or when a file is locked at write time), hash updates are deferred by marking the file's `file-checksum-updated_at` metadata entry as `pending:<mode>`. The `ProcessPendingUpdates` background job runs every 60 seconds and drains up to 50 pending entries per cycle.

## Duplicate File Browser

FCIAS provides a global duplicate file browser at **`/apps/file_checksum_search/duplicates`** (accessible via the "Duplicates" entry in the top navigation). Features:

- Filter by algorithm (SHA-1, MD5, SHA-256, SHA-512, SHA3-256, SHA3-512, CRC32)
- Set minimum duplicate count and result limit
- Expandable groups showing file paths
- **Verify hashes** button that recalculates all hashes from file content and flags mismatches
- "Only matching" checkbox to filter to fully-verified groups

The files sidebar also includes a **"Find duplicates"** button that shows files sharing hash values with the currently selected file.

## Public API (v1)

FCIAS provides a stable, versioned public API with three consumer surfaces: **HTTP REST**, **PHP DI**, and **PHP Bootstrap**.

Full documentation: [`docs/api-v1.md`](docs/api-v1.md) | OpenAPI spec: [`docs/api-v1-openapi.yaml`](docs/api-v1-openapi.yaml)

### HTTP REST API

All endpoints are under `/apps/file_checksum_search/api/v1/`. Authentication via NC session cookie, HTTP Basic Auth, or Bearer token.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/lookup?hash=<hex>&algo=<algo>&limit=<n>` | GET | Search files by hash value |
| `/api/v1/file/{fileId}/hashes` | GET | Get all checksums for a file |
| `/api/v1/file/{fileId}/duplicates` | GET | Find files sharing hash values |
| `/api/v1/file/{fileId}/recalc` | POST | Recalculate hash |
| `/api/v1/duplicates?algo=<algo>&min_count=<n>&limit=<n>&offset=<n>` | GET | Global duplicate groups |
| `/api/v1/status` | GET | Read-only health/status |

Quick example:

```bash
curl -u alice:app-password \
  "https://nc.example.com/apps/file_checksum_search/api/v1/lookup?hash=da39a3ee5e6b4b0d3255bfef95601890afd80709&algo=sha1"
```

### PHP API

The [`ChecksumApi`](lib/Public/ChecksumApi.php) class is the single public contract, usable via dependency injection or external bootstrap:

```php
// Within a Nextcloud app (DI)
use OCA\FileChecksumSearch\Public\ChecksumApi;

class MyService {
    public function __construct(private ChecksumApi $api) {}
    public function search(string $hash): array {
        return $this->api->findByHash($hash);
    }
}
```

```php
// External PHP app (bootstrap)
require_once '/var/www/nextcloud/lib/base.php';
$api = \OC::$server->get(\OCA\FileChecksumSearch\Public\ChecksumApi::class);

// Search by hash
$result = $api->findByHash('da39a3ee5e6b4b0d3255bfef95601890afd80709');

// Get hashes by path (relative to user root)
$hashes = $api->getHashesByPath('Documents/report.pdf', 'alice');

// Get hashes from a File object
$file = \OC::$server->getRootFolder()->getUserFolder('alice')->get('Documents/report.pdf');
$hashes = $api->getHashesByFile($file);
```

Full PHP method reference in [`docs/api-v1.md`](docs/api-v1.md#php-api).

### Legacy `/api/1.0/` Endpoints

The original `/api/1.0/` routes are retained for backward compatibility but are **frozen** — no new features. Migrate to `/api/v1/` for new integrations.

| Endpoint | v1 Equivalent |
|----------|--------------|
| `GET /api/1.0/lookup/{hash}` | `GET /api/v1/lookup?hash=...` |
| `GET /api/1.0/file/{id}/hashes` | `GET /api/v1/file/{id}/hashes` |
| `GET /api/1.0/file/{id}/duplicates` | `GET /api/v1/file/{id}/duplicates` |
| `POST /api/1.0/file/{id}/recalc` | `POST /api/v1/file/{id}/recalc` |

## Admin & Personal Settings

Navigate to **Administration settings → Additional settings → File Checksum Index & Search**.

The admin settings page provides:

- **Status overview** — app version, indexed hash count, and pending update stats by mode
- **Hash generation rules** — create, edit, toggle, and delete rules; per-rule `admin_enforced` checkbox
- **Rule-editing permissions** — allow-all toggle, group list, and user list
- **Crontab snippet generator** — produce `occ` commands for CLI-based hash generation
- **Documentation** — in-app access to FAQ, README, API specs, and license

The personal settings page lists the rules applying to the current user, with read-only `admin_enforced` state and edit actions only where permitted.

## FAQ & Help

- User help: [`docs/HELP.md`](docs/HELP.md)
- FAQ: [`docs/FAQ.md`](docs/FAQ.md)

Both are also served in-app: the FAQ and user help are available to all authenticated users via the `GET /help` endpoint, and administrators get a broader documentation set via the admin settings "Documentation" tab.

## Troubleshooting

If the checksum metadata index becomes out of sync with `oc_filecache`, rebuild it:

```bash
php occ file-checksum-search:rebuild
```

For hashes that are missing or stale, table-prefix configuration, and other common issues, see [docs/FAQ.md § Troubleshooting](docs/FAQ.md#troubleshooting).

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE) for details.
