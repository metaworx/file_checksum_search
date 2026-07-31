---
description: "Execute the current Action Plan — pre-flight checklist, tool reminders, and implementation guardrails"
argument-hint: "[additional instructions or scope overrides]"
---

# /EXEC — Execute Action Plan

Activates code-mode execution of the gated Action Plan. Provides a pre-flight checklist of project-specific tool conventions and common pitfalls before the agent begins modifying files.

## 1. Core Rules (MUST)

| Rule | Source |
|---|---|
| AP must be approved (EXEC signal received) before code changes | [`AGENTS.md`](AGENTS.md) §1.2 |
| Follow the Implementation Plan blocks in order | The AP UAMF |
| Each block completes with its Verification checkpoint before the next begins | This command |
| UAMF files are NEVER overwritten — each AP revision creates a new file | [`AGENTS.md`](AGENTS.md) §4 |
| Test runner is `.aiassistant\\tools\\phpunit`, never `vendor\\bin\\phpunit` | [`.aiassistant/TESTING.md`](.aiassistant/TESTING.md) §1 |
| Commit workflow follows [`.aiassistant/COMMIT.md`](.aiassistant/COMMIT.md) | This command |

## 2. Pre-Flight Checklist

Before writing a single line of code, verify:

- [ ] The AP UAMF file exists in `.aiassistant/messages/` and is approved
- [ ] Read [`.aiassistant/TESTING.md`](.aiassistant/TESTING.md) §1 for the correct test runner invocation
- [ ] Read [`.aiassistant/COMMIT.md`](.aiassistant/COMMIT.md) for commit workflow if the task will result in a commit
- [ ] Identify which files will be modified (the AP's Implementation Plan lists them)
- [ ] Read the current state of each file to be modified

## 3. Tool Conventions

### 3.1 Test Runner

Always use the project wrapper, never raw PHPUnit:

```
.\\.aiassistant\\tools\\phpunit tests\\Path\\To\\Test.php
```

The wrapper is documented at [`.aiassistant/TESTING.md`](.aiassistant/TESTING.md) §1. Raw `vendor\\bin\\phpunit` works but lacks pre-authorization and wrapper options (`--use-diff-stats`, `--env`).

Scoped runs:

```
.\\.aiassistant\\tools\\phpunit tests\\Integration\\Database\\Functions\\FilePathFunctionsTest.php
.\\.aiassistant\\tools\\phpunit tests\\Unit\\augias\\
```

### 3.2 Database Commands

The project CLI is `bin\\augias` (or `bin\\augias.bat`). Common operations:

```
bin\\augias db:execute     # Run sql files or snippets
bin\\augias db:upgrade     # Run pending migrations
bin\\augias db:snapshot    # Generate DDL snapshot
bin\\augias help           # Full command list
```

**Before running `db:upgrade`:** verify whether database objects are truly missing or whether the guard logic (e.g., `verifyFunctionsExist()`) has a bug. A "skipped" test with "not deployed" doesn't always mean objects are absent — the OBJECT_ID type parameter may be wrong (e.g., inline TVFs use `'IF'`, not `'TF'`).

### 3.3 File Editing

Prefer `apply_diff` for targeted changes. Remember: `apply_diff` requires BOTH `path` and `diff` parameters. Omitting `path` is a common error — the tool will reject the call.

For large rewrites, `write_to_file` is acceptable but loses local IDE history. Per [`AGENTS.md`](AGENTS.md) §1.5, consider writing to `.aiassistant/temp/` first and atomically replacing.

Use Git to move files where possible.


### 3.4 Git

Use the commit scripts at `.aiassistant/tools/`:
1. Write the commit message to `.aiassistant/tools/commit-msg.txt`
2. Run `powershell -File .aiassistant/tools/do-commit.ps1`

- Never commit without a gate message and EXEC confirmation per [`AGENTS.md`](AGENTS.md) §1.3.
- Never revert or checkout without a gate message and EXEC confirmation.

## 4. Common Pitfalls

| Pitfall | Prevention |
|---|---|
| Using `.\\vendor\\bin\\phpunit` instead of wrapper | §3.1 — always use the wrapper |
| Modifying UAMF in-place (overwriting same file for v1.0→v1.1) | Each revision gets a new timestamp; never overwrite |
| `apply_diff` fails with "Missing value for required parameter 'path'" | Always include `path` and `diff` parameters |
| DataProvider associative arrays unpacked as named parameters in PHPUnit 11 | Wrap each dataset row in an extra indexed array: `[ ['key' => val] ]` instead of `['key' => val]` |
| SQL Server inline TVFs use `OBJECT_ID(name, 'IF')` not `'TF'` | `'TF'` = multi-statement TVF; `'IF'` = inline TVF (`RETURNS TABLE AS RETURN SELECT`) |
| Functions with DEFAULT parameters still need explicit binding via PDO | Pass `null` explicitly: `SELECT FN_FileBasename(?, ?)` with `[$path, null]` |
| `FN_Dirname` strips trailing backslash from drive roots | `D:\file.txt` → dirname is `D:`, not `D:\` |
| Skipped test with "not deployed" may be a guard-logic bug, not missing objects | Check the `verifyFunctionsExist()` logic before running `db:upgrade` |

## 5. Workflow

1. Read the approved AP UAMF from `.aiassistant/messages/`
2. Run the §2 pre-flight checklist
3. Execute each Implementation Plan block in order
4. After each block, run the Verification step (typically: run the relevant tests)
5. When all blocks are complete, run the full scoped test suite
6. If the task includes a commit, run `/cm` (or present commit gate manually)

## 6. Related Commands

| Command | Purpose |
|---|---|
| `/plan` | Create/revise APs before implementation |
| `/cm` | Commit implemented APs with synthesized changelog |
| (This command) | Execute approved APs with guardrails |
