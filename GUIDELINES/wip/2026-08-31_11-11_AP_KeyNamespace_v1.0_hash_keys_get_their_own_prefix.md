# AP KeyNamespace v1.0: give the hash keys a prefix of their own

*Preliminary — not yet approved.*

## Discussion

Every piece of this app's metadata is spelled `file-checksum-…`:

| Key | Holds |
|---|---|
| `file-checksum-sha1` … `file-checksum-sha3-512` | a hash, one key per algorithm |
| `file-checksum-updated_at` | the freshness stamp, and the queue/`stale:` marker |

So there is no way to say *"the hash keys"* in a query. `LIKE 'file-checksum-%'`
means "any of this app's keys", and every place that wants hashes has to
subtract the stamp by name. Today that costs:

- **8 queries** carrying an `AND meta_key <> 'file-checksum-updated_at'` rider;
- **1 query** that cannot use `LIKE` at all and enumerates the seven algorithms
  as a seven-way `OR`, because the rider is not expressible against a JSON
  document;
- **31 references** to the stamp's constant in `MetadataService` alone, most of
  them there to exclude it rather than to use it.

The prefix was raised as a concern when the keys were designed, and rejected.
It has now cost twice in one working day, both times silently:

- the guard added in *AP RepairCommand* compared "files with a hash row" against
  "documents mentioning a hash", and the second count matched every file the app
  had ever *considered* — 455 documents against 302 holding a hash. The counts
  could never agree, so the guard never fired;
- the same pattern made the repair's walk visit half again as many documents as
  it needed.

Neither failed loudly. A count that is wrong in the same direction every time
looks like a working guard.

### The fix is to name what a hash key is

```
file-checksum-algo-sha256      a hash
file-checksum-algo-sha3-512    a hash
file-checksum-updated_at       not a hash
```

Then `LIKE 'file-checksum-algo-%'` means the hashes and nothing else, the eight
riders go, and the seven-way `OR` becomes one pattern.

*Considered:* renaming the **stamp** instead — `file-checksum-meta-updated_at` —
which touches one key rather than eight. Rejected, because it only holds until
the second non-hash key exists. Anything this app adds later (`…-source`,
`…-verified-at`) collides again, and the next person pays the same tax with the
same silent failures. Naming the hashes positively is the design that stays
correct as the app grows.

*Considered:* `file-checksum-hash-<algo>`, which is more literally true — the
value is a hash, keyed *by* algorithm. `algo` was the spelling proposed and is
the one used here; the difference is one word in a key nobody types.

### What is not affected

- **Backups.** A record stores `"algo": "sha256"`, never the metadata key
  (`HashRecord:47,65`), and the import maps it through `getHashKey()`. A backup
  written today restores correctly after the rename, and one written after it
  restores into an instance before it. Nothing to migrate, nothing to version.
- **`oc_filecache.checksum`.** Core's column, `SHA1:… MD5:…` format, untouched.
- **The frontend.** No `.ts` or `.vue` file mentions a metadata key; the REST
  payloads speak of algorithms.
- **The search API.** `algo:hash` query syntax is about algorithms too.

### The dangerous part is the window, not the rename

Nextcloud runs an app's migrations and repair steps *after* the new code is in
place. So between upgrading and finishing the migration there is an instance
running new code over old keys — and if the new code only knows the new
spelling, every search returns nothing for exactly as long as that lasts. On a
large instance, rewriting one metadata document per hashed file is not
instant.

So the readers learn both spellings **before** anything is renamed, and keep
knowing both until a later release drops the old one. That ordering is the
whole safety of this plan, and it is why the migration is not one commit.

## Analysis

### What has to change

| | Where | How |
|---|---|---|
| Key construction | `MetadataService::getHashKey()` and 24 call sites reaching it | one function; the call sites are already indirect |
| The broad `LIKE` | `KEY_FILE_CHECKSUM_LIKE`, 10 uses | becomes the algo prefix; 8 riders delete |
| The seven-way `OR` | `whereDocumentHoldsAHash()` | becomes one `LIKE` |
| Index rows | `oc_files_metadata_index.meta_key` | one `UPDATE` per algorithm — eight statements, no SQL functions |
| Documents | `oc_files_metadata.json` | read, rename the key, write: one rewrite per hashed file |

The index half is cheap and portable; the document half is the expensive one
and needs the same treatment `rebuild-from-metadata` already has — a guard, a
page size, and an interruptible walk.

### Reading both spellings

`getHashes()` reads a document by key. During the window it has to try the new
spelling and fall back to the old. `queryByHash()` matches the index by key
prefix, and has to match either.

That is a real cost in the read path — an `OR` on the hot query — and it is
temporary by design. The AP names the release that removes it rather than
leaving it to be discovered: **the shim goes when the repair step reports
nothing left to rename on an instance that has run every earlier version**,
which in practice means one release later.

### Which way the migration writes

Per file, in one pass: rename the keys inside the document, save it, then fix
that file's index rows. The index write already goes through
`syncHashIndex()`, which deletes the file's hash rows and writes them again —
so the index half needs no separate statement at all. **The eight `UPDATE`s are
only for files whose documents are already correct**, which is the case after a
partial run.

## Implementation Plan

### Block 1 — Read both spellings · `[TASK]`

`getHashKey()` gains `legacyHashKey()`; `getHashes()`, `extractAlgorithm()` and
`queryByHash()` accept either. Nothing writes the new spelling yet, so this
block changes no stored data and no behaviour: an instance that installs it
reads exactly what it read before.

**Verification:** a unit test per reader with a document holding the old key,
the new key, and both; live, search still finds every algorithm on instance 34,
whose data is entirely old-spelled.

### Block 2 — Write the new spelling · `[TASK]`

`getHashKey()` returns `file-checksum-algo-<algo>`; registration declares the
new keys. Files hashed from now on carry it; files hashed before do not, and
Block 1 is why both still answer.

**Verification:** hash one file and read its document — new key; search finds
it and finds an old-spelled neighbour in the same query.

### Block 3 — Rename what exists · `[FIX]`

A `key-namespace` repair step: page the documents holding an old-spelled key,
rename in place, save. Guarded by a count of documents still holding one, so a
completed instance pays two counts. Expensive, so `--include-expensive` forces
the full pass, and it is reachable on its own as
`occ fcias:repair --step key-namespace`.

**Verification:** on instance 34 — 302 documents and 762 index rows carry the
old spelling today; after the step, none do, every algorithm still searches,
and a second run reports nothing to do.

### Block 4 — Spend the winnings · `[TASK]`

Delete the eight riders, collapse the seven-way `OR`, and narrow
`KEY_FILE_CHECKSUM_LIKE` to the algo prefix. This is the block the AP exists
for, and it is deliberately last: it is only safe once nothing old-spelled
remains, which Block 3 guarantees and the shim covers until it has run.

**Verification:** the guard's two counts agree on a healthy instance — the
thing that was silently false before this AP — and the walk visits 302
documents rather than 455.

### Block 5 — Documentation · `[TASK]`

The README's metadata-key description, and a FAQ answer for an administrator
who reads their own database: what the keys are, and why the hashes have a
prefix of their own.

## Open questions for the user

1. **When does the shim go?** Naming a release now is a promise made before we
   know how upgrades land in the wild. The alternative is to keep it until an
   instance can be shown to have run Block 3 — which needs the repair markers
   from *AP RepairCommand*'s open question, and is a reason to answer that one.
2. **Should the old declarations be withdrawn from Nextcloud?** `initMetadata()`
   has no counterpart that forgets a key, so the old ones linger in core's
   config for ever, harmless and untidy. Naming it so it is not mistaken later
   for something this app failed to clean up.
