# File Checksum Index & Search (FCIAS)

A Nextcloud app that indexes file checksums for fast reverse hash lookups.

Nextcloud's `oc_filecache` stores checksums as space-delimited `algo:hash` pairs in a single unindexed TEXT column. Searching for files by hash requires a full-table `LIKE '%hash%'` scan — O(n) and unusable at scale.

FCIAS deploys a **MariaDB Trigger + Shadow Table** architecture that normalizes checksums into an indexed lookup table, enabling O(1) reverse hash lookups.

## Features

- **Automatic index maintenance** via database triggers (INSERT/UPDATE/DELETE on `oc_filecache`)
- **REST API** for hash lookup and file-hash retrieval
- **Unified Search** integration — type a hash directly into Nextcloud's search bar
- **8 CLI commands** for administration and maintenance
- **Admin settings page** with compatibility test and maintenance actions
- **Files sidebar tab** showing checksums for the selected file

## Requirements

| Component | Minimum Version |
|-----------|----------------|
| Nextcloud | v33 |
| PHP | 8.2 |
| Database | MariaDB ≥ 10.2 with TRIGGER privilege |

> **Note:** This app requires MariaDB. It does not support SQLite or PostgreSQL.

## Installation

```bash
# Clone into your Nextcloud apps directory
cd /var/www/nextcloud/apps
git clone https://github.com/metaworx/file_checksum_search.git

# Enable the app
cd /var/www/nextcloud
php occ app:enable file_checksum_search
```

The app will automatically deploy its database objects (shadow table, stored procedure, triggers) during the enable step.

## CLI Reference

### Core Commands

| Command | Description |
|---------|-------------|
| `file-checksum-search:rebuild` | Backfill the hash index from existing filecache checksums |
| `file-checksum-search:search <query>` | Search files by hash value or `algo:hash` pair |
| `file-checksum-search:generate --user=<user> [--path=<glob>] [--algo=<algo>]` | Generate checksums for user files. Default algo: `sha1` |
| `file-checksum-search:test-perf` | Benchmark indexed lookup vs unindexed LIKE scan |

### Admin Commands

| Command | Description |
|---------|-------------|
| `file-checksum-search:status` | Display app version, DB version, index status, and compatibility |
| `file-checksum-search:purge --force` | Truncate the hash index table |
| `file-checksum-search:teardown --force` | Drop triggers and stored procedure (preserves hash table) |
| `file-checksum-search:deploy-triggers --force` | Create triggers and stored procedure (idempotent) |
| `file-checksum-search:remove-table --force` | Drop the hash table entirely (run teardown first) |
| `file-checksum-search:create-table --force` | Create the hash table if it does not exist (idempotent) |

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

# Check status
php occ file-checksum-search:status

# Rebuild index after purge
php occ file-checksum-search:purge --force
php occ file-checksum-search:rebuild
```

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

## Admin Settings

Navigate to **Administration settings → Additional settings → File Checksum Index & Search**.

The settings page provides:
- **Status overview**: App version, DB version, indexed hash count, trigger/SP state
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
'dbprefix' => 'mycustomprefix_',
```

### Rebuilding after corruption

If the shadow table becomes out of sync with `oc_filecache`:

```bash
php occ file-checksum-search:purge --force
php occ file-checksum-search:rebuild
```

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE) for details.
