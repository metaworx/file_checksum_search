# AP KeyNamespace v1.1: give the hash keys a prefix of their own

*Approved. Supersedes v1.0, which stays where it is.*

**Changed in v1.1.** v1.0 was built around a window that does not exist. It had
readers learning both spellings first, a compatibility shim on the hot query,
an expensive paged walk behind a count guard, and an open question about which
release would remove the shim. All of that is gone:

- **`occ upgrade` runs under maintenance mode**, and an instance whose apps are
  not yet upgraded is forced into it. Migrations and post-migration repair steps
  therefore run with no other readers. There is nothing to be compatible with,
  and no shim — *not one that is later removed; one that is never written.*
- **The rename is not a walk.** `oc_files_metadata.json` is `Types::TEXT` on
  every backend, so a SQL `REPLACE` renames a key in place. Verified live on
  MariaDB: the result parses as JSON, carries `file-checksum-hash-sha256`, and
  leaves `file-checksum-updated_at` alone.
- **`hash` replaces `algo` in the new prefix.** The value is a hash, keyed *by*
  algorithm; `file-checksum-hash-sha256` says what it holds.
- **The old declarations are withdrawn**, which v1.0 had listed as impossible.

What is left is one migration and the cleanup it pays for.

## Discussion

Every piece of this app's metadata is spelled `file-checksum-…`:

| Key | Holds |
|---|---|
| `file-checksum-sha1` … `file-checksum-sha3-512` | a hash, one key per algorithm |
| `file-checksum-updated_at` | the freshness stamp, and the queue/`stale:` marker |

So no query can say *"the hash keys"*. `LIKE 'file-checksum-%'` means "any of
this app's keys", and everything that wants hashes subtracts the stamp by name:

- **8 queries** carry an `AND meta_key <> 'file-checksum-updated_at'` rider;
- **1 query** cannot use `LIKE` at all and enumerates the seven algorithms as a
  seven-way `OR`, because against a JSON document the subtraction is not
  expressible;
- **31 references** to the stamp's constant in `MetadataService` alone, most of
  them there to exclude it rather than to use it.

The prefix was raised when the keys were designed, and rejected. It has now cost
twice in one working day, both times silently:

- the guard added in *AP RepairCommand* compared "files with a hash row" against
  "documents mentioning a hash", and the second count matched every file the app
  had ever *considered* — 455 documents against the 302 holding a hash. The
  counts could never agree, so the guard never fired;
- the same pattern made the repair's walk visit half again as many documents as
  it needed.

Neither failed loudly. A count wrong in the same direction every time looks like
a working guard.

### The fix is to name what a hash key is

```
file-checksum-hash-sha256      a hash
file-checksum-hash-sha3-512    a hash
file-checksum-updated_at       not a hash
```

`LIKE 'file-checksum-hash-%'` then means the hashes and nothing else.

*Considered:* renaming the **stamp** instead — one key rather than eight.
Rejected, because it only holds until the second non-hash key exists. Anything
added later (`…-source`, `…-verified-at`) collides again and the next person
pays the same tax with the same silent failures. Naming the hashes positively
stays correct as the app grows.

## Analysis

### Why there is no window to cover

Nextcloud enables maintenance mode for the duration of `occ upgrade`, and
refuses to serve an instance whose apps are behind. Migrations and
post-migration repair steps both run inside that window. So between the new code
arriving and the data matching it, nothing reads.

That removes the only argument for teaching the readers both spellings. A shim
would have put an `OR` on the hot lookup path, for a case that cannot occur, and
left behind a question about when to take it out again.

### The rename is sixteen statements

`oc_files_metadata.json` is `Types::TEXT` (core's
`Version28000Date20231004103301:36`), so the document half is string
replacement, not a read-modify-write per file:

```sql
UPDATE oc_files_metadata
   SET json = REPLACE(json, '"file-checksum-sha256":', '"file-checksum-hash-sha256":')
 WHERE json LIKE '%"file-checksum-sha256":%';

UPDATE oc_files_metadata_index
   SET meta_key = 'file-checksum-hash-sha256'
 WHERE meta_key = 'file-checksum-sha256';
```

Eight of each, or one pass with the replacements nested if the scan cost
matters. Both are idempotent through their `WHERE`, and both are
backend-agnostic: `REPLACE` is implemented by MySQL/MariaDB, PostgreSQL, SQLite,
Oracle and SQL Server alike, and the index half needs no function at all.

The pattern includes the quotes and the colon — `"file-checksum-sha256":` — so
it matches a key and cannot match a value. Values are hex digests and integers;
no other app's metadata in the same document can spell this app's key.

**Verified live** on instance 34: `SELECT REPLACE(json, …)` over a real document
returns valid JSON holding `file-checksum-hash-sha256` and an untouched
`file-checksum-updated_at`.

### Withdrawing the old declarations

`initMetadata()` has no counterpart that forgets a key, but the declarations are
one app-config array — `core` / `files_metadata`
(`FilesMetadataManager::CONFIG_KEY`) — so the migration reads it, removes the
eight old entries, and writes it back. Doing it inside the upgrade window is
what makes it safe: nothing else is registering keys at the time.

Without this the old names linger in core's configuration for ever, and a later
reader would reasonably wonder what this app failed to clean up.

### What is not affected

- **Backups.** A record stores `"algo": "sha256"`, never the metadata key
  (`HashRecord:47,65`), and the import maps it through `getHashKey()`. A backup
  written before the rename restores after it, and one written after restores
  before it. Nothing to migrate, nothing to version.
- **`oc_filecache.checksum`.** Core's column, `SHA1:… MD5:…`, untouched.
- **The frontend.** No `.ts` or `.vue` file mentions a metadata key.
- **The search API.** `algo:hash` syntax is about algorithms, not keys.

## Implementation Plan

### Block 1 — Rename, in one release · `[FIX]`

`getHashKey()` returns `file-checksum-hash-<algo>`; `KEY_FILE_CHECKSUM_LIKE`
becomes the new prefix; registration declares the new keys. A migration renames
the index rows and the documents, and withdraws the old declarations.

The code change and the migration ship together and cannot be separated: the
first is what makes the second necessary, and the upgrade window is what makes
having both at once safe.

**Verification:** on instance 34 — 302 documents and 762 index rows carry the
old spelling; after `occ upgrade`, none do, every algorithm still searches, and
`core`/`files_metadata` no longer lists the old keys. Re-running the migration
changes nothing.

### Block 2 — Spend the winnings · `[TASK]`

Delete the eight riders and collapse the seven-way `OR` into one `LIKE`. The
block this AP exists for.

**Verification:** the repair's two counts agree on a healthy instance — the
thing that was silently false before this AP — and its walk visits 302
documents rather than 455.

### Block 3 — Documentation · `[TASK]`

The README's description of what this app stores, and a FAQ answer for an
administrator reading their own database: what the keys are, and why the hashes
have a prefix of their own.

## Decisions (answered by the user, 2026-08-31)

1. **`hash`, not `algo`,** in the new prefix.
2. **No shim, ever** — not one that is later removed. The upgrade window means
   there is nothing to be compatible with.
3. **Withdraw the old declarations**, by rewriting the array core keeps them in.
