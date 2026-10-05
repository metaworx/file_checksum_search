# AP ListingPerformance v1.0: the listing reads from the stamp row

## Discussion

- **The report.** Kunstarchiv's sync walks 57,459 files through the hash
  listing in 49.7 s, natively and over REST alike; the time is in the
  listing's batch query, not in the hashes or the transport.
- **Measured on helioscloud.ddev.site,** read-only, with the user's
  authorisation of 2026-10-04 (MariaDB 11.8.9; filecache 2.2M rows,
  metadata index 1.05M rows):
  - the batch query as it is: 768 ms at `after` 0, 93 ms near the end;
    without `algo` 1,754 and 237 ms;
  - one row per file without grouping, driven from the filecache: 3.4 ms
    and 1.7 ms; driven from the stamp row, as fast;
  - a whole walk driven from the filecache: 6.3 s (batch queries 4.1 s,
    hashes 2.2 s) against 53.7 s through `iterateHashes()` as it is;
  - the count: 240 ms against 567 ms.
- **Driven from the stamp row,** `file-checksum-updated_at`: settled with
  the user, 2026-10-04, once AP HashStamps made it a must for every file
  holding a hash. It was set aside at first because 25,906 files on
  helioscloud held hashes without one; HashStamps stamps them and keeps
  every write path stamping.
- **No new index.** Nextcloud's own `(file_id, meta_key, …)` indexes on
  `files_metadata_index` serve the driving row and the subquery.

## Analysis

- `MetadataService::pageListedFiles()` reads from the metadata index's hash
  rows, joins the filecache, the storages and the stamp row, and groups by
  the filecache's columns to make one row per file. The grouping makes the
  database read and sort every remaining hash row before it can return the
  first 500: each batch costs the rest of the listing.
- Driven from the stamp row — one per file, so nothing to group — the
  database stops after 500 files; the stamp, the stale marker and `since`
  are columns of the driving row, and only the hash row is a subquery:

  ```sql
  SELECT u.file_id, fc.storage, fc.path, s.id, u.meta_value_int AS updated_at
  FROM files_metadata_index u
  INNER JOIN filecache fc ON fc.fileid = u.file_id
  INNER JOIN storages s ON fc.storage = s.numeric_id
  WHERE u.meta_key = 'file-checksum-updated_at'
    AND (u.meta_value_string IS NULL OR u.meta_value_string NOT LIKE 'stale:%')
    AND EXISTS (SELECT 1 FROM files_metadata_index h
                WHERE h.file_id = u.file_id AND h.meta_key = ?)  -- LIKE the prefix without algo
    AND <governed> AND <within the reach> AND u.file_id > ?
  ORDER BY u.file_id LIMIT 501
  ```

- `since` is `u.meta_value_int >= since` on the driving row, until
  HashStamps block 4 moves it to the write time of the hash rows.
- The count is the same selection, `COUNT(*)`.
- What is listed does not change, given the stamp row: a file with a hash
  row in reach, not disowned, in a governed area. A hash row without a
  stamp row is what HashStamps block 2 repairs; until it has, such a file
  is not listed. The existing tests pin the rest.
- Through query builder only; the subquery and the `IS NULL OR NOT LIKE`
  are portable to the four backends Nextcloud supports.

## Implementation Plan

### Block 1: the listing's batch and count read from the stamp row

Depends on AP HashStamps blocks 1 and 2.

1. `MetadataService`: `whereListed()` selects from the stamp row, joined to
   the filecache and the storages, with the hash row as `EXISTS`, the stale
   marker and `since` on the driving row; `pageListedFiles()` takes the
   stamp from it and orders by file id without grouping;
   `countListedFiles()` counts the same selection.
2. Tests: the existing listing tests, unit and integration, unchanged; a
   unit test that the batch query neither groups nor orders by anything
   but the file id; an integration test that a file whose hash rows were
   deleted is not listed though its stamp row stands.
3. Measure the walk and the count on helioscloud.ddev.site, read-only, with
   and without `algo`, against 53.7 s and 567 ms — once HashStamps' repair
   has run there, which is the user's or Kunstarchiv's to start.
4. Ask Kunstarchiv's session for its regression run: `augias nc:hashes
   --dry-run -v`, natively and with `--rest`, 57,459 files both.

**Verification:** PHPUnit unit and integration on NC 33 and 34, ECS,
Psalm; the measurement and the regression run.
**CHANGELOG:** the Added bullet for `GET /api/v1/hashes` is unreleased and
stays accurate; the message names the exemption.

## Proposed commit messages

1. `[TASK] The hash listing reads its batches from the stamp row`

## Change History

- v1.0 (2026-10-04): first version.
