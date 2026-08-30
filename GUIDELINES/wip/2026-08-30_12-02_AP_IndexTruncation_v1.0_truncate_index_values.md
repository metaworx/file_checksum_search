# AP IndexTruncation v1.0: truncate index values on write

*Preliminary — not yet approved.*

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

### The read side has one hole left

`queryByHash()` truncates the search term, so a long-hash lookup returns every
file whose first 63 characters match. Two of its three consumers then confirm
against the full value from the document — `ChecksumApi:295-300` and
`DuplicateService:98-118`. **`HashSearchProvider:93` does not**, so Nextcloud's
unified search would show a false positive where the others would not. With no
index rows existing today the hole is unreachable; the moment Block 1 lands it
becomes live, so it is fixed in the same AP.

Collision risk is negligible (252 bits of prefix for SHA-256), but "negligible"
is not "handled", and the difference between two consumers that check and one
that does not is a bug whatever the odds.

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
sha256 and that the document keeps 64; live, a fresh hash of one file produces a
`file-checksum-sha256` index row.

### Block 2 — Search confirms the full hash · `[FIX]`

`HashSearchProvider` verifies each row's full value from the document the way
`ChecksumApi` and `DuplicateService` do. The check belongs in one place — extract
it, so a fourth consumer cannot forget it.

**Verification:** a test with two files whose sha256 hashes share 63 characters
and differ in the last: search finds one, not both.

### Block 3 — Backfill the rows that were never written · `[FIX]`

A repair step over every document holding a `file-checksum-*` key, writing the
missing truncated index rows. Idempotent, paged, and reported by count. Reuses
the paging of `exportHashes()`.

**Verification:** on instance 34, index rows for sha256/sha512/sha3-* appear for
all 102/112/82 documents that hold them, and `occ …:status`'s row count rises to
match.

### Block 4 — Say so in the documentation · `[TASK]`

The README's note about long digests being "silently truncated by the database"
describes something that never happened; it becomes an accurate description of
truncation on write plus full-value confirmation on read.

## Open questions for the user

1. **`--now`-style repair or automatic?** Block 3 as a `maintenance:repair` step
   runs on upgrade without asking. The alternative is a flag on
   `fcias:rebuild`, which an administrator has to know to run. Repair is the
   safer default; it costs one document read per hashed file, once.
2. **Should the index carry a length marker?** Storing 63 characters of a 64-
   character hash is indistinguishable, in the row, from a complete 63-character
   value that no algorithm produces. It does not matter today because the
   algorithm is in `meta_key`, but if a future algorithm produced exactly 63
   characters the confirmation step would skip it. Naming it here rather than
   discovering it later.
