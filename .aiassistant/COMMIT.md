# AI Agent Commit Guidelines (v1.2.0)

This document defines the complete commit workflow, message format, and execution signals for AI agents. Follow these rules precisely when preparing and executing commits.

## Contents

1. Quick Reference Checklist
2. Commit Gate & Execution Signals
   - 2.1 EXEC Signal Mini-Matrix (Quick Decision) 
3. Commit Message Format (STRICT)
   - 3.1 Summary Line (Line 1)
   - 3.2 Empty Second Line (Line 2)
   - 3.3 Body (Line 3+)
   - 3.4 Trailer
4. Tag‑Specific Rules
   - 4.1 [CLEANUP] (Strict)
   - 4.2 [WIP] – Work in Progress
5. Functional Atomicity
6. Commit Tools (/.aiassistant/tools/)
   - 6.1 Workflow
7. Example Commit Message
8. Related References
9. Document History

## 1. Quick Reference Checklist

Before committing, verify:

- [ ] Functional atomicity: one logical change per commit.
- [ ] Summary line starts with a valid tag (`[TASK]`, `[FIX]`, `[SECURITY]`, `[CLEANUP]`, `[WIP]`, `[UPDATE]`).
- [ ] Line 2 is empty.
- [ ] Detailed body explains what changed and why.
- [ ] `Co-authored-by` trailer included.
- [ ] Gate message sent and user confirmation received via `EXEC`-family signal.

---

## 2. Commit Gate & Execution Signals

Before executing `git commit`, the agent MUST present a **gate message** containing the complete proposed commit message and wait for user confirmation.

| Signal      | Scope                                                                                                                      |
|-------------|------------------------------------------------------------------------------------------------------------------------------------------------|
| `EXEC`      | Commit **only** the currently gated changes (scope described in the gate message).                                |
| `EXEC+`     | Commit the gated changes **and continue** to the next planned step.                                           |
| `EXEC++`    | Commit **all staged files** (including those outside the gated scope). Adapt commit message.                      |
| `EXEC+++`   | Commit **all changed files** (`git add .` respecting ignore rules). Adapt commit message.                       |

- The gate message must follow the **Universal Gate Template** defined in [`AGENTS.md`](../AGENTS.md#11-gate-message-mechanism).
- The `Proposed commit message` field MUST contain the **full** commit message body (summary, empty line, details, trailer).

### 2.1 EXEC Signal Mini-Matrix (Quick Decision)

| Signal | Agent action | One-line example |
|--------|--------------|------------------|
| `EXEC` | Commit only gated scope. | `EXEC` |
| `EXEC+` | Commit gated scope, then continue plan. | `EXEC+ continue` |
| `EXEC++` | Commit all currently staged files, then continue. | `EXEC++ use staged set` |
| `EXEC+++` | Stage all changed files (`git add .`), commit, then continue. | `EXEC+++ include all changes` |

If signal is ambiguous, send a clarification gate and stop.

---

## 3. Commit Message Format (STRICT)

### 3.1 Summary Line (Line 1)
- Must start with one of the following tags enclosed in square brackets:
    - `[TASK]` - Features, logic changes, documentation updates.
    - `[FIX]` - Bug fixes.
    - `[SECURITY]` - Security fixes/hardening.
    - `[CLEANUP]` - **Formatting/layout-only** changes; **NO functional changes**.
    - `[WIP]` - Intermediate work-in-progress; leaves repo in "dirty" state.
    - `[UPDATE]` - Environment, dependency, or test-config updates.
- Maximum length: ~72 characters.

### 3.2 Empty Second Line (Line 2)
- **MUST be empty.** This separates the summary from the body.

### 3.3 Body (Line 3+)
- Explain **what** changed and **why**.
- Use bullet points for `Key changes` (when helpful).
- Be concise but complete.

### 3.4 Trailer
- **Every agent commit** MUST end with a `Co-authored-by` trailer:
  ```
  Co-authored-by: Agent <agent@example.com>
  ```
  (If using the commit tools specified below, this is already accounted for.)

---

## 4. Tag-Specific Rules

### 4.1 `[CLEANUP]` (Strict)
- **Allowed:** Import reordering, whitespace normalization, code formatting, file reorganization.
- **NOT allowed:** Any change that alters runtime behavior (logic, control flow, data handling).

### 4.2 `[WIP] - Work in Progress
- Use for intermediate states (e.g., file renaming before heavy edits, saving state on a branch).
- A subsequent **non-`[WIP]`** commit MUST follow.
- The follow-up commit SHOULD reference the `[WIP]` commit by including a line like:
  ``g
  Follows [WIP] commit <hash>
  ```

---

## 5. Functional Atomicity

- One functional change per commit.
- Include **related code and tests** in the same commit.
- Do not bundle unrelated changes.

---

## 6. Commit Tools (`/.aiassistant/tools/`)

Reusable PowerShell scripts simplify creating compliant commits.

### 6.1 Workflow

1. Write the complete commit message to `.aiassistant/tools/commit-msg.txt` (do not delete; it's `.gitignore'd`).
2. Run the appropriate command from WSL:

    - **New commit**:
      ```
      wsl --cd ~/projects/nc_file_checksum_search /home/mdr/bin/git commit -F .aiassistant/tools/commit-msg.txt --trailer "Co-authored-by: Agent <agent@example.com>"
      ```
    - **Amend last commit**:
      ```
      wsl --cd ~/projects/nc_file_checksum_search /home/mdr/bin/git commit --amend -F .aiassistant/tools/commit-msg.txt --trailer "Co-authored-by: Agent <agent@example.com>"
      ```

> **Important:** When using the native agent `execute_command` tool, always pass `cwd: "C:\\"` to avoid CMD.EXE UNC path errors with `\\wsl.localhost\...` paths.

The `--trailer` flag appends the `Co-authored-by` trailer automatically.

> **Warning:** The `--trailer` flag appends to any existing `Co-authored-by` in the message file. Use exactly one method — either in-file OR via `--trailer`, never both. Duplicate trailers produce duplicate `Co-authored-by` lines in the commit.

> **Identity rule:** If the agent does not know the correct `Co-authored-by` identity string for the current project, it MUST ask the user before committing. Use the `ask_followup_question` tool: *"Which Co-authored-by trailer should I use for this project?"* Typical values: `Agent <roo-code@deepseek.com>`, `Agent <junie@jetbrains.com>`.

---

## 7. Example Commit Message

```
[TASK] Implement derivative validation flow and status handling

This commit introduces clearer synchronous validation in `Bild::checkFiles()` 
and improves derivative outcome branching for deterministic conflict resolution.

Key changes:
- Extracted focused status helpers used by `Bild::checkFiles()`.
- Simplified derivative outcome logic to avoid mixed-state false positives.
- Updated DB and contributor documentation.

Added 5 targeted tests covering mixed states and fallback handling.
All 25 tests pass, lint clean.

Co-authored-by: Agent <agent@example.com>
```

---

## 8. Related References

- [`AGENTS.md` commit policy](../AGENTS.md#6-commit-policy-strict) - High-level rules.
- [`CONTRIBUTING.md` commit rules](../../CONTRIBUTING.md#commit-rules) - Repository-specific examples.
- [`.aiassistant/tools/README.md`](tools/README.md) - Additional helper script details.

## 9. Document History

| Version | Date       | Changes                                                                 | Agent Impact                                                              |
|---------|------------|-------------------------------------------------------------------------|---------------------------------------------------------------------------|
| v1.2.0  | 2026-08-05 | Added trailer mutual-exclusion warning and ask-user-first identity rule in §6.1.              | Prevents duplicate Co-authored-by trailers; ensures correct identity string.  |
| v1.1.0  | 2026-04-22 | Added `EXEC` signal mini-matrix with quick examples for faster commit confirmation.   | Improves commit signal clarity in user-agent interaction.                     |
| v1.0.0  | 2026-04-22 | Initial consolidated version from `AGENTS.md` v2.0.4 and `GUIDELINES.md`              | Use this document as the authoritative commit reference; gate signals clarified. |
