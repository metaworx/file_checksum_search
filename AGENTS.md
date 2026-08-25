<!-- GENERATED FILE - DO NOT EDIT.
     Source: .aiassistant/shared/GUIDELINES.md + .aiassistant/PROJECT.md
     Regenerate: .aiassistant/shared/tools/sync.sh
     Shared contract version: v3.0.0 -->

# AI Agent Guidelines (v3.0.0)

Core behavioral rules for AI agents working on this codebase.  
All agents MUST comply.
This document is intentionally concise; refer to linked documents for extended guidance.

## Contents

1. Critical Behavioral Rules (STRICT)
2. Instruction Precedence (STRICT)
3. Task Interpretation & Keywords
4. User‑Accessible Message Files (UAMF)
5. Action Plan (AP)
6. Commit Policy (STRICT)
7. Additional References
8. Document Governance

## 1. Critical Behavioral Rules (STRICT)

### 1.1 Gate Message Mechanism

- A **gate message** is an `<answer>` (or idempotent) message that concludes the current turn and requests explicit user
  confirmation.
- After sending a gate message, the agent MUST **stop** — no further actions, shell commands, or status updates are
  permitted until the user responds with a new `<issue_update>`.
- The gate message MUST follow the **Universal Gate Template**:
    - `Checkpoint`
    - `Overall Task`
    - `Last Action`
    - `Pending action`
    - `Confirmation needed: EXEC/EXEC+/EXEC++/EXEC+++/ROLLBACK`
    - Additional information according to specialized gate message (e.g. AP or commit)
- A gate message is the **only** valid way to request user confirmation. Echoing "waiting for input" via shell commands
  is a **violation** of this rule.
- A visual workflow diagram is available in `.aiassistant/shared/GATE_WORKFLOW.md`.

### 1.2 Action Plan (AP) Requirement

- Every non‑trivial `[CODE]` task requires an Action Plan.
- Full AP format and versioning rules are defined in [§5](#5-action-plan-ap).
- Each AP iteration MUST be persisted as a [UAMF](#4-useraccessible-message-files-uamf) **before** any project writes.
- Unless the execution is directly authorized by an `EXEC`‑family keyword in the same user message, the AP MUST be
  presented as a **gate message** (see [§1.1](#11-gate-message-mechanism)).

### 1.3 Commit Confirmation Gate

- Before `git commit`, the agent MUST present a gate message containing the complete proposed commit message.
- Commit‑related execution signals (`EXEC`, `EXEC+`, `EXEC++`, `EXEC+++`) and commit tools are detailed in [
  `.aiassistant/shared/COMMIT.md`](.aiassistant/shared/COMMIT.md).
- The gate message must follow the Universal Gate Template.

### 1.4 `undo_edit` Authorization (`ROLLBACK`)

- A rollback action (calling `undo_edit`) requires explicit user authorization.
- Authorization may be given via:
    - The keyword `ROLLBACK` in the user message, or
    - An `EXEC`‑family signal that clearly references the rollback.
- If authorization is absent, the agent MUST present a gate message requesting `ROLLBACK` or `EXEC` confirmation.

### 1.5 Safe File Edits

- **Do not delete and recreate files** when making large changes. Instead, write the new content to a temporary file and
  atomically replace the original (`mv` on Linux/macOS, `Move-Item` on Windows). This preserves local IDE history.
- Temporary files SHOULD be placed in `/.aiassistant/temp/`.
- Always prefer history‑preserving edit tools (e.g., `apply_patch`, in‑place edits) over raw shell writes.

## 2. Instruction Precedence (STRICT)

1. Runtime/system rules (conflicts noted explicitly).
2. Direct user instruction (current session).
3. This document (the shared agent contract).
4. Local conventions

**IMPORTANT:**

- Writing *new* [UAMF](#4-useraccessible-message-files-uamf) messages does NOT constitute a source-file edit.
- Same for writing to .aiassistant/temp to create temporary files during evaluation (e.g. a temporary test file)
- BOTH are explicitly ALLOWED also in PLANNING-ONLY mode.

### 2.1 Rule Map & Canonical Owners (STRICT)

| Need                                                                                      | Canonical location              |
|-------------------------------------------------------------------------------------------|---------------------------------|
| Gate lifecycle, pause behavior, mixed-signal gate handling, `ERR` recovery, gate template | `.aiassistant/shared/GATE_WORKFLOW.md` |
| Commit gate and `EXEC+` variants in commit context, commit message format, commit tooling | `.aiassistant/shared/COMMIT.md`        |
| Test depth and commands                                                                   | `.aiassistant/shared/lang/<language>/TESTING.md`       |
| Quality pass over changed source files, of any language                                   | `.aiassistant/shared/QUALITY.md` |
| Lint/style commands and policy                                                            | `.aiassistant/shared/lang/<language>/LINTING.md`       |
| Document governance rules and canonical history ownership                                 | `.aiassistant/shared/GOVERNANCE.md`     |
| Agent-host command/tool-name conventions (Windows-hosted vs WSL-based, per-agent specifics) | `.aiassistant/shared/ENVIRONMENTS.md`  |
| AP requirements (`MUST`)                                                                  | this document §1.2 and §5      |

If overlap exists, follow the canonical owner document for that rule family.

## 3. Task Interpretation & Keywords

- **`ASK`** – Standalone: Send `<answer>` in [chat] mode. Combined with other keywords: include answer in new gate
  message (if task-relevant) or with the result of the gated action.
- **`PLAN`** – Produce/update AP, present it, send gate message.
- **`EXEC`** – Execute gated action(s). For `EXEC+`-family see [`.aiassistant/shared/COMMIT.md`](.aiassistant/shared/COMMIT.md).
- **`ROLLBACK`** – Authorize an `undo_edit` action (see [§1.4](#14-undo_edit-authorization-rollback)).
- **`ERR`** – Apply recovery protocol (detailed in `.aiassistant/shared/GATE_WORKFLOW.md`).
- **`UAMF`** – Instructs agent to write a [UAMF](#4-useraccessible-message-files-uamf) message file.

Latest `<issue_update>` overrides earlier `<issue_description>`.

### 3.1 First Response Contract

- Non-trivial `[CODE]` task without inline `EXEC`: produce AP, persist AP UAMF, send gate.
- If the same user message includes clear execution authorization (`EXEC` family): execute only the authorized scope.
- Before `git commit`: always send a commit gate with full proposed commit message.

## 4. User‑Accessible Message Files (UAMF)

- Store user‑visible artifacts in `/.aiassistant/messages/` with a timestamp prefix `YYYY-MM-DD_HH-NN_`.
- Never overwrite existing files; create new ones.
- This includes Action Plan iterations (see [§5.3](#53-persistence-uamf)).

## 5. Action Plan (AP)

### 5.1 Format & Versioning

- Title: `AP {topic} v{Major}.{Minor}: {2-5 word description}`
    - `{topic}` is a 1-3 word PascalCase slug describing the AP's subject (e.g. `Bild`, `Mock`, `DecisionEngine`, `ECSFixers`).
    - It is **not** a workflow signal — `PLAN`, `EXEC`, `ASK`, `ROLLBACK`, and `ERR` are user-facing keywords from §3, not AP title components.
- Examples: `AP Bild v1.0: Extract decision functions`, `AP FilterFix v1.0: Fix type validation`.
- Increment version on every update.
- Retain cumulative `Change History` within the AP document (append‑only).

### 5.2 Required Sections

- **Discussion** (if any)
- **Analysis**
- **Implementation Plan** (step‑by‑step; include a **Verification** checkpoint after each logical block)
- **Proposed commit message** (for changes since the session start or last commit)
- **Change History** (all previous version entries)

### 5.3 Persistence (UAMF)

- Write each AP iteration as a [UAMF](#4-useraccessible-message-files-uamf) before any modifying project files.

## 6. Commit Policy (STRICT)

- Summary MUST start with one of: `[TASK]`, `[FIX]`, `[SECURITY]`, `[CLEANUP]`, `[WIP]`, `[UPDATE]`, `[RELEASE]`.
- Empty second line.
- Detailed body explaining what and why.
- One functional change per commit.
- Commits touching shipped app code MUST add a `CHANGELOG.md` `[Unreleased]` entry; releases are cut via a
  dedicated `[RELEASE]` commit. See `.aiassistant/shared/COMMIT.md` §4.3–4.4.
- **Prefer using commit tools** documented in `.aiassistant/shared/COMMIT.md`.

## 7. Additional References

Paths below use two placeholders, substituted when this document is inlined
into a project's `AGENTS.md`: `.aiassistant` is the project's agent directory and
`.aiassistant/shared` is the shared guidelines submodule. Their values come from
`.aiassistant/.env`.

- `.aiassistant/PROJECT.md` – project facts: paths, versions, exact test and lint commands.
- `.aiassistant/shared/COMMIT.md` – commit workflow, gating, message and changelog policy.
- `.aiassistant/shared/GATE_WORKFLOW.md` – gate lifecycle and `ERR` recovery protocol.
- `.aiassistant/shared/ENVIRONMENTS.md` – agent-host command and tool-name conventions.
- `.aiassistant/shared/GOVERNANCE.md` – document versioning and history rules.
- `.aiassistant/shared/QUALITY.md` – the quality pass to run over every changed source file.
- `.aiassistant/shared/lang/<language>/TESTING.md` and `LINTING.md` – language baselines.
- `.aiassistant/shared/tools/README.md`, `.aiassistant/shared/tools/RUNTIME_TOOLS.md` – helper scripts and runtime tools.
- `.aiassistant/CI.md` – CI conventions, if the project has any.

## 8. Document Governance

- Version updated on every change (SemVer).
- Full document history is maintained in `.aiassistant/shared/GOVERNANCE.md`.
- Drift-check: when a specialized rule document changes ownership semantics, update §2.1 in the same change.
- In `8.1 Current version`, keep only the latest row; move older entries to `.aiassistant/shared/GOVERNANCE.md`, section 2.1.

### 8.1 Current version

| Version | Date       | Changed Sections | Change Type | Agent Impact                                    |
|---------|------------|------------------|-------------|-------------------------------------------------|
| v2.6.0  | 2026-08-24 | 2.1, 7           | minor       | Added `.aiassistant/ENVIRONMENTS.md` as the canonical owner for agent-host command/tool-name conventions (§2.1); referenced it in §7. |

---

# Project Guidelines Entry Point (v1.0.0)

This file is the project-specific entry point for agent-facing guidance in
**File Checksum Index & Search** (FCIAS) — a Nextcloud app that indexes file
checksums and makes them searchable.

## 1. Project Facts

| Fact                  | Value |
|-----------------------|-------|
| Project name          | `metaworx/file_checksum_search`, Nextcloud app id `file_checksum_search` |
| Repository root       | `/home/mdr/projects/nc_file_checksum_search` (WSL). Windows agents prefix commands per `.aiassistant/shared/ENVIRONMENTS.md` §1. |
| Language(s)           | PHP (backend), TypeScript + Vue (frontend), SCSS/CSS, YAML (CI) |
| Source directories    | `lib/` (PSR-4 `OCA\FileChecksumSearch\`), `src/` (frontend), `tests/` (PSR-4 `OCA\FileChecksumSearch\Tests\`), `appinfo/`, `templates/` |
| Shipped-code paths    | `lib/`, `src/`, `css/`, `js/`, `templates/`, `img/`, `appinfo/routes.php`, `appinfo/info.xml` |
| Version manifest      | `appinfo/info.xml` (`<version>`, currently `0.19.0`) |
| Test gate command     | `composer test` (unit + integration); individually `composer test:unit`, `composer test:integration`, `vendor/bin/phpunit -c tests/phpunit.xml`; frontend `npm test` (Vitest) |
| Lint command          | `composer cs:check` / `composer cs:fix` (php-cs-fixer), `composer psalm`, `composer rector`; frontend `npm run lint` and `npm run stylelint` |

## 2. Primary References

- `.aiassistant/shared/GUIDELINES.md` - runtime behavior contract, gating flow, action-plan workflow.
- `.aiassistant/shared/QUALITY.md` - the quality pass; this project is multi-language,
  so it applies to `.vue`, `.ts`, `.scss` and `.yaml` files as much as to `.php` ones.
- `.aiassistant/shared/lang/php/TESTING.md`, `.aiassistant/shared/lang/ts/TESTING.md`
  and the matching `LINTING.md` files - language baselines; the facts table above
  overrides their example commands.
- `.aiassistant/shared/COMMIT.md` - commit workflow, gating, and the `CHANGELOG.md`
  rules that apply here (see §3.3).
- `.aiassistant/shared/ENVIRONMENTS.md` - host/agent command conventions.
- `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `README.md` - contributor documentation.

## 3. Project-Specific Conventions

### 3.1 Nextcloud version matrix

The app is developed against more than one Nextcloud release; local source trees
(`nextcloud-v33`, `nextcloud-v34`) are linked so cross-version symbol resolution
works. The IDE therefore indexes every OCP symbol twice and reports
`Multiple definitions exist for class '...'` in bulk. This is expected — see
`.aiassistant/shared/QUALITY.md` §6: filter the message when triaging, and
never exclude a tree to silence it.

### 3.2 Frontend and backend are one deliverable

A change to `src/` usually needs a rebuilt bundle in `js/` before it is visible in
the app, and both count as shipped code. Run the quality pass and the tests for
**both** sides when a change spans them.

### 3.3 CHANGELOG.md is mandatory here

This project keeps a Keep-a-Changelog `CHANGELOG.md` and cuts releases with
`[RELEASE]` commits that bump `appinfo/info.xml`. The rules in
`.aiassistant/shared/COMMIT.md` §4.3 and §4.4 apply in full: any commit
touching the shipped-code paths above adds or amends a bullet under
`## [Unreleased]` in the same commit.

### 3.4 Roo Code prompts

`.roo/commands/` and `.roo/roo-code-settings.json` are tracked here because Roo
reads them from the project root. The shared repository keeps reference copies
under `.aiassistant/shared/assistants/roo/`; when a prompt changes here and
the change is not project-specific, port it there as well.

## 4. Document Governance

- This document follows the shared governance rules in `.aiassistant/shared/GOVERNANCE.md`.

## 5. Version History

| Version | Date       | Changed sections | Change type | Agent impact |
|---------|------------|------------------|-------------|--------------|
| v1.0.0  | 2026-08-25 | All              | major       | Replaces this project's own copies of the agent documents with the shared submodule plus these project facts. The documents removed here (AGENTS.md v2.6.0, ENVIRONMENTS.md, the testing and linting baselines) were the newest lineage in the set and are preserved in the shared repository's history. |
