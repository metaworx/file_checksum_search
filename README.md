# File Checksum Index & Search (FCIAS)

A Nextcloud app that indexes file checksums for fast reverse hash lookups and duplicate detection.

Nextcloud's `oc_filecache` stores checksums as space-delimited `algo:hash` pairs in a single unindexed TEXT column. Searching for files by hash therefore requires a full-table `LIKE '%hash%'` scan — O(n) and unusable at scale.

FCIAS stores checksums in Nextcloud's built-in **files metadata index** (`oc_files_metadata` / `oc_files_metadata_index`) and mirrors them back into the `filecache` checksum column. It adds composite indices to the built-in metadata index, enabling fast indexed reverse hash lookups without any custom tables.

> **Note on long hashes:** Nextcloud core's `oc_files_metadata_index.meta_value_string` column is
> `VARCHAR(63)` — a hard limit set by Nextcloud itself, not by FCIAS. SHA-256/SHA-512/SHA3-256/
> SHA3-512 digests (64+ hex chars) get silently truncated by the database when stored there.
> FCIAS accounts for this at every lookup and duplicate-grouping path (see
> `MetadataService::META_VALUE_STRING_MAX_LENGTH` and its truncation-aware query helpers) so
> results stay correct, but any *new* code that queries this index directly needs the same
> treatment or it will silently misbehave for long hashes.

## Features

- **Duplicate file browser** — a standalone page (`/duplicates`) and files sidebar integration for finding files with identical hashes
- **Files sidebar "Checksums" tab** — shows the selected file's checksums with recalculate and find-duplicates actions
- **Unified Search provider** — type a hash directly into Nextcloud's search bar
- **Rule-based hash generation** — nothing runs until a rule is enabled; `auto`, `missing`, `force`, and `lazy` modes, addressed at home folders, groups, group folders or storages
- **Admin settings page** — status overview, rule management, and rule-editing permissions
- **Personal settings page** — users can view, create, and edit rules subject to permissions; per-rule `admin_enforced` locks
- **Automatic index maintenance** — Nextcloud file event listeners and background jobs
- **Lazy & deferred hash recalculation** — a pending queue drained by a background job
- **Public API v1** — HTTP REST and PHP surfaces with an OpenAPI spec
- **12 CLI commands** for search, rule management, administration, and maintenance
- **User guide & FAQ** — served in-app, split by audience (see [Documentation](#documentation))

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

During the enable step the app registers its checksum metadata keys and copies the checksums the
filecache already holds into its index. No file is read, and nothing is hashed until you enable a
rule — see [How hashing happens](#how-hashing-happens).

## CLI Reference

FCIAS provides 12 `occ` commands — five for files and search, five for rules, two for status.
Run them as `php occ <command>`.

### Core Commands

| Command | Description |
|---------|-------------|
| `file-checksum-search:search <query>` | Search files by hash value or `algo:hash` pair |
| `file-checksum-search:hash [options]` | Compute checksums for user files, or mark them for background processing |
| `file-checksum-search:find-duplicates [options]` | Find files with duplicate hash values |
| `file-checksum-search:rebuild [--batch-size=<n>]` | Backfill the hash index from existing filecache checksums |
| `file-checksum-search:test-perf` | Benchmark indexed lookup vs unindexed LIKE scan |

#### `hash` and the rules

`hash` honours the hash generation rules, in both its direct and its `--mark` form: it is the
CLI face of the background job, so a rule saying not to hash a file stops it too. Its options
split into two groups — *what to compute*, and *named deviations from the rules*:

| Option | Effect |
|--------|--------|
| `-a`, `--algo=<name\|all\|auto>` | Repeatable (commas work too). Default `auto`: each file gets its **governing rule's** algorithms. Explicit names are exclusive — the rule's list is not consulted; combining names with `auto` forms the union. Unknown names fail the run. |
| `-m`, `--mode=<missing\|force>` | Default `missing`: compute each file's absent algorithms and refresh outdated ones (hashes older than the file's mtime). `force` recomputes everything requested. `auto` and `lazy` are rejected — the first is the background drain's semantics, the second is spelled `--mark`. |
| `-k`, `--mark` | Queue matching files as `pending:<mode>` for the background job instead of computing now. The drain resolves each file's rule at that point, so `--algo` does not apply to marked files (the command says so if you combine them). |
| `-u`, `--unmatched[=include\|skip\|unmatched]` | Files no rule governs: `skip` (default), `include` (process them too), or `unmatched` — bare `-u` — to process **only** them: the inverse view, for hashing a corner no rule covers without touching the rest. Needs at least one explicit `--algo`, since there is no rule to supply one; cannot be combined with `--mark`, whose drain would drop such files by design. |
| `--with-ignored` | Also process files whose governing rule is `ignore`. That verdict means "not automatically, but when asked", and typing a command *is* asking. Never affects `exclude`. |
| `--ignore-rule=<id>` | Evaluate as if that rule did not exist, so the next matching rule decides. Repeatable. An unknown ID fails the run rather than being skipped quietly. |
| `-v` / `-vv` | `-v` names the rule that skipped each file; `-vv` also names the rule for each file that proceeds. |

There is deliberately **no blanket `--force`**. The rules are a statement of intent about the
storage — an `exclude` on a metered mount means reading costs money — not a permission boundary,
and a flag whose main use is doing the expensive thing the configuration exists to avoid does not
earn its place in a cron line nobody is watching. `--ignore-rule` names what is being set aside,
so it is legible in shell history; it may set aside an admin-enforced rule, and logs a warning
naming that rule when it does.

### Rule Management Commands

| Command | Description |
|---------|-------------|
| `file-checksum-search:rules:list [-o json]` | List the rules in evaluation order, with ids, `band.position`, and every field |
| `file-checksum-search:rules:add [options]` | Create a rule (`--path`, `--type`, `--selector`, `-a/--algo`, `-m/--mode`, `--enforced`, `--enable/--disable`) |
| `file-checksum-search:rules:modify <id> [options]` | Change a rule; omitted options keep their value; a bare `--enable`/`--disable` is a toggle |
| `file-checksum-search:rules:delete <id> [-y]` | Delete a rule; a deleted shipped default is recreated (disabled) by the repair step |
| `file-checksum-search:rules:apply <id> [-m <mode>]` | Queue every file the rule currently governs for background hashing — uncapped, unlike the periodic sweep; `-m` overrides the rule's mode for this run (logged) |

Every command also answers to a short `fcias:` alias (`fcias:rules:list`, …). occ acts as an
administrator, and everything validates through the same code path as the web UI and REST —
selector grammar, user and group existence, algorithm names — so no surface can accept what another
refuses. `rules:list` is also where the ids for `hash --ignore-rule` come from. All rule
mutations are audit-logged with the acting surface; changes to admin-enforced rules log at
warning level.

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
php occ file-checksum-search:hash --user=alice --path="**/*.pdf"

# Hash a user's files with each file's rule-configured algorithms (the default)
php occ file-checksum-search:hash --user=alice

# Compute SHA-256 specifically, regardless of what the rules configure
php occ file-checksum-search:hash --user=alice --algo=sha256

# Hash the one corner no rule covers yet, without touching anything else
php occ file-checksum-search:hash --user=alice --path='Scans/**' -u -a sha1

# Mark files as pending instead of hashing immediately
php occ file-checksum-search:hash --user=alice --mark

# Hash files an "ignore" rule normally leaves alone (excluded files stay excluded)
php occ file-checksum-search:hash --user=alice --with-ignored

# Set one rule aside for this run and show which rule decided each file
php occ file-checksum-search:hash --user=alice --ignore-rule=a1b2c3d4 -v

# List the rules in evaluation order, with their band.position and ids
php occ file-checksum-search:rules:list

# Address one group folder, computing SHA-256 for everything in it
php occ fcias:rules:add --selector='groupfolder:1' --path='**' --type=include -a sha256 --enable

# Queue every file that rule currently governs, uncapped
php occ fcias:rules:apply <rule-id>

# Find SHA-1 duplicates (min 2 files per group) and verify from content
php occ file-checksum-search:find-duplicates --algo=sha1 --min-count=2 --verify

# Show app config as JSON
php occ file-checksum-search:show-config --output=json_pretty

# Check status
php occ file-checksum-search:status

# Rebuild the checksum metadata index from filecache
php occ file-checksum-search:rebuild
```

## How hashing happens

Nothing is hashed until a rule says so. A fresh installation ships two rules, both **disabled**:
one addressing every home folder, one addressing every storage there is. Until one of them — or a
rule you write — is enabled, the app computes nothing on its own, and the admin settings page says
so in a banner rather than leaving you to wonder.

Once a rule is enabled, five paths lead to a hash:

1. **A file event.** Creating, writing or copying a file asks the rules what governs it. The answer
   decides: an `include` rule queues the file as `pending:<mode>`, `ignore` and `exclude` queue
   nothing, and a file no rule governs is left alone.
2. **The queue drain.** `ProcessPendingUpdates` runs every 60 seconds and takes up to 50 queued
   files. It resolves each file's rule *again* at that moment and takes the algorithms from it — so
   a rule changed between the mark and the drain is honoured, and a file that lost its coverage in
   between is dropped from the queue instead of hashed.
3. **The periodic sweep.** `RuleProcessingJob` walks the storages each enabled rule addresses and
   queues what is outdated or unhashed. This is the net beneath the events: a file changed while the
   app was disabled, a rule enabled after the fact, an event that never fired.
4. **An explicit pass.** `occ file-checksum-search:rules:apply <id>`, or **Re-apply** in the rules
   table, queues every file that rule currently governs — uncapped, where the periodic sweep
   trickles.
5. **By hand.** The **Checksums** tab in the files sidebar recalculates one file on request. Only an
   `exclude` rule refuses: `ignore` means "not automatically", and asking is not automatic.

Installing the app reads no file content at all. It copies the checksums Nextcloud's own filecache
already carries into the searchable index; `occ file-checksum-search:rebuild` does the same on
demand, and neither overwrites a hash the app already stored.

### Which file a rule is talking about

A rule is matched against where a file **really lives**, never against the path of whoever happens
to be touching it. Every file has exactly one row in the filecache, and that row is the subject: a
file in a home folder is judged by its owner and by the path its owner knows it under, and a file
in a group folder or on any other storage is judged by that storage and the path inside it.

This is what makes shared files behave. When someone edits a file shared with them — through a
mount they may have renamed — the rules that decide it are the **owner's**, applied to the owner's
path, not the editor's. The same holds for a group folder: one rule decides the folder, and it
decides it identically for every member.

Files outside a user-visible area — trash bins, versions, application data — are governed by
nothing. No rule reaches them, including the catch-all.

### When hashes go away

Modifying a file that no rule maintains any more does not leave its old hashes in place. They
describe content that no longer exists, and a wrong hash is worse than none: it makes a changed
file look intact and can pair it with unrelated files as a duplicate. The app records this as
**erosion** — the file's index entry is marked `eroded`, the admin status page counts them, and the
loss heals itself: the next time a rule covers the file, re-hashing replaces the marker. Deleting a
file clears its entry outright.

## Hash Generation Rules

FCIAS reacts to file events (create, write, copy, delete) according to **hash generation rules** configured in **Administration settings → File Checksum Index & Search** (and, for permitted users, in **Personal settings**).

Rules are evaluated in order — the first matching rule handles a file, and a lower band number is a
higher priority. A rule's position is not arbitrary: it follows from what the rule *is*.

### What a rule addresses: the selector

One field says which slice of the file universe a rule is about. Scope and storage turned out to be
one question, not two — a group folder has no user dimension, and a home folder's user *is* its
sub-address — so there is one field, not a pair that could contradict each other:

| Selector | Addresses |
|----------|-----------|
| `home:<uid>` | one user's home folder |
| `group:<gid>` | the home folders of that group's members |
| `home:*` | every home folder on the instance |
| `groupfolder:<id>` | one group folder — the same files for every member (Team Folders app) |
| `storage:<raw id>` | one storage by its raw id, exactly as `oc_storages` spells it: an external mount, or anything the forms above cannot say |
| `*` | everything: every storage there is, external mounts and group folders included |

The value is split at its **first** colon, so a raw storage id containing further colons or slashes
(`smb::user@host//share/`) needs no escaping. `home:*` and `*` are deliberately spelled out rather
than abbreviated: what a rule reaches should be readable without knowing a convention.

### Bands

A rule's band follows from two things: how specifically its selector names its subject, and whether
an administrator enforced it. Enforced rules occupy bands 1–4, unenforced ones 5–8, in the same
order of specificity:

| Band | Selector addresses | Enforced |
|------|--------------------|----------|
| 1 | one user's home, or one storage | yes |
| 2 | one group's members, or one group folder | yes |
| 3 | every home folder | yes |
| 4 | everything | yes |
| 5 | one user's home, or one storage | no |
| 6 | one group's members, or one group folder | no |
| 7 | every home folder | no |
| 8 | everything | no |

Enforced beats unenforced; within each half, specific beats general. So no user rule can outrun one
an administrator enforced, while a user rule *can* override the non-enforced defaults below it —
which is what leaving a rule unenforced offers. Group folders rank with groups rather than with
individuals: a shared thing sits next to shared things, and since a group folder's files are never
anybody's home folder, the placement costs nothing in matching. A rule changes band by changing its
selector or its enforced flag, not by being moved.

### Ordering within a band

The settings pages show each rule's priority as `<band>.<position>` — `5.2` is the second rule of
its segment in band 5. Both numbers ascend as priority falls, so `1.1` is the strongest rule on the
instance and a catch-all is always last. Neither is stored: the band is derived from the rule's
selector and its enforced flag, and the position is the rule's index **within its segment**.

A **segment** is one selector value *inside one band* — so the same selector's enforced and
unenforced rules are different segments and number independently — and rules only ever compete
inside one. Two users' own rules never race for a file, so the list never asks you to rank them
against each other; the same holds for two group folders, or two storages. Two segments that share
a band both start at position 1, which is why `6.1` can appear twice: once per group folder.

Within every segment, rules whose path is a bare catch-all form a **defaults partition** at the end.
Three spellings count as bare: `**`, `/`, and the empty string — they all mean "everything this
selector reaches", so a rule written any of those ways is a default. A newly created rule lands *before* its segment's default, and a rule cannot be dragged
across that boundary. This is what makes a default behave like one: you never have to drag a new
rule past the catch-all that would otherwise shadow it.

Rules are reordered by dragging them, and a drag is confined to the segment and partition it
started in — there is no drop target outside it, so the browser shows a "no drop" cursor rather
than accepting a move that would change a rule's standing behind your back. To move a rule into a
different band, change its selector or its enforced flag.

There is no keyboard equivalent to the drag yet. Everything else on the rules page — creating,
editing, enabling, deleting, re-applying — is reachable without a pointer; only reordering is not.

### Defaults, and what is not covered

Two rules ship with the app, both **disabled**: `home:*` with path `**` (every home folder — the
safe one to enable), and `*` with path `**` (every storage there is, external mounts and group
folders included — its own deliberate decision). They are ordinary rules: editable, and deletable.
A repair step recreates a missing one, still disabled, so deleting one is reversible housekeeping
rather than a decision you cannot take back:

```bash
php occ maintenance:repair
```

That runs every enabled app's repair steps, including this one — look for *"File Checksum Index &
Search: quiet-start defaults and cleanup"* in the output. It is idempotent, and it also migrates
rules written before the selector model. Reach for it on an instance that received a new version
without the app version changing — any working copy tracking the repository between releases —
where Nextcloud never enters the upgrade path and the step has therefore not run.

The rules table ends with a row for every namespace that has **no catch-all of its own**: each
group folder, each addressable storage, all home folders, and everything. Each such row offers to
create that namespace's rule. Nothing is written by looking at the page — the rows are a view, and
configuration appears only when you save one. A rule naming a provider that is gone (a group folder
rule after the app was disabled, or one naming a deleted folder) is badged *provider missing*: it
is inert by construction, and saying so beats leaving you to wonder why it never matches.

Each rule combines:

| Field | Description |
|-------|-------------|
| `enabled` | Whether the rule is active |
| `selector` | Which slice of the file universe the rule addresses (see above) |
| `path` | A path glob (Symfony Finder `**` syntax), e.g. `**/*.pdf`. A bare `**`, `/` or empty value makes the rule its segment's default |
| `algos` | One or more of `sha1`, `md5`, `sha256`, `sha512`, `sha3-256`, `sha3-512`, `crc32`, `adler32` |
| `mode` | How outdated hashes are handled (see below) |
| `admin_enforced` | Whether users may edit the rule (admin-only lock) |
| `type` | `include` (default), `ignore`, or `exclude` — see below |

### Rule types

The first matching rule decides a file's fate outright; there is no fall-through.

| Type | Automatic hashing | Manual recalculation | Use it for |
|------|-------------------|----------------------|------------|
| `include` | yes, per `algos` and `mode` | yes | the normal case |
| `ignore` | no | yes | reducing noise, while leaving the file hashable on request |
| `exclude` | no | no | storage that must not be read at all |

**For metered or expensive storage, `exclude` is the correct tool.** Every other option still
reads the file eventually — even `lazy` mode only defers the read to the background queue.

An `ignore` or `exclude` rule computes nothing, so it stores no algorithms and no mode. What a
verdict can override depends on where its rule sits: an admin-enforced exclude is a mandate no
user rule can undo, while a user's own exclude only overrides the defaults below it.

### Modes

| Mode | Description |
|------|-------------|
| `auto` | Recalculate existing hashes only when outdated |
| `missing` | Recalculate outdated hashes and fill in missing ones |
| `force` | Clear all hashes and recalculate immediately |
| `lazy` | Clear hashes and defer recalculation to the background queue |

A rule may carry any of the four. The `hash` command's `--mode` accepts only `missing` and `force`:
`auto` is the background drain's own semantics, and `lazy` — "queue it for later" — is spelled
`--mark` there.

### Rule-editing permissions (`admin_enforced`)

There is a single global list of rules. Administrators edit all rules and can lock individual rules with the **admin-enforced** flag. Rule-editing permission is configured in admin settings via three options:

- **Allow all users to edit rules**
- **Groups** — group IDs allowed to edit rules
- **Users** — user IDs allowed to edit rules

Users may create and edit rules when they are in an enabled group/user list (or editing is enabled
for everyone) **and** the rule's path is in a folder they can write to *on their own home storage*.
A path leading into a received share, a group folder or any other mounted storage is refused with
the reason: a personal rule is `home:<uid>`, and by the identity rules above it could never match
those files — those answer to their owner's rules, or to the folder's own. Rules marked
`admin_enforced` are shown to users as read-only, and the `admin_enforced` and `selector` fields are
never trusted from a user's request.

## Pending Hash Queue

Work is queued by marking a file's `file-checksum-updated_at` metadata entry as `pending:<mode>` —
by a file event, by the periodic sweep, by `rules:apply`, or by `hash --mark`. `ProcessPendingUpdates`
runs every 60 seconds and drains up to 50 entries per cycle, re-dispatching itself while the queue
is still full.

The queue says only what needs looking at, never what to do with it: the drain resolves each file's
governing rule at that moment and takes the verdict and the algorithms from it. A file whose rule
changed in between is treated by the new rule, and one that lost its coverage has its mark dropped
without being hashed.

An entry exists only for a file that was actually queued, hashed, or eroded — **absence means "never
considered"**. The status page counts the queue by mode, alongside the eroded count and each
background job's last run.

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

All endpoints are served over OCS, under `/ocs/v2.php/apps/file_checksum_search/api/v1/`.
Responses are plain JSON — the controllers are `ApiController`s, so there is no OCS envelope
around the body, but the route prefix is required. Authentication via NC session cookie, HTTP
Basic Auth, or Bearer token.

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
  "https://nc.example.com/ocs/v2.php/apps/file_checksum_search/api/v1/lookup?hash=da39a3ee5e6b4b0d3255bfef95601890afd80709&algo=sha1"
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

## Admin & Personal Settings

Navigate to **Administration settings → Additional settings → File Checksum Index & Search**.

The admin settings page provides:

- **Status overview** — app version, indexed hash count, pending updates by mode, the eroded count,
  and each background job's last run with its counts
- **An idle banner** — shown while no enabled `include` rule exists, saying that automatic hashing
  is off, that sidebar recalculation still works, and that the home-folders default covers home
  folders only. *Acknowledged* silences it until hashing is switched on and off again
- **Hash generation rules** — one banded table in evaluation order: create, edit (the pen, or the
  row menu), enable/disable, re-apply, and delete; drag to reorder within a segment; rows for
  namespaces without a rule of their own
- **Rule-editing permissions** — allow-all toggle, group list, and user list
- **Documentation** — in-app access to the FAQ, the user guide, README, API specs, and license

The personal settings page lists the rules applying to the current user — the enforced ones above
and the defaults below their own, read-only, so what will actually decide a file is visible rather
than only the part they may change. Its second tab is the user guide.

## Documentation

The three documents are split by who is reading, not by topic:

| Document | Audience | Where it is shown in-app |
|----------|----------|--------------------------|
| [`docs/user-guide.md`](docs/user-guide.md) | users | Personal settings → Help, and the Duplicates page |
| [`docs/FAQ.md`](docs/FAQ.md) | administrators, integrators | Admin settings → Documentation |
| This README | administrators, developers | Admin settings → Documentation |

`GET /help` serves the user guide and the FAQ to any authenticated user, since both pages that
use it are reachable by everyone. Administrators get the broader set — README, API specs and
licence included — through the admin settings **Documentation** tab, which also carries the user
guide, so whoever answers a question is reading the same words as the person asking it.

## Troubleshooting

**Nothing is being hashed.** That is the default state, not a fault: no `include` rule is enabled.
The admin settings page says so in a banner; enable the *All home folders* rule, or write your own.

**The indexed count went down.** Files were eroded — modified while no rule maintained them, so
their outdated hashes were dropped (see [When hashes go away](#when-hashes-go-away)). The status page
counts them, and coverage heals them.

**A rule never matches.** Check its band and its selector: a higher band may be claiming the files
first, and a rule addressing a group folder whose app is disabled is badged *provider missing*
because it cannot match at all. `file-checksum-search:hash --user=<uid> -vv` names the rule that
decided each file.

If the checksum metadata index becomes out of sync with `oc_filecache`, rebuild it:

```bash
php occ file-checksum-search:rebuild
```

For hashes that are missing or outdated, table-prefix configuration, and other common issues, see [docs/FAQ.md § Troubleshooting](docs/FAQ.md#troubleshooting).

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE) for details.
