# AP KeyNamespace v1.2: give the hash keys a prefix of their own

*Approved. Supersedes v1.1 and v1.0, which stay where they are until the work
is done and all three retire together.*

**Changed in v1.2.** v1.1 still had a migration, an expensive document scan, and
a gap that would have stranded the very files the previous AP existed to rescue.

- **A repair step, not a migration.** A migration records itself as run, so
  restoring `oc_files_metadata` from before the rename would leave old-spelled
  documents that nothing ever touches again. A repair step heals that on the
  next `occ maintenance:repair`, and — being a post-migration step — still runs
  inside the upgrade's maintenance window, so nothing is lost.
- **The rename finds its work through the index**, not by scanning documents.
- **The stamp row is the candidate set** for files whose hash rows are missing,
  which closes the gap v1.1 left.
- **The repairer learns the legacy spelling.** That is not the shim that was
  ruled out: the readers never learn it. A repair step that cannot recognise
  what it repairs is useless.

## The two things a file has

Used throughout, and worth fixing before anything else because the whole AP is
about the gap between them:

| Term | Table | Holds |
|---|---|---|
| **document** | `oc_files_metadata` | one row per file — *all* apps' metadata as JSON, this app's checksums beside `photos-exif` and the rest. The authoritative copy. |
| **index row** | `oc_files_metadata_index` | one row per file **per key**, flattened so it can be searched. Derived. |

A rename has to reach both, and they can disagree — which is what most of this
plan is about.

## Discussion

Every key this app writes is spelled `file-checksum-…`:

| Key | Holds |
|---|---|
| `file-checksum-sha1` … `file-checksum-sha3-512` | a hash, one key per algorithm |
| `file-checksum-updated_at` | the freshness stamp, and the queue/`stale:` marker |

So no query can say *"the hash keys"*. `LIKE 'file-checksum-%'` means "any of
this app's keys", and everything that wants hashes subtracts the stamp by name:
**8 queries** carry an `AND meta_key <> 'file-checksum-updated_at'` rider, **1**
cannot use `LIKE` at all and enumerates seven algorithms as a seven-way `OR`,
and **31 references** to the stamp's constant sit in `MetadataService` alone,
most of them there to exclude it.

It has already cost twice in one working day, both times silently: a guard whose
two counts could never agree, because the second matched every file the app had
ever *considered* — 455 documents against the 302 holding a hash — and the same
pattern making a walk visit half again as many rows as it needed. A count wrong
in the same direction every time looks like a working guard.

```
file-checksum-hash-sha256      a hash
file-checksum-updated_at       not a hash
```

*Considered:* renaming the **stamp** instead, one key rather than eight.
Rejected: it holds only until the second non-hash key exists, and then the next
person pays the same tax with the same silent failures.

## Analysis

### There is no window to cover

`occ upgrade` runs under maintenance mode, and an instance whose apps are behind
is forced into it. Migrations and post-migration repair steps both run inside
that window, so between the new code arriving and the data matching it, nothing
reads. **No compatibility shim on the read path** — not one that is later
removed; one that is never written.

### The rename is sixteen statements, and none of them scans a document

`oc_files_metadata.json` is `Types::TEXT` on every backend
(`Version28000Date20231004103301:36`), so renaming a key is string replacement.
Narrowed through the index so it touches only the rows that need it:

```sql
UPDATE oc_files_metadata
   SET json = REPLACE(json, '"file-checksum-sha256":', '"file-checksum-hash-sha256":')
 WHERE file_id IN (SELECT file_id FROM oc_files_metadata_index
                    WHERE meta_key = 'file-checksum-sha256');

UPDATE oc_files_metadata_index
   SET meta_key = 'file-checksum-hash-sha256'
 WHERE meta_key = 'file-checksum-sha256';
```

Eight of each. The inner query scans a covering index of narrow entries; the
outer resolves `file_id` through `files_meta_fileid`. MySQL's "can't specify
target table in FROM" restriction does not apply — the subquery reads a
different table.

**No guard is needed.** Each statement guards itself: nothing old-spelled means
an empty subquery and an update that touches nothing. The pattern carries its
quotes and colon — `"file-checksum-sha256":` — so it matches a key and cannot
match a value; values are hex digests and integers.

**Verified live** on instance 34: `SELECT REPLACE(json, …)` over a real document
returns valid JSON holding `file-checksum-hash-sha256`, with
`file-checksum-updated_at` untouched. `REPLACE` is implemented by MySQL/MariaDB,
PostgreSQL, SQLite, Oracle and SQL Server alike; the index half needs no
function at all.

### The five states, and who fixes each

| Document | Index rows | Fixed by |
|---|---|---|
| old | old | the bulk rename above — the ordinary upgrade |
| old | **none** | `rebuild-from-metadata`, taught the legacy spelling |
| old | new | only from renaming one table by hand; `--include-expensive` |
| new | none | `rebuild-from-metadata`, as today |
| new | new | nothing |

Row 2 is the trap. Those are the files whose hash rows were never written
because the value was too long for the column — the population the previous AP
existed to rescue — on an instance upgrading from before that fix straight past
this one. The bulk rename finds documents *through* their index rows and these
have none, so it cannot see them.

**Rows 2 and 4 are the same problem** — a document holding hashes the index does
not have — differing only in spelling, which is incidental. So they are one
step's work, not two, and that step must recognise both spellings.

### The stamp row is how those files are found

Every file this app has considered has a `file-checksum-updated_at` index row,
**and that row is reliable for a structural reason**: it is an INT in
`meta_value_int` and never met the `varchar(63)` limit that lost the hash rows.
It survived exactly the failure that creates rows 2 and 4.

So the candidate set for the per-file work is not "every document" but:

```sql
file_id IN (SELECT file_id FROM oc_files_metadata_index
             WHERE meta_key = 'file-checksum-updated_at')
```

Measured on instance 34: 309 documents hold a hash, and **zero** of them lack a
stamp row. The saving is instance-shaped and never negative — here the app has
touched nearly everything, so it saves little; where Photos has written EXIF for
files this app never hashed, it is the difference between reading all of them
and reading none.

The stamp row can outlive its document (two such rows exist on instance 34,
left by hand-editing), so the walk must tolerate a candidate whose document has
gone.

### Why the repairer knowing the old spelling is not a shim

The readers — search, `getHashes()`, the lookup path — never learn it. The
*repairer* must, because recognising what it repairs is its purpose, exactly as
`stale-states`, `legacy-pending` and `legacy-seed-job` already know spellings
nothing writes any more. It costs nothing on the hot path and it does not
expire.

And it costs nothing extra to run: `hashIndexIsComplete()` already scans for
documents holding a hash on every repair. Teaching that same query the legacy
patterns adds `OR` terms to a scan already being paid for — and narrowing it by
the stamp row makes it cheaper than it is today.

### `--include-expensive` keeps a narrower job

One case the stamp row cannot see: index rows lost wholesale, as in a restore of
`oc_files_metadata` without its index. Then there are no stamp rows either, and
only reading the documents directly finds anything. That is a recovery path, not
the normal way to be thorough.

### What is not affected

- **Backups.** A record stores `"algo": "sha256"`, never the key
  (`HashRecord:47,65`); the import maps it through `getHashKey()`. A backup
  written before the rename restores after it, and one written after restores
  before it.
- **`oc_filecache.checksum`.** Core's column, `SHA1:… MD5:…`, untouched.
- **The frontend.** No `.ts` or `.vue` file mentions a metadata key.
- **The search API.** `algo:hash` syntax is about algorithms, not keys.

### Withdrawing the old declarations

`initMetadata()` has no counterpart that forgets a key, but the declarations are
one app-config array — `core` / `files_metadata`
(`FilesMetadataManager::CONFIG_KEY`) — so the step reads it, removes the eight
old entries and writes it back. Idempotent, and safe inside the upgrade window
because nothing else is registering keys then.

## Implementation Plan

One release; separate commits for review. Blocks 1 and 2 cannot be split across
releases — the first is what makes the second necessary.

### Block 1 — The new spelling, and the bulk rename · `[FIX]`

`getHashKey()` returns `file-checksum-hash-<algo>`; `KEY_FILE_CHECKSUM_LIKE`
becomes the new prefix; registration declares the new keys. A `key-namespace`
repair step runs the sixteen statements and withdraws the old declarations.

Declared **before** `rebuild-from-metadata`: a document renamed first is then
indexed correctly, where the other order would faithfully write old-spelled
index rows from an old-spelled document and undo the rename.

**Verification:** on instance 34 — 309 documents and 762 index rows carry the
old spelling; after the step, none do, every algorithm still searches, and
`core`/`files_metadata` no longer lists the old keys. A second run changes
nothing.

### Block 2 — The repairer learns the legacy spelling · `[FIX]`

`rebuild-from-metadata` and `hashIndexIsComplete()` recognise hashes in either
spelling, and both narrow their candidates by the stamp row. A document found
old-spelled is renamed before its index rows are written.

**Verification:** a file whose document holds an old-spelled hash and has **no**
hash index row — row 2, built by hand — is renamed and indexed by one repair
run, with no `--include-expensive`.

### Block 3 — Spend the winnings · `[TASK]`

Delete the eight riders and collapse the seven-way `OR`. Smaller than it first
looked: the repair keeps its legacy-aware finder deliberately.

**Verification:** the repair's two counts agree on a healthy instance — the
thing that was silently false before this AP.

### Block 4 — Documentation · `[TASK]`

The README's account of what this app stores, with the document/index-row
distinction named, and a FAQ answer for an administrator reading their own
database.

## Decisions (answered by the user, 2026-08-31)

1. **`hash`, not `algo`,** in the new prefix.
2. **No shim, ever,** on the read path — the upgrade window means there is
   nothing to be compatible with.
3. **Withdraw the old declarations**, by rewriting the array core keeps them in.
4. **A repair step, not a migration**, so a partial restore is healed rather
   than stranded.
5. **Retire v1.0, v1.1 and v1.2 together** when the work is done.
