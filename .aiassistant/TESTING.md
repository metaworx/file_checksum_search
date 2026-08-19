# Testing Conventions (v2.5.0)

Project-specific testing conventions for FCIAS (File Checksum Index & Search Nextcloud app).
Generic agent flow-control rules are in `AGENTS.md`; contributor context is in `CONTRIBUTING.md`.

## Contents

1. Gate Command
2. Expectations
3. Minimum Submission Checklist
4. When to Write Tests
5. Coverage Guidance
6. Testability Patterns
7. Diagnosis Strategy
8. Nextcloud Integration Tests
9. Cypress E2E Testing
10. Mocking Nextcloud Services
11. Database Test Isolation
12. Document Governance
13. Version History
14. JetBrains MCP Quality Workflow

## 1. Gate Command

The primary test runner is Composer-installed PHPUnit. Always prefix WSL commands with `wsl --cd ~/projects/nc_file_checksum_search`:

```bash
wsl --cd ~/projects/nc_file_checksum_search ./.aiassistant/tools/phpunit
```

Scoped run (single file or directory):

```bash
wsl --cd ~/projects/nc_file_checksum_search ./.aiassistant/tools/phpunit tests/Unit/Controller/LookupControllerTest.php
wsl --cd ~/projects/nc_file_checksum_search ./.aiassistant/tools/phpunit tests/Unit/
```

### 1.1 Test Runner Wrapper

The preferred way to run tests is through `.aiassistant/tools/phpunit`:

```bash
./.aiassistant/tools/phpunit tests/Unit/
./.aiassistant/tools/phpunit tests/Integration/ --filter Migration
```

The wrapper auto-detects whether ddev is available:
- **With ddev**: executes `ddev exec php` inside the helioscloud container with FCIAS mount paths
- **Without ddev**: falls back to direct `vendor/bin/phpunit`

Relative path arguments (`tests/`, `vendor/`) are automatically prefixed with the container mount path when running inside ddev.

For manual ddev execution:

```bash
wsl --cd ~/projects/helioscloud bash -c "ddev exec php /var/www/html/custom_apps/file_checksum_search/vendor/bin/phpunit -c /var/www/html/custom_apps/file_checksum_search/tests/phpunit.xml /var/www/html/custom_apps/file_checksum_search/tests/Unit/Path/To/Test.php"
```

The `tests/bootstrap.php` loads NC autoloader from `/var/www/html/3rdparty/autoload.php` and `/var/www/html/lib/base.php` for OCP class availability inside ddev.

> **Important:** When using the native agent `execute_command` tool, always pass `cwd: "C:\\"` to avoid CMD.EXE UNC path errors with `\\wsl.localhost\...` paths.

> **CI display‑warnings:** When `failOnWarning="true"` is set in `tests/phpunit.xml`, always include `--display-warnings` in the CI PHPUnit command (e.g. `./vendor/bin/phpunit -c tests/phpunit.xml --display-warnings`). Without this flag, warnings are invisible in CI logs but still cause exit code 1.

Fallback: JetBrains MCP `execute_run_configuration` with `filePath` + `line` on individual test methods.

## 2. Expectations

- All relevant tests SHOULD pass with zero errors/failures before submission.
- Warnings should be resolved where possible.
- Deprecations from third-party vendor code may occur and should be noted but are not blocking.

## 3. Minimum Submission Checklist

- [ ] Ran tests for changed scope (at minimum).
- [ ] Ran dependent/adjacent tests when change risk is moderate or higher.
- [ ] Confirmed new/updated tests are green.
- [ ] Documented known non-blockers in the final summary.

## 4. When to Write Tests

| Change type   | Requirement                                                                 |
|---------------|-----------------------------------------------------------------------------|
| Bug fix       | **Always** write a reproduction test; verify it fails before the fix.       |
| New feature   | Add tests proportional to complexity; skip only for trivial getters/labels. |
| Refactoring   | Rely on existing tests; add new ones only if coverage is clearly missing.   |
| Docs-only     | No tests required.                                                          |

## 5. Coverage Guidance

1. **Unit tests** — Controllers, Commands, and Search provider should have focused unit tests with mocked dependencies.
2. **Migration tests** — Verify forward migration creates table/SP/triggers; rollback drops them. Wrap in transactions.
3. **Integration tests** — For trigger cascade behavior (INSERT → shadow rows, UPDATE → refresh, DELETE → cascade), test against a real database.

## 6. Testability Patterns

- Use `\OCP\IDBConnection` injection for database-querying classes. Mock via `createMock(IDBConnection::class)` in unit tests.
- For Nextcloud service dependencies (`IRootFolder`, `IRequest`, `IManager`), inject via constructor and mock in tests.
- For CLI commands extending `Symfony\Component\Console\Command\Command`, test via `CommandTester`.
- Avoid tight coupling to `\OC::$server` in testable code; use constructor injection.
- **Readonly class mocking**: PHPUnit 10.5 cannot mock classes declared `readonly`. Use property-level `readonly` on constructor parameters instead of class-level `readonly`. For interfaces (e.g., `IAppConfig`), `createMock()` works normally.

### 6.1 Readonly Class Mockability

PHPUnit 10.5 relies on class inheritance to generate test doubles. PHP `readonly` classes cannot be extended by non-readonly classes, so PHPUnit cannot mock them. Workarounds (in order of preference):

1. **Extract interface** — Create an interface, type-hint it, mock the interface ( the best long-term)
2. **Anonymous fakes** — Use `new class extends Foo { ... }` (good for DTOs/simple services)
3. **Property-level readonly** — Remove class `readonly`, mark individual constructor properties as `readonly` (pragmatic, preserves immutability)
4. **Runtime bypass** — Use `dg/bypass-finals` or Mockery for third-party classes you can't modify

## 7. Diagnosis Strategy

- Prefer concise, filtered test output for first-pass diagnosis.
- Run scoped tests first, then expand to the full suite.
- For build verification without running tests, use JetBrains MCP `build_project` with `filesToRebuild`.
- After a failing run, apply only obvious, low-risk fixes first.
- If failures remain complex after limited attempts, pause, provide a short analysis, and ask the user for guidance before deeper fix/refactor attempts.

## 8. Nextcloud Integration Tests

Nextcloud integration tests (e.g., trigger cascade tests) must run as the NC web server user
inside WSL:

```bash
wsl --cd ~/projects/nc_file_checksum_search ./.aiassistant/tools/phpunit tests/Integration/
```

Prerequisites:
- NC instance installed and running
- App enabled (`occ app:enable file_checksum_search`)
- Database user has TRIGGER privilege

## 9. Mocking Nextcloud Services

Common mock patterns for FCIAS tests:

```php
// Mock database connection
$db = $this->createMock(\OCP\IDBConnection::class);
$queryBuilder = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
$db->method('getQueryBuilder')->willReturn($queryBuilder);

// Mock root folder for user path checks
$rootFolder = $this->createMock(\OCP\Files\IRootFolder::class);
$userFolder = $this->createMock(\OCP\Files\Folder::class);
$rootFolder->method('getUserFolder')->willReturn($userFolder);

// Mock request
$request = $this->createMock(\OCP\IRequest::class);
```

## 10. Database Test Isolation

- Wrap migration tests in transactions where possible. Roll back after each test.
- For trigger tests that need committed data, clean up explicitly in `tearDown()`:
  ```php
  protected function tearDown(): void {
      $this->db->executeQuery("DELETE FROM {prefix}filecache WHERE fileid = ?", [$this->testFileId]);
      $this->db->executeQuery("DELETE FROM {prefix}file_checksum_search_hashes WHERE fileid = ?", [$this->testFileId]);
  }
  ```
- Use unique file IDs (e.g., large negative numbers) to avoid collision with real data.

## 11. Document Governance

- This document follows the shared governance rules in `.aiassistant/CHANGELOG.md`.
- Update the title version on each change and append a new row in `Version History`.

## 9. Cypress E2E Testing

Cypress is a dev dependency of this project (`package.json`); specs live in `tests/e2e/` and are
configured in `cypress.config.cjs` at the project root.

### 9.1 Running locally

Run Cypress directly from this repository against the local ddev Nextcloud instance (helioscloud):

```bash
wsl bash -lc "cd ~/projects/nc_file_checksum_search && \
  CYPRESS_baseUrl=https://helioscloud.ddev.site \
  CYPRESS_occ='cd ~/projects/helioscloud && ddev exec php /var/www/html/occ' \
  CYPRESS_NC_ADMIN_PASSWORD='<admin-password>' \
  npx cypress run --spec tests/e2e/app-enable.cy.js"
```

Environment variables (read via `cy.env()` in the specs):

- `CYPRESS_baseUrl` — Nextcloud base URL; defaults to `https://helioscloud.ddev.site`.
- `CYPRESS_occ` — shell prefix used to invoke `occ`; must resolve from this repo's WSL path.
- `CYPRESS_NC_ADMIN_USER` / `CYPRESS_NC_ADMIN_PASSWORD` — admin credentials (default `admin`/`admin`).
  The helioscloud instance enforces a strong password, so set a non-trivial one locally.

Prerequisites:

- The ddev project is running and responsive (`ddev start` in `~/projects/helioscloud`).
- The instance UI is English: either set `force_language=en`
  (`ddev exec php /var/www/html/occ config:system:set force_language --value=en`), or run with
  `--browser chrome`, which is forced to `--lang=en-US` in `cypress.config.cjs` (Electron ignores the flag).

### 9.2 Notes

- Enabling an app via the Apps page requires a password confirmation dialog
  (`PasswordConfirmationRequired`); the spec fills it after clicking **Enable**.
- CI runs the same specs in `cypress-io/github-action` against `http://localhost:8081` with
  `appstoreenabled=false` and `force_language=en` (see `.github/workflows/test.yml`).

## 14. JetBrains MCP Quality Workflow

After editing PHP files, run through this quality pipeline using JetBrains MCP tools:

1. **Reformat**: `mcp--jetbrains--reformat_file` with `files: ["path/relative/to/project"]`
2. **Inspect**: `mcp--jetbrains--get_inspections` with `filePath: "path/relative/to/project"` — shows errors, warnings, weak warnings including "Unnecessary curly braces"
3. **Quick-fix**: `mcp--jetbrains--apply_quick_fix` with `filePath`, `line`, `column`, `quickFixName` from the inspection result
4. Repeat steps 1-3 until no additional changes needed
5. **Lint**: `mcp--jetbrains--lint_files` with `files: ["path1", "path2"]` for batch validation

Common inspections to watch for:
- "Unnecessary curly braces" (`{$this->prop}` → `$this->prop` in double-quoted strings)
- "Unhandled exceptions" — evaluate and either add `@throws` tag to the docblock, or suppress with `/** @noinspection PhpUnhandledExceptionInspection */` just before the statement (merge with existing comment if present), or place in the method's docblock as deemed appropriate
- "No data sources configured" (harmless IDE config issue, can be ignored)

## 13. Version History

| Version | Date       | Changed sections                              | Change type | Agent impact                                                                                                                                                                            |
|---------|------------|-----------------------------------------------|-------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| v2.5.0  | 2026-08-19 | 9                                             | minor       | Documented the local E2E run procedure (`npx cypress run` + `CYPRESS_*` env vars, `force_language`) and the app-enable password confirmation.                                            |
| v2.4.1  | 2026-08-16 | 9                                             | fix         | Renamed cypress.config.js to cypress.config.cjs to fix the ESM/CommonJS conflict in the CI Cypress job.                                                                                 |
| v2.4.0  | 2026-08-06 | 14                                            | minor       | Added JetBrains MCP Quality Workflow (§14). Author: metaworx.                                                                                                                           |
| v2.3.0  | 2026-08-05 | 1                                             | minor       | Added --display-warnings note for CI PHPUnit with failOnWarning="true".                                                                                                                 |
| v2.2.0  | 2026-08-05 | 1.1, 8–13                                     | minor       | Documented phpunit wrapper; added Cypress E2E section; fixed ddev path notes.                                                                                                           |
| v2.1.0  | 2026-08-05 | 1.1, 6.1, 12                                  | minor       | Added DDEV test runner commands. Added readonly class mockability guidance (§6.1).                                                                                                      |
| v2.0.0  | 2026-08-03 | All sections                                  | major       | Project switch: Kunstarchiv → FCIAS. Primary runner: `vendor/bin/phpunit`. Removed CS fixer infrastructure (§8-14 of v1.x). Added NC integration tests, mocking patterns, DB isolation. |
| v1.5.0  | 2026-04-23 | Title, 1, 13, 16                              | minor       | Makes `.aiassistant\tools\phpunit` the preferred agent test entrypoint (Kunstarchiv).                                                                                                   |
| v1.4.0  | 2026-04-22 | Title, 7, 8, 16                               | minor       | Documents wrapper `--use-diff-stats` and `--env` (Kunstarchiv).                                                                                                                         |
| v1.3.0  | 2026-04-22 | Title, 7, 16                                  | minor       | Restores explicit escalation guidance (Kunstarchiv).                                                                                                                                    |
| v1.2.0  | 2026-04-22 | Contents, 1-16                                | minor       | Adds section numbering (Kunstarchiv).                                                                                                                                                   |
| v1.1.0  | 2026-04-22 | Title, Contents, Document Governance, History | minor       | Adds explicit versioning (Kunstarchiv).                                                                                                                                                 |
| v1.0.0  | 2026-04-22 | Initial document                              | minor       | Baseline testing guidance for Kunstarchiv.                                                                                                                                              |
