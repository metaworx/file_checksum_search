# Testing Conventions (v2.0.0)

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
9. Mocking Nextcloud Services
10. Database Test Isolation
11. Document Governance
12. Version History

## 1. Gate Command

The primary test runner is Composer-installed PHPUnit. Always prefix WSL commands with `wsl --cd ~/projects/nc_file_checksum_search`:

```bash
wsl --cd ~/projects/nc_file_checksum_search vendor/bin/phpunit
```

Scoped run (single file or directory):

```bash
wsl --cd ~/projects/nc_file_checksum_search vendor/bin/phpunit tests/Unit/Controller/LookupControllerTest.php
wsl --cd ~/projects/nc_file_checksum_search vendor/bin/phpunit tests/Unit/
```

> **Important:** When using the native agent `execute_command` tool, always pass `cwd: "C:\\"` to avoid CMD.EXE UNC path errors with `\\wsl.localhost\...` paths.

Fallback: JetBrains MCP `execute_run_configuration` with `filePath` + `line` on individual test methods.

> **Note:** The `.aiassistant/tools/phpunit` wrapper depends on Kunstarchiv-specific classes
> (`mwx\Tests\ConditionalDiffFilter`) and does NOT work in FCIAS. Use `vendor/bin/phpunit` directly.

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
wsl --cd ~/projects/nc_file_checksum_search sudo --user www-data vendor/bin/phpunit tests/Integration/
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

## 12. Version History

| Version | Date       | Changed sections                              | Change type | Agent impact                                                      |
|---------|------------|-----------------------------------------------|-------------|-------------------------------------------------------------------|
| v2.0.0  | 2026-08-03 | All sections                                  | major       | Project switch: Kunstarchiv → FCIAS. Primary runner: `vendor/bin/phpunit`. Removed CS fixer infrastructure (§8-14 of v1.x). Added NC integration tests, mocking patterns, DB isolation. |
| v1.5.0  | 2026-04-23 | Title, 1, 13, 16                              | minor       | Makes `.aiassistant\tools\phpunit` the preferred agent test entrypoint (Kunstarchiv). |
| v1.4.0  | 2026-04-22 | Title, 7, 8, 16                               | minor       | Documents wrapper `--use-diff-stats` and `--env` (Kunstarchiv). |
| v1.3.0  | 2026-04-22 | Title, 7, 16                                  | minor       | Restores explicit escalation guidance (Kunstarchiv). |
| v1.2.0  | 2026-04-22 | Contents, 1-16                                | minor       | Adds section numbering (Kunstarchiv). |
| v1.1.0  | 2026-04-22 | Title, Contents, Document Governance, History | minor       | Adds explicit versioning (Kunstarchiv). |
| v1.0.0  | 2026-04-22 | Initial document                              | minor       | Baseline testing guidance for Kunstarchiv. |
