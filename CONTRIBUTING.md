# Contributing to FCIAS

Thank you for your interest in contributing to File Checksum Index & Search (FCIAS).

This document contains repository-specific contributor conventions for FCIAS.
Generic runtime and agent flow-control rules are defined in `AGENTS.md`.

## Table of Contents

- [Commit Rules](#commit-rules)
- [Testing](#testing)
- [Nextcloud Integration Notes](#nextcloud-integration-notes)
- [Code Style & Documentation](#code-style--documentation)

## Commit Rules

1. **Functional atomicity**
    - Prefer one commit per functional change.
    - Include related code and tests in the same commit.
2. **Message prefixes**
    - Commit summary line must begin with one of:
        - `[TASK]` - features, larger logic changes, or documentation updates.
        - `[FIX]` - bug fixes.
        - `[SECURITY]` - security fixes/hardening.
        - `[CLEANUP]` - formatting/layout-only changes without functional changes.
        - `[WIP]` - intermediate work-in-progress commits.
        - `[UPDATE]` - environment/dependency/test-configuration updates.
        - `[RELEASE]` - version-bump commit; reserved for the release workflow (see below).
3. **Prefix semantics**
    - `[CLEANUP]` MUST ONLY be used for changes in formatting of code or data, or rearranging file layout, and MUST
      NEVER be used for any functional change. Example boundary:
        - Allowed: import/order/format-only changes.
        - Not allowed: any change that modifies runtime behavior.
    - `[WIP]` SHOULD be used for commits that are intermediate changes and leave the repo in a "dirty" state.
        * Examples: renaming/moving/splitting files before significantly changing it to keep git history. Saving the
          state on a feature branch before switching branch.
        * `[WIP]` commits SHOULD be followed by a full non-`[WIP]` commit (any of the other tags, depending on the
          overall target).

        - The "full" commit SHOULD reference the `[WIP]` commit by including `Follows [WIP] commit [hash]` as its own
          paragraph (e.g., via a separate `-m` parameter) at the bottom of the commit message.
    - `[RELEASE]` cuts a version: it promotes `CHANGELOG.md`'s `## [Unreleased]` bullets to a new version section
      and bumps `appinfo/info.xml`. It touches only those two files. See `.aiassistant/COMMIT.md` §4.4 for the
      exact format and versioning rule (pre-`1.0.0`: `[FIX]`/`[SECURITY]` bump patch, everything else bumps minor).
4. **CHANGELOG.md**
    - Any commit touching shipped app code (`lib/`, `src/`, `css/`, `js/`, `templates/`, `img/`,
      `appinfo/routes.php`, `appinfo/info.xml`) MUST add a bullet to `## [Unreleased]` in the same commit,
      summarizing the actual effect (not just the summary line) — see `.aiassistant/COMMIT.md` §4.3.
5. **Message structure**
    - Line 1: summary with prefix.
    - Line 2: must be empty.
    - Line 3+: details about what changed and why.
6. **Automated commit trailer**
    - Commits made by assistants should include a `Co-authored-by` trailer.

### Example Commit Message

```text
[FIX] Correct truncated-hash comparison for SHA-512/SHA3-512

Nextcloud's files_metadata_index.meta_value_string column is
VARCHAR(63), so sha256/sha512/sha3-256/sha3-512 hashes are silently
truncated when written. queryByHash() compared the full search
term against that truncated column, so a full long hash never
matched its own (truncated) index row.

Key changes include:
- Added MetadataService::truncateForIndex() and used it in
  queryByHash()'s comparison.
- Added verifyTruncatedDuplicateGroups() to re-check each
  candidate's full hash before reporting a duplicate group.
- DuplicateService::findByHash() and ChecksumApi::findSameHash()
  now verify a match's full hash via extractAlgorithm() before
  trusting it.

Added targeted tests for the truncation and verification paths.
Full suite green.

Co-authored-by: Agent <agent@example.com>
```

### Commit message formatting tips

- Multiple `-m` flags (PowerShell):

```powershell
git commit -m "[TASK] Improve rule-matching validation" `
           -m "Adds stricter userScope checks and updates unit tests for cross-user cases."
```

- Message file (`-F`):

```text
[TASK] Add shared algo-whitelist validator

Introduces HashCalculationService::isValidAlgo() and updates both
controllers that duplicated the validation closure.
```

Then run:

```powershell
git commit -F .aiassistant\tools\commit-msg.txt
```

## Testing

### Gate command

Use PHPUnit 10 as the primary framework, run through the ddev-aware wrapper (falls back to
`vendor/bin/phpunit` directly when ddev isn't available):

```bash
./.aiassistant/tools/phpunit tests/Unit/
./.aiassistant/tools/phpunit tests/Integration/ --display-warnings
```

Frontend changes additionally require:

```bash
npm run test    # Vitest unit specs under src/**/*.spec.ts
npm run build   # Vite production build must succeed
```

### Expectations

- All relevant tests should pass with zero errors/failures.
- Warnings should be resolved where possible.
- Deprecations from third-party vendor code may occur and should be noted.

### Coverage guidance

1. **Unit tests** (`tests/Unit/`)
    - Every `Service`, `Controller`, `Command`, `Listener`, and `BackgroundJob` class should have a
      corresponding unit test, with collaborators mocked (see `tests/Unit/FciasUnitTestCase.php` for the
      shared `IQueryBuilder`/`IExpressionBuilder` mock helper used across `Service` tests).
2. **Integration tests** (`tests/Integration/`)
    - For behavior that depends on real Nextcloud core (migrations, real DB queries, full HTTP request
      handling), extend `tests/Integration/DatabaseTestCase.php`, which provides a real `IDBConnection` via
      `\OCP\Server` against the ddev Nextcloud instance.
3. **E2E tests** (`tests/e2e/*.cy.js`)
    - Cypress specs cover full user flows (app enable, checksums tab, duplicates page, global search,
      rules). See `tests/e2e/README.md`.

### Testability patterns

- Avoid tight coupling to non-mockable global calls (e.g. `\OCP\Server::get()`) when it blocks unit
  testing — inject the dependency instead where practical.
- Prefer dependency injection over static/service-locator access in new code.
- Nextcloud migrations (`lib/Migration/Version{10-digit}Date{YYYYMMDDHHMMSS}.php`, extending
  `SimpleMigrationStep`) that must call `\OCP\Server::get()` internally (required by Nextcloud's own
  migration contract) should keep that call isolated so the surrounding logic stays testable via a mocked
  `ISchemaWrapper`/`IOutput` — see `tests/Integration/Migration/` for the pattern.

## Nextcloud Integration Notes

FCIAS integrates with two Nextcloud-provided capabilities that are **not part of core `OCP`**
(`lib/public`) — both are separate, normally-bundled apps, and any code using them must treat
them as optional dependencies: check availability defensively, never hard-depend on them in
`info.xml`, and provide a graceful fallback.

- **`viewer`** — exposes `window.OCA.Viewer.open({ path, list?, fileInfo? })`, the standard
  cross-app file-preview integration point (Files, Photos, and Text all use it for their own
  "open preview" actions). This is public but not `OCP`-versioned API: guard with
  `typeof OCA?.Viewer?.open === 'function'`, fall back (e.g. to an open-in-new-tab link) when it
  isn't available, and verify the exact call signature against the target Nextcloud version
  before shipping new code that calls it.
- **`files_versions`** — owns Nextcloud's file version history entirely; there is nothing under
  `OCP\Files_Versions`. The real API lives at `OCA\Files_Versions\Versions\{IVersionManager,
  IVersionBackend, IVersion, IVersionsImporterBackend, IDeletableVersionBackend}`. Of particular
  note: `IVersionsImporterBackend::importVersionsForFile(IUser $user, Node $source, Node $target,
  array $versions)` (`@since 29.0.0`) is the documented mechanism for importing one file's
  version history into another. Check `class_exists(IVersionManager::class)` and that the
  resolved backend actually `instanceof IVersionsImporterBackend` before calling it — not every
  storage backend supports import.

If you need to check whether some other Nextcloud capability is part of core `OCP` or lives in a
separate app, this repo keeps full checkouts of `nextcloud-v33/` and `nextcloud-v34/` for exactly
this kind of lookup — search `lib/public/` first, then `apps/<name>/lib/` if it isn't there.

## Code Style & Documentation

### PHP code style

- Follow the existing style in surrounding files (naming, imports, formatting, structure) — see
  `CODE_STYLE.md` for the full house style (Allman braces, aligned declarations, etc.).
- Keep changes minimal and consistent with existing project idioms.
- **Linting/Formatting**:

  ```bash
  composer lint       # php -l syntax check across the codebase
  composer cs:check    # php-cs-fixer --dry-run --diff
  composer cs:fix      # php-cs-fixer fix
  composer psalm       # static analysis
  ```

### Frontend code style

- The frontend is uniformly Vue 3 (`<script setup lang="ts">` SFCs) — no vanilla-JS DOM-manipulation entry
  points remain. Shared UI (e.g. the rule table/form used by both settings pages) lives under
  `src/rules-vue/`; page-level apps live under `src/<page>-vue/`, each mounted once from its own `main.ts`.
- **Linting/Formatting**:

  ```bash
  npm run lint        # eslint src
  npm run stylelint   # stylelint across .vue/.scss/.css
  ```

### Documentation

- Document non-obvious behavior changes in code comments only when needed.
- Keep contributor-facing docs in sync when workflow conventions change.
- Use `README.md` for end-user/app usage context and `CONTRIBUTING.md` for contributor process conventions.

### Project references

- `AGENTS.md` - runtime behavior contract and flow-control rules.
- `.aiassistant/GUIDELINES.md` - agent-facing entry point for project references.
- `.aiassistant/tools/README.md` - helper scripts and usage notes.
- `docs/api-v1.md` - the public API's source of truth (HTTP + PHP surfaces).
