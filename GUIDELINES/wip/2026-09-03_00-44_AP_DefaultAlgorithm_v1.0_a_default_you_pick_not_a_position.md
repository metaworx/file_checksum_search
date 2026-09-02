# AP DefaultAlgorithm v1.0: a default you pick, not a position

> **Status: approved 2026-09-03, in execution.** Follows UserDocs v1.5 Block 1,
> which made the allowlist's first entry the default and left ordering to
> remove-and-re-add.

## Problem

The allowlist's order carries exactly one meaning: its first entry is the
default. Nothing else reads the order. Expressing one choice through the
order of a ten-entry list is indirect (the admin page says so itself: "remove
and re-add to change the order"), and the alternative — a sortable list —
costs a dependency, keyboard reordering, and tests, for that one choice.

Second, found on the same screens: on the personal page the help icon sits
under the select instead of beside it. `.fcias-permission-row` is *scoped* to
`PermissionSection.vue`; `PreferenceSection.vue` and the admin
`AlgorithmSection.vue` use the class and get nothing. Both render stacked.

## Decision

The catalogue is a **set plus a designated default**, not an ordered list.

- New app config `default_algorithm` (string, lexicon; empty = "the first
  allowed"). `AlgorithmCatalogue::default()` returns the stored name when it
  is in force, else the first allowed. `setDefault(name)` refuses a name not
  in force; the empty name clears the setting.
- `setAllowlist()` clears a stored default the new list no longer contains,
  so the default snaps to the first remaining and does not silently return
  when the algorithm is re-allowed later.
- `PUT /settings/global` accepts an optional `defaultAlgorithm`, applied
  after the allowlist when both are sent; a name outside the allowed set is
  400. The response carries `defaultAlgorithm`. `GET` already does.
- Admin page: beside the allowlist picker a second, single `AlgorithmSelect`
  "Default algorithm" offering only the allowed ones, following the allowlist
  live (default no longer allowed → first remaining). One Save for both.
  The "Default: X — the first in the list" line and the drag remark go.
- The allowlist is displayed in stored order, which no longer means anything;
  no sorting is introduced.
- Row layout: `.fcias-field-row` (flex, select grows, help icon at the end,
  NcSelect width rules) moves to `src/settings-admin.css`, the stylesheet both
  settings pages import. `PreferenceSection`, `AlgorithmSection` and
  `PermissionSection` use it; the scoped copy in `PermissionSection` goes.

Personal page, sidebar, `GET /api/v1/algorithms`, `/api/v1/preferences` are
untouched: they already read `default()`.

## Scope

One block, one commit.

Backend: `AlgorithmCatalogue` (`DEFAULT_KEY`, `default()`, `setDefault()`,
snap in `setAllowlist()`), `ConfigLexicon` (+1 entry → 18),
`SettingsController::saveAdminOptions()`.

Frontend: `AlgorithmSection.vue` (second picker, snap, save both),
`PreferenceSection.vue` / `PermissionSection.vue` (shared row class),
`settings-admin.css`, admin `App.vue` hint sentence.

Tests: `AlgorithmCatalogueTest` (stored default honoured; stale default falls
back; `setDefault` refuses unknown, empty clears; allowlist change clears a
dropped default), `SettingsControllerTest` (default outside the allowed → 400;
valid → echoed), `ConfigLexiconTest` count, new `AlgorithmSection.spec.ts`
(default follows the allowlist; save sends both fields).

Docs: every sentence saying the first allowed is the default — README admin
bullet, admin page hint, `api-v1.md`/OpenAPI where `default` is explained.
CHANGELOG bullet.

Verification: PHP unit + integration, vitest, lint, build; live on instance
34 (pick default → personal page "Default (X)", sidebar first button; remove
it from the allowlist → snaps); full e2e regression run, no new e2e.

## Open decisions

- No new e2e: the admin section has none today and the behaviour is covered
  at unit level on both sides. Add one if the suite is to cover the section.

## Change History

- v1.0 (2026-09-03): initial.
