# FCIAS — Frequently Asked Questions

## What does FCIAS do?

File Checksum Index & Search (FCIAS) is a Nextcloud app that indexes file
checksums so files can be found by their hash value — quickly and at scale.

Nextcloud's `oc_filecache` stores checksums as space-delimited `algo:hash`
pairs in a single unindexed TEXT column. Searching for a file by hash would
require a full-table `LIKE '%hash%'` scan, which is O(n) and unusable at
scale. FCIAS stores checksums in Nextcloud's built-in files metadata index
(`oc_files_metadata_index`) and mirrors them back into the filecache
checksum column, enabling fast indexed reverse hash lookups without custom
tables or database triggers.

Key features:

- Duplicate file browser (`/duplicates`) and sidebar integration
- A files sidebar tab with checksums, recalculate, and find-duplicates actions
- Unified Search integration — paste a hash into Nextcloud's search bar
- Rule-based hash generation (admin and personal settings)
- Automatic index maintenance via file event listeners and background jobs
- A stable public REST API (v1) and PHP API
- CLI commands for search, generation, and maintenance

## How are checksums computed and stored?

FCIAS supports the following algorithms: `sha1`, `md5`, `sha256`, `sha512`,
`sha3-256`, `sha3-512`, and `crc32`.

For each file, the configured algorithm(s) produce a hex digest of the file
content. Digests are stored as metadata keys of the form
`file-checksum-{algo}` in Nextcloud's files metadata index
(`oc_files_metadata_index`). A companion key `file-checksum-updated_at`
records when the checksum was last refreshed.

The computed checksums are also mirrored back into Nextcloud's `filecache`
`checksum` column as `algo:hash` pairs, so the values remain visible to
anything that reads the standard filecache checksum field.

No custom tables or database triggers are required — FCIAS adds composite
indices to the built-in metadata index.

## How do rules work?

Rules control which files get hashes, with which algorithms, and when. They
are evaluated in priority order: the global rule (priority 0) first, then
additional rules. Each file is handled by the first matching rule.

A rule combines:

- **User scope** — `all` users or a single user ID
- **Path glob** — a `**` glob (Symfony Finder syntax), e.g. `/` or `**/*.pdf`
- **Algorithms** — one or more of the supported algorithms
- **Mode** — how stale hashes are handled
- **Admin-enforced** — whether users may edit the rule

### Modes

| Mode | Behavior |
|------|----------|
| `auto` | Recalculate existing hashes only when they are stale |
| `missing` | Recalculate stale existing hashes and fill in missing ones |
| `force` | Clear all hashes and recalculate immediately |
| `lazy` | Clear hashes and defer recalculation to the background queue |

## How does cron / pending processing work?

FCIAS reacts to file events (create, write, copy, delete) through Nextcloud
listeners. When a rule's mode is `lazy` — or when a file is locked at write
time — the hash update is deferred.

Deferred updates are recorded by setting the file's
`file-checksum-updated_at` metadata entry to `pending:<mode>` (for example
`pending:lazy` or `pending:force`).

The `ProcessPendingUpdates` background job runs every 60 seconds and drains
up to 50 pending entries per cycle. Each cycle recalculates the queued
hashes and repeats until the queue is empty.

The admin settings page includes a crontab snippet generator that produces
`occ` commands for CLI-based hash generation, for users who prefer
system-level cron scheduling.

## How do duplicates get detected?

Duplicates are files that share the same hash value for a given algorithm.

The duplicate browser groups indexed hashes (`GROUP BY algo, hash_value`)
and joins the filecache to list the files in each group. Only groups meeting
the configured minimum file count are shown.

Because a hash match is not byte-for-byte proof of identical content, FCIAS
provides a **Verify hashes** action that recalculates every hash in the
current result set from file content and flags any group where the
recalculated hashes do not match. Use the "Only matching" filter to hide
groups that failed verification.

## How does the public API work?

FCIAS exposes a stable, versioned API at
`/apps/file_checksum_search/api/v1/` (see the API documentation).
Authentication uses the Nextcloud session cookie, HTTP Basic Auth, or a
Bearer token.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/lookup?hash=<hex>&algo=<algo>` | GET | Search files by hash value |
| `/api/v1/file/{fileId}/hashes` | GET | Get all checksums for a file |
| `/api/v1/file/{fileId}/duplicates` | GET | Find files sharing hash values |
| `/api/v1/file/{fileId}/recalc` | POST | Recalculate a file's hash |
| `/api/v1/duplicates?algo=<algo>&min_count=<n>` | GET | Global duplicate groups |
| `/api/v1/status` | GET | Read-only health/status |

The same functionality is available to PHP consumers through the
`OCA\FileChecksumSearch\Public\ChecksumApi` class (dependency injection or
bootstrap). The legacy `/api/1.0/` routes are frozen and retained for
backward compatibility only — migrate to `/api/v1/` for new integrations.

## How do personal settings and admin_enforced work?

There is a single global list of rules. Administrators edit all rules and
can lock individual rules with the **admin-enforced** flag.

Rule editing permission is configured in admin settings via three options:
**Allow all users to edit rules**, **Groups**, and **Users**. Users may
create and edit rules when:

1. they are in an enabled group/user list (or editing is enabled for
   everyone), **and**
2. the rule's path is in a folder they can write to.

Rules marked `admin_enforced` are shown to users as read-only; users cannot
edit, delete, toggle, or otherwise modify them. The `admin_enforced` and
`userScope` fields are never trusted from user requests — the server strips
them, so users cannot forge them.

In the personal settings page, each applicable rule shows its status, whether
it is admin-enforced (grayed out), and edit actions only when the current
user is allowed to edit that rule.

## Troubleshooting

### The index is out of sync with filecache

Rebuild the checksum metadata index from existing filecache checksums:

```bash
php occ file-checksum-search:rebuild
```

### Hashes are missing or stale

Check the admin settings status overview (indexed hashes and pending
updates). If pending entries accumulate, ensure Nextcloud's background jobs
(cron) are running — the `ProcessPendingUpdates` job drains the queue every
60 seconds. You can also generate hashes on demand:

```bash
php occ file-checksum-search:generate --user=alice --path="**"
```

### A file's hash does not match its content

Run the duplicate browser's **Verify hashes** action, or recalculate a
single file via the API or the sidebar. A stale hash means the file was
modified after its checksum was last computed.

### Table prefix issues

FCIAS reads Nextcloud's table prefix dynamically. If you use a non-default
prefix, ensure `dbtableprefix` is correctly configured in `config.php`.

### Where do I check compatibility and status?

Run `php occ file-checksum-search:status` for the app version, database
version, index status, and pending stats.
