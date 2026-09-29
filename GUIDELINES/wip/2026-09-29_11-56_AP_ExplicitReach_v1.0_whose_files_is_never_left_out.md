# AP ExplicitReach v1.0: whose files is never left out

> **Status: proposal, 2026-09-29.** From a handover of the kunstarchiv
> session, which found that `ChecksumApi::findByHash()` answers for every
> account when its reach is left out. The handover is in `messages/` and
> is not cited further; what it established is restated below.

## Discussion

Five public methods of `ChecksumApi` take `?array $reachUids`, *whose
files may be answered for*, and four of them default it to `null`, which
the API document defines as "the whole instance". A native caller that
leaves the argument out gets the widest reach there is: from
`findByHash()`, every account's copy of a hash — with `localPath` pointing
into other accounts' homes when asked for — and possibly not its own, since
`$limit` is spent before anything is scoped.

It is documented, not accidental, and it is not a hole: every REST route
passes an explicit reach (the caller's own account on the ordinary routes,
the resolved reach on the `/sudo/` twins, `null` only for a sudoer), and a
native caller runs inside the server. It is a trap for integrators: the
answer is wrong, not refused.

Scoping by the session instead was considered and rejected by the handover,
rightly: *Say who is asking and what they may reach as two things* separated
exactly that, and background jobs and `occ` have no session or another one.

The maintainer also asked whether optional parameters should be passed by
name, so that their order is not part of the contract. Block 1 settles that
first, because block 2 reorders parameters.

## Analysis

1. **The five methods.**

   | Method | Reach today |
   |---|---|
   | `findByHash(string $hash, ?string $algo = null, int $limit = 100, ?array $reachUids = null, bool $withLocalPath = false)` | defaults to every account |
   | `getHashesByFileId(int $fileId, ?string $actingUser = null, ?array $reachUids = null)` | defaults to every account |
   | `findSameHash(int $fileId, ?array $reachUids = null)` | defaults to every account |
   | `recalcHash(int $fileId, ?string $algo = null, ?string $actingUser = null, ?array $reachUids = null)` | defaults to no reach check |
   | `recalcMany(array $fileIds, ?string $algo = null, ?string $actingUser = null, ?array $reachUids = null)` | defaults to no reach check |
   | `findDuplicatesFor(?array $reachUids, …)` | already required, right after the subject |

   `findDuplicates()` takes no reach and answers for the session's account,
   nothing without one — already the safe default.
2. **Why not just drop the default.** In four of the five the reach comes
   after optional parameters. Since PHP 8.0 a required parameter after
   optional ones makes those required too and raises a deprecation, so
   "drop the default" is a reordering in all but name.
3. **A sentinel** ("every account" as a named value, omission refused)
   keeps positions, but PHP cannot tell an omitted `null` from a passed
   one: it needs a new value in the parameter's type, which is a larger
   change to the contract than moving the parameter.
4. **The precedent** is `findDuplicatesFor()`: the reach first, after the
   subject, no default. Following it gives one rule for the whole API.
5. **Named arguments.** PHP 8 lets a caller name any argument. If optional
   parameters are passed by name, their order can change in a minor
   release; the price is that their *names* become the contract, so a
   rename is breaking. Required parameters keep their positions. The API
   document's breaking-change table says nothing about either today.
6. **Callers that rely on the defaults.** Inside the app:
   `ChecksumApi::getHashesByFile()` and `getHashesByPath()` call
   `getHashesByFileId()` with the file already resolved, and the REST
   controller calls all five with an explicit reach. Outside: kunstarchiv's
   planned call, not written yet, is
   `findByHash( $sha1, 'sha1', $limit, [ NC_USER ], withLocalPath: true )`,
   positional, and moves its reach to second place.
7. **Release.** Pre-1.0, any change bumps the minor; `[Unreleased]` already
   makes the next cut `0.21.0`, so this fits it with a Changed bullet.

## Implementation Plan

1. **[TASK] The PHP API's optional parameters are passed by name.**
   `docs/api-v1.md`: the method reference's preface says a caller passes
   required parameters by position and optional ones by name; the
   breaking-change table gains *Reorder or reposition optional parameters*
   (allowed, minor) and *Rename a parameter* (breaking) rows; the
   `ChecksumApi` class docblock says the same in one paragraph. No code
   changes. CHANGELOG: a Changed bullet naming the rule, since integrators
   read the changelog and not the document.
   **Verification:** the document reads consistently; `changelog.sh check`.

2. **[TASK] Every method that takes a reach takes it without a default,
   right after its subject.** The five signatures become

   ```php
   findByHash( string $hash, ?array $reachUids, ?string $algo = null, int $limit = 100, bool $withLocalPath = false )
   getHashesByFileId( int $fileId, ?array $reachUids, ?string $actingUser = null )
   findSameHash( int $fileId, ?array $reachUids )
   recalcHash( int $fileId, ?array $reachUids, ?string $algo = null, ?string $actingUser = null )
   recalcMany( array $fileIds, ?array $reachUids, ?string $algo = null, ?string $actingUser = null )
   ```

   An explicit `null` still means every account, or no reach check for the
   two `recalc*`. Every caller in the app passes the reach in its new place
   and its optional arguments by name: the controller, `getHashesByFile()`,
   `getHashesByPath()` and `recalcMany()` itself. The docblocks and
   `docs/api-v1.md` — the method headings, the parameter tables, the two
   parameters' preface — follow.
   CHANGELOG: one Changed bullet, the break stated.
   Tests: every existing call and expectation updated; one test per method
   that the reach is required (calling without it is an `ArgumentCountError`).
   **Verification:** PHP unit and integration suites; psalm on
   `lib/Public/` and `lib/Controller/`; `composer cs:check`; the e2e specs
   that drive these routes (`checksums`, `duplicates`, `sudo-tokens`).

3. **Kunstarchiv is told.** A short note in `messages/` for its next
   session: the new positions, and that optional arguments go by name. Not
   a commit.

## Proposed commit messages

Block 1: `[TASK] The PHP API's optional parameters are passed by name`
Block 2: `[TASK] Every public method that takes a reach takes it without a default`

Each in full at its commit gate.

## Open decisions

- **Block 1 at all.** Without it, block 2 is a break either way; with it,
  the next reordering of optional parameters is not one.
- **`recalc*` with `null`.** Kept as "no reach check", as today, rather than
  split into a separate method.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.0    | 2026-09-29 | Initial plan: optional parameters by name, then the reach required and moved after the subject in the five methods that default it. |
