# AP CrossAccount v1.5: a link that opens

> **Status: proposal, 2026-09-22.** Revises v1.4. Blocks 1–13 are committed.
> Adds block 15, which runs **before** block 14 so the docs describe the
> final shape. Everything else stands as v1.4 wrote it.

## Blocks

1–13. **Done.** See v1.4 for their statements.

14. **[TASK] The docs.** As v1.4. Additionally owes: the two batch routes,
    block 10's PHP signatures, block 11's `owner`/`location` and block 15's
    `openable` on the row schemas, and the user guide's chunking sentence.

15. **[FIX] A link only where it opens.** *Note.* A row links to
    `/apps/files/files/{fileid}`, which core resolves in the *viewer's*
    folder and computes `dir` for itself — so our `?dir=` is ignored, rows
    the viewer holds open whatever `path` says, and rows they do not hold
    (every foreign row on *Others*, a leader's member files) cannot be
    opened by any Files-app URL. The client cannot tell the two apart from
    `owner`: a received share is foreign and openable. So the server says.
    Cross-account answers — the sudo listing, lookup and per-file
    duplicates — gain `openable: bool` per row, one batched
    `batchLookupFilecachePaths( ids, mountsFor([ viewer ]) )` per answer;
    own-listing rows are openable by construction (block 9). Both pages
    link only where `openable !== false`, else plain text with a title
    saying so; both link builders lose the dead `?dir=`. One `ReachTest`
    assertion: admin's sudo rows for alice's and bob's copies are not
    openable, admin's own is.

## Open decisions

As v1.4's 2–6. Decision 1 stays decided (mounts).

## Gate

As v1.4.

## Change History

- v1.5 (2026-09-22): block 15 added as a note, ordered before 14.
- v1.4 (2026-09-16): reach as (storage, root); decision 1 taken.
- v1.3 (2026-09-04): the review's Tier 1 as blocks 7–12.
- v1.2 (2026-09-04): the batch route.
- v1.1 (2026-09-04): the cross-account hole.
- v1.0 (2026-09-03): proposal.
