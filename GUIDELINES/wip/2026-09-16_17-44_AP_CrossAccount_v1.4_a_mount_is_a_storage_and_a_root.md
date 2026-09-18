# AP CrossAccount v1.4: a mount is a storage and a root

> **Status: proposal, 2026-09-16.** Revises v1.3. Blocks 1–8 are committed.
> Block 9 is rewritten: the review's "storage ids from mounts" would have
> made a subfolder share leak its owner's whole storage into the listing, and
> the code's own comment already said so. Open decision 1 is decided here,
> because block 9 is where it stops being deferrable.
>
> Source: `wip/2026-09-04_12-44_ANALYSIS_CrossAccountDesign_v1.0`.

## Why v1.3's block 9 was wrong

`listDuplicatesForUser()` uses `batchLookupFilecachePaths()` as the
**authority** on which files appear (`HashIndexService.php:281`) — nothing
checks a file again afterwards. The filter today is
`s.id IN ('home::<uid>', …)`, which is why the own listing misses shares and
group folders.

v1.3 said: replace it with the storage ids of the caller's mounts. But
`MetadataService.php:2672` says, of exactly that: *storage membership is not
an authorisation test, because a share of a subfolder mounts the owner's
whole storage.* Bob shares `Projects/x` with alice; alice's mount sits on
bob's entire home storage; a storage-id filter used as authority lists every
file bob owns for alice. `findByHash()` is safe only because it resolves each
row through `getById()` afterwards — a per-row cost the listing's paging
exists to avoid.

## The rule the blocks below serve

Unchanged: **one question, one answer, asked once.** *Who is acting* decides
permissions and is always the session's account; *what may be reached* is
resolved in one place. What v1.4 adds: **a mount is a storage and a root**,
and reach is expressed as the pair, never as the storage alone.

## Decision 1, taken

**Reach is mounts** — a group leader's reach is what their members can see,
subfolders received from outsiders included. Not home mounts only.

Because: it is core's own delegation (`isUserAccessible()` is about the
member, not about who owns what the member sees); it is the one reading
under which the listing and the per-file reach can share a single predicate,
which is the point of this AP; and block 11's owner column makes it honest —
a leader sees whose file it is. Home-mounts-only would need a second rule
for `mayReachFile()` and would refuse rows the member themself can open.

## Blocks

Committed:

1. **[TASK] Verify what was asked for.** *(done)*
2. **[TASK] The e2e and the docs.** *(done)*
3. **[FIX] Read the verdict.** *(done)*
4. **[FIX] Ask SudoScope about a file.** *(done, then withdrawn under
   `[SECURITY]`)* — `mayReachFile()` answers `isSudoer()` alone until block 9.
5. **[FIX] Recalculation across accounts.** *(done)*
6. **[FIX] The two routes that disagreed with their listing.** *(done)*
7. **[FIX] One predicate for "may cross".** *(done)* `SudoScope::mayCross()`.
8. **[FIX] A ceiling, not "everyone".** *(done)* `resolve( uid, null )`
   answers a leader's groups' members; `findByHash()` takes several accounts.

Rewritten:

9. **[FIX] One reach resolver, on mounts.** `ReachResolver` in
   `lib/Service/`, over `IUserMountCache`:
   - `mountsFor( ?array $uids ): ?list<array{storage: int, root: string}>`
     — every mount of every named account, deduplicated, `null` for
     everyone. `root` is `ICachedMountInfo::getRootInternalPath()`: `''` for
     a home, the shared subtree for a share.
   - `storageIdsFor( ?array $uids ): ?list<int>` — the same mounts' storage
     ids, for callers that only *narrow* and keep their own authority.
   - `batchLookupFilecachePaths()` filters by mount **subtree**, one
     predicate per mount — `fc.storage = :s AND (fc.path = :r OR fc.path
     LIKE :r/%)`, joined by `OR` — and loses its `home::` construction. As an
     authority it is now correct: a subfolder share admits the subtree and
     nothing beside it. The listing, the set listing and the occ command all
     pass through it unchanged in shape.
   - `queryByHash()` keeps bare storage ids as narrowing; `findByHash()`
     and the search provider take them from `storageIdsFor()` instead of
     building them inline.
   - `mayReachFile()` comes back on as **the same predicate**: a sudoer
     reaches anything; anyone else reaches a file lying within one of the
     mounts `mountsFor()` gives their ceiling. The listing and the per-file
     reach now agree by construction, which is what block 4 claimed and
     could not deliver.
   - `homeStorageNumericIds()` is untouched: it answers where a *rule*
     applies, a different question.
10. **[FIX] Who is asking, and what they may reach.** `ChecksumApi` methods
    take `?string $actingUser` and `?array $reachUids`; `recalcHash()`'s
    `$anyAccount` renames to match. `canRecalc` and the preference read
    `$actingUser`; every reach check reads `$reachUids` through the
    resolver. `findSameHash()` renders each duplicate inside the reach.
11. **[TASK] Owner on every row.** `owner: ?string` and `location: string`
    from `FileLocation::describe()`. `path` stays what the caller's own
    folder would call it; `location` is rendered wherever the owner is not
    the caller.
12. **[TASK] Drop `user=`.** `users[]`/`groups[]` name a set; naming nothing
    means the ceiling from block 8.
13. **[TASK] One gesture, one request.** `POST /api/v1/file/many/recalc` and
    its sudo twin, capped at 25 files or 100 MiB per call, at least one file
    always; `requirements: [ 'fileId' => '\d+' ]` on every `{fileId}` route.
14. **[TASK] The docs.**

## Tests that must exist before this ships

Each an HTTP or database-backed test, not a unit test — the unit suite would
have passed unchanged through every defect this AP fixes.

- **The subtree test, both halves.** bob shares `Projects/x` with alice;
  both hold duplicates. Alice's own listing shows the files under
  `Projects/x` **and does not show** `bob/Other/…`, though it shares a
  storage with them. The second half is the one that fails under v1.3's
  block 9. The share fixture in `FileListenerTest.php:389` is the pattern.
- A sub-admin against every `/sudo/` route — now on `SudoRouteTest`'s own
  minted group (block 7). With block 9: the leader's ceiling lists a
  member's received subtree, and `mayReachFile()` admits a file in it and
  refuses one beside it.
- `sudo/file/{id}/duplicates` for a sudoer, asserting a foreign duplicate
  appears (block 10).

## Open decisions

1. ~~What a sub-admin's reach is.~~ **Decided: mounts.** See above.
2. Whether this should still be one AP. Kept as one; the name now fits.
3. Whether **Verify all** should warn before a large group. Still no.
4. Whether a verified file's state survives a reload. Still no.
5. Bytes per minute as a real limiter. Still not scoped.
6. **The `LIKE` on `fc.path` and the index.** `oc_filecache` indexes
   `(storage, path_hash)`, not `path`; a prefix `LIKE` per mount is a range
   scan within the storage. For a home mount the root is `''` and the
   predicate collapses to `fc.storage = :s`, which is the common case and
   fully indexed. Measure on the 34 instance before accepting; if a
   subfolder share of a large storage is slow, the fallback is the per-row
   `getById()` for non-home mounts only.

## Gate

PHP suite, `npm run lint`, `npx vitest run`, `npm run build`, the
`duplicates` e2e; full e2e at the end. Block 9 moves a security boundary
and re-enables one: its own tests, its own commit, measured over HTTP.

## Change History

- v1.4 (2026-09-16): block 9 rewritten — reach as (storage, root) pairs,
  subtree filtering, `mayReachFile()` back on the same predicate; decision 1
  taken for mounts; the subtree test named in both halves; decision 6 on the
  `LIKE` cost.
- v1.3 (2026-09-04): the review's Tier 1 as blocks 7–12.
- v1.2 (2026-09-04): the rate limit measured; the batch route.
- v1.1 (2026-09-04): the cross-account hole the per-row buttons uncovered.
- v1.0 (2026-09-03): proposal.
