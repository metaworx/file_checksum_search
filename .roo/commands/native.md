---
description: "agent tooling precedence and usage guide"
---

# Native Agent Tooling Guide

## 1. Precedence

1. Use native agent tools where available.
2. Use JetBrains MCP server tools as fallback or where they provide additional functionality.
3. Don't use shell to edit files (particularly PowerShell commands regularly fail due to syntax errors and escaping mistakes). Use a temp script instead, if required.
4. Follow AGENTS.md for more instructions.

## 2. Native Agent Tools

| Tool | Best For | Watch Out For |
|------|----------|---------------|
| `read_file` | Reading file contents. `mode:'slice'` for sequential, `mode:'indentation'` with `anchor_line` for semantic code blocks. | Always read before editing — never assume content. |
| `write_to_file` | Creating **new** files only. | **NEVER overwrite existing UAMF files** (AGENTS.md §4). For source code, prefer `apply_diff` for surgical edits. |
| `apply_diff` | Surgical edits to **source code** files. Multiple SEARCH/REPLACE blocks in one call. | **NEVER on UAMF files** (`.aiassistant/messages/*`). Search block must match exactly including whitespace. |
| `search_files` | Regex search across project. Use `file_pattern` glob to narrow (e.g., `*.php`). | Rust regex syntax, not PCRE. No lookahead/lookbehind. |
| `search_text` | Literal substring search — faster than regex for exact strings. | Case-sensitive by default. |
| `list_files` | Directory exploration. Use `recursive:true` for deep scans. | Prefer this over `ls`/`dir` — works cross-platform. |
| `execute_command` | Run CLI commands. | **CRITICAL: always pass `cwd: "C:\\"`** — the default workspace `\\wsl.localhost\...` is a UNC path unsupported by CMD.EXE. All WSL commands must use `wsl --cd ~/projects/nc_file_checksum_search` prefix. |

## 3. JetBrains MCP Server (Fallback)

| Tool | Best For |
|------|----------|
| `mcp--jetbrains--get_run_configurations` | Discover runnable test methods, main entry points. Pass `filePath` to find run points in a file. |
| `mcp--jetbrains--execute_run_configuration` | Run tests, PHP scripts. Use `waitForExit:true` with `timeout`. Check `exitCode` in result. |
| `mcp--jetbrains--build_project` | Compile/validate after edits. Use `filesToRebuild` for targeted builds (faster). |
| `mcp--jetbrains--get_file_problems` | Single-file IDE inspection. `errorsOnly:true` for critical issues only. |
| `mcp--jetbrains--lint_files` | Batch inspection across multiple files. Use `min_severity:'error'` for focused results. |
| `mcp--jetbrains--open_file_in_editor` | Open AP or source files in PhpStorm for review. |
| `mcp--jetbrains--reformat_file` | Apply IDE code style after edits. |
| `mcp--jetbrains--get_composer_dependencies` | Check available PHP libraries before generating code. Use `nameFilter` glob (e.g., `"symfony/*"`). |
| `mcp--jetbrains--get_php_project_config` | Verify PHP version, interpreter, extensions before generating version-specific code. |
| `mcp--jetbrains--search_symbol` | Find classes, methods, fields by name. Use `include_external:true` for SDK symbols. |
| `mcp--jetbrains--analyze_calls` | Build call hierarchy (incoming/outgoing). More precise than text search. |
| `mcp--jetbrains--git_status` | Check VCS state before committing. |
| `mcp--jetbrains--rename_refactoring` | Safe symbol rename with automatic reference updates. |

## 4. Key Patterns

### 4.1 Verify → Edit → Verify Cycle
Read file → `apply_diff` → `mcp--jetbrains--build_project` or `lint_files` → fix any issues.

### 4.2 UAMF Rule (CRITICAL)
Files in `.aiassistant/messages/` are immutable once written. Always create a **new** timestamped file for revisions — never `apply_diff` or `write_to_file` on existing UAMF files (AGENTS.md §4).

### 4.3 Mode Awareness
- **Architect mode**: `.md`-only writes, no shell commands. Switch to `code` mode (`switch_mode`) for implementation.
- **Code mode**: Full file writes, `apply_diff`, `execute_command` available.

### 4.4 Parallel Reads
`read_file`, `search_files`, `list_files` can all be called in parallel (single message, multiple invocations) to reduce round-trips.

### 4.5 JetBrains MCP for PHP-specific Tasks
Use `get_composer_dependencies` to check library availability, `get_php_project_config` for PHP version, `build_project` with `filesToRebuild` for fast targeted validation.

## 5. FCIAS Environment (CRITICAL)

### 5.1 execute_command
**Always** pass `cwd: "C:\\"`. The default workspace path `\\wsl.localhost\Ubuntu\...` is a UNC path unsupported by CMD.EXE, causing: `CMD.EXE was started with the above path as the current directory. UNC paths are not supported.`

### 5.2 WSL Command Prefix
All commands targeting the project must use:
```
wsl --cd ~/projects/nc_file_checksum_search <command>
```

### 5.3 Test Runner
```
wsl --cd ~/projects/nc_file_checksum_search vendor/bin/phpunit tests/Unit/...
```

### 5.4 Integration Tests
```
wsl --cd ~/projects/nc_file_checksum_search sudo --user www-data vendor/bin/phpunit tests/Integration/...
```

### 5.5 Commit
```
wsl --cd ~/projects/nc_file_checksum_search /home/mdr/bin/git add <files>
wsl --cd ~/projects/nc_file_checksum_search /home/mdr/bin/git commit -F .aiassistant/tools/commit-msg.txt --trailer "Co-authored-by: Agent <agent@example.com>"
```

### 5.6 Environment Reference
| Item | Value |
|------|-------|
| Project root (WSL) | `/home/mdr/projects/nc_file_checksum_search` |
| WSL home shorthand | `~/projects/nc_file_checksum_search` |
| Git binary | `/home/mdr/bin/git` |
| PHPUnit | `vendor/bin/phpunit` |
| PHP version | ≥8.2 |
| NC version | v33–v34 |
