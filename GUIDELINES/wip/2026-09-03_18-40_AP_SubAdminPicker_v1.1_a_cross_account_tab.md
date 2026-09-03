# AP SubAdminPicker v1.1: a cross-account tab

> **Status: proposal, 2026-09-03.** Supersedes v1.0 (`7055c1c`), which is
> immutable. v1.0 proposed one picker replacing the *Show all users* switch
> and selecting a single account. The user's review replaced that with a
> larger shape: many targets, groups as well as users, a tab of its own, and
> a configurable prefill threshold. The feasibility questions in that review
> are answered under *What is possible* below.

## What changed against v1.0

1. **Many targets, not one.** The routes take a set — users *and* the
   groups a caller may reach — and the server expands groups to members,
   authorizes each, and answers **one merged listing**, the same semantics
   today's *all accounts* view already has (a duplicate group may span
   selected accounts, which is the point of looking across them).
2. **A tab, not a switch.** Cross-account browsing leaves the *Show all
   users* switch behind and becomes a third tab on the Duplicates page:
   **Duplicates · Cross-account · Help**, its panel on a distinct amber
   ground so it can never be mistaken for one's own files. The red switch
   from AP DuplicatesControls is removed with it — the tab is the warning
   now, and a control that changes whose files are shown no longer hides
   inside the ordinary listing.
3. **Prefill, then typeahead.** The picker prefills every option it may
   offer up to a threshold (default **21**); above it, it asks the server as
   you type. The same rule for administrators and sub-admins alike — the
   difference is only *which* accounts the server will name.
4. **The threshold is configurable**, and the admin page's **Status** tab is
   renamed **Advanced**: the read-only diagnostics it already shows, plus
   the tunables this introduces. Documentation stays the last tab.
5. **The sidebar is a separate AP** (see *Companion* below).

## What is possible (the review's three questions, answered)

- **A picker of the groups a user sub-administers** — yes.
  `ISubAdmin::getSubAdminsGroups()` returns exactly those. Note
  `NcSettingsSelectGroup`, which `PermissionSection` uses, lists *every*
  group and is the wrong control here; a plain `NcSelect` fed by our own
  endpoint is right.
- **Several users at once** — yes. `NcSelect` with `multiple`, as
  `PermissionSection`'s user picker and `AlgorithmSelect` already do.
- **Mixing groups and users in one control** — yes, with kind-tagged
  options, the pattern `RuleForm` already uses for
  `home:* / groupfolder:<id> / storage:<id>`.

The cost of all three is the backend: `SudoScope::resolve()` authorizes one
target today and the routes take a single `?user=`. Block 1 is that change.

## Blocks

1. **[TASK] Authorize a set, and say who may be named.**
   `SudoScope` gains a set form: given the caller and a requested set of
   uids and group ids, return the uids they may actually read — an
   administrator (or `instance_view`) any, a sub-admin only members
   reachable through `ISubAdmin::isUserAccessible()`, anyone else nothing.
   Groups are expanded server-side, never by the client: expansion in the
   browser would hand out membership the caller may not otherwise see. The
   cross-account routes accept the set (`users[]`, `groups[]`) in place of
   the single `?user=`. A new read route answers *who may I name* —
   the caller's selectable groups and users, plus `prefill: bool` telling
   the client whether the list is complete or it must type to search, and a
   `?search=` form for the typeahead. Unit tests per role; the HTTP
   integration suite gains a sub-admin case.
2. **[TASK] The Cross-account tab.** A third tab on the Duplicates page
   carrying the picker and the results, on the amber ground
   (`--color-warning` family, as the red used `--color-error`). It reuses
   `DuplicateGroup` and the listing wholesale — only the source of the data
   and the ground colour differ. Selecting targets confirms the password
   through the existing `SudoConfirmation` flow, once per window, exactly as
   the switch did. The *Show all users* switch and its red styling are
   removed from the Duplicates tab. *All accounts* stays on offer, for a
   sudoer only, as one option in the same picker.
3. **[TASK] Advanced, and the threshold.** The admin page's Status tab
   becomes **Advanced**: the same diagnostics, plus the prefill threshold
   (default 21) as its first tunable, stored through the config lexicon.
   `status.cy.js` follows the rename; Documentation stays last.
4. **Docs and the full suite.** User guide (the tab, who sees which
   options), FAQ, README (admin tabs, the renamed Advanced),
   `docs/api-v1.md` and the OpenAPI for the changed routes and the new one;
   full e2e.

## DRY

- The **target picker** is one component, not one per caller: block 2 uses
  it, and the companion sidebar AP reuses it if a picker ever appears there.
- The **listing** is `DuplicateGroup` and the existing table, reused as-is;
  the tab supplies rows, not its own renderer.
- The **confirmation** is `SudoConfirmation` plus
  `@nextcloud/password-confirmation`, already the switch's path.
- The **amber ground** is a modifier class beside the error one the switch
  used, so a third state later has somewhere to go.

## Companion

**AP SidebarScope** (to be written): the sudo switch beside the sidebar's
*Find duplicates* button with its password dialog, no target picker but
user/group/storage-prefixed paths, and a shared location-icon set reused by
the rules table's Scope column.

## Open decisions

1. The amber tokens: `--color-warning` / `--color-warning-text` for the
   panel ground — recommended, and consistent with the error pair the red
   switch used. Confirm before block 2.
2. The threshold's config key and whether it is per-instance only
   (recommended) or also per-user.
3. Whether *All accounts* should remain available to a sudoer at all now
   that named targets exist — recommended yes, it is the cheapest way to
   answer "is this file anywhere on the instance".

## Gate

Per block: PHP suite; for blocks 2 and 3, `npm run lint` + `npx vitest run`
+ `npm run build` + the `duplicates` and `status` e2e; full e2e at the end.

## Change History

- v1.0 (2026-09-03): proposal — one picker, single target, replacing the switch.
- v1.1 (2026-09-03): many targets, groups and users, a tab of its own, the
  prefill threshold, Status renamed Advanced; sidebar split to its own AP.
