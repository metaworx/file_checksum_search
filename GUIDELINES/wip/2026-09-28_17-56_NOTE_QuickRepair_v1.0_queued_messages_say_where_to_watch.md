# NOTE QuickRepair v1.0: the queued messages say where to watch

Accompanies `2026-09-28_17-36_AP_QuickRepair_v1.1_a_whole_repair_asks_nothing_expensive.md`,
which stays as written. Where the two differ, this note holds.

## Block 3 gains two things

Asked by the maintainer after block 1: a whole repair that queues a job
should say so, and say where its progress can be read.

- **Both queued messages name the two status views.** The filecache copy's
  and the hash-index check's "queued … for the background jobs" go on:
  its progress shows under *Administration settings → File Checksum Index &
  Search → Advanced → Status Info*, and in `occ fcias:status`. The
  "already queued" variants say the same.
- **`file-checksum-search:status` gets the alias `fcias:status`**, beside
  `fcias:repair` and the other `fcias:` commands; the messages name the
  short form.

Both belong in block 3 because only there do the two views list these
jobs: until then the admin page does not show the check or the copy, and
`occ …:status` shows no job at all, so a pointer written earlier would
point at nothing.

Tests: the step's queued messages name both views; the command answers to
`fcias:status`.
