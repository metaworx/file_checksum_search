# Testing Conventions (v2.9.0)

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
15. Frontend Unit Tests (Vitest) — Gotchas

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
- **With ddev**: executes `ddev exec php` inside the `nextcloud_testing` instance the app is
  mounted into, using the in-container mount path (§9.1)
- **Without ddev**: falls back to direct `vendor/bin/phpunit`

Relative path arguments (`tests/`, `vendor/`) are automatically prefixed with the container mount path when running inside ddev.

The instance is selected by `FCIAS_DDEV_DIR` (default `~/projects/nextcloud_testing/instances/34`);
set it to `.../instances/33` to run the suite against NC 33 instead.

For manual ddev execution:

```bash
wsl --cd ~/projects/nextcloud_testing/instances/34 bash -c "ddev exec php /var/www/html/apps/file_checksum_search/vendor/bin/phpunit -c /var/www/html/apps/file_checksum_search/tests/phpunit.xml /var/www/html/apps/file_checksum_search/tests/Unit/Path/To/Test.php"
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

### 6.2 Mock Expectations After Adding Optional Parameters

When a method gains a new optional trailing parameter (e.g. `$userName = null`) and the calling
code is updated to explicitly pass it — even as `null` — every existing `->with(...)` mock
expectation on that call breaks. PHPUnit records exactly what the caller explicitly wrote at the
call site, not what the signature's default would produce for an *omitted* argument, but it
**does** record an explicitly-passed `null`. Whenever you thread a new parameter through existing
call sites, audit every mock's `->with()` expectations for that method — don't assume adding an
optional parameter is mock-transparent just because it has a default.

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

### 9.1 Preparing an instance

Local E2E runs go against the `~/projects/nextcloud_testing` DDEV harness (see its own
`README.md`), which provides clean NC 33/34 instances (admin `admin`/`admin`). Its `nc-test` CLI
mounts this repository into the instance at `/var/www/html/apps/file_checksum_search` and
pre-configures everything the specs need — `appstoreenabled=false` and forced English — so no
manual `occ config:system:set` is required.

```bash
cd ~/projects/nextcloud_testing
./scripts/nc-test check 34 ~/projects/nc_file_checksum_search   # free, or already ours?
./scripts/nc-test reset 34 file_checksum_search                 # mount + wipe DB/data + enable
```

`reset` is the command to reach for before an E2E run: it wipes the database and data directory,
so the instance matches CI's fresh-install state instead of accumulating data across runs.
Use `mount` instead of `reset` to keep existing data. Substitute `33` to target NC 33.

The app's built assets are served from the mounted working tree, so run `npm run build` after any
frontend change before running the specs — nothing in the harness builds for you.

Bumping `appinfo/info.xml`'s `<version>` puts an already-installed instance into
"needs upgrade", where every page answers **503** and Cypress fails in its `before each` login
hook. `ddev exec php occ status` reports `needsDbUpgrade: true`; fix it with
`ddev exec php occ upgrade` (or a `nc-test reset`, which reinstalls the app anyway).

### 9.2 Running locally

Run Cypress from this repository against that instance:

```bash
wsl bash -lc "cd ~/projects/nc_file_checksum_search && \
  CYPRESS_baseUrl=https://nextcloud-34.ddev.site \
  CYPRESS_occ='cd ~/projects/nextcloud_testing/instances/34 && ddev exec php occ' \
  npx cypress run"
```

Or for a single spec:

```bash
wsl bash -lc "cd ~/projects/nc_file_checksum_search && \
  CYPRESS_baseUrl=https://nextcloud-34.ddev.site \
  CYPRESS_occ='cd ~/projects/nextcloud_testing/instances/34 && ddev exec php occ' \
  npx cypress run --spec tests/e2e/app-enable.cy.js"
```

Environment variables (read via `cy.env()` in the specs):

- `CYPRESS_baseUrl` — Nextcloud base URL (`https://nextcloud-<version>.ddev.site`).
- `CYPRESS_occ` — shell prefix used to invoke `occ`; must resolve from this repo's WSL path.
- `CYPRESS_NC_ADMIN_USER` / `CYPRESS_NC_ADMIN_PASSWORD` — admin credentials, default `admin`/`admin`.
  The harness installs with those defaults, so both can normally be omitted.

Prerequisites:

- The instance is running and reachable
  (`curl -sS -o /dev/null -w '%{http_code}\n' https://nextcloud-34.ddev.site/login` → `200`/`302`).
- The UI is English. The harness forces this already; on any other instance either set
  `force_language=en` or run with `--browser chrome`, which `cypress.config.cjs` forces to
  `--lang=en-US` (Electron ignores the flag).
- Chrome is not always installed locally — `--browser electron` (or `firefox`) is the fallback.

### 9.3 Notes

- Enabling an app via the Apps page requires a password confirmation dialog
  (`PasswordConfirmationRequired`); the spec fills it after clicking **Enable**.
- A `cy.visit()` that dies with `ESOCKETTIMEDOUT` on `/settings/apps/...` is an instance/network
  problem, not a spec failure — those pages hit the app store when `appstoreenabled` is left on.
- CI runs the same specs from the `e2e` job in `.gitlab-ci.yml`, as an NC 33/34 matrix against
  `http://localhost:8081` on a freshly installed instance with `appstoreenabled=false` and
  `force_language=en`, using `--browser chrome`.

### 9.4 Test suite documentation

[`tests/e2e/README.md`](../tests/e2e/README.md) is the canonical description of
the Cypress suite: what each spec does, the execution order (Cypress runs specs
alphabetically), any ordering dependencies between specs, and the data strategy.
Consult it before adding or running a spec — some specs depend on data produced
by earlier-running specs and must not be run standalone.

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

## 15. Frontend Unit Tests (Vitest) — Gotchas

Frontend unit tests use Vitest with the `happy-dom` environment (config: `vitest.config.ts`,
specs colocated as `src/**/*.spec.ts`). Run via `npm run test` (or `npm run test:watch`).
`npm run build` (Vite production build) is also a real check, not a formality — it has caught
wiring mistakes (wrong prop names, stale imports) unit tests alone missed; run it after any
frontend change of consequence.

- **`window.location.hash` leaks between tests in the same spec file.** Page-level `App.vue`
  components (`duplicates-vue`, `settings-admin-vue`, `settings-personal-vue`) read
  `window.location.hash` at setup time to pick their initial tab. `mount()` creates a fresh
  component instance per test, but happy-dom's `window` is shared across tests in one file — a
  test that switches tabs (setting `window.location.hash`) leaves that hash set for the *next*
  test's `mount()`, silently changing which tab starts active there. The resulting failure looks
  unrelated to the real cause (e.g. "Cannot call trigger on an empty DOMWrapper" for a button
  that plainly exists in the rendered HTML — because it's inside a tab panel that isn't the one
  actually mounted). Fix: reset it in `afterEach`:
  ```ts
  afterEach(() => {
      window.location.hash = ''
  })
  ```
- **Testing the `AbortController` stale-response guard** (used by every composable that fetches —
  `useDuplicates.ts`, `useAdminSettings.ts`, `usePersonalSettings.ts`, `useSidebarHashes.ts`):
  a plain resolved/rejected mock isn't enough, since the guard's behavior depends on the request's
  `signal` actually firing an `abort` event. Use a `mockAbortableFetch()` helper that queues a
  resolver per call and rejects a call's own promise with a real `AbortError` when its `signal`
  aborts (see `useDuplicates.spec.ts` for the canonical implementation) — copy it into new specs
  rather than reinventing it per composable.
- **A vanilla-JS module that calls `document.addEventListener('DOMContentLoaded', ...)` at import
  time, combined with `vi.resetModules()` + repeated dynamic `import()` across tests in one file,
  accumulates listeners** — a single dispatched event then fires every previously-registered
  handler from earlier tests, corrupting later stateful tests. The frontend is now fully Vue (no
  vanilla-JS DOM-manipulation entry points remain), so this shouldn't recur here, but the gotcha
  applies to any future black-box test of a vanilla-JS file with a module-level global listener.

## 13. Version History

| Version | Date       | Changed sections                              | Change type | Agent impact                                                                                                                                                                            |
|---------|------------|-----------------------------------------------|-------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| v2.9.0  | 2026-08-22 | 1.1, 9                                        | minor       | Replaced the `helioscloud` ddev instance with the `~/projects/nextcloud_testing` harness as the documented local target for PHPUnit-in-ddev and Cypress; corrected the harness CLI (`scripts/nc-test`, not `mount-app.sh`), the in-container mount path (`apps/`, not `custom_apps/`), and the CI reference (GitLab `e2e` job, not a GitHub workflow); documented `nc-test reset` for a CI-like fresh instance and the `ESOCKETTIMEDOUT` app-store symptom. |
| v2.8.0  | 2026-08-22 | 6.2, 15                                       | minor       | Added §6.2 (PHPUnit `->with()` breaks when a new optional param is explicitly passed at call sites) and §15 (Vitest gotchas: `window.location.hash` test-leak, `AbortController` mock pattern, `DOMContentLoaded` listener accumulation) — carried over from the settings-Vue-migration session handoff. |
| v2.7.1  | 2026-08-21 | 9                                             | minor       | Generalized §9.4 into a generic pointer so TESTING.md no longer enumerates specs (details live in `tests/e2e/README.md`).                                                               |
| v2.7.0  | 2026-08-21 | 9                                             | minor       | Added §9.4 linking the new `tests/e2e/README.md` and documenting the spec-ordering dependency (`checksums` → `duplicates`).                                                             |
| v2.6.0  | 2026-08-20 | 9                                             | minor       | Documented the vanilla NC 33/34 `nextcloud_testing` DDEV harness for local E2E runs.                                                                                                    |
| v2.5.0  | 2026-08-19 | 9                                             | minor       | Documented the local E2E run procedure (`npx cypress run` + `CYPRESS_*` env vars, `force_language`) and the app-enable password confirmation.                                           |
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
