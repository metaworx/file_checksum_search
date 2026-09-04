# AP VerifyPerRow v1.2: one gesture, one request

> **Status: proposal, 2026-09-04.** Revises v1.1, which was written before
> the rate limit was measured against a real group. Blocks 1–3 are unchanged
> and already implemented; 4–6 are v1.1's cross-account work; 7–8 are new.

## What v1.1 got wrong

Verifying a 147-file group hits the recalculation rate limit within seconds.
v1.1 treated that as expected behaviour to be resumed through. It is not —
it is the wrong unit being counted.

`recalcHash` is `#[UserRateLimit( limit: 20, period: 60 )]`; every other
route in this app is 60/60. The 20 is low because one call means one file
read. But verification spends **one request per file**, so one click on
*Verify all* becomes 147 requests. The sidebar's one-file-one-click matches
the unit. A group does not.

## Counting requests is a poor proxy for cost

Small files read fast, answer fast, and the next request arrives fast — so
cheap work hits the limit hardest. Large files read slowly and answer late,
so expensive work hits it least. The limiter is anticorrelated with the thing
it exists to protect.

Bytes per minute would be the honest measure. It is not being built here:
`ILimiter` counts requests, so a byte budget needs accounting of its own, and
it would still miss the other half of the cost — per-read latency on a remote
or metered mount, which does not scale with size. Recorded so it is not
rediscovered as a new idea.

What *is* built here is the cheap half of the same insight. The batch has to
decide how much one call may do anyway, and the filecache knows every file's
size before anything is read, so the cap is stated in both units.

## The batch route

`POST /api/v1/file/many/recalc`, taking a list of file ids and one algorithm,
answering one result per id. One gesture, one request: a 147-file group
chunked at 25 is 6 requests instead of 147, comfortably inside 20/min, and
the limit goes on meaning something.

**Caps, enforced server-side:** 25 files or 100 MiB of filecache-reported
size, whichever is reached first — except that a single file always goes
through even when it alone exceeds the byte cap, or a large file could never
be verified at all. Both are constants; an admin setting is not offered until
something asks for one.

`many` is a literal in the same position as `{fileId}` on the sibling route,
and nothing currently stops `{fileId}` from swallowing it —
`/api/v1/file/abc/hashes` resolves today and casts to `0`. Every route
carrying `{fileId}` gains `requirements: [ 'fileId' => '\d+' ]`
({@see ApiRoute}), which settles the ambiguity and is worth having anyway.

A sudo twin, `POST /api/v1/sudo/file/many/recalc`, checks `mayReachFile()`
per id and reports per id. A group on the *Others* tab can hold files from
several accounts, and refusing the whole batch for one unreachable id would
make a mixed group unverifiable; the per-file result already has a place to
say why.

The single-file routes stay as they are — the sidebar wants exactly one.

## Blocks

1. **[TASK] Verify what was asked for.** *(done, ungated.)* `useDuplicates`
   gains `verifyFile()` beside the group one, sharing the loop that handles
   the rate limit and the resume. The page-wide button and the *Only
   matching* filter go; `DuplicateGroup` gains the two buttons and emits what
   to verify. Vitest for one file and one group, including the limit.
2. **[TASK] The e2e and the docs.** *(done, ungated.)* The `duplicates` spec
   verifies through the group button and through a file row; the *Only
   matching* case goes with the checkbox. User guide, FAQ and README
   rewritten for both buttons and the reason; CHANGELOG under Changed.
3. **[FIX] Read the verdict.** *(done, ungated.)* The tick, the cross and the
   header badge use `--color-success-text` / `--color-error-text` /
   `--color-warning-text`, not the background fills, which vanish against a
   dark row. The count, the badge and **Verify all** share one right-aligned
   row, and every per-file **Verify** lines up on the same right edge.
4. **[FIX] A reach check for one file.** `SudoScope::mayReachFile( string
   $uid, int $fileId ): bool`. A sudoer reaches anything; anyone else reaches
   a file when some account holding it is accessible to them — the accounts
   from `IUserMountCache::getMountsForFileId()`, each through the
   `isUserAccessible()` this class already uses. Mounts rather than
   ownership: a file reachable by an account a sub-admin administers is
   already in the listing they are looking at. `IUserMountCache` is a new
   dependency. Unit tests: a sudoer, a sub-admin over a member's file, a
   sub-admin over a stranger's file, an unknown id, a plain account.
5. **[FIX] Recalculation across accounts.** `ChecksumApi::recalcHash()`
   splits `$requestingUser` into `$actingUser` (`mayRecalc()` always,
   `userCanAccessFile()` unless waived) and `bool $anyAccount` (waives the
   access check and nothing else). Its docblock states what the parameter is
   *not*: in-process callers already run with server privileges, so this is a
   convenience guard for the HTTP layer, never a boundary against PHP
   callers — the boundary is `scopeOrRefusal()`, which reads the session and
   never a request parameter. The recalculation log lines gain the acting
   user, which they carry no uid for today, so a cross-account recalculation
   is not silently attributed to nobody. New
   `PublicApiController::sudoRecalcHash()` at
   `POST /api/v1/sudo/file/{fileId}/recalc`, refusing in the established
   order — `scopeOrRefusal()`, `mayReachFile()`, `SudoConfirmation` — under
   the same `#[UserRateLimit]` and the same CSRF reasoning as its own-file
   twin. Unit tests for both refusals; `SudoRouteTest` for the HTTP shape.
6. **[FIX] The two routes that disagree with their own listing.**
   `sudoGetHashes()` and `sudoFindDuplicates()` gate on `mayReachFile()`
   instead of `sudoScopeOrRefusal( null )`, which answers `false` for a
   sub-admin and so refuses files they may already list. Unit and integration
   cover the sub-admin case that 403s today.
7. **[TASK] One gesture, one request.** The batch route and its sudo twin as
   above, with the `\d+` requirements on every `{fileId}`. `useDuplicates`
   sends one request per chunk instead of one per file, keeps the resume
   behaviour on a 429, and reports per-file results from the batch answer.
   PHPUnit for both caps, for the single-oversized-file exception, for a
   mixed-ownership batch under sudo, and for `many` not being parsed as an
   id; vitest for the chunking and the partial answer.
8. **[TASK] The docs.** `docs/api-v1.md` and `docs/api-v1-openapi.yaml` gain
   the batch routes and the corrected sub-admin reach on the two old ones;
   the README route table follows. The user guide says verification works on
   the *Others* tab, under whose permission, and that a large group is sent
   in chunks. CHANGELOG under Fixed.

## Open decisions

1. Whether **Verify all** should warn before a large group. *Still no*: the
   button names its scope, the header says how many files it holds, and with
   the batch the cost is bounded per request rather than per click.
2. Whether a verified file should stay marked after a reload. It does not,
   and this keeps that: storing it would mean writing a judgement about
   content nobody re-read.
3. A cross-account audit trail. **Dropped**, by ruling: recalculation
   computes from content and cannot write an arbitrary value, and the
   expensive-read case is already gated per path by the `exclude` rules. The
   acting user in the log (block 5) is what remains of it.
4. Whose paths the *Others* tab shows. Three rows reading
   `files/Templates/Certificate.odt` are three accounts' copies with nothing
   to tell them apart. Scoped to the **SidebarScope AP**; noted because a
   per-row verdict is worth less when the rows are indistinguishable.
5. Bytes per minute as a real limiter, rather than a per-request cap. Argued
   above; not scoped anywhere. Revisit if a group is ever slow for a reason
   the cap does not explain.

## Not in scope: the test litter

The 147-file group is not a realistic instance. It is 215 filecache rows
under `files/fcias-e2e-sidebar-*` and 27 `fcias_e2e_nobody_*` accounts left
on the dev instance by runs that predate the fixed-directory fix in
`checksums.cy.js`. That fix holds; nothing regrows. Two separate small items,
neither belonging to this AP: a one-off sweep of the instance, and a
`before()` that reaps stale `fcias_e2e_*` accounts, since every
`fciasDeleteAccount()` is paired with its `fciasMakeAccount()` but only runs
if the spec reaches cleanup — a failing run leaks one.

## Gate

PHP suite, `npm run lint`, `npx vitest run`, `npm run build`, the
`duplicates` e2e; full e2e at the end. Blocks 4–7 touch a security boundary,
so each carries its own tests in its own commit.

## Change History

- v1.2 (2026-09-04): the rate limit measured against a real group; the batch
  route at `/api/v1/file/many/recalc` with caps in files and bytes, the
  `\d+` requirements it needs, the acting user in the log, and the audit
  trail dropped by ruling. Blocks 1–6 carried forward from v1.1.
- v1.1 (2026-09-04): the cross-account hole the per-row buttons uncovered,
  the reach check, and the two existing routes that share it.
- v1.0 (2026-09-03): proposal.
