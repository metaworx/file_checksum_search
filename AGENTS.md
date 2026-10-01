<!-- GENERATED FILE - DO NOT EDIT.
     Source: shared/_AGENTS.md + project/_CONTRACT.md
     Regenerate: GUIDELINES/shared/tools/sync-docs.sh
     Contract version: v3.15.0 -->


# AI Agent Guidelines (v3.15.0)

Core behavioral rules for AI agents working on this codebase.  
All agents MUST comply.
This document is intentionally concise;
refer to linked documents for extended guidance.

## Contents

<!-- BEGIN GENERATED CONTENTS - do not edit; run "GUIDELINES/shared/tools/sync-docs.sh" -->
- AI Agent Guidelines (v3.15.0)
  1. Critical Behavioral Rules (STRICT)
    - 1.1 Gate Message Mechanism
    - 1.2 Action Plan (AP) Requirement
    - 1.3 Commit Confirmation Gate
    - 1.4 `undo_edit` Authorization (`ROLLBACK`)
    - 1.5 Safe File Edits
  2. Instruction Precedence (STRICT)
    - 2.1 Rule Map & Canonical Owners (STRICT)
  3. Task Interpretation & Keywords
    - 3.1 First Response Contract
  4. User-Accessible Message Files (UAMF)
  5. Action Plan (AP)
    - 5.1 Format & Versioning
    - 5.2 Required Sections
    - 5.3 Persistence (UAMF)
    - 5.4 Notes
  6. Commit Policy (STRICT)
  7. Additional References
  8. Document Governance
- File Checksum Index & Search — Project Contract (v2.14.0)
  1. Project Facts
  2. Primary References
  3. Project-Specific Conventions
    - 3.1 Nextcloud version matrix
    - 3.2 Frontend and backend are one deliverable
    - 3.3 CHANGELOG.md is mandatory here
    - 3.4 Roo Code prompts
    - 3.5 The harness that tests this app is a separate repository
    - 3.6 Frontend specs mount the real Nextcloud components
    - 3.7 Every user-facing text is translatable
  4. Document Governance
  5. Version History
<!-- END GENERATED CONTENTS -->

## 1. Critical Behavioral Rules (STRICT)

### 1.1 Gate Message Mechanism

- After sending a gate message, the agent MUST **stop** — no further actions,
  shell commands,
  or status updates are permitted until the user responds with a new `<issue_update>`.
- What a gate message is, and the Pause Latch and No-Input rules,
  are `GUIDELINES/shared/GATE_WORKFLOW.md` §3.
- The gate message MUST follow the **Universal Gate Template**
  (`GUIDELINES/shared/GATE_WORKFLOW.md` §4), which owns its fields.
- The gate MUST state, in the user's terms,
  **what each signal it offers will do**.
  A signal the user cannot predict the effect of is not consent.
  Which signals a gate offers, `EXEC+` and variants of the gate's own included,
  is `GUIDELINES/shared/GATE_WORKFLOW.md` §6.
- The signals are listed as text, at the end of the gate message itself.
  A runtime that can end a turn only through a tool call, as Roo's can,
  carries a short label there and nothing more:
  the gate is the text, and the tool is not where the choice is explained.
- A gate message is the **only** valid way to request user confirmation.
  Echoing "waiting for input" via shell commands is a **violation** of this rule.
- A visual workflow diagram is available in `GUIDELINES/shared/GATE_WORKFLOW.md`.

### 1.2 Action Plan (AP) Requirement

- Every non‑trivial `[CODE]` task requires an Action Plan.
- Full AP format and versioning rules are defined in [§5](#5-action-plan-ap).
- Each AP revision MUST be persisted as a
  [UAMF](#4-user-accessible-message-files-uamf) **before** any project writes.
- Unless the execution is directly authorized by an `EXEC`‑family keyword in the
  same user message,
  the AP MUST be presented as a **gate message** (see [§1.1](#11-gate-message-mechanism)).

### 1.3 Commit Confirmation Gate

- Before `git commit`, the agent MUST present a commit gate.
  [`GUIDELINES/shared/COMMIT.md`](GUIDELINES/shared/COMMIT.md) §7.1 states what it
  must contain, and §7.2 the commands that follow it.

### 1.4 `undo_edit` Authorization (`ROLLBACK`)

- A rollback action (calling `undo_edit`) requires explicit user authorization.
  `GUIDELINES/shared/GATE_WORKFLOW.md` §7.3 states what authorizes it and what the
  gate must say where authorization is absent.

### 1.5 Safe File Edits

- **Do not delete and recreate files** when making large changes.
  Instead, write the new content to a temporary file and atomically replace the
  original (`mv` on Linux/macOS, `Move-Item` on Windows).
  This preserves local IDE history.
- Temporary files SHOULD be placed in `GUIDELINES/temp/`,
  which is scratch: it may be deleted wholesale at any time.
  User-visible artifacts belong in `GUIDELINES/messages/`,
  which is durable and never purged.
  Why the two are siblings is in `GUIDELINES/README.md`.
- Always prefer history‑preserving edit tools (e.g., `apply_patch`,
  in‑place edits) over raw shell writes.

## 2. Instruction Precedence (STRICT)

1. Runtime/system rules (conflicts noted explicitly).
2. Direct user instruction (current session).
3. This document (the shared agent contract).
4. Local conventions

**IMPORTANT:**

- Writing *new* [UAMF](#4-user-accessible-message-files-uamf) messages does NOT
  constitute a source-file edit.
- Same for writing to .aiassistant/temp to create temporary files during
  evaluation (e.g. a temporary test file)
- BOTH are explicitly ALLOWED also in PLANNING-ONLY mode.

### 2.1 Rule Map & Canonical Owners (STRICT)

| Need                                                                                      | Canonical location              |
|-------------------------------------------------------------------------------------------|---------------------------------|
| Gate lifecycle, execution signals, pause behavior, mixed-signal gate handling, `ERR` recovery, the Universal Gate Template | `GUIDELINES/shared/GATE_WORKFLOW.md` |
| Commit gate, commit message format and shape, changelog obligation, commit tooling | `GUIDELINES/shared/COMMIT.md`        |
| Test depth and commands                                                                   | `GUIDELINES/shared/lang/<language>/TESTING.md`       |
| Quality pass over changed source files, of any language                                   | `GUIDELINES/shared/QUALITY.md` |
| Lint/style commands and policy                                                            | `GUIDELINES/shared/lang/<language>/LINTING.md`       |
| Document governance rules and canonical history ownership                                 | `GUIDELINES/shared/GOVERNANCE.md`     |
| Agent-host command/tool-name conventions (Windows-hosted vs WSL-based, per-agent specifics) | `GUIDELINES/shared/ENVIRONMENTS.md`  |
| CI conventions: what the pipeline runs, in what order, and why                             | `GUIDELINES/project/CI.md` |
| AP requirements (`MUST`)                                                                  | this document §1.2 and §5      |

If overlap exists, follow the canonical owner document for that rule family.

## 3. Task Interpretation & Keywords

- **`ASK`** – Standalone: Send `<answer>` in [chat] mode.
  Combined with other keywords:
  include answer in new gate message (if task-relevant) or with the result of
  the gated action.
- **`PLAN`** – Produce/update AP, present it, send gate message.
- **`EXEC`** – Execute gated action(s).
  The signals and their scope are `GUIDELINES/shared/GATE_WORKFLOW.md` §6.
- **`ROLLBACK`** – Authorize an `undo_edit` action (see
  [§1.4](#14-undo_edit-authorization-rollback)).
- **`ERR`** – Apply recovery protocol (detailed in `GUIDELINES/shared/GATE_WORKFLOW.md`).
- **`UAMF`** – Instructs agent to write a
  [UAMF](#4-user-accessible-message-files-uamf) message file.

Latest `<issue_update>` overrides earlier `<issue_description>`.

### 3.1 First Response Contract

- Non-trivial `[CODE]` task without inline `EXEC`: produce AP, persist AP UAMF,
  send gate.
- If the same user message includes clear execution authorization (`EXEC` family):
  execute only the authorized scope.
- Before `git commit`: always send a commit gate with full proposed commit message.

## 4. User-Accessible Message Files (UAMF)

A UAMF is a stored artefact, written for the user to read,
that follows strict rules:

- Its name begins with a timestamp, `YYYY-MM-DD_HH-NN_`,
  then describes its subject.
- It is **never overwritten**.
  A revision is a new file carrying a new version in its name;
  the previous one stays where it is.
- Within the agent turn that writes it, the agent MAY still edit it:
  to fill in a copy of the previous revision it started from,
  or to correct a mistake it notices after writing.
  That ends once work based on it has started,
  or it has been presented to the user.

Where a file is written depends on what it is and how long it needs to live:

| Directory | Tracked | For |
|-----------|---------|-----|
| `GUIDELINES/messages/` | no  | the default: analyses, findings and records written for the user |
| `GUIDELINES/wip/`      | yes | the Action Plan driving the change in progress, and any analysis that change depends on |
| `GUIDELINES/temp/`     | no  | scratch that is not a UAMF at all |

In an overlay - a guidelines root that is a repository of its own,
in a project that does not carry the guidelines -
tracked means tracked by the overlay,
and a plan is committed there (`GUIDELINES/shared/COMMIT.md` §7.3).

Citation rules follow from that, and they are absolute:

- **`messages/` is never cited.**
  Not from a document, not from a commit message, not from another UAMF.
  It is untracked: it exists in one working copy and nobody else can follow the reference.
- **`wip/` may be cited from another `wip/` document** — an Action Plan naming
  the analysis it rests on — **and from a commit message**,
  which is immutable and dated,
  so it names something that existed then and that git can still produce.
- **Nothing that outlives the change may cite either.**
  If a conclusion is worth citing from a contract,
  a shared document or a README, it belongs *in* that document.

## 5. Action Plan (AP)

### 5.1 Format & Versioning

- Title: `AP {topic} v{Major}.{Minor}: {2-5 word description}`
    - `{topic}` is a 1-3 word PascalCase slug describing the AP's subject (e.g. `Bild`,
      `Mock`, `DecisionEngine`, `ECSFixers`).
    - It is **not** a workflow signal — `PLAN`, `EXEC`, `ASK`, `ROLLBACK`,
      and `ERR` are user-facing keywords from §3, not AP title components.
- Examples: `AP Bild v1.0: Extract decision functions`,
  `AP FilterFix v1.0: Fix type validation`.
- Increment the version on every revision.
  A NOTE (§5.4) is not a revision, and leaves the version as it is.
- Retain cumulative `Change History` within the AP document (append‑only).

### 5.2 Required Sections

- **Discussion** (if any)
- **Analysis**
- **Implementation Plan** (step‑by‑step;
  include a **Verification** checkpoint after each logical block)
- **Proposed commit message** (for changes since the session start or last commit)
- **Change History** (all previous version entries)

### 5.3 Persistence (UAMF)

- Write each AP revision as a [UAMF](#4-user-accessible-message-files-uamf) in
  `GUIDELINES/wip/` before modifying any project files,
  and commit it before any commit names a block of it.
  A plan that exists only in one working copy cannot be reviewed,
  and a commit that cites it would be citing nothing.
- Registering, noting and retiring an AP - which commit carries each,
  when a plan may retire, what stays reachable afterwards,
  and the MUST NOT retire on the agent's own judgement -
  is `GUIDELINES/shared/COMMIT.md` §6.

### 5.4 Notes

A NOTE records a small change or a decision beside its plan,
without a new revision.

- Its **name** is `YYYY-MM-DD_HH-NN_NOTE_<Name>_v<X.Y>_<slug>.md`,
  in `GUIDELINES/wip/` beside the plan,
  where `<Name>` and `<X.Y>` are those of the plan revision it amends.
- Its **title** is `NOTE <Name> v<X.Y>: <slug words>`.
- It is **for** a decision, a measured fact,
  or a change to a step while the plan's blocks stand.
  Where the blocks change, it SHOULD be a new revision instead.
- It is a **UAMF** like any other, never overwritten,
  so a correction is another NOTE.
- Its **record** is a `File-revision-v1` trailer in the commit that adds it,
  carrying the version of the plan revision it amends, which its title names.
- It **stays** until its plan retires,
  which takes every revision and every NOTE of the plan together.
  A later revision neither replaces it nor folds it in.

## 6. Commit Policy (STRICT)

The rules are [`GUIDELINES/shared/COMMIT.md`](GUIDELINES/shared/COMMIT.md),
and an agent committing here is held to all of them:
§2 one functional change with its tests, §3 the message and its shape,
§4 the rules a tag carries, §5 the `CHANGELOG.md` obligation, §7 the gate,
the signals and the commands.

## 7. Additional References

Paths below use two placeholders,
substituted when this document is inlined into a project's `AGENTS.md`:
`GUIDELINES` is the project's agent directory and `GUIDELINES/shared` is
the shared guidelines submodule.
Their values come from `GUIDELINES/config.ini`.

- **This document, below** – project facts: paths, versions,
  exact test and lint commands.
  They are inlined here from the project's `project/_CONTRACT.md`.
- `GUIDELINES/shared/COMMIT.md` – commit workflow, gating,
  message and changelog policy.
- `GUIDELINES/shared/GATE_WORKFLOW.md` – gate lifecycle and `ERR` recovery protocol.
- `GUIDELINES/shared/ENVIRONMENTS.md` – agent-host command and tool-name conventions.
- `GUIDELINES/shared/GOVERNANCE.md` – document versioning and history rules.
- `GUIDELINES/shared/QUALITY.md` – the quality pass to run over every changed source file.
- `GUIDELINES/shared/lang/<language>/TESTING.md`, `LINTING.md` and,
  where the language has one, `CLI.md` – language baselines.
- `GUIDELINES/shared/tools/README.md`,
  `GUIDELINES/shared/tools/RUNTIME_TOOLS.md` – helper scripts and runtime tools.
- `GUIDELINES/project/CI.md` – CI conventions, if the project has any.

## 8. Document Governance

- Version updated on every change (SemVer).
- History follows `GUIDELINES/shared/GOVERNANCE.md` §1.2: one line per version,
  in the commit that made it.
- Drift-check: when a specialized rule document changes ownership semantics,
  update §2.1 in the same change.

---


# File Checksum Index & Search — Project Contract (v2.14.0)

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
| Language(s)           | declared as `project.languages` in `GUIDELINES/config.ini`, kept honest by `GUIDELINES/shared/tools/detect-languages.sh --check`. PHP backend, TypeScript and Vue frontend, SCSS/CSS, YAML for CI, Python for tooling (`scripts/l10n.py`, `tests/e2e/store/appstore.py`) — of which Vue and YAML have no shared baseline, so they are not declared. |
| Source directories    | `lib/` (PSR-4 `OCA\FileChecksumSearch\`), `src/` (frontend), `tests/` (PSR-4 `OCA\FileChecksumSearch\Tests\`), `appinfo/`, `templates/` |
| Shipped-code paths    | `lib/`, `src/`, `css/`, `js/`, `l10n/`, `templates/`, `img/`, `appinfo/routes.php`, `appinfo/info.xml` |
| Version manifest      | `appinfo/info.xml`: the `manifest` of `CHANGELOG.md` in `GUIDELINES/config.ini`, so `changelog.sh cut` sets its `<version>` and `check` holds it to the newest section; its `<screenshot>` URLs sit in a `RELEASE-PIN` block the same cut moves |
| Test gate command     | `GUIDELINES/shared/lang/php/tools/phpunit` (the shipped wrapper; `composer test` inside the container or CI); this checkout has no `.ddev/`, so the wrapper needs `MWX_PHPUNIT_DDEV_DIR` set to the harness instance's directory (§3.5) and `MWX_PHPUNIT_MOUNT=/var/www/html/apps/file_checksum_search`, whose values this machine keeps in `GUIDELINES/config.local.ini` under `[phpunit]`; `--testsuite unit` or `integration` for one of them; frontend `npm test` (Vitest) |
| Lint command          | `composer cs:check` / `composer cs:fix` (ECS with `mwx/coding-standard`, configured in `ecs.php` over `lib/`, `tests/`, `appinfo/` and `templates/`), `composer psalm` (Psalm in `vendor-bin/psalm`, over `lib/` at level 4 with no baseline: `psalm.xml` suppresses by name only the non-public server classes the app uses, with its reasons); CI's Lint job runs ECS and Psalm. Rector is not a lint command but the upgrade tool: `composer rector:check` shows and `composer rector` applies Nextcloud's own rules for the oldest supported version (`rector.php`), in the harness container, where the server's classes are; frontend `npm run lint`, `npm run stylelint` and `npm run typecheck` (`vue-tsc`, which checks `.vue` files as well as `.ts`; the build strips types without checking them); the manifest `xmllint --noout --schema info.xsd appinfo/info.xml`, with `info.xsd` fetched from `https://raw.githubusercontent.com/nextcloud/appstore/master/nextcloudappstore/api/v1/release/info.xsd`, which is what the app store runs at upload and CI runs first |
| Translation command   | `scripts/l10n.sh` (the door to `scripts/l10n.py`; needs PHP and gettext): `update` after a wrapped text changes, `add <lang>` to start a language, `build` after a `.po` file changes; `check` fails when the template, a `.po` file or `l10n/` is out of date, or a language kept here misses a translation. The work is done by Nextcloud's own `translationtool.phar`, pinned by commit and SHA-256 in `scripts/l10n.py` (§3.7) |

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

### 3.6 Frontend specs mount the real Nextcloud components

A Vitest spec under `src/` mounts `@nextcloud/vue`'s components as they are,
never a stand-in written for the spec: the runner inlines the library and
defines what it needs (`vitest.config.ts`, `vitest.setup.ts`), and
`src/test-utils/` drives the controls that open menus — `NcSelect` and
`NcActions` — the way a person does. `.eslintrc.cjs` refuses
`vi.mock('@nextcloud/vue/…')` in a spec. A mock of the server or of the page
(`@nextcloud/router`, `@nextcloud/axios`, `@nextcloud/l10n`, the app's own
modules) is the spec's to write, and spreads the original where it names
only part of a module.

### 3.7 Every user-facing text is translatable

A text a person reads in the app — in a template, a toast, a label, a server
message the interface shows — goes through
`t('file_checksum_search', '…')` or `n('file_checksum_search', '…', '…', count)`,
and in PHP through `IL10N`. Nextcloud's translation tool finds only what these
calls spell out:

- the text is a literal; in a Vue template, on one line or as a template
  literal without `${}`, one call to a line, and no quoted text among its
  values: the tool reads a template with a pattern that runs to the line's
  last quote, so a longer text, a choice between texts or a value with
  quotes goes into the script;
- a value is a placeholder (`t('file_checksum_search', 'Band {band}', { band })`),
  never a concatenation of translated pieces;
- a count takes `n()`, and in a template reaches it through a computed value,
  since the tool does not read `n()` there;
- a text kept in a constant is translated where it is read, not when the
  module loads;
- a short or ambiguous text gets a `TRANSLATORS` comment, which the tool
  hands to translators.

In the frontend, `t` and `n` come from `src/l10n.ts`: the same signatures as
`@nextcloud/l10n`'s, but they leave escaping to Vue, since the library's
would show in a text as `&amp;`. A translated text therefore never goes into
`v-html`, and a sentence carries no markup: emphasis wraps a whole sentence,
and a name inside one is quoted.

A server message is translated where it is made, through `IL10N`, as
Nextcloud core does: in the user's language on the web, and on the command
line, which has neither a user nor a request, in the server's default
language. A command's own output and every log stay English; where a message
is both shown and logged, the log gets the English — an `OCP\HintException`'s
message, a result's `reason` — and the person the translation. PHP's
placeholders are `IL10N`'s, `%s` and `%1$s`.

A change to a wrapped text runs
`scripts/l10n.sh update`; every language kept in `translationfiles/<lang>/` stays
complete, and `l10n/` is built from them, never edited by hand. A
language's terms and registers are in `translationfiles/<lang>/GLOSSARY.md`,
which a new text follows.

`appinfo/info.xml` carries its summary and description in each language the
app store knows (`lang="de"`); the tool then leaves both out of the template,
so `check` cannot see them, and a change to the English changes every other
language's in the same commit.

Two checks hold this: `.eslintrc.cjs` fails a bare text in a template
(`vue/no-bare-strings-in-template`, the components' text attributes included)
and a literal handed straight to a toast, and CI's lint job runs
`scripts/l10n.sh check`.

## 4. Document Governance

- This document follows the shared governance rules in `GUIDELINES/shared/GOVERNANCE.md`.

## 5. Version History

| Version | Date       | Changed sections | Change type | Agent impact |
|---------|------------|------------------|-------------|--------------|
| v2.14.0 | 2026-10-01 | 1                | minor       | The lint row: Rector runs, as the upgrade tool rather than a lint command — `composer rector:check` and `composer rector`, with Nextcloud's rules for the oldest supported version, in the harness container. |
| v2.13.0 | 2026-10-01 | 1                | minor       | The lint row no longer names `composer rector`, which has never run: `rector.php` does not load on Rector 2. |
| v2.12.0 | 2026-10-01 | 1                | minor       | The lint row: `composer psalm` runs, at level 4 with no baseline, and CI's Lint job runs it and ECS. A Psalm finding is fixed or, for a non-public server class, suppressed by name in `psalm.xml`. |
| v2.11.0 | 2026-09-30 | 1                | minor       | The lint row names `npm run typecheck`: `vue-tsc` checks the frontend's types, `.vue` files included, which nothing did before. |
| v2.10.0 | 2026-09-28 | 3                | minor       | §3.7: a language's terms are in its `GLOSSARY.md`; `info.xml`'s summary and description are translated in the manifest itself, outside what `check` sees, so they change together. |
| v2.9.0  | 2026-09-28 | 3                | minor       | §3.7 names the checks that hold it: the lint fails a bare text in a template and a literal handed to a toast, and CI runs `scripts/l10n.sh check`. |
| v2.8.0  | 2026-09-28 | 3                | minor       | §3.7: a server message is translated where it is made, as Nextcloud core does, so the command line shows a service's message in the server's default language; a command's own output and every log stay English, the log of a message that is also shown getting the English. |
| v2.7.0  | 2026-09-28 | 3                | minor       | §3.7: in a Vue template, one `t()` to a line with no quoted text among its values, which is what the tool's pattern can read; the frontend's `t`/`n` come from `src/l10n.ts`, which leaves escaping to Vue, so no translated text goes into `v-html` and a sentence carries no markup. |
| v2.6.0  | 2026-09-28 | 1, 3             | minor       | `l10n/` is shipped code; the translation command is `scripts/l10n.sh`, running Nextcloud's own tool, pinned; Python, the tooling's language, is declared. §3.7: every user-facing text goes through `t()`/`n()` or `IL10N` in the form the tool can read, and every language kept here stays complete. |
| v2.5.0  | 2026-09-25 | 1                | minor       | The PHP style is the project's own: ECS with `mwx/coding-standard` replaces the Nextcloud php-cs-fixer config, which had never been installed and would have reformatted the tree to a style it does not use. The test gate is the shipped phpunit wrapper, with the two variables that point it at the harness instance. |
| v2.4.0  | 2026-09-24 | 1                | minor       | The lint row names the manifest check: `xmllint` against the app store's published `info.xsd`, the validation the store runs at upload, run in both pipelines' test stage. |
| v2.3.0  | 2026-09-24 | 1                | minor       | The version manifest row: `appinfo/info.xml` is the changelog's `manifest` and its `<screenshot>` URLs a `RELEASE-PIN` block, both moved by `changelog.sh cut` (shared v4.23.0); the by-hand step of v2.2.0 is gone. |
| v2.2.0  | 2026-09-24 | 1                | minor       | The version manifest row names the release the `<screenshot>` URLs in `appinfo/info.xml` carry, rewritten by hand in the `[RELEASE]` commit beside `<version>`; the markdown documents carry theirs in `RELEASE-PIN` blocks that `changelog.sh cut` rewrites. |
| v2.1.0  | 2026-09-23 | 3                | minor       | §3.6: a frontend spec mounts the real Nextcloud components; the runner's config and `src/test-utils/` make that possible, and the lint rule keeps it so. Mocks of the server and the page stay the spec's. |
| v2.0.0  | 2026-08-27 | All              | major       | Becomes the fragment `project/_CONTRACT.md` under `GUIDELINES/`, inlined into the human-facing `GUIDELINES/README.md` as well as `AGENTS.md`. Placeholders move from the v2-era `{{.aiassistant_root}}` / `{{.aiassistant_shared}}`, which this document still carried and the generator had long stopped substituting, to `GUIDELINES` / `GUIDELINES/shared`. The contract is cited as `/AGENTS.md`; the languages row points at `project.languages` and says which of this project's languages have no shared baseline; the version manifest no longer names a version number that had gone stale; §3.5 names the harness repository. |
| v1.1.0  | 2026-08-25 | 1, 3             | minor       | Removes the absolute repository root; the root is derived and Windows hosts prefix with wsl --cd "$PWD". |
| v1.0.0  | 2026-08-25 | All              | major       | Replaces this project's own copies of the agent documents with the shared submodule plus these project facts. The documents removed here (AGENTS.md v2.6.0, ENVIRONMENTS.md, the testing and linting baselines) were the newest lineage in the set and are preserved in the shared repository's history. |
