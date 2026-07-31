# Contributing to Kunstarchiv

Thank you for your interest in contributing to Kunstarchiv.

This document contains repository-specific contributor conventions for Kunstarchiv.
Generic runtime and agent flow-control rules are defined in `AGENTS.md`.

## Table of Contents

- [Commit Rules](#commit-rules)
- [Testing](#testing)
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
4. **Message structure**
    - Line 1: summary with prefix.
    - Line 2: must be empty.
    - Line 3+: details about what changed and why.
5. **Automated commit trailer**
    - Commits made by assistants should include a `Co-authored-by` trailer.

### Example Commit Message

```text
[TASK] Implement derivative validation flow and repository-level infrastructure refinements

This commit introduces clearer synchronous validation flow in `Bild::checkFiles()` and related status handling improvements in the derivative evaluation path, allowing for more deterministic conflict/error resolution.

It also consolidates supporting refactors in utility and data-access seams and expands test coverage across the repository.

Key changes include:
- Extracted focused status-evaluation helpers used by `Bild::checkFiles()`.
- Simplified derivative outcome branching to avoid mixed-state false positives.
- Improved DB test seams via existing `SimpleDbObj::setConnection()` usage patterns.
- Updated contributor-facing references in `CONTRIBUTING.md` and `.aiassistant/GUIDELINES.md` for consistent workflow documentation.

Added 5 targeted tests covering mixed derivative states, fallback handling, and mocked DB integration paths.
All 25 tests pass, lint clean.

Co-authored-by: Agent <agent@example.com>
```

### Commit message formatting tips

- Multiple `-m` flags (PowerShell):

```powershell
git commit -m "[TASK] Improve Objekt validation" `
           -m "Adds stricter input normalization and updates unit tests for invalid and edge input."
```

- Message file (`-F`):

```text
[TASK] Add utility normalization helper

Introduces shared normalization logic in `src/Util/StringUtil.php` and updates unit tests.
```

Then run:

```powershell
git commit -F .aiassistant\tools\commit-msg.txt
```

## Testing

### Gate command

Use PHPUnit 10 as the primary framework. The gate test command is:

```powershell
.\vendor\bin\phpunit --display-warnings --display-deprecations
```

### Expectations

- All relevant tests should pass with zero errors/failures.
- Warnings should be resolved where possible.
- Deprecations from third-party vendor code may occur and should be noted.

### Coverage guidance

1. **Unit tests**
    - Utility/base classes should have focused unit tests.
2. **Entity tests**
    - For DB entities (`Bestand`, `Objekt`, `Bild`), prefer connection/query abstraction points for mocks.
3. **Integration tests**
    - For major workflow changes, add/extend tests around entry points such as `foto.php` or `index.php` where feasible.

### Testability patterns

- Avoid tight coupling to non-mockable global calls when it blocks unit testing.
- Prefer dependency injection or existing setter-based seams (for example DB connection injection).
- For output buffering/log flushing paths, ensure CLI-safe behavior under PHPUnit.

## Database migrations & SQL snapshots

- Migrations live in `src/augias/Db/Migrations` (Phinx). Subclass `AbstractMigration` and implement
  `safeUp()` / `safeDown()` (the base wraps each in a transaction).
- When a migration fully (re)creates a database object (view/function/procedure/trigger/type/table),
  **do not inline the DDL as a PHP heredoc**. Store it as a frozen `.sql.txt` snapshot in a sidecar
  directory named like the migration file and load it with `$this->executeSnapshot('schema.Object.sql.txt')`.
- Call `executeSnapshot()` once per object **in dependency order** — execution order is the order of
  the calls, never directory/filename order. Keep guards, `SET` toggles, seeding, and one-time data
  ops inline in the migration PHP.
- Snapshots are **frozen and immutable**; the living canonical definitions stay in `scripts.SQL/`.
  See [`scripts.SQL/README.md`](scripts.SQL/README.md#migration-ddl-snapshots-frozen-sqltxt) for the
  full convention and the `db:snapshot` authoring command.

## Code Style & Documentation

### PHP code style

- Follow the existing style in surrounding files (naming, imports, formatting, structure).
- Keep changes minimal and consistent with existing project idioms.
- **Linting/Formatting**:

  `php ecs.php check [--fix] [--clear-cache] [--no-progress-bar] [--no-error-table] [--no-diffs] [--output-format OUTPUT-FORMAT] [--] [<paths>...]`.

  Scoped e.g. `php ecs.php check --fix src`.

### Documentation

- Document non-obvious behavior changes in code comments only when needed.
- Keep contributor-facing docs in sync when workflow conventions change.
- Use `README.md` for end-user/project usage context and `CONTRIBUTING.md` for contributor process conventions.

### Project references

- `AGENTS.md` - runtime behavior contract and flow-control rules.
- `.aiassistant/GUIDELINES.md` - agent-facing entry point for project references.
- `.aiassistant/tools/README.md` - helper scripts and usage notes.
