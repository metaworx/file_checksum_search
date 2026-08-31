# AP KeyNamespace v1.3: give the hash keys a prefix of their own

*Approved. Supersedes v1.2, v1.1 and v1.0, which stay where they are until the
work is done and all four retire together.*

**Changed in v1.3.**

- **A step that finds what nothing else can.** `unindexed-hashes` scans every
  metadata document for hashes the index has never heard of. It is the one case
  no cheap sentinel reaches, so it never runs automatically — a new
  `manualOnly` flag on `#[RepairStep]`, distinct from `expensive`.
- **"Metadata document" throughout.** A file may itself *be* a document — a PDF,
  a spreadsheet — so the bare word was ambiguous in the one plan where the
  distinction carries the argument. Also 57 code comments and 5 doc paragraphs
  in the existing tree, swept in Block 5.
- **The legacy-aware finder is confirmed repair-only.** `reindexHashes()` has
  exactly one caller, the repair step; `hashIndexIsComplete()` and
  `whereDocumentHoldsAHash()` are reached only from it. No request path
  evaluates a legacy pattern.

## The two things a file has

The whole AP is about the gap between them:

| Term | Table | Holds |
|---|---|---|
| **metadata document** | `oc_files_metadata` | one row per file — *all* apps' metadata as JSON, this app's checksums beside `photos-exif` and the rest. The authoritative copy. |
| **index row** | `oc_files_metadata_index` | one row per file **per key**, flattened so it can be searched. Derived. |

A rename must reach both, and they can disagree.

## Discussion

Every key this app writes is spelled `file-checksum-…`:

| Key | Holds |
|---|---|
| `file-checksum-sha1` … `file-checksum-sha3-512` | a hash, one key per algorithm |
| `file-checksum-updated_at` | the freshness stamp, and the queue/`stale:` marker |

So no query can say *"the hash keys"*. `LIKE 'file-checksum-%'` means "any of
this app's keys", and everything wanting hashes subtracts the stamp by name:
**8 queries** carry an `AND meta_key <> 'file-checksum-updated_at'` rider, **1**
cannot use `LIKE` at all and enumerates seven algorithms as a seven-way `OR`,
and **31 references** to the stamp's constant sit in `MetadataService` alone,
most there to exclude it.

It has cost twice in one working day, both times silently: a guard whose two
counts could never agree, because the second matched every file the app had ever
*considered* — 455 metadata documents against the 302 holding a hash — and the
same pattern making a walk visit half again as many rows as it needed. A count
wrong in the same direction every time looks like a working guard.

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
reads. **No compatibility shim on the read path** — not one later removed; one
never written.

### The rename is sixteen statements, and none reads a metadata document

`oc_files_metadata.json` is `Types::TEXT` on every backend
(`Version28000Date20231004103301:36`), so renaming a key is string replacement,
narrowed through the index so it touches only the rows needing it:

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
an empty subquery and an update touching nothing. The pattern carries its quotes
and colon — `"file-checksum-sha256":` — so it matches a key, never a value;
values are hex digests and integers.

**Verified live** on instance 34: `SELECT REPLACE(json, …)` over a real metadata
document returns valid JSON holding `file-checksum-hash-sha256`, with
`file-checksum-updated_at` untouched. `REPLACE` is implemented by MySQL/MariaDB,
PostgreSQL, SQLite, Oracle and SQL Server alike; the index half needs no
function.

### The five states, and who fixes each

| Metadata document | Index rows | Fixed by |
|---|---|---|
| old | old | the bulk rename — the ordinary upgrade |
| old | stamp only | `rebuild-from-metadata`, taught the legacy spelling |
| old | **none at all** | `unindexed-hashes` |
| new | stamp only | `rebuild-from-metadata`, as today |
| new | complete | nothing |

Row 2 is the trap the earlier versions missed: files whose hash rows were never
written because the value exceeded the column — the population the previous AP
existed to rescue — on an instance upgrading from before that fix straight past
this one. The bulk rename finds metadata documents *through* their index rows,
and these have none.

**Rows 2 and 4 are the same problem** — a metadata document holding hashes the
index does not have — differing only in spelling, which is incidental. One
step's work, and it must know both spellings.

### The stamp row finds rows 2 and 4

Every file this app has considered has a `file-checksum-updated_at` index row,
**reliable for a structural reason**: it is an INT in `meta_value_int` and never
met the `varchar(63)` limit that lost the hash rows. It survived exactly the
failure that creates those rows.

```sql
file_id IN (SELECT file_id FROM oc_files_metadata_index
             WHERE meta_key = 'file-checksum-updated_at')
```

Measured on instance 34: 309 metadata documents hold a hash, and **zero** lack a
stamp row. The saving is instance-shaped and never negative — here the app has
touched nearly everything; where Photos has written EXIF for files this app
never hashed, it is the difference between reading all of them and none.

A stamp row can outlive its metadata document (two such exist on instance 34,
left by hand-editing), so the walk must tolerate a candidate whose document has
gone.

### Row 3 needs a scan, so it needs its own step

If the index was lost wholesale — a restore of `oc_files_metadata` without
`oc_files_metadata_index` — there is no stamp row either, and no cheap query can
find those files. Only reading every metadata document does:

```sql
SELECT file_id FROM oc_files_metadata m
 WHERE (m.json LIKE '%"file-checksum-sha1":%' OR … OR m.json LIKE '%"file-checksum-hash-sha1":%' OR …)
   AND NOT EXISTS (SELECT 1 FROM oc_files_metadata_index i
                    WHERE i.file_id = m.file_id
                      AND i.meta_key = 'file-checksum-updated_at')
```

Finding them *is* the expense, so there is nothing to guard with — which makes
this different from every other expensive step, all of which can ask cheaply
whether they have work. It therefore **never runs automatically**: a new
`manualOnly` flag on `#[RepairStep]`, reached by `--step unindexed-hashes` or by
`--include-expensive`, and listed by `--list` as what it is.

What it does when it finds one: reindex that file — write its hash index rows,
and let saving the metadata document restore the stamp row Nextcloud still
indexes for us.

### Why the repairer knowing the old spelling is not a shim

The readers never learn it. `reindexHashes()` has exactly **one** caller, the
repair step, and `hashIndexIsComplete()` and `whereDocumentHoldsAHash()` are
reached only from there — so no search, no lookup, no sidebar request ever
evaluates a legacy pattern. The repairer must know it, because recognising what
it repairs is its purpose, exactly as `stale-states`, `legacy-pending` and
`legacy-seed-job` already know spellings nothing writes any more.

It also costs nothing extra to run: `hashIndexIsComplete()` already scans for
metadata documents holding a hash on every repair. Teaching that query the
legacy patterns adds `OR` terms to a scan already paid for — and narrowing it by
the stamp row makes it cheaper than it is today.

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

Declared **before** `rebuild-from-metadata`: a metadata document renamed first
is then indexed correctly, where the other order would faithfully write
old-spelled index rows from an old-spelled document and undo the rename.

**Verification:** on instance 34 — 309 metadata documents and 762 index rows
carry the old spelling; after the step, none do, every algorithm still searches,
and `core`/`files_metadata` no longer lists the old keys. A second run changes
nothing.

### Block 2 — The repairer learns the legacy spelling · `[FIX]`

`rebuild-from-metadata` and `hashIndexIsComplete()` recognise hashes in either
spelling, and both narrow their candidates by the stamp row. A metadata document
found old-spelled is renamed before its index rows are written.

**Verification:** a file whose metadata document holds an old-spelled hash and
has no hash index row — row 2, built by hand — is renamed and indexed by one
repair run, with no flag.

### Block 3 — `unindexed-hashes`, and `manualOnly` · `[TASK]`

The attribute gains `manualOnly`; `fcias:repair` skips such steps unless named
or `--include-expensive`; `--list` and `--dry-run` say so. The step itself scans
every metadata document for hashes with no stamp row, and reindexes what it
finds.

**Verification:** delete every index row for one hashed file; an ordinary repair
does not find it, `--step unindexed-hashes` does, and afterwards it searches
again.

### Block 4 — Spend the winnings · `[TASK]`

Delete the eight riders and collapse the seven-way `OR`. Smaller than it first
looked: the repair keeps its legacy-aware finder deliberately.

**Verification:** the repair's two counts agree on a healthy instance — the
thing that was silently false before this AP.

### Block 5 — Documentation and the word · `[TASK]`

The README's account of what this app stores, with the metadata-document /
index-row distinction named, and a FAQ answer for an administrator reading their
own database. Plus the sweep: 57 code comments and 5 doc paragraphs say
"document" where they mean "metadata document", which is ambiguous in an app
whose subject matter is files that may themselves be documents.

## Decisions (answered by the user, 2026-08-31)

1. **`hash`, not `algo`,** in the new prefix.
2. **No shim, ever,** on the read path.
3. **Withdraw the old declarations.**
4. **A repair step, not a migration**, so a partial restore heals rather than
   strands.
5. **Retire v1.0 through v1.3 together** when the work is done.
6. **A full-scan step for hashes with no stamp row**, never automatic.
7. **"Metadata document", never bare "document"**, in this plan and in the tree.
