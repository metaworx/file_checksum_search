# File Checksum Index & Search (FCIAS)

A Nextcloud app that indexes file checksums for fast reverse hash lookups.

Nextcloud's `oc_filecache` stores checksums as space-delimited `algo:hash` pairs in a single unindexed TEXT column. Searching for files by hash requires a full-table `LIKE '%hash%'` scan — O(n) and unusable at scale.

FCIAS stores checksums in Nextcloud's built-in **files metadata index** (`oc_files_metadata_index`) and mirrors them back into the `filecache` checksum column, enabling fast indexed reverse hash lookups without a custom table or database triggers.

## Features

- **Duplicate file browser** — standalone page (`/duplicates`) and sidebar integration for finding files with identical hashes
- **Files sidebar tab** showing checksums for the selected file with recalculate and duplicate-finding actions
- **Unified Search** integration — type a hash directly into Nextcloud's search bar
- **Admin settings page** with status overview, rule-based hash generation
- **Automatic index maintenance** via Nextcloud file event listeners and background jobs (no database triggers required)
- **Lazy & deferred hash recalculation** with a pending queue drained by a background job
- **REST API** for hash lookup, file-hash retrieval, recalculation, and duplicate detection
- **7 CLI commands** for search, administration, and maintenance

## Requirements

| Component | Minimum Version |
|-----------|----------------|
| Nextcloud | v33 |
| PHP | 8.2 |
| Database | Any Nextcloud-supported database (MySQL/MariaDB, PostgreSQL, SQLite) |
| Node.js | ≥ 18 (build only, not needed at runtime) |

> **Note:** FCIAS uses Nextcloud's built-in files metadata index and adds
> composite indices on it. No custom tables are required.

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

### Core Commands

| Command | Description |
|---------|-------------|
| `file-checksum-search:search <query>` | Search files by hash value or `algo:hash` pair |
| `file-checksum-search:generate --user=<user> [--path=<glob>] [--algo=<algo>]` | Generate checksums for user files. Default algo: `sha1` |
| `file-checksum-search:find-duplicates [--algo=<algo>] [--user=<user>] [--min-count=<n>] [--output=<fmt>] [--verify]` | Find files with duplicate hash values |
| `file-checksum-search:rebuild` | Backfill the hash index from existing filecache checksums |
| `file-checksum-search:test-perf` | Benchmark indexed lookup vs unindexed LIKE scan |

### Status & Configuration Commands

| Command | Description |
|---------|-------------|
| `file-checksum-search:status` | Display app version, DB version, metadata index status, and pending stats |
| `file-checksum-search:show-config` | Display all app config key/value pairs |

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

# Find SHA-1 duplicates with verification
php occ file-checksum-search:find-duplicates --algo=sha1 --verify

# Show app config as JSON
php occ file-checksum-search:show-config --output=json_pretty

# Check status
php occ file-checksum-search:status

# Rebuild the checksum metadata index from filecache
php occ file-checksum-search:rebuild
```

## Duplicate File Browser

FCIAS provides a global duplicate file browser at **`/apps/file_checksum_search/duplicates`** (accessible via the "Duplicates" entry in the top navigation). Features:

- Filter by algorithm (SHA-1, MD5, SHA-256, SHA-512, SHA3-256, SHA3-512, CRC32)
- Set minimum duplicate count and result limit
- Expandable groups showing file paths
- **Verify hashes** button that recalculates all hashes from file content and flags mismatches
- "Only matching" checkbox to filter to fully-verified groups

The files sidebar also includes a **"Find duplicates"** button that shows files sharing hash values with the currently selected file.

## Hash Generation Rules

FCIAS reacts to file events (create, write, copy, delete) according to **hash
generation rules** configured in **Admin settings → File Checksum Index & Search**.
Each rule combines a user scope, a path glob, an algorithm set, and a processing **mode**:

| Mode | Description |
|------|-------------|
| `auto` | Recalculate existing hashes only when stale |
| `missing` | Recalculate existing hashes and fill in missing ones |
| `force` | Clear all hashes and recalculate immediately |
| `lazy` | Clear hashes and defer recalculation to the background queue |

## Pending Hash Queue

When a rule's mode is `lazy` (or when a file is locked at write time with `force`),
hash updates are deferred by marking the file's `file-checksum-updated_at`
metadata entry as `pending:<mode>`. The `ProcessPendingUpdates` background job
runs every 60 seconds and drains up to 50 pending entries per cycle.

## Cron Scheduling

A crontab snippet generator in the admin settings produces `occ` commands for
CLI-based hash generation, for users who prefer system-level cron scheduling.

## Public API (v1)

FCIAS provides a stable, versioned public API with three consumer surfaces: **HTTP REST**, **PHP DI**, and **PHP Bootstrap**.

Full documentation: [`docs/api-v1.md`](docs/api-v1.md) | OpenAPI spec: [`docs/api-v1-openapi.yaml`](docs/api-v1-openapi.yaml)

### HTTP REST API

All endpoints under `/apps/file_checksum_search/api/v1/`. Authentication via NC session cookie, HTTP Basic Auth, or Bearer token.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/lookup?hash=<hex>&algo=<algo>` | GET | Search files by hash value |
| `/api/v1/file/{fileId}/hashes` | GET | Get all checksums for a file |
| `/api/v1/file/{fileId}/duplicates` | GET | Find files sharing hash values |
| `/api/v1/file/{fileId}/recalc` | POST | Recalculate hash |
| `/api/v1/duplicates?algo=<algo>&min_count=<n>` | GET | Global duplicate groups |
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
| `GET /api/1.0/lookup` | `GET /api/v1/lookup` |
| `GET /api/1.0/file/{id}/hashes` | `GET /api/v1/file/{id}/hashes` |
| `GET /api/1.0/file/{id}/same-hash` | `GET /api/v1/file/{id}/duplicates` |
| `POST /api/1.0/file/{id}/recalc` | `POST /api/v1/file/{id}/recalc` |
| `GET /api/1.0/duplicates` | `GET /api/v1/duplicates` |

## Admin Settings

Navigate to **Administration settings → Additional settings → File Checksum Index & Search**.

The settings page provides:
- **Status overview**: App version, DB version, indexed hash count, and pending update stats by mode
- **Hash Generation Rules**: Global rule plus additional path-scoped rules (user scope, path glob, algorithms, mode)
- **Crontab Snippet Generator**: Generate crontab entries for CLI-based hash generation

## Troubleshooting

### "Table prefix" issues

FCIAS uses Nextcloud's table prefix dynamically (`$db->getPrefix()`). If you use a non-default prefix, ensure it's correctly configured in `config.php`:

```php
'config_prefix' => 'mycustomprefix_',
```

### Rebuilding the metadata index

If the checksum metadata index becomes out of sync with `oc_filecache`:

```bash
php occ file-checksum-search:rebuild
```

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE) for details.
