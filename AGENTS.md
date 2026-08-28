<!-- GENERATED FILE - DO NOT EDIT.
     Source: shared/_AGENTS.md + project/_CONTRACT.md
     Regenerate: GUIDELINES/shared/tools/sync.sh
     Contract version: v3.8.1 -->


# AI Agent Guidelines (v3.8.1)

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
    - `Last Action` - optionally extended with bullets of what was implemented,
      changed or fixed
    - `Pending action`
    - `CHANGELOG bullet` - the bullet the change ships with, verbatim; `N/A` where it ships none
    - `Proposed commit message` - wherever a signal at this gate would commit; otherwise `N/A`
    - `Files to be committed` - conditional: the paths, wherever a signal at this gate would commit
    - `Important notes` - conditional: what the user needs in order to decide and would not otherwise
      see; omitted when there is nothing
    - `Confirmation needed: EXEC/EXEC+/ROLLBACK`
    - Additional information according to specialized gate message (e.g. AP or commit)
- The gate MUST state, in the user's terms, **what `EXEC` will do** and **what
  `EXEC+` will continue to** afterwards. A signal the user cannot predict the
  effect of is not consent.
- Where the runtime offers a structured question tool (see
  `GUIDELINES/shared/tools/RUNTIME_TOOLS.md`), the gate SHOULD present the
  available signals through it as selectable options, with the recommended one
  first. The text template still applies: the tool carries the choice, not the
  reasoning.
- A gate message is the **only** valid way to request user confirmation. Echoing "waiting for input" via shell commands
  is a **violation** of this rule.
- A visual workflow diagram is available in `GUIDELINES/shared/GATE_WORKFLOW.md`.

### 1.2 Action Plan (AP) Requirement

- Every non‑trivial `[CODE]` task requires an Action Plan.
- Full AP format and versioning rules are defined in [§5](#5-action-plan-ap).
- Each AP iteration MUST be persisted as a [UAMF](#4-useraccessible-message-files-uamf) **before** any project writes.
- Unless the execution is directly authorized by an `EXEC`‑family keyword in the same user message, the AP MUST be
  presented as a **gate message** (see [§1.1](#11-gate-message-mechanism)).

### 1.3 Commit Confirmation Gate

- Before `git commit`, the agent MUST present a gate message containing the complete proposed commit message.
- Commit‑related execution signals (`EXEC`, `EXEC+`) and commit tools are detailed in [
  `GUIDELINES/shared/COMMIT.md`](GUIDELINES/shared/COMMIT.md).
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
- Temporary files SHOULD be placed in `GUIDELINES/temp/`, which is scratch:
  it may be deleted wholesale at any time. User-visible artifacts belong in
  `GUIDELINES/messages/`, which is durable and never purged - the two are
  siblings so that reclaiming disk space cannot destroy the written record.
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
| Gate lifecycle, pause behavior, mixed-signal gate handling, `ERR` recovery, gate template | `GUIDELINES/shared/GATE_WORKFLOW.md` |
| Commit gate and `EXEC+` variants in commit context, commit message format, commit tooling | `GUIDELINES/shared/COMMIT.md`        |
| Test depth and commands                                                                   | `GUIDELINES/shared/lang/<language>/TESTING.md`       |
| Quality pass over changed source files, of any language                                   | `GUIDELINES/shared/QUALITY.md` |
| Lint/style commands and policy                                                            | `GUIDELINES/shared/lang/<language>/LINTING.md`       |
| Document governance rules and canonical history ownership                                 | `GUIDELINES/shared/GOVERNANCE.md`     |
| Agent-host command/tool-name conventions (Windows-hosted vs WSL-based, per-agent specifics) | `GUIDELINES/shared/ENVIRONMENTS.md`  |
| AP requirements (`MUST`)                                                                  | this document §1.2 and §5      |

If overlap exists, follow the canonical owner document for that rule family.

## 3. Task Interpretation & Keywords

- **`ASK`** – Standalone: Send `<answer>` in [chat] mode. Combined with other keywords: include answer in new gate
  message (if task-relevant) or with the result of the gated action.
- **`PLAN`** – Produce/update AP, present it, send gate message.
- **`EXEC`** – Execute gated action(s). For `EXEC+`-family see [`GUIDELINES/shared/COMMIT.md`](GUIDELINES/shared/COMMIT.md).
- **`ROLLBACK`** – Authorize an `undo_edit` action (see [§1.4](#14-undo_edit-authorization-rollback)).
- **`ERR`** – Apply recovery protocol (detailed in `GUIDELINES/shared/GATE_WORKFLOW.md`).
- **`UAMF`** – Instructs agent to write a [UAMF](#4-useraccessible-message-files-uamf) message file.

Latest `<issue_update>` overrides earlier `<issue_description>`.

### 3.1 First Response Contract

- Non-trivial `[CODE]` task without inline `EXEC`: produce AP, persist AP UAMF, send gate.
- If the same user message includes clear execution authorization (`EXEC` family): execute only the authorized scope.
- Before `git commit`: always send a commit gate with full proposed commit message.

## 4. User‑Accessible Message Files (UAMF)

A UAMF is a stored artefact, written for the user to read, that follows strict
rules:

- Its name begins with a timestamp, `YYYY-MM-DD_HH-NN_`, then describes its
  subject.
- It is **never overwritten**. A revision is a new file carrying a new version
  in its name; the previous one stays where it is.

Where it is stored depends on how long it needs to live:

| Directory | Tracked | For |
|-----------|---------|-----|
| `GUIDELINES/messages/` | no  | the default: analyses, findings and records written for the user |
| `GUIDELINES/wip/`      | yes | the Action Plan driving the change in progress, and any analysis that change depends on |
| `GUIDELINES/temp/`     | no  | scratch that is not a UAMF at all |

Citation rules follow from that, and they are absolute:

- **`messages/` is never cited.** Not from a document, not from a commit
  message, not from another UAMF. It is untracked: it exists in one working copy
  and nobody else can follow the reference.
- **`wip/` may be cited from another `wip/` document** — an Action Plan naming
  the analysis it rests on — **and from a commit message**, which is immutable
  and dated, so it names something that existed then and that git can still
  produce.
- **Nothing that outlives the change may cite either.** If a conclusion is worth
  citing from a contract, a shared document or a README, it belongs *in* that
  document.

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

- Write each AP iteration as a [UAMF](#4-useraccessible-message-files-uamf) in
  `GUIDELINES/wip/` before modifying any project files, and commit it
  with the work it drives. A plan that exists only in one working copy cannot be
  reviewed, and a commit that cites it would be citing nothing.
- An AP is **retired** — deleted — by the commit that completes it. It remains
  reachable in history: `git log --all --full-history -- <path>` finds it.
- An AP may span many commits, of any tag, interrupted by other work. Retirement
  follows completion, not the shape of the commit series.
- The agent MUST NOT retire an AP on its own judgement. When it believes one is
  complete it **asks**, in the commit gate, and the user decides.

## 6. Commit Policy (STRICT)

- Summary MUST start with one of: `[TASK]`, `[FIX]`, `[SECURITY]`, `[CLEANUP]`, `[WIP]`, `[UPDATE]`, `[RELEASE]`.
- Empty second line.
- Detailed body explaining what and why.
- One functional change per commit.
- Commits touching shipped app code MUST add a `CHANGELOG.md` `[Unreleased]` entry; releases are cut via a
  dedicated `[RELEASE]` commit. See `GUIDELINES/shared/COMMIT.md` §4.3–4.4.
- **Prefer using commit tools** documented in `GUIDELINES/shared/COMMIT.md`.

## 7. Additional References

Paths below use two placeholders, substituted when this document is inlined
into a project's `AGENTS.md`: `GUIDELINES` is the project's agent directory and
`GUIDELINES/shared` is the shared guidelines submodule. Their values come from
`GUIDELINES/config.ini`.

- **This document, below** – project facts: paths, versions, exact test and lint commands. They are inlined here from the project's `project/_CONTRACT.md`.
- `GUIDELINES/shared/COMMIT.md` – commit workflow, gating, message and changelog policy.
- `GUIDELINES/shared/GATE_WORKFLOW.md` – gate lifecycle and `ERR` recovery protocol.
- `GUIDELINES/shared/ENVIRONMENTS.md` – agent-host command and tool-name conventions.
- `GUIDELINES/shared/GOVERNANCE.md` – document versioning and history rules.
- `GUIDELINES/shared/QUALITY.md` – the quality pass to run over every changed source file.
- `GUIDELINES/shared/lang/<language>/TESTING.md` and `LINTING.md` – language baselines.
- `GUIDELINES/shared/tools/README.md`, `GUIDELINES/shared/tools/RUNTIME_TOOLS.md` – helper scripts and runtime tools.
- `GUIDELINES/project/CI.md` – CI conventions, if the project has any.

## 8. Document Governance

- Version updated on every change (SemVer).
- Full document history is maintained in `GUIDELINES/shared/GOVERNANCE.md`.
- Drift-check: when a specialized rule document changes ownership semantics, update §2.1 in the same change.
- In `8.1 Current version`, keep only the latest row; move older entries to `GUIDELINES/shared/GOVERNANCE.md`, section 2.1.

### 8.1 Current version

| Version | Date       | Changed Sections | Change Type | Agent Impact                                    |
|---------|------------|------------------|-------------|-------------------------------------------------|
| v3.8.1 | 2026-08-28 | 7, 8.1 | patch | Cites the generated document rather than the `project/_CONTRACT.md` fragment. A fragment is inlined into a generated document and is not somewhere a reader can be sent: the facts are already in `/AGENTS.md`, which an agent has loaded, and the fragment may not have been generated from yet. |

---


# File Checksum Index & Search — Project Contract (v2.0.0)

What binds work in this project, for everyone working on it. Inlined into
`/AGENTS.md` for agents and into `GUIDELINES/README.md` for people, so
that the two cannot drift apart.

A Nextcloud app that indexes file checksums and makes them searchable.

## 1. Project Facts

> No absolute paths here. An agent is already inside the checkout, so the
> repository root is `git rev-parse --show-toplevel`; Windows-hosted agents
> prefix with `wsl --cd "$PWD"` (see `GUIDELINES/shared/ENVIRONMENTS.md`).
> Machine-specific values belong in `GUIDELINES/config.local.ini`,
> which is not tracked.

| Fact                  | Value |
|-----------------------|-------|
| Project name          | `metaworx/file_checksum_search`, Nextcloud app id `file_checksum_search` |
| Language(s)           | declared as `project.languages` in `GUIDELINES/config.ini`, kept honest by `GUIDELINES/shared/tools/detect-languages.sh --check`. PHP backend, TypeScript and Vue frontend, SCSS/CSS, YAML for CI — of which Vue and YAML have no shared baseline, so they are not declared. |
| Source directories    | `lib/` (PSR-4 `OCA\FileChecksumSearch\`), `src/` (frontend), `tests/` (PSR-4 `OCA\FileChecksumSearch\Tests\`), `appinfo/`, `templates/` |
| Shipped-code paths    | `lib/`, `src/`, `css/`, `js/`, `templates/`, `img/`, `appinfo/routes.php`, `appinfo/info.xml` |
| Version manifest      | `appinfo/info.xml` (`<version>`) |
| Test gate command     | `composer test` (unit + integration); individually `composer test:unit`, `composer test:integration`, `vendor/bin/phpunit -c tests/phpunit.xml`; frontend `npm test` (Vitest) |
| Lint command          | `composer cs:check` / `composer cs:fix` (php-cs-fixer), `composer psalm`, `composer rector`; frontend `npm run lint` and `npm run stylelint` |

## 2. Primary References

- `/AGENTS.md` — runtime behavior contract, gating flow, action-plan workflow.
- `GUIDELINES/shared/QUALITY.md` — the quality pass; this project is multi-language,
  so it applies to `.vue`, `.ts`, `.scss` and `.yaml` files as much as to `.php` ones.
- `GUIDELINES/shared/lang/php/TESTING.md`, `GUIDELINES/shared/lang/ts/TESTING.md`
  and the matching `LINTING.md` files — language baselines; the facts table above
  overrides their example commands.
- `GUIDELINES/shared/COMMIT.md` — commit workflow, gating, and the `CHANGELOG.md`
  rules that apply here (see §3.3).

## 3. Project-Specific Conventions

### 3.1 Nextcloud version matrix

The app is developed against more than one Nextcloud release; local source trees
(`nextcloud-v33`, `nextcloud-v34`) are linked so cross-version symbol resolution
works. The IDE therefore indexes every OCP symbol twice and reports
`Multiple definitions exist for class '...'` in bulk. This is expected — see
`GUIDELINES/shared/QUALITY.md` §6: filter the message when triaging, and
never exclude a tree to silence it.

### 3.2 Frontend and backend are one deliverable

A change to `src/` usually needs a rebuilt bundle in `js/` before it is visible in
the app, and both count as shipped code. Run the quality pass and the tests for
**both** sides when a change spans them.

### 3.3 CHANGELOG.md is mandatory here

This project keeps a Keep-a-Changelog `CHANGELOG.md` and cuts releases with
`[RELEASE]` commits that bump `appinfo/info.xml`. The rules in
`GUIDELINES/shared/COMMIT.md` §4.3 and §4.4 apply in full: any commit
touching the shipped-code paths above adds or amends a bullet under
`## [Unreleased]` in the same commit.

### 3.4 Roo Code prompts

`.roo/commands/` and `.roo/roo-code-settings.json` are tracked here because Roo
reads them from the project root. The shared repository keeps reference copies
under `GUIDELINES/shared/assistants/roo/`; when a prompt changes here and
the change is not project-specific, port it there as well.

### 3.5 The harness that tests this app is a separate repository

`nextcloud_testing` spins up the Nextcloud versions above and mounts this app
into them. It has its own contract; a change to how instances are built belongs
there, not here.

## 4. Document Governance

- This document follows the shared governance rules in `GUIDELINES/shared/GOVERNANCE.md`.

## 5. Version History

| Version | Date       | Changed sections | Change type | Agent impact |
|---------|------------|------------------|-------------|--------------|
| v2.0.0  | 2026-08-27 | All              | major       | Becomes the fragment `project/_CONTRACT.md` under `GUIDELINES/`, inlined into the human-facing `GUIDELINES/README.md` as well as `AGENTS.md`. Placeholders move from the v2-era `{{.aiassistant_root}}` / `{{.aiassistant_shared}}`, which this document still carried and the generator had long stopped substituting, to `GUIDELINES` / `GUIDELINES/shared`. The contract is cited as `/AGENTS.md`; the languages row points at `project.languages` and says which of this project's languages have no shared baseline; the version manifest no longer names a version number that had gone stale; §3.5 names the harness repository. |
| v1.1.0  | 2026-08-25 | 1, 3             | minor       | Removes the absolute repository root; the root is derived and Windows hosts prefix with wsl --cd "$PWD". |
| v1.0.0  | 2026-08-25 | All              | major       | Replaces this project's own copies of the agent documents with the shared submodule plus these project facts. The documents removed here (AGENTS.md v2.6.0, ENVIRONMENTS.md, the testing and linting baselines) were the newest lineage in the set and are preserved in the shared repository's history. |
