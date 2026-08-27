# Project Guidelines Entry Point (v1.1.0)

This file is the project-specific entry point for agent-facing guidance in
**File Checksum Index & Search** (FCIAS) — a Nextcloud app that indexes file
checksums and makes them searchable.

## 1. Project Facts

> No absolute paths here. An agent is already inside the checkout, so the
> repository root is `git rev-parse --show-toplevel`; Windows-hosted agents
> prefix with `wsl --cd "$PWD"` (see `{{.aiassistant_shared}}/ENVIRONMENTS.md`).
> Machine-specific values belong in `{{.aiassistant_root}}/.env.local`, untracked.


| Fact                  | Value |
|-----------------------|-------|
| Project name          | `metaworx/file_checksum_search`, Nextcloud app id `file_checksum_search` |
| Language(s)           | PHP (backend), TypeScript + Vue (frontend), SCSS/CSS, YAML (CI) |
| Source directories    | `lib/` (PSR-4 `OCA\FileChecksumSearch\`), `src/` (frontend), `tests/` (PSR-4 `OCA\FileChecksumSearch\Tests\`), `appinfo/`, `templates/` |
| Shipped-code paths    | `lib/`, `src/`, `css/`, `js/`, `templates/`, `img/`, `appinfo/routes.php`, `appinfo/info.xml` |
| Version manifest      | `appinfo/info.xml` (`<version>`, currently `0.19.0`) |
| Test gate command     | `composer test` (unit + integration); individually `composer test:unit`, `composer test:integration`, `vendor/bin/phpunit -c tests/phpunit.xml`; frontend `npm test` (Vitest) |
| Lint command          | `composer cs:check` / `composer cs:fix` (php-cs-fixer), `composer psalm`, `composer rector`; frontend `npm run lint` and `npm run stylelint` |

## 2. Primary References

- `{{.aiassistant_shared}}/GUIDELINES.md` - runtime behavior contract, gating flow, action-plan workflow.
- `{{.aiassistant_shared}}/QUALITY.md` - the quality pass; this project is multi-language,
  so it applies to `.vue`, `.ts`, `.scss` and `.yaml` files as much as to `.php` ones.
- `{{.aiassistant_shared}}/lang/php/TESTING.md`, `{{.aiassistant_shared}}/lang/ts/TESTING.md`
  and the matching `LINTING.md` files - language baselines; the facts table above
  overrides their example commands.
- `{{.aiassistant_shared}}/COMMIT.md` - commit workflow, gating, and the `CHANGELOG.md`
  rules that apply here (see §3.3).
- `{{.aiassistant_shared}}/ENVIRONMENTS.md` - host/agent command conventions.
- `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `README.md` - contributor documentation.

## 3. Project-Specific Conventions

### 3.1 Nextcloud version matrix

The app is developed against more than one Nextcloud release; local source trees
(`nextcloud-v33`, `nextcloud-v34`) are linked so cross-version symbol resolution
works. The IDE therefore indexes every OCP symbol twice and reports
`Multiple definitions exist for class '...'` in bulk. This is expected — see
`{{.aiassistant_shared}}/QUALITY.md` §6: filter the message when triaging, and
never exclude a tree to silence it.

### 3.2 Frontend and backend are one deliverable

A change to `src/` usually needs a rebuilt bundle in `js/` before it is visible in
the app, and both count as shipped code. Run the quality pass and the tests for
**both** sides when a change spans them.

### 3.3 CHANGELOG.md is mandatory here

This project keeps a Keep-a-Changelog `CHANGELOG.md` and cuts releases with
`[RELEASE]` commits that bump `appinfo/info.xml`. The rules in
`{{.aiassistant_shared}}/COMMIT.md` §4.3 and §4.4 apply in full: any commit
touching the shipped-code paths above adds or amends a bullet under
`## [Unreleased]` in the same commit.

### 3.4 Roo Code prompts

`.roo/commands/` and `.roo/roo-code-settings.json` are tracked here because Roo
reads them from the project root. The shared repository keeps reference copies
under `{{.aiassistant_shared}}/assistants/roo/`; when a prompt changes here and
the change is not project-specific, port it there as well.

## 4. Document Governance

- This document follows the shared governance rules in `{{.aiassistant_shared}}/GOVERNANCE.md`.

## 5. Version History

| Version | Date       | Changed sections | Change type | Agent impact |
|---------|------------|------------------|-------------|--------------|
| v1.1.0  | 2026-08-25 | 1, 3             | minor       | Removes the absolute repository root; the root is derived and Windows hosts prefix with wsl --cd \"$PWD\". |
| v1.0.0  | 2026-08-25 | All              | major       | Replaces this project's own copies of the agent documents with the shared submodule plus these project facts. The documents removed here (AGENTS.md v2.6.0, ENVIRONMENTS.md, the testing and linting baselines) were the newest lineage in the set and are preserved in the shared repository's history. |
