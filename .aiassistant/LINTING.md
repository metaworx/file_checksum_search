# Linting & Code Style Conventions (v1.2.0)

Project-specific linting and style conventions for Kunstarchiv.
Generic agent flow-control rules are in `AGENTS.md`; contributor context is in `CONTRIBUTING.md`.

## Contents

1. Linting Tool
2. When to Run
3. Minimum Submission Checklist
4. Code Style Rules
5. Documentation Style
6. Document Governance
7. Version History

## 1. Linting Tool

The project uses [Easy Coding Standard (ECS)](https://github.com/easy-coding-standard/easy-coding-standard) via a local wrapper script.

### Command syntax

```powershell
php ecs.php check [--fix] [--clear-cache] [--no-progress-bar] [--no-error-table] [--no-diffs] [--output-format OUTPUT-FORMAT] [--] [<paths>...]
```

### Common invocations

| Purpose                           | Command                                          |
|-----------------------------------|--------------------------------------------------|
| Check entire project              | `php ecs.php check`                              |
| Check and auto-fix entire project | `php ecs.php check --fix`                        |
| Check a specific path             | `php ecs.php check src`                          |
| Fix a specific path               | `php ecs.php check --fix src`                    |
| Fix with clean cache              | `php ecs.php check --fix --clear-cache src`      |
| Quiet output (CI-style)           | `php ecs.php check --no-progress-bar --no-diffs` |

## 2. When to Run

- Run linting **before submission** for any code change.
- If formatter/lint output conflicts with ad-hoc style assumptions, **project lint output is authoritative**.
- Lint changed files after risky or multi-file edits to prevent syntax errors reaching handoff.

## 3. Minimum Submission Checklist

- [ ] Ran lint on changed scope (or broader when risk requires).
- [ ] Applied formatter/lint fixes without introducing functional drift.
- [ ] Rechecked edited files after auto-fixes.
- [ ] Documented any accepted non-blocking lint caveats in the final summary.

## 4. Code Style Rules

- Follow the existing style in surrounding files (naming, imports, formatting, structure).
- Keep changes minimal and consistent with existing project idioms.
- Always use Linux line endings (`\n`) in source files.
- ECS configuration is in `ecs.php` at the project root; standard rules are in `.config/ecs/default.php`.

## 5. Documentation Style

- Document non-obvious behavior changes in code comments only when needed.
- Keep contributor-facing docs in sync when workflow conventions change.
- Use `README.md` for end-user/project usage context and `CONTRIBUTING.md` for contributor process conventions.

## 6. Document Governance

- This document follows the shared governance rules in `.aiassistant/CHANGELOG.md`.
- Update the title version on each change and append a new row in `Version History`.

## 7. Version History

| Version | Date       | Changed sections                              | Change type | Agent impact                                                     |
|---------|------------|-----------------------------------------------|-------------|------------------------------------------------------------------|
| v1.2.0  | 2026-04-22 | Contents, 1-7                                 | minor       | Adds section numbering (except `Contents`) and aligned numbered `Contents` entries. |
| v1.1.0  | 2026-04-22 | Title, Contents, Document Governance, History | minor       | Adds explicit versioning, ToC, and local history tracking format. |
| v1.0.0  | 2026-04-22 | Initial document                              | minor       | Baseline linting/style guidance for agent workflows in this repository. |
