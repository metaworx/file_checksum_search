# AP VerifyPerRow v1.1: verification you ask for, on files that are not yours

> **Status: proposal, 2026-09-04.** Revises v1.0, whose blocks 1–2 are
> implemented and awaiting their gate. Everything v1.0 says still holds; this
> adds what the per-row buttons uncovered.

## What v1.0 uncovered

Verifying a file on the *Others* tab answers `400 {"success":false,"error":
"File not found."}` for every file the caller does not own. Measured on the
34 instance, admin verifying one duplicate group:

```
 25  home::admin  files/Templates/Certificate.odt   ✓
360  home::alice  files/Templates/Certificate.odt   ✗ File not found.
432  home::bob    files/Templates/Certificate.odt   ✗ File not found.
```

`PublicApiController::recalcHash()` calls `scopeOrRefusal()`, which always
yields the caller's own uid, and `ChecksumApi::recalcHash()` then refuses
anything outside that user's home. There is no
`/api/v1/sudo/file/{fileId}/recalc`.

This is not v1.0's doing — the page-wide **Verify hashes** button failed
identically, for every file on the tab at once, and had done since the tab
existed. Per-row buttons only made it legible: one row, one verdict, one
reason.

## Two more routes have the same hole, from the other side

`sudoGetHashes()` and `sudoFindDuplicates()` ask `sudoScopeOrRefusal()` with
no target. That resolves through `SudoScope::resolve( $uid, null )`, which
answers `false` for anyone who is not a sudoer — so a sub-admin who may
*list* another account's duplicates (`resolveSet()`, since v1.0 of
SubAdminPicker) may not read a single one of those files' hashes. The
listing and the per-file routes disagree about who a sub-admin is.

The cause is the same in all three: **a per-file route is being asked a
per-account question.** `resolve()` wants a uid; a file id is what the caller
has.

## The reach check

`SudoScope` gains the question the per-file routes actually need:

```php
public function mayReachFile( string $uid, int $fileId ): bool
```

A sudoer reaches anything. Anyone else reaches a file when some account that
holds it is accessible to them — the accounts resolved from
`IUserMountCache::getMountsForFileId()`, each put through the
`isUserAccessible()` this class already uses. Nobody else reaches anything.

Mounts rather than ownership, deliberately: a file reachable by an account a
sub-admin administers is a file that account can already be asked about, and
is already in the listing the sub-admin is looking at. Ownership would refuse
rows the page shows.

`IUserMountCache` is a new dependency on `SudoScope`.

## The acting user and the reach are two different questions

`ChecksumApi::recalcHash()`'s `$requestingUser` does two jobs — the
file-access boundary *and* `mayRecalc()`, the may-calculate-by-hand
permission — with `null` meaning "skip both". Passing `null` to reach across
accounts would therefore also hand the permission to an account that does not
have it, and let it write hashes onto other people's files.

`getHashesByFileId()` and `findSameHash()` carry the same one-parameter
shape and keep it: they only read, so there the parameter has one job.
`recalcHash()` is the only one that writes, and the only one that splits:

```php
public function recalcHash(
    int     $fileId,
    ?string $algo = null,
    ?string $actingUser = null,
    bool    $anyAccount = false,
): array
```

- `$actingUser` — who is asking. `mayRecalc()` always. `userCanAccessFile()`
  unless `$anyAccount`. `null` remains the trusted DI/bootstrap caller.
- `$anyAccount` — the caller has established reach already. Lifts the
  file-access check and nothing else.

A bool, not a second uid or scope: it cannot be transposed with
`$actingUser` — that is a type error rather than a silent widening of who
one can reach — it defaults to `false` so every existing call site keeps
today's strict behaviour, and `anyAccount: true` reads at the call site as
the dangerous thing it is.

Not a second entry point, because a `sudoRecalcHash()` beside it would have
to repeat `mayRecalc()`, `excludingRuleFor()` and the algorithm default, or
delegate to a shared private and be a wrapper anyway. Those three checks are
exactly what must not drift between the two paths, and the defect being fixed
here *is* a skipped check.

The API layer still decides nothing about who may look across accounts. That
stays in the controller, where `SudoScope` and `SudoConfirmation` are.

Breaking the signature is acceptable: pre-1.0.0, and the parameter is
positional-compatible for every caller that passes at most three.

## Blocks

1. **[TASK] Verify what was asked for.** *(v1.0, done, ungated.)*
   `useDuplicates` gains `verifyFile()` beside the group one, sharing the loop
   that handles the rate limit and the resume. The page-wide button and the
   *Only matching* filter go; `DuplicateGroup` gains the two buttons and emits
   what to verify. Vitest for one file and one group, including the limit.
2. **[TASK] The e2e and the docs.** *(v1.0, done, ungated.)* The `duplicates`
   spec verifies through the group button and through a file row; the *Only
   matching* case goes with the checkbox. User guide, FAQ and README rewritten
   for both buttons and the reason; CHANGELOG under Changed.
3. **[FIX] Read the verdict.** The tick and the cross use
   `--color-success-text` / `--color-error-text`, not the background fills,
   which vanish against a dark row. The group header's badge likewise. The
   count, the badge and **Verify all** share one right-aligned row, and every
   per-file **Verify** lines up on the same right edge.
4. **[FIX] A reach check for one file.** `SudoScope::mayReachFile()`, as
   above. Unit tests: a sudoer, a sub-admin over a member's file, a sub-admin
   over a stranger's file, an unknown file id, a plain account.
5. **[FIX] Recalculation across accounts.** `ChecksumApi::recalcHash()`
   splits its parameter. New `PublicApiController::sudoRecalcHash()` at
   `POST /api/v1/sudo/file/{fileId}/recalc`, refusing in the established
   order — `scopeOrRefusal()`, then `mayReachFile()`, then
   `SudoConfirmation` — under the same `#[UserRateLimit]` as its own-file
   twin, and with the CSRF reasoning of that twin (no `#[NoCSRFRequired]` on
   a mutating POST). `useDuplicates` calls it when the listing is scoped.
   Unit tests for both refusals; `SudoRouteTest` for the HTTP shape; vitest
   for the route choice.
6. **[FIX] The two routes that disagree with their own listing.**
   `sudoGetHashes()` and `sudoFindDuplicates()` gate on `mayReachFile()`
   instead of `sudoScopeOrRefusal( null )`, so a sub-admin may read what they
   may list. Unit and integration cover the sub-admin case that 403s today.
7. **[TASK] The docs.** `docs/api-v1.md` and `docs/api-v1-openapi.yaml` gain
   the new route and the corrected sub-admin reach on the two old ones; the
   README route table follows; user guide says verification works on the
   *Others* tab and under whose permission; CHANGELOG under Fixed.

## Open decisions

1. Whether **Verify all** should warn before a large group — a confirm above,
   say, 50 files. *v1.0 recommended not, and this keeps that*: the button
   names its scope and the header says how many files it holds. Worth
   revisiting only if a group of 147 (which the test instance has) proves
   unpleasant in practice.
2. Whether a verified file should stay marked after a reload. It does not
   (verification is page state, never stored) and this keeps that: storing it
   would mean writing a judgement about content nobody re-read.
3. Whether a cross-account recalculation should be recorded somewhere a
   person can see. It writes to another account's file metadata on a
   sub-admin's say-so, which nothing currently reports. **Recommend: out of
   scope here, worth its own AP** — an audit surface is a feature, not a
   parenthesis in a bug fix.
4. Whose paths the *Others* tab shows. Three rows reading
   `files/Templates/Certificate.odt` are three accounts' copies, and the tab
   gives no way to tell them apart. Already scoped to the **SidebarScope AP**
   (user/group/storage-prefixed paths); noted here because verification makes
   it sharper — a verdict per row is less useful when the rows are
   indistinguishable.

## Gate

PHP suite, `npm run lint`, `npx vitest run`, `npm run build`, the
`duplicates` e2e; full e2e at the end. Blocks 4–6 touch a security boundary,
so each carries its own tests in its own commit.

## Change History

- v1.1 (2026-09-04): the cross-account hole the per-row buttons uncovered,
  the reach check, and the two existing routes that share it. Blocks 1–2
  carried forward from v1.0 unchanged; block 3 records the layout and colour
  fixes made under review.
- v1.0 (2026-09-03): proposal.
