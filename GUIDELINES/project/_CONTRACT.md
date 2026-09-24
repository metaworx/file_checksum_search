> **Fragment** — inlined by `tools/sync.sh`; not a standalone document.

# {{project_name}} — Project Contract (v2.2.0)

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
| Language(s)           | declared as `project.languages` in `{{guidelines_root}}/config.ini`, kept honest by `{{shared_root}}/tools/detect-languages.sh --check`. PHP backend, TypeScript and Vue frontend, SCSS/CSS, YAML for CI — of which Vue and YAML have no shared baseline, so they are not declared. |
| Source directories    | `lib/` (PSR-4 `OCA\FileChecksumSearch\`), `src/` (frontend), `tests/` (PSR-4 `OCA\FileChecksumSearch\Tests\`), `appinfo/`, `templates/` |
| Shipped-code paths    | `lib/`, `src/`, `css/`, `js/`, `templates/`, `img/`, `appinfo/routes.php`, `appinfo/info.xml` |
| Version manifest      | `appinfo/info.xml` (`<version>`, and the release its `<screenshot>` URLs name: the `[RELEASE]` commit rewrites both by hand, since `changelog.sh cut` pins markdown documents only) |
| Test gate command     | `composer test` (unit + integration); individually `composer test:unit`, `composer test:integration`, `vendor/bin/phpunit -c tests/phpunit.xml`; frontend `npm test` (Vitest) |
| Lint command          | `composer cs:check` / `composer cs:fix` (php-cs-fixer), `composer psalm`, `composer rector`; frontend `npm run lint` and `npm run stylelint` |

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

## 4. Document Governance

- This document follows the shared governance rules in `{{shared_root}}/GOVERNANCE.md`.

## 5. Version History

| Version | Date       | Changed sections | Change type | Agent impact |
|---------|------------|------------------|-------------|--------------|
| v2.2.0  | 2026-09-24 | 1                | minor       | The version manifest row names the release the `<screenshot>` URLs in `appinfo/info.xml` carry, rewritten by hand in the `[RELEASE]` commit beside `<version>`; the markdown documents carry theirs in `RELEASE-PIN` blocks that `changelog.sh cut` rewrites. |
| v2.1.0  | 2026-09-23 | 3                | minor       | §3.6: a frontend spec mounts the real Nextcloud components; the runner's config and `src/test-utils/` make that possible, and the lint rule keeps it so. Mocks of the server and the page stay the spec's. |
| v2.0.0  | 2026-08-27 | All              | major       | Becomes the fragment `project/_CONTRACT.md` under `GUIDELINES/`, inlined into the human-facing `GUIDELINES/README.md` as well as `AGENTS.md`. Placeholders move from the v2-era `{{.aiassistant_root}}` / `{{.aiassistant_shared}}`, which this document still carried and the generator had long stopped substituting, to `{{guidelines_root}}` / `{{shared_root}}`. The contract is cited as `/AGENTS.md`; the languages row points at `project.languages` and says which of this project's languages have no shared baseline; the version manifest no longer names a version number that had gone stale; §3.5 names the harness repository. |
| v1.1.0  | 2026-08-25 | 1, 3             | minor       | Removes the absolute repository root; the root is derived and Windows hosts prefix with wsl --cd "$PWD". |
| v1.0.0  | 2026-08-25 | All              | major       | Replaces this project's own copies of the agent documents with the shared submodule plus these project facts. The documents removed here (AGENTS.md v2.6.0, ENVIRONMENTS.md, the testing and linting baselines) were the newest lineage in the set and are preserved in the shared repository's history. |
