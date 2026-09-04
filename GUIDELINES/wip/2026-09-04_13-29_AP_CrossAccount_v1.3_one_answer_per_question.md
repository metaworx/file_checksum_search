# AP CrossAccount v1.3: one answer per question

> **Status: proposal, 2026-09-04.** Revises VerifyPerRow v1.2, under the
> name the work now has. Blocks 1–6 are committed;
> block 4's delegated reach was withdrawn under `[SECURITY]` after the design
> review found it wider than the listing it claimed to match. Blocks 7–12 are
> that review's Tier 1, and 13–14 are v1.2's remaining work, now behind it.
>
> Source: `wip/2026-09-04_12-44_ANALYSIS_CrossAccountDesign_v1.0`, whose
> author designed the model cold before reading this code
> (`…_12-39_…_stage1.md`).

## Why the plan grew

v1.2 set out to make verification something you ask for per row. Doing it
uncovered that verification had never worked across accounts at all, and
fixing *that* uncovered the reason: the app asks one question — *what may
this caller reach* — in four different ways, and answers it differently in
each.

The review found six such divergences and two defects nobody had noticed:

- A group leader **never sees the cross-account tab**. `canSudo` asks
  `isSudoer()` (`PublicApiController.php:586`), which a sub-admin fails, and
  `App.vue:56` builds the tab from it. The picker built for them across two
  APs has no way in.
- `canRecalc` is reported **true for every caller** on the cross-account
  hashes route, because `null` means both "trusted caller" and "every
  account".
- `sudo/file/{id}/duplicates` **returns only the caller's own copies**:
  `findSameHash()` resolves every candidate through the session's own folder
  and skips what it cannot find there, whatever scope was granted.

The third is the same fault as the one block 5 hit in the recalculation path,
in a third place. Patching a third site is what this AP stops doing.

## The rule the blocks below serve

**One question, one answer, asked once.** Two things travel together and are
never conflated: *who is acting* — which decides permissions and is always
the session's account — and *what may be reached* — which decides scope and
is resolved in exactly one place.

## Blocks

Committed:

1. **[TASK] Verify what was asked for.** *(done)* Per-group and per-file
   verification; the page-wide button and the *Only matching* filter gone.
2. **[TASK] The e2e and the docs.** *(done)*
3. **[FIX] Read the verdict.** *(done)* Text colours, not background fills;
   one right-aligned row of controls.
4. **[FIX] Ask SudoScope about a file.** *(done, then narrowed)*
   `mayReachFile()`; its delegated branch withdrawn under `[SECURITY]`.
5. **[FIX] Recalculation across accounts.** *(done)* `$actingUser` split from
   `$anyAccount`; `POST /api/v1/sudo/file/{fileId}/recalc`; the node resolved
   through a holder's folder rather than the session's root.
6. **[FIX] The two routes that disagreed with their listing.** *(done)*

Tier 1, in this order because each rests on the one before:

7. **[FIX] One predicate for "may cross".** `SudoScope::mayCross( string
   $uid ): bool` — a sudoer, or a sub-admin of any group. `canSudo` uses it,
   so the tab appears for the people the picker was built for. `all` on
   `/sudo/selectable` keeps `isSudoer()`: naming everyone is not the same
   permission as crossing at all.
8. **[FIX] A ceiling, not "everyone".** `resolve( $uid, null )` answers what
   the caller may reach at most: `null` for a sudoer, their groups' members
   for a sub-admin — the expansion `resolveSet()` already does — and `false`
   otherwise. `/sudo/lookup` and a bare `/sudo/duplicates` then serve a
   sub-admin. This is the value the sidebar switch will send, and the return
   type becomes `list<string>|null|false`.
9. **[FIX] One reach resolver.** `ReachResolver::storageIds( ?array $uids ):
   ?array` — the union of `IUserMountCache::getMountsForUser()` storage ids,
   `null` meaning every account. `batchLookupFilecachePaths()` takes storage
   ids and loses its `home::` construction; the listing, the lookup and the
   set listing all pass through it. The listing then agrees with the reach
   instead of contradicting it, which is what made block 4's justification
   false.
10. **[FIX] Who is asking, and what they may reach.** `ChecksumApi` methods
    take `?string $actingUser` and `?array $reachUids` rather than one
    parameter meaning both; `recalcHash()`'s `$anyAccount` renames to match.
    `canRecalc` and the preference read `$actingUser`; every reach check
    reads `$reachUids`. `findSameHash()` renders each duplicate inside the
    reach — never through the session — which is what makes it return what
    its name says.
11. **[TASK] Owner on every row.** `owner: ?string` and `location: string`
    from the existing `FileLocation::describe()`, which is log-only today.
    `path` stays what the caller's own folder would call it; `location` is
    rendered wherever the owner is not the caller. Without this, three rows
    reading `files/Templates/Certificate.odt` are three accounts' copies with
    nothing to tell them apart.
12. **[TASK] Drop `user=`.** `users[]`/`groups[]` name a set; naming nothing
    means the ceiling from block 8. Three encodings of one idea become one,
    and `docs/api-v1.md:851-852` collapse to a single row.

Then v1.2's remaining work, which block 10 reshapes the signature for:

13. **[TASK] One gesture, one request.** `POST /api/v1/file/many/recalc` and
    its sudo twin, capped at 25 files or 100 MiB per call, whichever comes
    first, and always at least one file so a large one stays verifiable.
    `requirements: [ 'fileId' => '\d+' ]` on every `{fileId}` route, so the
    literal `many` cannot be parsed as an id. The frontend sends one request
    per chunk instead of one per file, keeping the resume behaviour on a 429.
14. **[TASK] The docs.** `docs/api-v1.md` and the OpenAPI document for the
    batch routes, the corrected sub-admin reach and the dropped `user=`; the
    user guide for cross-account verification and chunking; CHANGELOG.

## Tests that must exist before this ships

The review's sharpest observation: **the unit suite would have passed
unchanged through both defects it found.** Mocks agree with whatever the
code believes. So each of these is an HTTP test, not a unit test:

- A sub-admin against every `/sudo/` route. `SudoRouteTest.php:32` makes its
  account an admin, so no test in this repository has ever exercised the
  delegated path. **Blocked**: the review could not determine alice's
  password and tripped core's brute-force throttle trying. Mint a sub-admin
  account in the fixture rather than borrowing one.
- `sudo/file/{id}/duplicates` for a sudoer, asserting a *foreign* duplicate
  appears. This is the assertion whose absence hid the defect.
- The ordinary listing, asserting a received share appears — block 9 changes
  what "own" means from home storage to mounts, and that is the case which
  proves it.

## Open decisions

1. **What a sub-admin's reach is**, settled once and written down. Either
   *what my members can see* — mounts, matching core's delegation and making
   the listing and `mayReachFile()` agree — or *what my members own*, home
   mounts only, which excludes a file shared in by an outsider. The review
   recommends the first, and block 11's owner column is what makes it
   honest, since the leader can then see whose file it is. **This decision
   also says whether `mayReachFile()`'s delegated branch comes back on**, and
   nothing between here and block 9 depends on it, so it can be taken late.
2. **Whether this should still be one AP.** Blocks 7–12 are a cross-account
   rework that verification merely uncovered; the name no longer describes
   the work. Splitting costs a retirement and a new plan; not splitting
   leaves an AP whose title is wrong.
3. Whether **Verify all** should warn before a large group. Still no: block
   13's cap bounds the cost per request rather than per click.
4. Whether a verified file's state survives a reload. Still no: storing it
   would mean writing a judgement about content nobody re-read.
5. Bytes per minute as a real limiter rather than a per-request cap. Argued
   in v1.2 and not scoped: `ILimiter` counts requests, and a byte budget
   still would not capture per-read latency on a remote mount.

## Not in scope

- **Tier 2.** Collapsing the six `/sudo/` twins into a `scope` parameter is
  not warranted — the review argues the twins are the better answer and says
  so against its own cold design. A `Reach` value object may grow out of
  block 10 if the parameter pairs sprawl; it is not a separate project.
- **The test litter.** 215 filecache rows under `files/fcias-e2e-sidebar-*`
  and 27 `fcias_e2e_nobody_*` accounts predate the fixed-directory fix in
  `checksums.cy.js`; nothing regrows. A one-off sweep, and a `before()` that
  reaps stale accounts so a failing run stops leaking one.

## Gate

PHP suite, `npm run lint`, `npx vitest run`, `npm run build`, the
`duplicates` e2e; full e2e at the end. Blocks 7–12 move a security boundary,
so each carries its own tests in its own commit, and each is measured over
HTTP rather than only mocked.

## Change History

- v1.3 (2026-09-04): the design review's Tier 1 folded in as blocks 7–12,
  after block 4's delegated reach was withdrawn; v1.2's batch route and docs
  move behind it. Blocks 1–6 carried forward as committed.
- v1.2 (2026-09-04): the rate limit measured against a real group; the batch
  route, its caps, and the acting user in the log.
- v1.1 (2026-09-04): the cross-account hole the per-row buttons uncovered.
- v1.0 (2026-09-03): proposal.
