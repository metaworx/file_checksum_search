# Stage 1 — cold design for FCIAS cross-account access (pre-registration)

Written before reading any of the app. Only Nextcloud framework source consulted
(PasswordConfirmationMiddleware, IToken, IProvider, ISubAdmin, IUserMountCache).

## 1. One concept: Scope

Every request carries exactly one `scope`. It answers one question: *whose files
may this request touch?* Nothing else rides on it.

```
scope = own | managed | accounts
```

- `own` — default when absent. The caller's own reach (home, received shares,
  group folders). Never widened by anything else in the request.
- `managed` — "everything I am allowed to see cross-account", resolved
  server-side: admin / instance-view → every account; sub-admin → members of the
  groups they administer; anyone else → 403. This is what the sidebar switch
  sends, because it has no picker.
- `accounts` — an explicit set. Companion parameters `accounts[]` (uids) and
  `groups[]` (gids). Union of the two, groups expanded to members. Must be a
  subset of what `managed` would give the caller, else 403 (not silently
  trimmed). Empty set is a 400.

There is no `all` value: for an admin `managed` *is* all, and the picker's
"all accounts" checkbox simply sends `managed`. That keeps one axis with three
points and no combination that means the same thing twice.

`scope` is a query parameter on GET routes and a body field on POST. Same name,
same values, on all five capabilities.

## 2. One resolver: ScopeResolver

`ScopeResolver::resolve(IRequest, IUser $caller): ResolvedScope`

```
final class ResolvedScope {
    public readonly ScopeKind $kind;          // own | managed | accounts
    /** @var list<string>|null  null = every account (admin/instance-view managed) */
    public readonly ?array $targetUids;
    public readonly bool $crossAccount;       // kind !== own
}
```

Steps, in this order, all in this one class:

1. Parse `scope` (default `own`). Unknown value → 400.
2. If `own` → return immediately. No further checks; nothing below ever runs
   for own mode.
3. **Role**: is the caller admin (`IGroupManager::isAdmin`), an instance-view
   account (app config `instance_view_accounts`, list of uids), or a sub-admin
   (`ISubAdmin::isSubAdmin`)? None → 403 `not_permitted`.
4. **Elevation** (see §3). Not elevated → 403 `elevation_required`.
5. Compute the caller's *ceiling*: `null` for admin/instance-view; the member
   set of `ISubAdmin::getSubAdminsGroups()` for a sub-admin.
6. `managed` → targetUids = ceiling. `accounts` → requested set; every uid must
   be inside the ceiling (`ISubAdmin::isUserAccessible` per uid for sub-admins),
   every gid must be one of the sub-admin's groups. Anything outside → 403
   `outside_managed_groups`. Return.

Controllers call this once at the top and pass the `ResolvedScope` down. No
service ever re-derives "is this cross-account" from anything but this object.

## 3. Elevation — one predicate, two sources

`Elevation::isElevated(): bool`, checked only in step 4 above.

- Interactive session: `ISession->get('last-password-confirm')` within
  30 min + 15 s, exactly the window core's `PasswordConfirmationMiddleware`
  uses. I do not use the `#[PasswordConfirmationRequired]` attribute, because
  it is unconditional per route and own-mode requests on the same route must
  not demand a confirmation.
- App password: `IProvider::getToken(ISession::getId())->getId()` gives the
  token id. The opt-in is one app-config row per token:
  `file_checksum_search / cross_account_token_<tokenId> = 1`. It is set from
  the user's personal settings page, which lists their app passwords (name +
  id from core) with a toggle; flipping the toggle is itself a route with
  `#[PasswordConfirmationRequired]`. It is cleared when the token is deleted
  (listen to core's token-invalidation, or lazily: missing token → ignore row).
- Token has `SCOPE_SKIP_PASSWORD_VALIDATION` (SSO user, cannot confirm) →
  treated as *not* elevated unless the per-token opt-in exists. SSO users
  therefore elevate through an app password; that is acceptable.

A caller with both (browser session that is an app-password login) is elevated
if either source says so.

## 4. Two checks that ignore scope entirely

- **may-calculate** — `Permissions::mayCalculate(IUser $caller)`; app-config
  per uid, default from a global setting. Checked in capability 5 on the
  *caller*, before anything else, regardless of scope. The file's owner's
  setting is never consulted.
- **path policy** — `PathPolicy::readAllowed(FileRef): bool`; the admin-
  configured deny list, matched on the file's absolute storage path
  (`<storage id>:<internal path>` or mount point). Applied to every file about
  to be *read* (capability 5) and, cheaply, as a filter on listings so a
  forbidden path is not advertised as a duplicate. Same call in own and
  cross-account mode.

Neither of these lives in ScopeResolver and neither takes a ResolvedScope.

## 5. Reach — how a ResolvedScope turns into a filecache filter

`ReachResolver::storageIds(ResolvedScope): ?list<int>`

- `own` → numeric storage ids of `IUserMountCache::getMountsForUser(caller)`.
- `accounts` / `managed` with a uid list → union of `getMountsForUser(uid)` for
  each target uid. (Received shares of a target are that target's reach too;
  they are included. Group folders appear once because storage ids dedupe.)
- `managed` with `null` (every account) → `null` = no storage filter.

All five capabilities put `storage IN (:ids)` (or nothing) on their queries via
IQueryBuilder. Single-file capabilities (1, 2, 5) additionally require the
target fileId to resolve inside the reach: `getMountsForFileId($fileId)`
intersected with the storage set; empty → 404, never 403 (do not leak
existence).

## 6. FileRef — every returned path is renderable for a foreign file

```
FileRef {
  fileId:      int
  owner:       string|null     // uid of the home the file lives in; null for
                               // group folders / external mounts without owner
  mountKind:   home | share | groupfolder | external
  mountLabel:  string          // uid for home, group-folder name, mount point
  path:        string          // path inside the mount, leading slash
  displayPath: string          // what the UI shows; see below
  size, mtime, mimetype
  checksums:   { sha1?: string, md5?: string, sha256?: string, ... }
}
```

`displayPath` is built server-side once:

- own home → `/Documents/a.pdf`
- another home → `alice:/Documents/a.pdf`
- group folder → `groupfolder:Marketing/a.pdf`
- external mount → `storage:S3 archive/a.pdf`

The sidebar and the Duplicates page both render `displayPath`; neither
reconstructs a prefix client-side. The prefix is present whenever
`mountKind !== home || owner !== caller`, so in own mode a received share still
shows `alice:/…`, which is what a user expects to see.

## 7. Routes (OCS, `/ocs/v2.php/apps/file_checksum_search/api/v1`)

| # | Route | scope | Notes |
|---|-------|-------|-------|
| 1 | `GET  /files/{fileId}/checksums` | query | → `FileRef` |
| 2 | `GET  /files/{fileId}/duplicates` | query | → `list<FileRef>` (excluding the file itself) |
| 3 | `GET  /hashes/{algo}/{hash}` | query | → `list<FileRef>` |
| 4 | `GET  /duplicates?page&limit&algo&minSize&mimetype&owner` | query | → `{ groups: list<{hash, algo, files: list<FileRef>}>, total, page, limit }` |
| 5 | `POST /files/{fileId}/checksums/recalculate` body `{algo?}` | body | → `FileRef`; 403 `calc_not_permitted` if caller lacks may-calculate; 403 `path_forbidden` if policy denies |

Every 403 body carries `{ reason: <code> }` from the closed set
`not_permitted | elevation_required | outside_managed_groups |
calc_not_permitted | path_forbidden`. `elevation_required` is the only one the
UI reacts to (it opens core's password-confirmation dialog and retries).

Paging in 4 is by hash cursor, not offset, because duplicate groups are
grouped rows and offset paging over `GROUP BY … HAVING COUNT(*) > 1` is unstable
when the index is being written.

## 8. Frontend

One Pinia/composable store `useScope()`:

```
{ kind: 'own'|'managed'|'accounts', accounts: string[], groups: string[] }
```

- Duplicates page, "Cross-account" tab: the picker writes `accounts`/`groups`;
  the "All accounts" checkbox (shown only if `capabilities.managedIsAll`) sets
  `kind = 'managed'`. Leaving the tab resets to `own`.
- Files sidebar: the switch toggles `own` ↔ `managed`. Shown only if
  `capabilities.mayCrossAccount`.
- A single `GET /capabilities` (or the initial-state provider) tells the UI:
  `{ mayCrossAccount: bool, managedIsAll: bool, mayCalculate: bool,
  elevated: bool }`. The UI never infers role on its own.
- One API client function `request(route, params)` appends the scope from the
  store to every call — so the client cannot send two scope encodings.

## 9. What I deliberately did not do

- No separate "sudo"/"impersonate" endpoint, no server-side stored "current
  scope". Scope is stateless and per request; the store is a client
  convenience only.
- No `user=<uid>` single-account parameter alongside `accounts[]`; one of them
  would become the odd one out.
- No boolean `crossAccount=true` flag *plus* a target list; the list (or
  `managed`) already says it.
- Elevation is never a substitute for role, and role never for elevation; both
  are required, in that order, and only for `kind !== own`.
