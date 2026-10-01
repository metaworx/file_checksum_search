# NOTE StatusSplit v1.2: the plans on helioscloud

Measured on jackal, 2026-10-01, in the database `helioscloud` itself.
Analysis 1 of v1.2 says its EXPLAINs ran in a database named `nextcloud`.

- `EXPLAIN SELECT COUNT(*) FROM oc_files_metadata_index WHERE meta_key LIKE
  'file-checksum-hash-%'` gives the same plan in `helioscloud`: a range
  scan on `oc_fcias_f_metadata_int_idx`, index only, about 519,911 rows
  estimated.
- The stamp-row count (`meta_key = 'file-checksum-updated_at'`, 305,870
  rows) took 0.093 s run again, against 7.28 s the first time.
- The untrusted count is still empty, in 0.000 s.

So the conclusion stands, and on the instance concerned: the indexes are
used, and what made the counts slow was a cold cache.
