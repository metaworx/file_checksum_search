# ANALYSIS CrossAccountDesign v1.0: one question asked many ways

> **Status:** analysis only, no code changed. 2026-09-04, against the
> working tree at `593586c` plus uncommitted changes. Line numbers are
> from that tree. HTTP results were measured on the 34 instance
> (`~/projects/nextcloud_testing/instances/34`) as `admin`; the sub-admin
> path (`alice`, sub-admin of `test group`) is code-traced only — see §5.

Method: the cross-account model was designed cold from the requirements
before any of this app was read (§1, pre-registered in the reviewer's
scratchpad and unedited since), then compared with the code (§2). §3 keeps
only the differences that cause a wrong answer or a second way of asking
the same question; §4 proposes in proportion.

## Verdict

No sudoer over-reach. The permission order (may-ask, then confirm), the
grant model, the "refuse the whole set" rule and the `/sudo/` twin routes
are sound, and two of them are better than what I designed. The problems
are of one kind: **the same question is answered by different code in
different places, and the answers disagree.** *Who may cross accounts* has
two predicates, so the tab built for sub-admins is never shown to them.
*What the caller may reach* is resolved four ways, so the per-file
cross-account duplicates route returns the caller's own copies and nothing
else — measured. *Whose files these are* is never said, so a cross-account
listing shows three identical strings for three accounts' files. And one
`null` means "every account" on one method and "trusted caller, skip the
check" on the next, which is how `canRecalc` lies on the sudo route.

---

## 1. The model designed cold

Reproduced from the pre-registration; compressed, not changed.

**One concept, `scope`,** on every request, answering only *whose files may
this request touch*: `own` (default; never widened by anything else in the
request) · `managed` ("everything I may see cross-account", resolved
server-side: admin / instance-view → every account, sub-admin → members of
their groups, anyone else → 403) · `accounts` (explicit `accounts[]` +
`groups[]`, groups expanded server-side, must be a subset of `managed`,
else 403 — never trimmed). No separate `all`: for an admin `managed` *is*
all, so the picker's "All accounts" and the sidebar's switch (which has no
picker) send the same value.

**One resolver,** `ScopeResolver::resolve(request, caller): ResolvedScope
{ kind, ?list<string> targetUids (null = every account), bool crossAccount }`,
in this order: parse → `own` returns at once → role (admin | instance-view
| `ISubAdmin::isSubAdmin`) else 403 `not_permitted` → elevation else 403
`elevation_required` → ceiling → `managed` = ceiling, `accounts` ⊆ ceiling
else 403 `outside_managed_groups`. Controllers call it once; nothing
downstream re-derives "is this cross-account" from anything but the object.

**One elevation predicate,** two sources: `last-password-confirm` within
core's 30 min + 15 s window; or, for an app-password session, a per-token
opt-in stored by token id, set on the personal settings page behind
`#[PasswordConfirmationRequired]`. Not core's attribute on the routes: it
is unconditional per route, and `own` on the same route must not ask.

**Two checks that ignore scope:** `mayCalculate(caller)` — on the asker,
always, before anything else in capability 5; `PathPolicy::readAllowed(
file)` — on the file's absolute location, same call in every mode.

**Reach,** one function: `ReachResolver::storageIds(ResolvedScope):
?list<int>` from `IUserMountCache::getMountsForUser()` for the caller
(`own`) or each target (`accounts`/`managed`), `null` for every account.
Every capability filters `storage IN (…)` through it; per-file routes
additionally require the id to resolve inside it, answering 404 (not 403)
when it does not.

**FileRef,** every returned row: `{ fileId, owner|null, mountKind:
home|share|groupfolder|external, mountLabel, path, displayPath, … }`,
`displayPath` built once server-side: own home `/Documents/a.pdf`; another
home `alice:/Documents/a.pdf`; group folder `groupfolder:Marketing/a.pdf`;
external `storage:S3 archive/a.pdf`. Neither surface reconstructs a prefix.

**Routes,** five, each with `scope`:
`GET /files/{id}/checksums` · `GET /files/{id}/duplicates` ·
`GET /hashes/{algo}/{hash}` · `GET /duplicates?…` (cursor-paged) ·
`POST /files/{id}/checksums/recalculate`. Every 403 carries one `reason`
from a closed set; only `elevation_required` makes the UI do anything.

**Frontend,** one `useScope()` store `{ kind, accounts, groups }` appended
by one client function to every call, and one capabilities answer
`{ mayCrossAccount, managedIsAll, mayCalculate, elevated }` so the UI never
infers role.

---

## 2. What the code does

### 2.1 Who may cross accounts — two predicates

| Question | Answered by | Admits a sub-admin? |
|---|---|---|
| `canSudo` on the own listing (the only thing that makes the *Others* tab exist) | `SudoScope::isSudoer()` — `PublicApiController.php:586`, consumed at `App.vue:56` | **no** |
| `all` on `/sudo/selectable` | `isSudoer()` — `PublicApiController.php:841` | no (correct: "all" is not theirs) |
| `/sudo/lookup`, `/sudo/duplicates` with nothing named | `SudoScope::resolve( uid, null )` → `false` for a non-sudoer — `SudoScope.php:75-92`, called at `PublicApiController.php:178, 863` | **no** |
| `/sudo/duplicates?users[]=&groups[]=` | `resolveSet()` — `SudoScope.php:117-178` | yes |
| `/sudo/selectable` (the lists) | `selectableFor()` — `SudoScope.php:264-336` | yes |
| `/sudo/file/{id}/*` | `mayReachFile()` — `SudoScope.php:203-249` | yes |

`isSudoer()` is `admin || instance_view` (`SudoScope.php:56-61`). Nothing
in the app answers "may this account cross at all" as one predicate.

### 2.2 What the caller may reach — four resolutions

| Route | Own mode | Cross-account mode |
|---|---|---|
| `file/{id}/hashes`, `file/{id}/recalc` | `getUserFolder( uid )->getById()` — `ChecksumApi.php:926-943` (mounts: home, shares, group folders) | `mayReachFile()`: `getMountsForFileId()` ∩ `isUserAccessible()` — `SudoScope.php:214-236`; sudoer reaches anything |
| `file/{id}/duplicates` | as above for the reference file; each duplicate through the **session** user's folder — `ChecksumApi.php:444-447, 474-481` | reference file via `mayReachFile()`; **each duplicate still through the session user's folder** (same lines — `$requestingUser` is not consulted there) |
| `lookup` | `getMountsForUser()` storage ids, then `getById()` — `ChecksumApi.php:286-310` | no filter, raw filecache row — `ChecksumApi.php:253-271` |
| `duplicates` | `home::<uid>` storage **only** — `FilecacheService.php:933-937` | set → `home::<uid>` per uid; `null` → no filter — same lines |

So "own" excludes received shares and group folders in the listing but
includes them in the lookup and the per-file routes; a home on an object
store (`object::user:<uid>`, which `FileLocation.php:75-92` knows) lists
nothing at all; and the cross-account per-file reach (mounts) is wider than
the cross-account listing (home storages), which contradicts the reason
given for choosing mounts — "a file reachable by an account a sub-admin
administers is a file already in the listing" (`SudoScope.php:196-199`,
AP VerifyPerRow v1.2 block 4).

### 2.3 One `null`, two meanings

`ChecksumApi` takes `?string $requestingUser` / `$actingUser` on every
method. `null` means *trusted caller, skip the ownership check* on
`getHashesByFileId()` (`:100`), `findSameHash()` (`:432`) and
`recalcHash()` (`:565`); it means *every account* on `findByHash()` (`:253`)
and `findDuplicatesFor()` (`:378-385`). The sudo controller passes `null`
into the first group to mean the second (`PublicApiController.php:465,
720`). Two consequences:

- `getHashesByFileId( $id, null )` reports `'canRecalc' => mayRecalc( null )`
  (`:141`), and `mayRecalc( null )` is `actsAsAdmin( null )` = `true`
  (`:878-897`). On `/sudo/file/{id}/hashes` every caller is told they may
  recalculate, whatever the *manual_recalc* permission says about them.
  The recalc route itself is right — `recalcFor()` passes the uid
  (`PublicApiController.php:1022`) — so this is a lie in the hint, not a
  hole; but the sidebar hides its buttons on that hint.
- `findSameHash( $id, null )` skips the reference check and then resolves
  every duplicate through `$this->userSession->getUser()` (`:444-447`),
  dropping any it cannot open (`:476-481`).

`recalcHash()` already had to grow a fourth parameter, `bool $anyAccount`,
to say "the reach is settled" without giving up `$actingUser` (`:558-563`
and the docblock at `:520-550` explaining why). That is the design telling
you `null` was overloaded.

### 2.4 The scope parameter — three encodings on one route

`sudoFindAllDuplicates()` (`PublicApiController.php:604-629`) takes
`?string $user` **and** `?array $users` / `?array $groups`; absent means
every account. Measured as `admin`:

| Request | Result |
|---|---|
| `sudo/duplicates?user=bob&users[]=alice` | 200, alice only — `user` silently ignored (`:619`) |
| `sudo/duplicates?user=nobody_x` | 200, empty — `resolve()` returns the target for a sudoer without looking it up (`SudoScope.php:80-83`) |
| `sudo/duplicates?users[]=nobody_x` | 403 "Not yours to look at." — `resolveSet()` does look it up (`:158-163`) |
| `sudo/duplicates?users=alice` | 400 (framework type mismatch) |
| `sudo/duplicates?users[0][]=alice` | 200, empty — nested values filtered to an empty set (`:621`), then "a set naming nobody matches nothing" (`FilecacheService.php:940-944`) |

The last is the right failure mode (empty, never everyone). The second and
third are the same question — *does this account exist and may I read it*
— answered differently by two methods on the same class.

### 2.5 Whose file is it — never said

Row shape is `{fileid, path, name}` everywhere. Measured path spellings for
the *same file*, bob's `files/Templates/Pitch deck.odp` (fileid 450), and
admin's copy (43):

| Route | `path` |
|---|---|
| `duplicates` (own) and `sudo/duplicates` | `files/Templates/Pitch deck.odp` — raw filecache, no owner (`HashIndexService.php:291-295`) |
| `lookup` (own) | `/Templates/Pitch deck.odp` — user-relative (`ChecksumApi.php:310, 323`) |
| `sudo/lookup` | `files/Templates/Pitch deck.odp` — raw (`:265`) |
| `file/{id}/duplicates`, `sudo/file/{id}/duplicates` | `/Templates/Pitch deck.odp` — session-user-relative (`:484-491`) |

`sudo/duplicates?hash=ce16a5…&users[]=bob&users[]=admin` returned one
group with files 43 and 450, both spelled `files/Templates/Pitch deck.odp`.
`FilecacheService::batchLookupFilecachePaths()` computes a `user` per row
(`:983-998`); `HashIndexService` drops it (`:291-295`). `FileLocation::
describe()` (`FileLocation.php:151-161`) already renders
`/<owner>/<path>`, `groupfolder:<id>/…`, `storage:<id>/…` — for log lines
only. AP VerifyPerRow v1.2, open decision 4, names this and defers it to a
"SidebarScope AP" that does not exist in `GUIDELINES/wip/`.

### 2.6 The surfaces

- Duplicates page: the *Others* tab exists iff `canSudo` (`App.vue:56`);
  entering it runs `confirmPassword()` client-side (`App.vue:72-75`); the
  picker sends `users[]`/`groups[]` or nothing for "all"
  (`useDuplicates.ts:116-126`); a 403 is reported as "needs your password
  confirmed again, or is not yours to look at" (`:130-134`).
- Files sidebar: **no cross-account switch.** `routes.ts:11-33` has no
  entry for `sudo/file/{id}/hashes`, `sudo/file/{id}/duplicates` or
  `sudo/lookup`; `useSidebarHashes.ts:136` calls the own route only. Three
  of the six sudo routes have no consumer in the app, which is how 2.3's
  second bullet went unnoticed.

### 2.7 Measured, per-file, as `admin` on bob's file 450

| Request | Status | Body |
|---|---|---|
| `file/450/hashes` | 404 | own mode does not widen — correct |
| `sudo/file/450/hashes` | 200 | two hashes, `canRecalc: true` |
| `file/450/duplicates` | 404 | correct |
| `sudo/file/450/duplicates` | 200 | **one** file: 43 (admin's own copy). alice's 378 absent. |
| `sudo/lookup?hash=ce16a5…` | 200 | 43, 378, 450 |

`docs/api-v1.md:849` promises "any account's file; duplicates from every
account". The route delivers "the caller's own copies of any account's
file" — for a sudoer too.

### 2.8 What is right, and in two places better than §1

- `/sudo/` twins instead of a `scope=` parameter. A parameter with a
  default is one typo from silent widening; a separate URL is not. The
  code's choice serves "must never silently widen" at least as well as
  mine, and I withdraw the parameter for that reason.
- Grants (`SudoTokens.php`): keyed by token id in user config, refused for
  a token kept out of the filesystem (`:200-203`), never for a browser
  session (`:195-198`), listed instance-wide for the administrator
  (`:238-292`). More complete than my appconfig row.
- Order of refusal: permission before confirmation, "so that someone who
  may not ask is told so without being made to type a password first"
  (`PublicApiController.php:157-161`). Same as §1, stated better.
- `SudoConfirmation` reads the same key and window as core (`:56-61`) and
  gives the reason for not using the attribute (`:23-30`) — the reason I
  gave.
- Capability 5 is right: `mayRecalc( $actingUser )` always, on the asker
  (`ChecksumApi.php:578`); exclude rules before any read (`:587`), waived
  by nothing; the acting user logged (`PublicApiController.php:975-982`).
- A whole set refused when one target is out of reach
  (`SudoScope.php:114-116`). Same as §1.
- Core sets `last-password-confirm` on any login with the *account*
  password (`nextcloud-v34/lib/private/User/Session.php:456`), so a script
  using the account password over Basic auth is confirmed on every request
  and never needs a grant. That is core's semantics, the same its
  middleware applies; `SudoRouteTest::testTheAccountPasswordCountsAsAConfirmation`
  pins it. Not a defect, worth one sentence in the docs.

---

## 3. The divergences that matter

Ranked by what they break. Dropped as cosmetic: the `sudo` naming; the
`anywhere` flag's proximity to "any account" (it means substring match on
the hash filter, `docs/api-v1.md:489`); `success:false` + `message` on one
refusal and `error` on the next; "Not yours to look at." for a group that
does not exist.

**D1 — `sudo/file/{id}/duplicates` answers for the caller's own tree.**
§2.3, §2.7. Wrong for every caller, contradicts the docs, and the only
route the planned sidebar switch would call. Cause: `findSameHash()`
renders duplicates through the session folder regardless of scope. Not a
security defect (under-reach, never over-reach). Rank 1 because it is a
measured wrong answer on a shipped route.

**D2 — sub-admins never see the feature built for them.** §2.1. `canSudo`
is `isSudoer()`, the tab is gated on `canSudo`, so a sub-admin has no
*Others* tab, and `/sudo/lookup` refuses them outright. AP SubAdminPicker
v1.1 block 1–2 was implemented on the server and is unreachable from the
client. No e2e covers a sub-admin (`grep -rn subadmin tests/e2e` is empty).
Cause: two predicates for one question.

**D3 — reach is resolved four ways.** §2.2. Concretely: (a) the own
listing omits received shares and group folders that the own lookup and
sidebar include; (b) an object-store home lists nothing; (c) the
cross-account per-file reach is *wider* than the cross-account listing
for the same sub-admin, and the code's own justification for the wider
one assumes they are equal. Security-relevant part of (c), stated plainly:
`mayReachFile()` accepts any account whose *mounts* contain the file, and
core registers a share mount under the source storage — so a sub-admin
reaches, via `/sudo/file/{id}/hashes` and `/sudo/file/{id}/recalc`, a file
**owned by an account outside their groups, an administrator included,**
whenever it has been shared into a member's home. `isUserAccessible()`
refuses administrators by design (`nextcloud-v34/lib/private/SubAdmin.php:255`);
the mount route around it does not. The member could read that file
anyway, so the exposure is the hash and a paid read, not new content
access; but it is a widening the stated rule ("only accounts in the groups
they administer") does not grant, and it is unlisted, so the caller cannot
see it happening.

**D4 — one `null`, two meanings.** §2.3. The `canRecalc` lie is the
visible symptom; `$anyAccount` is the workaround already paid for. Every
future method on `ChecksumApi` will face the same choice.

**D5 — no owner on any row, three path spellings.** §2.5. Both required
surfaces show foreign files; neither can say whose. The cross-account
listing is unusable as a listing for exactly the case it exists for: two
accounts' copies of the same template are two identical lines.

**D6 — the sidebar switch is absent.** §2.6. A requirement not met rather
than a defect, but it is the reason D1 was never exercised, and it cannot
be added until D1 is fixed.

**D7 — three encodings for one scope.** §2.4. Confusing, and the
`user=nobody_x` / `users[]=nobody_x` split is a small inconsistency, but
nothing widens. Lowest rank; falls out of the D2/D4 fix for free.

---

## 4. A route forward

### Tier 1 — the smallest coherent change

Six edits, each closing one divergence, none touching the route table
except to drop one parameter. Together they make one answer per question.

1. **One predicate for "may cross".** `SudoScope::mayCross( uid ): bool`
   = `isSudoer( uid ) || subAdmin->isSubAdmin( user )`. `canSudo` uses it
   (`PublicApiController.php:586`). `all` on `/sudo/selectable` keeps
   `isSudoer()`. Closes D2's tab half.
2. **`resolve( uid, null )` means "my ceiling", not "everyone".** For a
   sudoer `null`; for a sub-admin the member list of their groups (the
   same expansion `resolveSet()` does for a named group); for anyone else
   `false`. `sudoScopeOrRefusal()` then serves `/sudo/lookup` and a bare
   `/sudo/duplicates` to a sub-admin with their groups' scope. Closes
   D2's route half and is the value the sidebar switch will send. Return
   type becomes `list<string>|null|false`; the string case goes with (6).
3. **Split "who is asking" from "what may be reached" on `ChecksumApi`.**
   `findSameHash( int $fileId, ?string $actingUser, ?array $reachUids )`,
   `findByHash( …, ?array $reachUids )`, `getHashesByFileId( int $fileId,
   ?string $actingUser, ?array $reachUids )`; `recalcHash()` already has
   the shape, rename `$anyAccount` to match. `$actingUser` is null only
   for occ/DI; `$reachUids` null means every account. `canRecalc` and the
   preference read `$actingUser`; the reach check reads `$reachUids`.
   `findSameHash()` renders each duplicate by resolving it inside the
   reach — through `fileForAnyAccount()`-style holder lookup filtered to
   `$reachUids`, or through the storage filter of (4) — never through the
   session. Closes D1 and D4.
4. **One reach resolver.** `ReachResolver::storageIds( ?array $uids ):
   ?array` — `null` → `null`; else the union of
   `IUserMountCache::getMountsForUser()` numeric storage ids, deduplicated.
   `FilecacheService::batchLookupFilecachePaths()` takes storage ids, not
   uids, and loses its `home::` construction; the listing, the lookup and
   the set listing all pass through this one function. Mounts everywhere
   makes (a) and (b) of D3 go away and makes the listing agree with
   `mayReachFile()`. For (c), decide once and write it down: either accept
   that a sub-admin's reach is "what my members can see" (then the listing
   now shows it, with the owner from (5), so it is no longer hidden), or
   restrict both `mayReachFile()` and the resolver to home mounts of
   accessible accounts (then admin-owned shares are out on both). I would
   take the first: it matches core's own delegation and the code's
   argument, once the argument is true. Closes D3.
5. **Owner on every row.** Every `files[]` element gains `owner: ?string`
   and `location: string` from `FileLocation::describe()`; `path` stays
   what the caller's own folder would call it when the file is in reach,
   and `location` is what both surfaces render whenever `owner !==
   caller` or the mount is not a home. The listing already has `user` per
   row; the lookup and per-file routes get it from the storage id they
   already join. Closes D5 and unblocks D6.
6. **Drop `user=`.** `users[]`/`groups[]` name a set; nothing named means
   the ceiling from (2). `docs/api-v1.md:851-852` collapse to one row.
   Closes D7.

Then D6: the sidebar switch calls `sudo/file/{id}/duplicates`, which after
(3) returns what its name says, and renders `location`.

Tests that must exist before this ships: a sub-admin over HTTP on every
sudo route (the integration suite has none — `SudoRouteTest.php` makes its
user an admin, `:32`); `sudo/file/{id}/duplicates` for a sudoer asserting a
foreign duplicate appears; the own listing asserting a received share
appears. The unit tests would have passed through D1 and D2 unchanged,
which is the argument for the HTTP ones.

### Tier 2 — the restructuring, and whether it is warranted

The full §1 shape: a `ResolvedScope` value object produced by one
`ScopeResolver` in place of the three `*OrRefusal` helpers
(`PublicApiController.php:168-293`), passed into `ChecksumApi` instead of
`(?string, ?array)` pairs, with the six `/sudo/` twins collapsed into a
`scope` parameter.

The route collapse is **not** warranted: §2.8 says why the twins are the
better answer, and pre-1.0 freedom is not a reason to spend it. The
resolver and the value object are worth doing *only if* `ChecksumApi` is
being reshaped anyway — Tier 1 step 3 already gives every method two
parameters that always travel together, and a small final class
`Reach { ?string actingUser; ?array uids; }` is the natural next edit, not
a separate project. Recommendation: Tier 1 now, in six commits in the
order given, and let step 3 grow into the value object in the same AP if
the parameter pairs start to sprawl. The three private helpers in the
controller can stay; they read well and each is one line of policy.

---

## 5. What could not be determined

- **Sub-admin behaviour over HTTP.** `alice` is a sub-admin of `test
  group` (`oc_group_admin`), but her password is not `alice` or the
  `Secret#1` the testing README uses as its example; guessing tripped
  core's brute-force throttle for `127.0.0.1`, which was reset with
  `occ security:bruteforce:reset` (the only instance state touched, and
  only to undo the effect of this review). D2 and D3(c) are therefore
  traced in code, not measured. Setting a password on `alice` would have
  been a mutation of the instance beyond this review's remit.
- **`canRecalc` for a non-admin sudoer** was traced (`ChecksumApi.php:141
  → :892 → :878`), not measured: demonstrating it needs the *manual_recalc*
  permission narrowed, which is an app-config change on the instance.
- **PHP suite.** First run: 974 tests, 23 failures, every one a 429 from
  `SudoRouteTest` — the brute-force throttle above was live while it ran.
  Second run after the reset: **OK, 974 tests, 2495 assertions.** The
  suite is green and D1, D2 and D5 are all present, which is the measure
  of what it covers.
