---
description: "Commit Action Plans — synthesize concise changelog entries from UAMF drafts, write to docs/changelog/, and commit"
argument-hint: "[EXEC|EXEC+|EXEC++|EXEC+++] [additional instructions]"
---

# /cm — Commit Action Plan

Commits implemented Action Plans with a synthesized changelog entry. Condenses UAMF AP drafts from `.aiassistant/messages/` into clean committed versions at `docs/changelog/NNN-slug.md`, and commits everything together.

## 1. Execution Signals

Follows `.aiassistant/COMMIT.md` §2 semantics. The `/cm` command always writes the changelog file first, then presents a commit gate.

| Signal            | Changelog | Stage scope | Commit scope |
|-------------------|---|---|---|
| `/cm`, `/cm EXEC` | Write | Changelog + related source/test files | Only gated scope |
| `/cm EXEC+`       | Write | Changelog + related source/test files | Gated scope, then continue to next step |
| `/cm EXEC++`      | Write | Changelog + all currently staged files | All staged, adapt message |
| `/cm EXEC+++`     | Write | `git add .` (all changed files) | All changed, adapt message, no gate |

Additional text after the signal is treated as amendments to the commit message body or scope instructions.

## 2. Workflow

### 2.1 Discover UAMF APs

Scan `.aiassistant/messages/` for `AP_*` files from the current task. Each distinct AP topic may have multiple versioned files (v1.0, v1.1, ..., v1.N). **Read all versions** to understand how the plan evolved. Apply the latest-wins rule (§2.2): where versions disagree, the highest-numbered version takes precedence. The committed file reflects only the final synthesis — not the deliberation journey.

### 2.2 Apply Latest-Wins Synthesis

Read all versions; output only the conclusion.

| Section | Process |
|---|---|
| Discussion | Read all versions. Where they disagree, v1.N wins. The committed file contains only the final conclusion. Do not include "we considered X then chose Y" unless the rejected alternative is essential for understanding the final choice. |
| Analysis | Read all versions. Use v1.N as the base; incorporate corrections from intermediate versions that are not contradicted by v1.N. |
| Implementation | List steps **actually executed**, not planned-then-revised. |
| Change History | Single row: `Implemented on YYYY-MM-DD.` (not one row per UAMF iteration). |

### 2.3 Determine Next Sequence Number

List `docs/changelog/` for the highest `NNN` prefix. Use `NNN+1`. Create the directory if absent. Start at `001`.

### 2.4 Write Committed Changelog

Create `docs/changelog/NNN-slug.md`. Condense — do not copy any UAMF file verbatim.

**Keep:**
- Title: plain descriptive (drop `AP EXEC v1.0:` prefix, drop version number)
- Discussion: only if it provides essential context (§2.2)
- Analysis: the final synthesis (§2.2)
- Implementation: numbered list of executed steps (drop inline verification checkpoints)
- Proposed commit message → use as actual commit message

**Drop:**
- Gate protocol sections (`Checkpoint`, `Overall Task`, `Last Action`, `Pending action`, `Confirmation needed`)
- `EXEC` / `ROLLBACK` keywords
- Iterative UAMF version entries in Change History
- Inline verification checkpoints in Implementation

**Template:**

````markdown
# {Descriptive Title}

## Discussion

{Only if essential context; otherwise omit}

## Analysis

{Final synthesis — latest-wins applied}

## Implementation

1. {Step 1}
2. {Step 2}
3. ...

## Commit

```
{Full commit message}
```

## Change History

| Date | Change |
|---|---|
| YYYY-MM-DD | Implemented. |
````

### 2.5 Stage and Commit

1. Stage: `git add docs/changelog/NNN-slug.md` plus all changed source/test files
2. Write commit message to `.aiassistant/tools/commit-msg.txt`, then run the appropriate commit script (`.aiassistant/COMMIT.md` §6.1)
3. Present commit gate following `AGENTS.md` §1.1 Universal Gate Template
4. Execute according to the signal in §1

### 2.6 Commit Gate Format

Follow `AGENTS.md` §1.1 Universal Gate Template. The gate message MUST include:

```
Checkpoint: {AP title}
Overall Task: {one-line summary}
Last Action: Created AP at {UAMF path}
Pending action: {numbered blocks from Implementation Plan}
Proposed commit message: {complete message per `.aiassistant/COMMIT.md` §3 format}
Changelog: {The changelog file path being created}
Files to be committed: {List of files to be committed}
Confirmation needed: EXEC / EXEC+ / EXEC++ / EXEC+++ / ROLLBACK
```

Ask the user to confirm with an `EXEC`-family signal or provide feedback for revision.

**Delivery mechanism (STRICT):** Use the native tool `ask_followup_question`. Write the gate message in the `question` parameter of `ask_followup_question` **ending with a short call-to-action label**.

- **Tool**: Use the native tool`ask_followup_question`
- **`question` parameter**: Full gate summary in Markdown (Checkpoint, Overall Task, Last Action, Pending action, Proposed commit message, Changelog, Files to be committed). Write this exactly as you would any other response body. Ending on a single short line: `"Confirmation needed: EXEC / EXEC+ / EXEC++ / EXEC+++ / ROLLBACK"`.
- **`follow_up` parameter**: User choice buttons with mode switches where appropriate.

## 3. Slug Convention

Lowercase, hyphens, 6-8 meaningful words, no articles.

Examples:
- `001-fix-filter-assert-and-inline-trivial-methods.md`
- `002-extract-file-check-decision-engine.md`

## 4. Edge Cases

| Scenario | Action |
|---|---|
| No UAMF APs found | Ask which AP to base the changelog on |
| Multiple distinct APs | One file per AP, sequential numbers |
| Same AP spans multiple commits | Append to existing `docs/changelog/NNN-slug.md`, add Change History row |
| `docs/changelog/` does not exist | Create it |
| User adds instructions after signal | Append to commit body after AP message, blank line separator |
