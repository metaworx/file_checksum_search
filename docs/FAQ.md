# FCIAS — Frequently Asked Questions

This page answers how the app works, for administrators and integrators.
What your users see and do is in [user-guide.md](user-guide.md), which is also what the
personal settings page shows them.

## What does FCIAS do?

File Checksum Index & Search (FCIAS) is a Nextcloud app that indexes file
checksums so files can be found by their hash value — quickly and at scale.

Nextcloud's `oc_filecache` stores checksums as space-delimited `algo:hash`
pairs in a single unindexed TEXT column. Searching for a file by hash would
require a full-table `LIKE '%hash%'` scan, which is O(n) and unusable at
scale. FCIAS stores checksums in Nextcloud's built-in files metadata index
(`oc_files_metadata_index`) and mirrors them back into the filecache
checksum column, enabling fast indexed reverse hash lookups without custom
tables.

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
`sha3-256`, `sha3-512`, `crc32`, and `adler32`.

For each file, the configured algorithm(s) produce a hex digest of the file
content. Digests are stored as metadata keys of the form
`file-checksum-{algo}` in Nextcloud's files metadata index
(`oc_files_metadata_index`). A companion key `file-checksum-updated_at`
records when the checksum was last refreshed.

The computed checksums are also mirrored back into Nextcloud's `filecache`
`checksum` column as `algo:hash` pairs, so the values remain visible to
anything that reads the standard filecache checksum field.

No custom tables are required — FCIAS adds composite indices to the
built-in metadata index.

## How do rules work?

Rules control which files get hashes, with which algorithms, and when — each
file is handled by the first matching rule, evaluated in order, and that
decision is final: there is no fall-through to a later rule.

The order follows from what each rule *is*, not from where anyone put it.
Rules fall into seven **bands**: administrator-enforced rules first (aimed at
a single user, then at a group, then at everyone), then users' own rules, then
the non-enforced administrator defaults in the same order, and the catch-all
`**` rule last. Enforced beats unenforced; within each half, specific beats
general. So no rule of a user's own can outrun one an administrator enforced,
while it *can* override a default — which is what leaving a rule unenforced
offers.

The settings pages write a rule's priority as `<band>.<position>`, both
ascending as priority falls: `1.1` is the strongest rule on the instance and
the catch-all is always last. A rule moves between bands by having its scope
or its enforced flag changed, never by being dragged; dragging only reorders
rules inside one band.

A rule can also say *not* to hash — `ignore` stops automatic hashing while
still allowing it on request, and `exclude` blocks it entirely, including the
sidebar's Recalculate button and the `occ` command. See
[README.md § Hash Generation Rules](../README.md#hash-generation-rules) for
the full field and band reference, the rule types, and the mode table
(`auto`, `missing`, `force`, `lazy`), and
[user-guide.md § Your hashing rules](user-guide.md#your-hashing-rules) for the same model
explained to the people who see it on their personal settings page.

## How does cron / pending processing work?

When a rule defers a hash update (mode `lazy`, or a file locked at write
time), FCIAS marks it pending and a background job drains the queue shortly
after. See [README.md § Pending Hash Queue](../README.md#pending-hash-queue)
for the exact mechanism, interval, and batch size.

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

FCIAS exposes a stable, versioned REST API at
`/apps/file_checksum_search/api/v1/`, plus an equivalent PHP API
(`OCA\FileChecksumSearch\Public\ChecksumApi`) for other Nextcloud apps.
[`docs/api-v1.md`](api-v1.md) is the authoritative reference for both
surfaces — full endpoint/method list, authentication, request/response
examples, versioning policy, and error handling.

## How do personal settings and admin_enforced work?

There is a single global list of rules. Administrators edit all rules and
can lock individual rules with the **admin-enforced** flag; a locked rule is
shown to users as read-only. Whether a given user can create/edit rules at
all is configured in admin settings (allow-all toggle, groups, users), and
is further limited to rules whose path they can write to.

Personal settings shows a user every rule that can decide one of their files,
not only the ones they may change: the enforced rules above their own and the
defaults below, both read-only. Seeing only the editable part would make a
file's actual fate look like it came from nowhere. That page's Help tab
renders [user-guide.md](user-guide.md), so what a user reads about rules is the same
document you can read yourself when supporting them.

`admin_enforced` and `userScope` are never trusted from a user's own
request — the server always decides them. See
[README.md § Rule-editing permissions](../README.md#rule-editing-permissions-admin_enforced)
for the full permission model.

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
php occ file-checksum-search:hash --user=alice --path="**"
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
