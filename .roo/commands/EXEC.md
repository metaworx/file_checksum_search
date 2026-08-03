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
| Test runner is `wsl --cd ~/projects/nc_file_checksum_search vendor/bin/phpunit` or JetBrains MCP | [`.aiassistant/TESTING.md`](.aiassistant/TESTING.md) §1 |
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

Always prefix with `wsl --cd ~/projects/nc_file_checksum_search`:

```
wsl --cd ~/projects/nc_file_checksum_search vendor/bin/phpunit tests/Path/To/Test.php
```

Scoped runs:

```
wsl --cd ~/projects/nc_file_checksum_search vendor/bin/phpunit tests/Unit/Controller/LookupControllerTest.php
wsl --cd ~/projects/nc_file_checksum_search vendor/bin/phpunit tests/Unit/
```

Integration tests (as Nextcloud web server user):

```
wsl --cd ~/projects/nc_file_checksum_search sudo --user www-data vendor/bin/phpunit tests/Integration/
```

Fallback: JetBrains MCP `execute_run_configuration` with `filePath` + `line`.

> **Important:** When using the native agent `execute_command` tool, always pass `cwd: "C:\\"` — the default workspace path `\\wsl.localhost\...` is a UNC path unsupported by CMD.EXE.

> Note: The `.aiassistant/tools/phpunit` wrapper is Kunstarchiv-specific and does NOT work in FCIAS.

### 3.2 Database Commands

FCIAS has no project-specific CLI. Database operations are via Nextcloud migrations (`occ migrations:execute`) or raw SQL through `IDBConnection`.

For MariaDB trigger/stored procedure management, use the admin settings page or CLI commands (`file-checksum-search:status`, `file-checksum-search:teardown`).

### 3.3 File Editing

Prefer `apply_diff` for targeted changes. Remember: `apply_diff` requires BOTH `path` and `diff` parameters. Omitting `path` is a common error — the tool will reject the call.

For large rewrites, `write_to_file` is acceptable but loses local IDE history. Per [`AGENTS.md`](AGENTS.md) §1.5, consider writing to `.aiassistant/temp/` first and atomically replacing.

Use Git to move files where possible.


### 3.4 Git

Commit workflow — always prefix with `wsl --cd ~/projects/nc_file_checksum_search`:

1. Write the commit message to `.aiassistant/tools/commit-msg.txt`
2. Stage: `wsl --cd ~/projects/nc_file_checksum_search /home/mdr/bin/git add <files>`
3. Commit: `wsl --cd ~/projects/nc_file_checksum_search /home/mdr/bin/git commit -F .aiassistant/tools/commit-msg.txt --trailer "Co-authored-by: Agent <agent@example.com>"`
4. Amend: `wsl --cd ~/projects/nc_file_checksum_search /home/mdr/bin/git commit --amend -F .aiassistant/tools/commit-msg.txt --trailer "Co-authored-by: Agent <agent@example.com>"`

> **Important:** When using the native agent `execute_command` tool, always pass `cwd: "C:\\"` to avoid CMD.EXE UNC path errors.

- Never commit without a gate message and EXEC confirmation per [`AGENTS.md`](AGENTS.md) §1.3.
- Never revert or checkout without a gate message and EXEC confirmation.

## 4. Common Pitfalls

| Pitfall | Prevention |
|---|---|
| Modifying UAMF in-place (overwriting same file for v1.0→v1.1) | Each revision gets a new timestamp; never overwrite (AGENTS.md §4) |
| `apply_diff` fails with "Missing value for required parameter 'path'" | Always include both `path` and `diff` parameters |
| Hardcoded `oc_` table prefix in SQL | Always use `TableNameService` (injects `IConfig`, reads `dbtableprefix`) |
| TRIGGER privilege missing on MariaDB user | Run compatibility test first (`file-checksum-search:status`) |
| NC API version mismatch in production | Check `info.xml` `<dependencies>` min/max version before using NC APIs |
| `generate` command uses wrong default algo | Default is `sha1` (NC default), not `sha256` |
| `--path` option rejects valid patterns | Supports `**` glob syntax via Symfony Finder, not regex |

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
