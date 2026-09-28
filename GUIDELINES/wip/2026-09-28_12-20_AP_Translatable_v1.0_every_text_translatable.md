# AP Translatable v1.0: every text translatable

> **Status: proposal, 2026-09-28.** The app ships no translations, and only
> 17 of its roughly 300 user-facing texts can be translated at all. This plan
> wraps every text, sets up the files Nextcloud's own translation tooling
> reads and writes, keeps them current in CI, and ships German as the first
> language. Joining Nextcloud's Transifex is left for later; the layout here
> is the one that sync uses, so it can take over without a change of files.

## Discussion

The maintainer asked whether the app is translated through Nextcloud's
Transifex the way some third-party apps are, and what German coverage it has.
Two research passes answered it; their full reports are to be added to `wip/`
as ANALYSIS L10n v1.0 beside this plan. In short:

- Nothing is automatic. Nextcloud's sync takes a repository only on request,
  pushes to its GitHub `master` nightly, and conflicts with this project's
  GitLab-to-GitHub mirror. Decided: **no Transifex yet**; first make the app
  translatable and maintain translations here.
- There is nothing to translate yet: no `l10n/`, no translation files, and
  the texts are not wrapped.

## Analysis

1. **What is wrapped today.** PHP: one string, the app's name, in
   `lib/Settings/AdminSection.php`, `PersonalSection.php` and
   `templates/partials/settings-header.php`. Frontend: 16 strings, all in the
   sidebar (`src/sidebar.ts`, `src/sidebar-vue/`). Every call already uses the
   app id `file_checksum_search`.
2. **What is not.**
   - Frontend, roughly 250–300 texts: the admin and personal settings, the
     rules, the duplicates and docs pages, the shared components, about 20
     toasts, the band labels and descriptions in `src/rules-vue/bands.ts`, the
     `HELP` constants, and 16 `result.error || '…'` fallbacks.
   - Several texts are built from variables (`Band ${rule.band}, position
     ${rule.position}`) and need placeholders; counts need plurals.
   - PHP: the Unified Search heading (`lib/Search/HashSearchProvider.php`),
     the docs page's labels (`PageController`), and about 55 error messages in
     `lib/Controller/*.php` and `lib/Service/RuleDefinitionValidator.php`, many
     of which the UI shows as they come.
   - `appinfo/info.xml`: name, summary, description, and the navigation entry
     "Duplicates".
3. **How Nextcloud loads translations.** Nothing to code: `Util::addScript`
   and `addInitScript` add `l10n/<lang>.js` for the app's scripts, PHP's
   `IL10N` reads `l10n/<lang>.json`, and a navigation name is translated
   through the app's l10n. `package.sh` ships `l10n/` as it is.
4. **Nextcloud's translation tool** (`translationtool.phar`, in
   `nextcloud/docker-ci/translations/translationtool`) is what the Transifex
   sync runs:
   - `create-pot-files` writes `translationfiles/templates/<app>.pot` with
     `xgettext`: `t`/`n` in PHP, `t:2`/`n:2,3` in `.js`/`.ts`, and the
     `info.xml` texts. `.l10nignore` excludes paths.
   - In a `.vue` file it passes the `<script>` blocks to `xgettext` as
     JavaScript, and reads the `<template>` with regular expressions that find
     only `t('app', '…')`, `t("app", "…")` and `` t('app', `…`) `` with a
     literal text. A variable, a concatenation or a template placeholder in
     the call is not extracted. `n()` in a template is not read at all.
   - `convert-po-files` turns `translationfiles/<lang>/<app>.po` into
     `l10n/<lang>.js` and `.json`.
   - Its `check-files` mode warns about text that looks like a missed `t()`.
5. **German is two languages in Nextcloud:** `de` (informal, "du") and
   `de_DE` (formal, "Sie"). A user set to `de_DE` falls back to `de` only
   through the browser's language, so both ship.
6. **Maintaining translations here.** Hand-written `l10n/` files would ship
   and load, but nothing would say when a text changed or a translation is
   missing. Keeping `.po` files per language, merged from the `.pot` with
   `msgmerge` and converted by the tool, gives that for free: `msgfmt
   --statistics` counts what is missing or fuzzy. It is also the layout the
   Transifex sync uses, so switching later means handing the `.po` files over.
7. **Checks that exist and would miss this.** ESLint covers `src/` but has no
   rule on bare texts; `eslint-plugin-vue` 9.33 has
   `vue/no-bare-strings-in-template`. The two sidebar specs mock
   `@nextcloud/l10n`'s translate as the identity; no test loads a
   translation. CI checks nothing about l10n. `l10n/` is not among the
   contract's shipped-code paths.

## Implementation Plan

Conventions for every wrapped text, from analysis 4:

- `t('file_checksum_search', '…')` and `n('file_checksum_search', '…', '…',
  count)` with literal texts, in scripts and templates alike; in a template,
  on one line or as a template literal without `${}`.
- Values go in as placeholders, `t('file_checksum_search', 'Band {band},
  position {position}', { band, position })`, never by concatenating
  translated pieces.
- A count takes `n()`. In a template the count text comes from a computed
  value, since the tool does not read `n()` there.
- A short or ambiguous text gets a `// TRANSLATORS …` comment, which the tool
  passes to the `.pot`.
- Texts in constants (`bands.ts`, `HELP`) are translated where they are read
  (a function or computed), not at module load.
- Logs and `occ` output stay English, as in Nextcloud itself.

1. **[TASK] Translation files and tools.**
   - `l10n.sh` beside `package.sh`: fetches `translationtool.phar` from
     `nextcloud/docker-ci` at a pinned commit and checks its SHA-256, then
     `pot` (create the `.pot`), `update` (merge it into every
     `translationfiles/<lang>/file_checksum_search.po`), `build` (convert to
     `l10n/`), `check` (the `.pot` is current, every declared-complete
     language has nothing untranslated or fuzzy, `check-files` is quiet).
     Needs PHP and gettext.
   - `.l10nignore` (`tests/`, `vendor/`, `vendor-bin/`, `node_modules/`,
     `js/`, `build/`, `GUIDELINES/`); `.nc.publish.ignore` excludes
     `translationfiles/` and `l10n.sh`; `l10n/` ships.
   - The contract (`GUIDELINES/project/_CONTRACT.md`, inlined by the
     guidelines' `tools/sync.sh`): `l10n/` among the shipped-code paths, the
     `l10n.sh` commands, and the conventions above as a new §3.7.
   - CHANGELOG: exempt, tooling only.

   **Verification:** `l10n.sh pot` on today's tree writes 17 texts plus the
   `info.xml` texts, the same set the research extracted.

2. **[TASK] The frontend's texts are translatable.** One commit per area so
   each stays reviewable: settings (admin, personal); rules; duplicates and
   docs; shared components, toasts and `bands.ts`. Each commit rebuilds
   `js/`, updates the `.pot`, and keeps the specs green; a spec asserting an
   English text keeps passing, as `t()` without a loaded translation returns
   its source.
   CHANGELOG (first commit adds, the others amend): Changed, "Every text of
   the web interface can be translated."

   **Verification per commit:** `npm run lint`, `npm test`, `npm run build`;
   `l10n.sh pot` gains the area's texts, and its count matches the calls in
   the area, so none is lost to the tool's template reading.

3. **[TASK] The server's texts are translatable.** `IL10N` in the search
   provider, `PageController`, the controllers and the rule validator; an
   error message the UI shows is translated where it is made, in the user's
   language.
   CHANGELOG: amends the bullet of block 2, "… and the messages the server
   sends it".

   **Verification:** `composer cs:check`, `composer psalm`, the unit and
   integration suites (PHPUnit wrapper); `l10n.sh pot` gains the texts.

4. **[TASK] A bare text fails the lint.** `vue/no-bare-strings-in-template`
   as an error, with an allowlist for symbols and units; a
   `no-restricted-syntax` rule against a literal passed straight to the toast
   helpers; the CI lint job runs `l10n.sh check`.
   CHANGELOG: exempt.

   **Verification:** the rule passes on the tree after blocks 2 and 3, and
   fails on a bare text and on a stale `.pot` put in on purpose.

5. **[TASK] German, informal and formal.** `translationfiles/de/` and
   `de_DE/`, both complete, and the built `l10n/de.*`, `l10n/de_DE.*`;
   German summary and description in `info.xml` (`lang="de"`). A Vitest spec
   loads the German translation and renders a component with a placeholder
   and a plural; a Cypress spec opens the settings as a user set to `de`.
   CHANGELOG: Added, "German translation, informal (`de`) and formal
   (`de_DE`)."

   **Verification:** `l10n.sh check` with both declared complete; the specs;
   a look at the admin settings, the rules and the duplicates page in both
   registers.

## Proposed commit messages

1. `[TASK] Translation files and the tool that keeps them current`
2. `[TASK] The settings pages' texts can be translated`, and one per area
3. `[TASK] The server's messages can be translated`
4. `[TASK] A bare text in a template fails the lint`
5. `[TASK] German translation, informal and formal`

Each in full at its commit gate.

## Open decisions

- **`.po` files kept here** (recommended, analysis 6) or `l10n/` edited by
  hand.
- **The tool pinned by download and checksum** (recommended) or the 420 KB
  `.phar` committed.
- **Server messages translated in PHP** (recommended, block 3) or sent as
  codes the frontend translates.
- **Who writes the German.** Proposed: drafted here in both registers,
  reviewed by the maintainer, with the terms settled first (checksum
  "Prüfsumme", hash "Hash", duplicates "Duplikate", rule "Regel", band
  "Band").
- **More languages** (fr, es, it, nl, pt_BR, zh_CN, ja or pl, in the order
  the research suggests) need translators; not in this plan.
- **Transifex later:** the mirror conflict, the bot's commit format and the
  CHANGELOG exemption for its commits are decided then.

## Change History

| Version | Date       | Change |
|---------|------------|--------|
| v1.0    | 2026-09-28 | Initial plan: every text wrapped for Nextcloud's translation tool, `.po` files maintained here and checked in CI, German in both registers; no Transifex yet. |
