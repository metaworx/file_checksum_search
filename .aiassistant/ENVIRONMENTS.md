# Agent Environments (v1.0.0)

Canonical reference for host- and agent-specific command and tool-call conventions. Any
document that shows a shell command or an MCP/IDE tool call assumes **one** of the environments
below; translate examples per this document rather than duplicating host notes locally.

## Contents

1. Windows-based agents
   - 1.1 General
   - 1.2 Zoo Code / Roo Code
2. WSL-based agents
   - 2.1 General
   - 2.2 Claude Code
3. Document Governance
4. Version History

## 1. Windows-based agents

### 1.1 General

The agent's shell is on the Windows side, so:

- Prefix shell commands with `wsl --cd ~/projects/nc_file_checksum_search`. Every bare command
  shown elsewhere in `.aiassistant/` (e.g. `./.aiassistant/tools/phpunit ...`,
  `/home/mdr/bin/git commit ...`) needs this prefix here.
- Pass `cwd: "C:\\"` to the `execute_command` tool, avoiding CMD.EXE UNC path errors with
  `\\wsl.localhost\...` paths.
- IDE tools are named `mcp--jetbrains--*`.

### 1.2 Zoo Code / Roo Code

Your command tool calls run in a CMD.EXE session: `cmd /c "your command"`.
Configured per clone in `.roo/` — `roo-code-settings.json` plus the `commands/` prompts. Commit
trailer identity is `Agent <roo-code@deepseek.com>`; see `.aiassistant/COMMIT.md` for the
identity rule.

## 2. WSL-based agents

### 2.1 General

The agent's shell already starts in the repo root inside WSL, so:

- Do **not** prefix commands with `wsl --cd ...`. The bare form shown elsewhere in
  `.aiassistant/` runs directly:

  ```bash
  ./.aiassistant/tools/phpunit tests/Unit/Service/PermissionServiceTest.php
  ```

- The `cwd: "C:\\"` note (§1.1) does not apply — nothing crosses CMD.EXE.
- **But the IDE is still a Windows process.** PhpStorm indexes this repo under its UNC form, and
  the MCP server routes on that string alone:

  ```
  //wsl.localhost/Ubuntu/home/mdr/projects/nc_file_checksum_search
  ```

  The plain Linux path is rejected with *"Unable to determine the target project"*. Omitting the
  project header entirely makes the server enumerate the open projects, which is the quickest
  way to recover the exact string.

### 2.2 Claude Code

IDE tools are prefixed `mcp__phpstorm__*`. The `.aiassistant/LINTING.md` §1 / `.aiassistant/TESTING.md`
§13 pipeline maps as:

| Windows-agent tool (`mcp--jetbrains--*`) | Claude Code tool                 |
|-------------------------------------------|-----------------------------------|
| `reformat_file`                            | `mcp__phpstorm__reformat_file`   |
| `get_inspections`                          | `mcp__phpstorm__get_inspections` |
| `apply_quick_fix`                          | `mcp__phpstorm__apply_quick_fix` |
| `lint_files`                               | `mcp__phpstorm__lint_files`      |
| `get_file_problems`                        | `mcp__phpstorm__get_file_problems` |
| `build_project`                            | `mcp__phpstorm__build_project`   |

They arrive **deferred** — names only, no schemas — and calling one before its schema is loaded
fails with `InputValidationError`, which reads like "server unavailable" but is not. Load them
first, batching everything expected into a single query:

```
ToolSearch query="select:mcp__phpstorm__get_inspections,mcp__phpstorm__apply_quick_fix"
```

Registration is per project and local-scoped, so each clone carries its own project header:

```bash
claude mcp add --transport http phpstorm http://127.0.0.1:<port>/stream \
  --header "IJ_MCP_SERVER_PROJECT_PATH: //wsl.localhost/Ubuntu/home/mdr/projects/nc_file_checksum_search"
```

Two prerequisites, both silent when unmet:

- The server must be registered under the exact name `phpstorm`. The JetBrains plugin's
  `SessionStart` hook greps `claude mcp get phpstorm` for the port; under any other name it
  exits 0 and the post-edit inspection hook never runs.
- PhpStorm's **router-only mode must be off** (Settings → MCP Server). When on, `tools/list`
  collapses to a single `execute_tool` dispatcher and direct calls fail with
  `Tool <name> not found`. Claude Code already defers tool schemas, so router-only saves no
  context here and costs the argument schemas.

The port is per-IDE-run unless pinned in the MCP Server plugin settings.

## 3. Document Governance

- This document follows the shared governance rules in `.aiassistant/CHANGELOG.md`.
- Update the title version on each change and append a new row in `Version History`.
- This is the canonical owner for host/agent environment conventions (see `AGENTS.md` §2.1).
  Other documents MUST NOT duplicate these rules locally — point here instead.

## 4. Version History

| Version | Date       | Changed sections | Change type | Agent impact                                                                                                                                                  |
|---------|------------|-------------------|-------------|------------------------------------------------------------------------------------------------------------------------------------------------------------|
| v1.0.0  | 2026-08-24 | Initial document  | major       | Extracted from `.aiassistant/TESTING.md` §15 into a standalone, cross-referenced canonical document so `.aiassistant/COMMIT.md`, `.aiassistant/LINTING.md`, and `.aiassistant/tools/README.md` (which each duplicated the Windows `wsl --cd` / `cwd: "C:\\"` note) can point here instead. |
