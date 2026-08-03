# Linting & Code Style Conventions (v2.0.0)

Project-specific linting and style conventions for FCIAS (File Checksum Index & Search Nextcloud app).
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

FCIAS uses JetBrains IDE inspections for linting and validation. All invocations go through the JetBrains MCP server.

| Purpose | Tool | Notes |
|---------|------|-------|
| Batch lint multiple files | `mcp--jetbrains--lint_files` | Pass array of project-relative paths |
| Single file inspection | `mcp--jetbrains--get_file_problems` | Use `errorsOnly: true` for critical issues |
| Compile/validate | `mcp--jetbrains--build_project` | Use `filesToRebuild` for targeted checks |
| Code formatting | `mcp--jetbrains--reformat_file` | Apply IDE code style after edits |

## 2. When to Run

- Run linting **before submission** for any code change.
- If formatter/lint output conflicts with ad-hoc style assumptions, **project lint output is authoritative**.
- Lint changed files after risky or multi-file edits to prevent syntax errors reaching handoff.
- After `apply_diff` or `write_to_file`, run `build_project` on the changed files.

## 3. Minimum Submission Checklist

- [ ] Ran `mcp--jetbrains--lint_files` on changed scope (or `get_file_problems` on individual files).
- [ ] Applied `mcp--jetbrains--reformat_file` without introducing functional drift.
- [ ] Rechecked edited files after formatting.
- [ ] Documented any accepted non-blocking lint caveats in the final summary.

## 4. Code Style Rules

- Follow Nextcloud coding standards and the existing style in surrounding files.
- Keep changes minimal and consistent with existing project idioms.
- Always use Linux line endings (`\n`) in source files.
- Apply IDE formatting via `mcp--jetbrains--reformat_file` after edits.
- PHP 8.2+ features (typed properties, enums, readonly classes) are allowed per project minimum.

## 5. Documentation Style

- Document non-obvious behavior changes in code comments only when needed.
- Keep contributor-facing docs in sync when workflow conventions change.
- Use `README.md` for end-user/app usage context and `CONTRIBUTING.md` for contributor process conventions.

## 6. Document Governance

- This document follows the shared governance rules in `.aiassistant/CHANGELOG.md`.
- Update the title version on each change and append a new row in `Version History`.

## 7. Version History

| Version | Date       | Changed sections                              | Change type | Agent impact                                                     |
|---------|------------|-----------------------------------------------|-------------|------------------------------------------------------------------|
| v2.0.0  | 2026-08-03 | All sections                                  | major       | Project switch: Kunstarchiv → FCIAS. Linting tool: `ecs.php` → JetBrains MCP (`lint_files`, `get_file_problems`, `build_project`, `reformat_file`). |
| v1.2.0  | 2026-04-22 | Contents, 1-7                                 | minor       | Adds section numbering (Kunstarchiv). |
| v1.1.0  | 2026-04-22 | Title, Contents, Document Governance, History | minor       | Adds explicit versioning (Kunstarchiv). |
| v1.0.0  | 2026-04-22 | Initial document                              | minor       | Baseline linting guidance for Kunstarchiv (ECS). |
