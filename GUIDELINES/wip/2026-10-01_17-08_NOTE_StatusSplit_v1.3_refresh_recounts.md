# NOTE StatusSplit v1.3: refresh recounts

Decided with the user, 2026-10-01, after v1.3 was presented.

- **Refresh always recounts.** Opening the panel follows the interval;
  Refresh asks `/settings/status/queues` with `recount=1`, which takes the
  indexed-checksum count whatever its age (Block 2, steps 1 and 2).
  `GET /api/v1/status` has no Refresh and keeps the interval.
- **Refresh is disabled while its sequence runs.** An aborted request does
  not stop the query on the server, so repeated clicks would stack counts.
- **Only the indexed-checksum count is stored**, and it shows its own time
  in the column where the jobs show theirs (Block 2, step 3). The queue
  counts are taken on every load and carry no time of their own: theirs is
  "Last updated".
- **The "Checksum count" job row shows only while the switch is on**
  (Block 1b). Its record is still where the count is kept, however taken.

Why the queue counts are not stored: they share the table with the hash
count but not its reads. The hash count scans `fcias_f_metadata_int_idx`
over every hash row (406,419 on jackal); the queue and untrusted counts
scan `fcias_f_metadata_str_idx` for `file-checksum-updated_at` rows whose
value starts with `pending:` or `stale:`, which reads only those rows (16
and 0). Cold cost follows the rows read, so they stay cheap while the
backlog is small. A large backlog makes the queued count slow too, but a
stored backlog would be wrong exactly while it is being worked through, and
it is in the second request, which the information does not wait for.
