> **Fragment** — inlined by `tools/sync.sh`; not a standalone document.

# {{project_name}} — Project Contract (v2.8.0)

What binds work in this project, for everyone working on it. Inlined into
`/AGENTS.md` for agents and into `{{guidelines_root}}/README.md` for people, so
that the two cannot drift apart.

A Nextcloud app that indexes file checksums and makes them searchable.

## 1. Project Facts

> No absolute paths here. An agent is already inside the checkout, so the
> repository root is `git rev-parse --show-toplevel`; Windows-hosted agents
> prefix with `wsl --cd "$PWD"` (see `{{shared_root}}/ENVIRONMENTS.md`).
> Machine-specific values belong in `{{guidelines_root}}/config.local.ini`,
> which is not tracked.

| Fact                  | Value |
|-----------------------|-------|
| Project name          | `metaworx/file_checksum_search`, Nextcloud app id `file_checksum_search` |
| Language(s)           | declared as `project.languages` in `{{guidelines_root}}/config.ini`, kept honest by `{{shared_root}}/tools/detect-languages.sh --check`. PHP backend, TypeScript and Vue frontend, SCSS/CSS, YAML for CI, Python for tooling (`scripts/l10n.py`, `tests/e2e/store/appstore.py`) — of which Vue and YAML have no shared baseline, so they are not declared. |
| Source directories    | `lib/` (PSR-4 `OCA\FileChecksumSearch\`), `src/` (frontend), `tests/` (PSR-4 `OCA\FileChecksumSearch\Tests\`), `appinfo/`, `templates/` |
| Shipped-code paths    | `lib/`, `src/`, `css/`, `js/`, `l10n/`, `templates/`, `img/`, `appinfo/routes.php`, `appinfo/info.xml` |
| Version manifest      | `appinfo/info.xml`: the `manifest` of `CHANGELOG.md` in `{{guidelines_root}}/config.ini`, so `changelog.sh cut` sets its `<version>` and `check` holds it to the newest section; its `<screenshot>` URLs sit in a `RELEASE-PIN` block the same cut moves |
| Test gate command     | `GUIDELINES/shared/lang/php/tools/phpunit` (the shipped wrapper; `composer test` inside the container or CI); this checkout has no `.ddev/`, so the wrapper needs `MWX_PHPUNIT_DDEV_DIR` set to the harness instance's directory (§3.5) and `MWX_PHPUNIT_MOUNT=/var/www/html/apps/file_checksum_search`, whose values this machine keeps in `GUIDELINES/config.local.ini` under `[phpunit]`; `--testsuite unit` or `integration` for one of them; frontend `npm test` (Vitest) |
| Lint command          | `composer cs:check` / `composer cs:fix` (ECS with `mwx/coding-standard`, configured in `ecs.php` over `lib/`, `tests/`, `appinfo/` and `templates/`), `composer psalm`, `composer rector`; frontend `npm run lint` and `npm run stylelint`; the manifest `xmllint --noout --schema info.xsd appinfo/info.xml`, with `info.xsd` fetched from `https://raw.githubusercontent.com/nextcloud/appstore/master/nextcloudappstore/api/v1/release/info.xsd`, which is what the app store runs at upload and CI runs first |
| Translation command   | `scripts/l10n.sh` (the door to `scripts/l10n.py`; needs PHP and gettext): `update` after a wrapped text changes, `add <lang>` to start a language, `build` after a `.po` file changes; `check` fails when the template, a `.po` file or `l10n/` is out of date, or a language kept here misses a translation. The work is done by Nextcloud's own `translationtool.phar`, pinned by commit and SHA-256 in `scripts/l10n.py` (§3.7) |

## 2. Primary References

- `/AGENTS.md` — runtime behavior contract, gating flow, action-plan workflow.
- `{{shared_root}}/QUALITY.md` — the quality pass; this project is multi-language,
  so it applies to `.vue`, `.ts`, `.scss` and `.yaml` files as much as to `.php` ones.
- `{{shared_root}}/lang/php/TESTING.md`, `{{shared_root}}/lang/ts/TESTING.md`
  and the matching `LINTING.md` files — language baselines; the facts table above
  overrides their example commands.
- `{{shared_root}}/COMMIT.md` — commit workflow, gating, and the `CHANGELOG.md`
  rules that apply here (see §3.3).

## 3. Project-Specific Conventions

### 3.1 Nextcloud version matrix

The app is developed against more than one Nextcloud release; local source trees
(`nextcloud-v33`, `nextcloud-v34`) are linked so cross-version symbol resolution
works. The IDE therefore indexes every OCP symbol twice and reports
`Multiple definitions exist for class '...'` in bulk. This is expected — see
`{{shared_root}}/QUALITY.md` §6: filter the message when triaging, and
never exclude a tree to silence it.

### 3.2 Frontend and backend are one deliverable

A change to `src/` usually needs a rebuilt bundle in `js/` before it is visible in
the app, and both count as shipped code. Run the quality pass and the tests for
**both** sides when a change spans them.

### 3.3 CHANGELOG.md is mandatory here

This project keeps a Keep-a-Changelog `CHANGELOG.md` and cuts releases with
`[RELEASE]` commits that bump `appinfo/info.xml`. The rules in
`{{shared_root}}/COMMIT.md` §4.3 and §4.4 apply in full: any commit
touching the shipped-code paths above adds or amends a bullet under
`## [Unreleased]` in the same commit.

### 3.4 Roo Code prompts

`.roo/commands/` and `.roo/roo-code-settings.json` are tracked here because Roo
reads them from the project root. The shared repository keeps reference copies
under `{{shared_root}}/assistants/roo/`; when a prompt changes here and
the change is not project-specific, port it there as well.

### 3.5 The harness that tests this app is a separate repository

`nextcloud_testing` spins up the Nextcloud versions above and mounts this app
into them. It has its own contract; a change to how instances are built belongs
there, not here.

### 3.6 Frontend specs mount the real Nextcloud components

A Vitest spec under `src/` mounts `@nextcloud/vue`'s components as they are,
never a stand-in written for the spec: the runner inlines the library and
defines what it needs (`vitest.config.ts`, `vitest.setup.ts`), and
`src/test-utils/` drives the controls that open menus — `NcSelect` and
`NcActions` — the way a person does. `.eslintrc.cjs` refuses
`vi.mock('@nextcloud/vue/…')` in a spec. A mock of the server or of the page
(`@nextcloud/router`, `@nextcloud/axios`, `@nextcloud/l10n`, the app's own
modules) is the spec's to write, and spreads the original where it names
only part of a module.

### 3.7 Every user-facing text is translatable

A text a person reads in the app — in a template, a toast, a label, a server
message the interface shows — goes through
`t('file_checksum_search', '…')` or `n('file_checksum_search', '…', '…', count)`,
and in PHP through `IL10N`. Nextcloud's translation tool finds only what these
calls spell out:

- the text is a literal; in a Vue template, on one line or as a template
  literal without `${}`, one call to a line, and no quoted text among its
  values: the tool reads a template with a pattern that runs to the line's
  last quote, so a longer text, a choice between texts or a value with
  quotes goes into the script;
- a value is a placeholder (`t('file_checksum_search', 'Band {band}', { band })`),
  never a concatenation of translated pieces;
- a count takes `n()`, and in a template reaches it through a computed value,
  since the tool does not read `n()` there;
- a text kept in a constant is translated where it is read, not when the
  module loads;
- a short or ambiguous text gets a `TRANSLATORS` comment, which the tool
  hands to translators.

In the frontend, `t` and `n` come from `src/l10n.ts`: the same signatures as
`@nextcloud/l10n`'s, but they leave escaping to Vue, since the library's
would show in a text as `&amp;`. A translated text therefore never goes into
`v-html`, and a sentence carries no markup: emphasis wraps a whole sentence,
and a name inside one is quoted.

A server message is translated where it is made, through `IL10N`, as
Nextcloud core does: in the user's language on the web, and on the command
line, which has neither a user nor a request, in the server's default
language. A command's own output and every log stay English; where a message
is both shown and logged, the log gets the English — an `OCP\HintException`'s
message, a result's `reason` — and the person the translation. PHP's
placeholders are `IL10N`'s, `%s` and `%1$s`.

A change to a wrapped text runs
`scripts/l10n.sh update`; every language kept in `translationfiles/<lang>/` stays
complete, and `l10n/` is built from them, never edited by hand.

## 4. Document Governance

- This document follows the shared governance rules in `{{shared_root}}/GOVERNANCE.md`.

## 5. Version History

| Version | Date       | Changed sections | Change type | Agent impact |
|---------|------------|------------------|-------------|--------------|
| v2.8.0  | 2026-09-28 | 3                | minor       | §3.7: a server message is translated where it is made, as Nextcloud core does, so the command line shows a service's message in the server's default language; a command's own output and every log stay English, the log of a message that is also shown getting the English. |
| v2.7.0  | 2026-09-28 | 3                | minor       | §3.7: in a Vue template, one `t()` to a line with no quoted text among its values, which is what the tool's pattern can read; the frontend's `t`/`n` come from `src/l10n.ts`, which leaves escaping to Vue, so no translated text goes into `v-html` and a sentence carries no markup. |
| v2.6.0  | 2026-09-28 | 1, 3             | minor       | `l10n/` is shipped code; the translation command is `scripts/l10n.sh`, running Nextcloud's own tool, pinned; Python, the tooling's language, is declared. §3.7: every user-facing text goes through `t()`/`n()` or `IL10N` in the form the tool can read, and every language kept here stays complete. |
| v2.5.0  | 2026-09-25 | 1                | minor       | The PHP style is the project's own: ECS with `mwx/coding-standard` replaces the Nextcloud php-cs-fixer config, which had never been installed and would have reformatted the tree to a style it does not use. The test gate is the shipped phpunit wrapper, with the two variables that point it at the harness instance. |
| v2.4.0  | 2026-09-24 | 1                | minor       | The lint row names the manifest check: `xmllint` against the app store's published `info.xsd`, the validation the store runs at upload, run in both pipelines' test stage. |
| v2.3.0  | 2026-09-24 | 1                | minor       | The version manifest row: `appinfo/info.xml` is the changelog's `manifest` and its `<screenshot>` URLs a `RELEASE-PIN` block, both moved by `changelog.sh cut` (shared v4.23.0); the by-hand step of v2.2.0 is gone. |
| v2.2.0  | 2026-09-24 | 1                | minor       | The version manifest row names the release the `<screenshot>` URLs in `appinfo/info.xml` carry, rewritten by hand in the `[RELEASE]` commit beside `<version>`; the markdown documents carry theirs in `RELEASE-PIN` blocks that `changelog.sh cut` rewrites. |
| v2.1.0  | 2026-09-23 | 3                | minor       | §3.6: a frontend spec mounts the real Nextcloud components; the runner's config and `src/test-utils/` make that possible, and the lint rule keeps it so. Mocks of the server and the page stay the spec's. |
| v2.0.0  | 2026-08-27 | All              | major       | Becomes the fragment `project/_CONTRACT.md` under `GUIDELINES/`, inlined into the human-facing `GUIDELINES/README.md` as well as `AGENTS.md`. Placeholders move from the v2-era `{{.aiassistant_root}}` / `{{.aiassistant_shared}}`, which this document still carried and the generator had long stopped substituting, to `{{guidelines_root}}` / `{{shared_root}}`. The contract is cited as `/AGENTS.md`; the languages row points at `project.languages` and says which of this project's languages have no shared baseline; the version manifest no longer names a version number that had gone stale; §3.5 names the harness repository. |
| v1.1.0  | 2026-08-25 | 1, 3             | minor       | Removes the absolute repository root; the root is derived and Windows hosts prefix with wsl --cd "$PWD". |
| v1.0.0  | 2026-08-25 | All              | major       | Replaces this project's own copies of the agent documents with the shared submodule plus these project facts. The documents removed here (AGENTS.md v2.6.0, ENVIRONMENTS.md, the testing and linting baselines) were the newest lineage in the set and are preserved in the shared repository's history. |
