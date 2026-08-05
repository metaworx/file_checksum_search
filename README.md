# File Checksum Index & Search (FCIAS)

A Nextcloud app that indexes file checksums for fast reverse hash lookups.

Nextcloud's `oc_filecache` stores checksums as space-delimited `algo:hash` pairs in a single unindexed TEXT column. Searching for files by hash requires a full-table `LIKE '%hash%'` scan — O(n) and unusable at scale.

FCIAS deploys a **MariaDB Trigger + Shadow Table** architecture that normalizes checksums into an indexed lookup table, enabling O(1) reverse hash lookups.

## Features

- **Automatic index maintenance** via database triggers (INSERT/UPDATE/DELETE on `oc_filecache`)
- **Lazy & deferred hash recalculation** with a pending queue drained by a background job
- **REST API** for hash lookup, file-hash retrieval, recalculation, and duplicate detection
- **Unified Search** integration — type a hash directly into Nextcloud's search bar
- **Duplicate file browser** — standalone page (`/duplicates`) and sidebar integration for finding files with identical hashes
- **12 CLI commands** for administration and maintenance
- **Admin settings page** with status overview, compatibility test, rehash behavior configuration, cron job management, and maintenance actions
- **Files sidebar tab** showing checksums for the selected file with recalculate and duplicate-finding actions

## Requirements

| Component | Minimum Version |
|-----------|----------------|
| Nextcloud | v33 |
| PHP | 8.2 |
| Database | MariaDB ≥ 10.2 with TRIGGER privilege |
| Node.js | ≥ 18 (build only, not needed at runtime) |

> **Note:** This app requires MariaDB. It does not support SQLite or PostgreSQL.

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

The app will automatically deploy its database objects (shadow tables, stored procedure, triggers) during the enable step.

## CLI Reference

### Core Commands

| Command | Description |
|---------|-------------|
| `file-checksum-search:rebuild` | Backfill the hash index from existing filecache checksums |
| `file-checksum-search:search <query>` | Search files by hash value or `algo:hash` pair |
| `file-checksum-search:generate --user=<user> [--path=<glob>] [--algo=<algo>]` | Generate checksums for user files. Default algo: `sha1` |
| `file-checksum-search:find-duplicates [--algo=<algo>] [--user=<user>] [--min-count=<n>] [--output=<fmt>] [--verify]` | Find files with duplicate hash values |
| `file-checksum-search:test-perf` | Benchmark indexed lookup vs unindexed LIKE scan |

### Admin Commands

| Command | Description |
|---------|-------------|
| `file-checksum-search:status` | Display app version, DB version, index status, and compatibility |
| `file-checksum-search:show-config` | Display all app config key/value pairs |
| `file-checksum-search:purge` | Truncate the hash index table |
| `file-checksum-search:teardown` | Drop triggers and stored procedure (preserves hash table) |
| `file-checksum-search:deploy-triggers` | Create triggers and stored procedure (idempotent) |
| `file-checksum-search:remove-table` | Drop the hash table entirely (run teardown first) |
| `file-checksum-search:create-table` | Create the hash table if it does not exist (idempotent) |

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

# Rebuild index after purge
php occ file-checksum-search:purge --force
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

## Rehash Behavior Settings

Configure how the hash index responds to file system events via **Admin settings → File Checksum Index & Search → File Update Handling**:

| Event | Options | Default | Description |
|-------|---------|---------|-------------|
| On File Write | `off`, `force`, `lazy`, `auto` | `auto` | `auto`: recalc only if hashes exist; `force`: immediate recalc; `lazy`: delete hashes + queue for later |
| On File Create | `off`, `lazy`, `force` | `off` | When to hash newly created files |
| On File Delete | `off`, `on` | `off` | Whether to delete hash rows when files are deleted |

## Pending Hash Queue

When rehash behavior is set to `lazy` (or when a file is locked at write time with `force`), hash updates are deferred to a **pending queue** (`file_checksum_search_pending` table). A background job (`DrainPendingUpdates`) runs every 60 seconds, processing up to 50 pending entries per cycle.

## Cron Job Management

Via **Admin settings → File Checksum Index & Search → NC Background Job Definitions**, you can create, edit, enable/disable, and delete cron job definitions. Each definition specifies:

- **User Scope**: Single user or all users
- **Path**: Glob pattern for file paths (e.g. `**/*.pdf`)
- **Algorithm**: Hash algorithm to generate
- **Batch Size**: Maximum files per run
- **Interval**: 5, 15, 30, or 60 minutes

A crontab snippet generator is also available for users who prefer system-level cron.

## REST API

### Lookup by Hash

```
GET /apps/file_checksum_search/api/1.0/lookup?hash=<hex>&algo=<algo>
```

**Parameters:**
- `hash` (required): Hex-encoded hash value (32, 40, or 64 characters)
- `algo` (optional): Filter by algorithm (e.g. `sha1`, `sha256`)

**Response (200):**
```json
{
  "results": [
    {
      "fileid": 12345,
      "algo": "sha1",
      "hash": "da39a3ee5e6b4b0d3255bfef95601890afd80709",
      "path": "Documents",
      "name": "report.pdf"
    }
  ]
}
```

**Response (400):**
```json
{
  "error": "Hash parameter is required."
}
```

### Get Hashes by File ID

```
GET /apps/file_checksum_search/api/1.0/file/{fileId}/hashes
```

**Response (200):**
```json
{
  "fileid": 12345,
  "hashes": [
    { "algo": "sha1", "hash": "da39a3ee5e6b4b0d3255bfef95601890afd80709" },
    { "algo": "sha256", "hash": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855" }
  ]
}
```

### Recalculate Hash for File

```
POST /apps/file_checksum_search/api/1.0/file/{fileId}/recalc?algo=<algo>
```

### Find Same-Hash Files

```
GET /apps/file_checksum_search/api/1.0/file/{fileId}/same-hash
```

### Find All Duplicates

```
GET /apps/file_checksum_search/api/1.0/duplicates?algo=<algo>&minCount=<n>&limit=<n>&offset=<n>&user=<user>
```

## Admin Settings

Navigate to **Administration settings → Additional settings → File Checksum Index & Search**.

The settings page provides:
- **Status overview**: App version, DB version, indexed hash count, pending updates, table/SP/trigger state
- **File Update Handling**: Configure rehash behavior for write/create/delete events
- **Cron Job Definitions**: Create and manage background hash generation jobs
- **Crontab Snippet Generator**: Generate crontab entries for CLI-based hash generation
- **Compatibility test**: Verifies MariaDB ≥ 10.2, TRIGGER privilege, checksum column
- **Maintenance actions**: Purge index, rebuild index, deploy triggers & SP, remove triggers & SP, create hash table, remove hash table

## Troubleshooting

### "TRIGGER privilege" error

The database user must have the `TRIGGER` privilege. Run:

```sql
GRANT TRIGGER ON nextcloud.* TO 'nextcloud'@'localhost';
FLUSH PRIVILEGES;
```

### "Table prefix" issues

FCIAS uses Nextcloud's table prefix dynamically (`$db->getPrefix()`). If you use a non-default prefix, ensure it's correctly configured in `config.php`:

```php
'config_prefix' => 'mycustomprefix_',
```

### Rebuilding after corruption

If the shadow table becomes out of sync with `oc_filecache`:

```bash
php occ file-checksum-search:purge --force
php occ file-checksum-search:rebuild
```

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE) for details.
