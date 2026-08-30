# AP IndexTruncation v1.3: truncate index values on write

*Approved. Supersedes v1.2, v1.1 and v1.0, which stay where they are.*

**Changed in v1.3:** records one alternative considered while Block 2 was being
written — confirming the full hash with a `LIKE` against the stored document,
in SQL, rather than in PHP — and why it was not taken.

**Changed in v1.1:** Block 2 was written as "add the confirmation to
`HashSearchProvider`". That would have been a third copy of a check that is
already written twice. It becomes an extraction instead — see below.

**Changed in v1.2:** both open questions are answered and folded into the plan.
The repair runs automatically as a `maintenance:repair` step — an administrator
can run those on demand, so no second entry point earns its place. And there is
**no length marker**; the reasoning is below, because the reason it was
considered is worth keeping.

## Discussion

**Searching for a SHA-256, SHA-512 or SHA3-* hash finds nothing.** Not "finds
the wrong thing" — finds nothing, silently, on every instance, for the
algorithms most people would pick. The app's headline feature does not work for
its most likely input.

The cause is one missing step. `oc_files_metadata_index.meta_value_string` is
`varchar(63)`. The design this app was built on assumed MySQL would truncate a
longer value on insert; under strict mode — the default everywhere now — the
insert **fails** instead. Nextcloud's own writer swallows that:

```php
// nextcloud/lib/private/FilesMetadata/Service/IndexRequestService.php:66
} catch ( FilesMetadataNotFoundException|FilesMetadataTypeException|DbException $e ) {
    $this->dbConnection->rollBack();
    $this->logger->warning( 'issue while updateIndex', [ … ] );
}
```

So no index row is ever written for a long hash, and no error reaches the app.
Measured on instance 34 on 2026-08-30: **11,688** `issue while updateIndex`
warnings in `nextcloud.log`, zero `file-checksum-sha256` index rows against 102
documents holding one, `sha1` search working and `sha256` search returning
"No files found."

The intended behaviour was written down long ago and is already half-built:
**truncate to 63 before the INSERT, truncate the search term when matching, and
fall back to the full value in `oc_files_metadata.json` to confirm.** Two of
those three exist — `truncateForIndex()` on the query side and
`verifyTruncatedDuplicateGroups()` on the grouping side. Only the write side is
missing, and read-side truncation cannot help: it matches against a row that was
never created.

### Why the app must write the index row itself

There is no hook to alter what Nextcloud puts in the index: `updateIndex()`
inserts `$metadata->getString($key)` verbatim. The document must keep the **full**
hash — the sidebar shows it, the backup exports it, and the full-value fallback
depends on it — so the two values must differ, and only one party can write the
index row.

The precedent is already in the codebase: `upsertUpdatedAtString()` writes the
string half of `file-checksum-updated_at` **after** `saveMetadata()`, because
saving regenerates index rows. Hash keys take the same route:

| | Today | After |
|---|---|---|
| `initMetadata( 'file-checksum-<algo>', …, indexed: **true** )` | Nextcloud tries to index the full value and fails | registered **unindexed** — Nextcloud writes no row |
| Index row | never written | written by the app, truncated to 63 |
| Document | full hash | unchanged, full hash |

*Rejected:* storing the truncated value in the document (loses data the export
and the sidebar need) and the dual-key `<algo>` + `<algo>-full` scheme (dropped
in the 2026-08-06 design for good reason — it doubles every write and every
document).

### The read side has one hole, and it is a hole because the check is copied

`queryByHash()` truncates the search term, so a long-hash lookup returns every
file whose first 63 characters match. The caller has to confirm the full value
before trusting a row — and **the same confirmation is written out three times**:

| Where | What it does |
|---|---|
| `ChecksumApi:300-320` | `strlen($hash) > MAX` → `extractAlgorithm()` → skip if the full value differs |
| `DuplicateService:118-137` | the same two steps, the same comment, the same skip |
| `MetadataService::verifyTruncatedDuplicateGroups()` | the same idea again, for groups rather than rows |

`HashSearchProvider:93` has no copy, so Nextcloud's unified search would show a
false positive where the other two would not. That is the hole — but the *cause*
is that the check is a habit rather than a function. Adding a fourth copy would
fix the symptom and leave the next consumer to forget it again.

So Block 2 **extracts** it: one method on `MetadataService` — the class that
owns both the index and the documents the confirmation reads — and all three
existing sites plus the search provider call it. Net effect: one copy where
there were three, and one fewer place to forget.

With no index rows existing today the hole is unreachable; the moment Block 1
lands it becomes live, which is why it is in the same AP.

Collision risk is negligible (252 bits of prefix for SHA-256), but "negligible"
is not "handled", and a check that two callers make and a third does not is a
bug whatever the odds.

### Existing instances need the rows backfilled

Every hash written before this fix is in a document with no index row. Nothing
notices it — the count on the status page comes from the index, so those files
look unhashed there while the sidebar shows their hashes. A repair step has to
walk the documents and write the missing rows. `MetadataService::exportHashes()`
already pages documents exactly this way and can be reused.

## Analysis

### What is affected

| Algorithm | Hex length | Indexable today | After |
|---|---|---|---|
| crc32, adler32 | 8 | yes | yes |
| md5 | 32 | yes | yes |
| sha1 | 40 | yes | yes |
| **sha256, sha3-256** | **64** | **no** | truncated to 63 |
| **sha512, sha3-512** | **128** | **no** | truncated to 63 |

Four of the seven supported algorithms are unusable for search today, including
the two the rules on a typical instance select.

### Where hashes are written

| Site | What it does |
|---|---|
| `HashCalculationService:752,908,971` | computes and stores; the main path |
| `MetadataService::backfillHashes():1283` | copies filecache checksums in |
| `MetadataService::writeHashes():1385` | the import |

All three end in `metadataManager->saveMetadata()`. The index write belongs
immediately after it, in one place, rather than at each call site.

### Verification that the fix is real

Not "the tests pass": **`occ file-checksum-search:search <a sha256>` returns the
file**, on instance 34, having returned nothing before. And
`grep -c 'issue while updateIndex' nextcloud.log` stops growing.

## Implementation Plan

Each block is one commit.

### Block 1 — The app writes its own hash index rows · `[FIX]`

Register hash keys with `indexed: false`, and add
`MetadataService::indexHashes( int $fileId, array $algoToHash )`, called after
every `saveMetadata()` that writes hashes: one upsert per algorithm with
`truncateForIndex()` applied, mirroring `upsertUpdatedAtString()`. Removal stays
with `pruneHashIndexRows()`, which already exists.

**Verification:** a unit test asserting the *stored* value is 63 characters for a
sha256 while the document keeps 64; a guard test asserting no supported
algorithm produces a digest of exactly `META_VALUE_STRING_MAX_LENGTH`
characters, since that is what makes a truncated row recognisable without a
marker; live, a fresh hash of one file produces a `file-checksum-sha256` index
row.

### Block 2 — One full-hash confirmation, not three copies and a gap · `[FIX]`

Extract `MetadataService::confirmFullHash( array $rows, string $hash ): array` —
returns the rows whose *document* value really is `$hash`, and returns them
untouched when the hash is short enough that the index could not have truncated
it. Then:

- `ChecksumApi` and `DuplicateService` drop their copies and call it;
- `HashSearchProvider` calls it, closing the gap by construction rather than by
  remembering;
- `verifyTruncatedDuplicateGroups()` keeps its own shape — it splits a group
  rather than filtering rows — but reuses the same length test so there is one
  definition of "could this have been truncated".

**Verification:** a test with two files whose sha256 hashes share their first 63
characters and differ in the last — search finds one, not both — plus the
existing `ChecksumApi` and `DuplicateService` tests still passing against the
extracted method, which is what proves it is the same check and not a new one.

### Block 3 — Backfill the rows that were never written · `[FIX]`

A `maintenance:repair` step over every document holding a `file-checksum-*`
key, writing the missing truncated index rows. It runs on upgrade, and an
administrator can run it sooner with `occ maintenance:repair`. Idempotent,
paged, and reported by count. Reuses the paging of `exportHashes()`.

**Verification:** on instance 34, index rows for sha256/sha512/sha3-* appear for
all 102/112/82 documents that hold them, and `occ …:status`'s row count rises to
match.

### Block 4 — Say so in the documentation · `[TASK]`

The README's note about long digests being "silently truncated by the database"
describes something that never happened; it becomes an accurate description of
truncation on write plus full-value confirmation on read.

### Considered: confirming in SQL instead of PHP

`queryByHash()` already joins `oc_files_metadata`, so the confirmation could be
a predicate rather than a second step:

```sql
AND m.json LIKE '%"file-checksum-sha256":{"value":"<full hash>"%'
```

It is portable — plain `LIKE`, no vendor JSON functions, and a hex digest
contains no `%` or `_` to escape — and it would not force a scan, because the
pattern is only evaluated on rows the indexed predicate already returned.

**Not taken**, for two reasons. The document is read anyway: both existing
callers report `algo` and the full `hash` from `extractAlgorithm()`, so the
predicate would sit on top of a read that still has to happen, and after an
equality match on a 63-character prefix there is at most a row or two to narrow.
And the pattern depends on the key order and whitespace of Nextcloud's own
serialisation — the value is nested under `"value"` — so a change there would
make the query return **nothing**, silently. A silent-empty failure is the exact
shape of the defect this AP exists to fix.

## Decisions (answered by the user, 2026-08-30)

1. **`--now`-style repair or automatic?** **Automatic**, as a
   `maintenance:repair` step: it runs on upgrade without asking, and an
   administrator who wants it sooner can run `occ maintenance:repair` on
   demand — so a second entry point on `fcias:rebuild` would add API for
   something already reachable. It costs one document read per hashed file,
   once.
2. **Should the index carry a length marker?** **No.** Two reasons, the first
   decisive:

   - **A hex digest can never be 63 characters.** They are even-length by
     construction — 8, 32, 40, 64, 128 — so a 63-character row *is* a prefix,
     always. And `meta_key` names the algorithm, so the full length is known for
     free: `file-checksum-sha256` means 64, so a 63-character value is
     truncated. A marker would encode what is already derivable.
   - **`varchar(63)` has no spare character.** A marker means truncating to 62,
     paid for in discriminating prefix — the one thing the index is for.

   Were one ever wanted, it must be a **suffix**, not a prefix. A prefix groups
   every truncated value together and away from the untruncated ones, which
   destroys ordering by hash: range scans and `LIKE 'abc%'` on the value stop
   working. A suffix at position 63 preserves lexicographic order exactly, and
   stays unambiguous because no hex value can end in a non-hex character.

   What this does need is a **guard rather than a marker**: a test asserting
   that no supported algorithm produces a digest of exactly
   `META_VALUE_STRING_MAX_LENGTH` characters. That is the condition under which
   the reasoning above stops holding, and it should fail loudly on the day
   somebody adds such an algorithm rather than silently skipping the
   confirmation.
