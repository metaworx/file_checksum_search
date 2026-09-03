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

FCIAS computes whichever algorithms the administrator allows, chosen from what the
server's PHP provides (admin settings → *Hash Algorithms*). Out of the box that is
`sha1`, `md5`, `adler32`, `crc32`, `sha256`, `sha384`, `sha512`, `sha3-256`, `sha3-384`
and `sha3-512`; `GET /api/v1/algorithms` lists what is in force. Removing an
algorithm stops new hashes being computed under it — hashes already stored stay
searchable. Names are limited to `[a-z0-9-]`, because they double as metadata keys.

For each file, the configured algorithm(s) produce a hex digest of the file
content. Each digest is stored under a metadata key of the form
`file-checksum-hash-{algo}` — `file-checksum-hash-sha256`, and so on — in the
file's **metadata document**, the row in `oc_files_metadata` that holds every
app's metadata for that file as one JSON column. A companion key
`file-checksum-updated_at` records when the file was last considered.

Each hash is then copied into an **index row** in `oc_files_metadata_index`,
which is the table a search can actually reach. The document is the record;
the index is the lookup. See
[What am I looking at in the database?](#what-am-i-looking-at-in-the-database)
for what that distinction costs you if you query the index by hand.

The computed checksums are also mirrored back into Nextcloud's `filecache`
`checksum` column as `algo:hash` pairs, so the values remain visible to
anything that reads the standard filecache checksum field.

No custom tables are required — FCIAS adds composite indices to the
built-in metadata index.

## What am I looking at in the database?

Two tables, both Nextcloud's own, holding two different things.

**`oc_files_metadata`** — one row per file, keyed by `file_id`, with a `json`
column holding that file's metadata for *every* app. This is the **metadata
document**. FCIAS's keys in it are:

| Key | Holds |
|-----|-------|
| `file-checksum-hash-sha256` (one per algorithm) | the digest, **whole** |
| `file-checksum-updated_at` | when the file was last considered, as a Unix timestamp |

The word *document* here never means the user's file. A PDF has a metadata
document; so does a photo.

**`oc_files_metadata_index`** — one row per file per key, and the only one of
the two that a query can search. FCIAS writes its own hash rows here:

| Column | For a hash row | For the `updated_at` row |
|--------|----------------|--------------------------|
| `meta_key` | `file-checksum-hash-{algo}` | `file-checksum-updated_at` |
| `meta_value_string` | the digest, **truncated to 63 characters** | the queue or trust state — `pending:auto`, `stale:reset`, … — or empty |
| `meta_value_int` | 0 | the timestamp |

Two things surprise people here.

**The stored hash may be a prefix.** `meta_value_string` is `VARCHAR(63)`, set
by Nextcloud, and a SHA-256 is 64 characters. FCIAS truncates on the way in and
truncates its search term the same way, then reads the metadata document to
confirm the full value before returning a match. **Anything querying this index
directly must do the same** — compare truncated, confirm from the document —
or it will happily return a file whose hash merely shares the first 63
characters. Nothing marks a row as truncated, and nothing needs to: hex digests
are even-length, so no whole digest is ever exactly 63 characters, and
`meta_key` names the algorithm and therefore the length to expect.

**The `updated_at` row does double duty.** Its integer half is the freshness
stamp; its string half is what the file is waiting for or why its hashes are
not to be trusted. Its absence means something too: a file whose metadata
document holds hashes but which has no `updated_at` row at all is one the index
has lost track of entirely, and no ordinary repair can reach it, because every
one of them starts from a row it does not have. `occ fcias:repair --step
unindexed-hashes` is the one that finds those.

If you want to read the hashes rather than search them, read the metadata
document: it holds them whole. `occ fcias:backup --format=sum` writes them out
for you and does exactly that.

## How do rules work?

**Nothing is hashed until a rule says so.** A fresh installation ships two
rules and leaves both disabled: one addressing every home folder, one
addressing every storage there is. Until one of them — or a rule you write —
is enabled, the app computes nothing on its own; the admin settings page says
so in a banner, and manual recalculation from the files sidebar keeps working
regardless. Enabling the home-folders rule is the safe first step; the
universal one also reaches external storage and group folders, which is why it
is a separate, deliberate switch.

Rules control which files get hashes, with which algorithms, and when — each
file is handled by the first matching rule, evaluated in order, and that
decision is final: there is no fall-through to a later rule.

Every rule names what it addresses in one field, its **selector**: one user's
home (`home:<uid>`), a group's members (`group:<gid>`), every home folder
(`home:*`), one group folder (`groupfolder:<id>`), one storage by its raw id
(`storage:<id>`), or everything (`*`). Which file a rule is talking about is
decided by where the file really lives, not by who is touching it: editing a
file shared with you is governed by its **owner's** rules, under the owner's
path.

The order follows from what each rule *is*, not from where anyone put it.
Rules fall into eight **bands**: the four degrees of specificity above —
one user or one storage, one group or one group folder, all home folders,
everything — first as administrator-enforced rules (bands 1–4), then as
unenforced ones (bands 5–8). Enforced beats unenforced; within each half,
specific beats general. So no rule of a user's own can outrun one an
administrator enforced, while it *can* override a default — which is what
leaving a rule unenforced offers.

The settings pages write a rule's priority as `<band>.<position>`, both
ascending as priority falls: `1.1` is the strongest rule on the instance and a
catch-all is always last. A rule moves between bands by having its selector or
its enforced flag changed, never by being dragged; dragging only reorders
rules that address the same thing, and never past that selector's own
catch-all.

A rule *is* that catch-all when its path is a bare `**`, a `/`, or left
empty — all three mean the same "everything this selector reaches", and all
three sink to the end of their segment. That is why a rule you write for a
scope you already have a catch-all on lands above it without dragging.

A rule can also say *not* to hash — `ignore` stops automatic hashing while
still allowing it on request, and `exclude` blocks it entirely, including the
sidebar's Recalculate button and the `occ` command. Whether a person may ask
by hand at all is a permission of its own, *Who may calculate by hand* on
the admin page's *Permissions* tab: it gates triggering a computation on top of owning the file,
ships allowed, and hides the sidebar's buttons for an account it does not
name rather than offering them to fail. See
[README.md § Hash Generation Rules](../README.md#hash-generation-rules) for
the full field and band reference, the rule types, and the mode table
(`auto`, `missing`, `force`, `lazy`), and
[user-guide.md § Your hashing rules](user-guide.md#your-hashing-rules) for the same model
explained to the people who see it on their personal settings page.

## How does cron / pending processing work?

Work is queued by marking a file pending, and a background job drains the
queue shortly after. The queue records only that a file needs looking at: the
drain resolves its governing rule at that moment and takes the verdict and the
algorithms from it, so a rule changed in between is honoured and a file that
lost its coverage is dropped rather than hashed.

A second job sweeps the storages each enabled rule addresses and queues what is
outdated or unhashed — the net beneath the file events. The admin status page
shows both jobs' last run with their counts, which is how you tell "nothing to
do" apart from "not running". See
[README.md § How hashing happens](../README.md#how-hashing-happens) and
[§ Pending Hash Queue](../README.md#pending-hash-queue) for the exact
mechanism, interval, and batch size.

## Why did the number of indexed checksums go down?

Because hashes **erode**. When a file is modified and no rule maintains it any
more, its stored hashes are dropped rather than kept: they describe content
that no longer exists, and a wrong hash is worse than none — it makes a changed
file look intact and can pair it with unrelated files as a duplicate.

This is recorded, not silent: the file's index entry is marked `eroded` and the
admin status page counts them. It also heals itself — the next time a rule
covers the file, re-hashing replaces the marker. A rising eroded count usually
means coverage was narrowed (a rule disabled, an `exclude` added) while the
files it used to cover are still being edited.

## How do I start over?

`occ fcias:reset`. On its own it changes nothing — it reports what it would
remove and stops there. Adding `--force` carries it out, and what it removes
cannot be recovered by any other means, which is why it asks twice.

Three slices, and naming none takes all of them:

```bash
php occ fcias:reset --hashes --force      # disown every stored checksum
php occ fcias:reset --config --force      # forget the configuration, rules included
php occ fcias:reset --status --force      # forget the queue
```

Take a backup first, and stop if it fails:

```bash
php occ fcias:reset --force --backup=/backups/before.json
```

Resetting hashes does not clear them there and then. Each file is marked as
disowned, which takes it out of search and out of duplicate groups
**immediately**, and the background job clears them as it goes — one database
write per thousand files instead of one metadata document rewrite each. If you
need it
done before the command returns, add `--now`; expect roughly a file per few
milliseconds, so a large instance takes a while.

## I already have checksums — can I load them?

Yes, including a plain `sha1sum` listing. `occ fcias:import` reads what
`fcias:backup` wrote, and also the `csv` and `sum` shapes:

```bash
# a backup, put back
php occ fcias:import --replace --hashes -i /backups/fcias.json

# checksums you computed yourself, for one user's files
cd /path/to/alices/files
find . -type f -print0 | xargs -0 sha256sum > /tmp/alice.sum
php occ fcias:import --merge -i /tmp/alice.sum \
    --format=sum --algo=sha256 --user=alice --stamp=mtime
```

Spell `--format` out unless the filename says it: it is guessed from the
extension, and a name like `SHA256SUMS` says nothing, so the import would try
to read the listing as `json`.

Three things the command will not decide for you:

- **`--merge` or `--replace`.** Merging adds only algorithms a file does not
  already have; replacing overwrites what is stored. One of the two is
  required, because guessing wrong is silent either way.
- **What the timestamps mean.** Freshness here is `updated_at >= mtime`, so a
  hash stamped later than the content it describes will never be recomputed by
  anything. The default `--stamp=source` therefore refuses records older than
  the file; `--stamp=mtime` is right for checksums you just computed, and
  `--stamp=now` claims more than the data supports and says so.
- **Where a `sum` file's paths start.** Such a listing names no storage, so say
  `--user=<uid>` (paths relative to that user's files directory) or
  `--storage=<id>`. A record that names its own storage — anything from a
  `json` or `csv` export — is never re-anchored.

Try it with `--dry-run` first: it reports exactly what would happen and writes
nothing. Files this instance does not have are counted and skipped, never
created, since an import restores what a file *is*, not that it exists.

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
`/ocs/v2.php/apps/file_checksum_search/api/v1/`, plus an equivalent PHP API
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

The same page carries the user's one preference, the algorithm the sidebar
offers first (`GET`/`PUT /api/v1/preferences/preferred_algorithm`). Its select
names your instance default as its first entry. Disallowing an algorithm does
not delete anyone's preference for it; it stops applying until they pick
again, and the page says so beneath the select while that is the case.

For an account the *Who may use the API* permission names, the page also
lists their app passwords under *Sudo tokens*, each with a switch granting it
the `/api/v1/sudo/` routes without a password prompt — for a script, which
cannot confirm one. A grant is a standing authorisation: it costs the user's
password to make, it is only offered on an app password allowed to access
files, and every grant on the instance is on your admin page's *Sudo tokens*
tab, where you can revoke any of them. It replaces the prompt, not the
permission: whether that account may look across accounts at all is still
*Who may look across accounts*.

`admin_enforced` and `selector` are never trusted from a user's own request —
the server always decides them, and a personal rule is always `home:<uid>`. A
path leading into a received share or a group folder is refused outright: such
a rule could never match, since those files answer to their owner's rules or to
the folder's own. See
[README.md § Rule-editing permissions](../README.md#rule-editing-permissions-admin_enforced)
for the full permission model.

## Troubleshooting

### The index is out of sync with filecache

Which repair you want depends on where the truth is. `occ fcias:repair --list`
describes each step; the four that rebuild something are:

```bash
# clients show a checksum this app does not know
php occ fcias:repair --step rebuild-from-filecache

# a file's details show a hash, but searching for it finds nothing
php occ fcias:repair --step rebuild-from-metadata

# after restoring a database: the index has no record of the file at all,
# so nothing else can find it. Reads every metadata document, and for that
# reason never runs on its own
php occ fcias:repair --step unindexed-hashes

# a reset left hashes for the background job to clear
php occ fcias:repair --step clear-disowned
```

If the rules have asked for hashes that nothing has computed yet, that is not a
repair — it reads file content — and has its own command:

```bash
php occ fcias:queue:drain --batch-size 200   # one batch
php occ fcias:queue:drain --all              # until the queue is empty
```

With no step named it runs all of them. Every step is safe to run again, and one
marked *expensive* asks whether there is anything to do before doing it —
`--include-expensive` tells it not to ask.

### Hashes are missing or outdated

First check whether an `include` rule is enabled at all — with none, nothing is
hashed by design.

The admin settings page's **Status** tab is where the rest shows. Four
rows answer four different questions, and it is worth knowing which one you are
reading:

- **Indexed Hashes** — how many the index holds. Zero with rules enabled means
  the work has not happened yet, not that it failed.
- **Untrusted Hashes** — files whose stored hashes are not to be believed, with
  a breakdown by reason. *Eroded* means they were dropped on write because no
  rule maintains the file any more, and heals itself once a rule covers it
  again. *Reset* means they are still stored but disowned, already hidden from
  search, waiting for the background job or an import.
- **Background Jobs** — each job's last run and its counts: the *Rule sweep*,
  the *Queue drain*, and the *Orphan purge*, which rides the sweep once a day
  (`orphan_purge_interval`, seconds) to forget files that no longer exist and
  runs at once after a user is deleted. The timestamp is the point: a job that
  stopped running is invisible until someone notices its clock has not moved.
- **Last Updated** — when the page itself last asked, not when anything was
  hashed.

If pending entries accumulate, ensure Nextcloud's background jobs (cron) are
running — that is what the Background Jobs clock tells you — and
`ProcessPendingUpdates` drains the queue every 60 seconds. You can also hash on
demand:

```bash
php occ file-checksum-search:hash --user=alice --path="**"
```

### A file's hash does not match its content

Run the duplicate browser's **Verify hashes** action, or recalculate a
single file via the API or the sidebar. An outdated hash means the file was
modified after its checksum was last computed.

### Table prefix issues

FCIAS reads Nextcloud's table prefix dynamically. If you use a non-default
prefix, ensure `dbtableprefix` is correctly configured in `config.php`.

### Where do I check compatibility and status?

Run `php occ file-checksum-search:status` for the app version, database
version, index status, and pending stats.
