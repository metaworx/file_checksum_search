# NOTE EtagStamp v1.1: How the run went

Where the five blocks went differently from the plan, block by block.

## Block 1

- The bulk writers keep `insertIndexRow()`: the reset's state rows, the
  marker move and the MIME type queueing make sure in one query that no
  file of theirs holds the row, and a read per file through
  `setSelfIndexed()` would cost them that query again for every file.
  Every single-file writer goes through it.

## Block 2

- `stamp()` sets the document only. The row is written after every save
  the app makes, by `syncStampRow()` through `saveDocument()`, so that no
  save of a stamped document can leave the row behind it.
- The placeholder stamp row a queued, never-hashed file gets (0, no
  document key behind it) stays a direct insert: it is no stamp.
- `marker-row` reads only a marker's shapes from the stamp row's string
  half. It took any string there for a marker, and would have moved every
  ETag into a state row.
- The `etag-stamp` walk reads the stamp rows without an ETag, not every
  stamped document: a document whose row has an ETag was stamped by the
  new code, unindexed already.
- Adoption also asks the existing rule, a stamp no older than the file's
  mtime, beside the storage's mtime. A stamp older than the file has its
  hashes out of the index already, and the queue's.

## Block 3

- The recalc answers are unchanged: what a recalculation returns it has
  just read, against the ETag it stamps.
- The daily walk does a write's work through `RuleService::contentChanged()`:
  eroded where no rule maintains the file, else out of the index and queued
  in the rule's mode. `force` and `lazy` clear the document when the drain
  runs, where the write's listener clears it at once.
- The lookup and a file's hashes leave out hash rows without a stamp row,
  as the listing always has; `missing-stamps` stamps them.
- `PublicApiTest` and `HashSearchProviderTest` wrote hash rows without a
  stamp, a state the app does not produce; their fixtures stamp now, the
  recalc fixture a second before the file's mtime so that a recalculation
  still takes its hash for outdated.

## Block 4

- A stale entry's `mimetype` is null under `stat` too: it was detected from
  the content the hashes were read from.
- Hashes stamped before the app kept the ETag, and vouched for by their
  stamp, give the ETag the filecache holds, as adoption gives them.

## Block 5

- The scan budget is a window of 10,000 file ids per page, bounded by the
  filecache's primary key; a page may come back empty with `next`.
- The missing route takes no `localPath`, and its `/sudo/` twin no
  canonical reading: neither was asked for.

## Outside the plan

- Instance 33 had 0.24.0 installed under the 0.25.0 code, and its HTTP
  tests failed on that; `occ upgrade` brought it level before Block 1's run.
