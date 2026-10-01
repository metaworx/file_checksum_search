<!-- GENERATED FILE - DO NOT EDIT.
     Source: project/_README.md + project/_CONTRACT.md + shared/_README.md
     Regenerate: GUIDELINES/shared/tools/sync-docs.sh
     Contract version: v1.0.0 -->


# File Checksum Index & Search (v1.0.0)

This directory holds the conventions that work in this repository follows. They
are not AI configuration: they are how this app is written, and an agent is
simply another party required to follow them.

For what the app does and how to install it into a Nextcloud instance, see the
repository's own [`README.md`](../README.md).

Three things shape almost every change here:

- **Frontend and backend ship together.** A change under `src/` usually needs a
  rebuilt bundle in `js/` before anyone can see it, and both are shipped code.
- **The app is built against more than one Nextcloud release**, with both source
  trees linked, so the IDE reports `Multiple definitions exist for class '...'`
  in bulk. That is expected; never exclude a tree to silence it.
- **`CHANGELOG.md` is mandatory.** Any commit touching shipped code adds or
  amends a bullet under `## [Unreleased]`, in the same commit.

The environment that runs this app across those Nextcloud versions lives in a
separate repository, `nextcloud_testing`, with its own contract.

## Where the rules live

- The contract below is generated into `/AGENTS.md` as well, so people and
  agents are held to the same text.
- `GUIDELINES/shared` holds the shared metaworx conventions: commit policy, the
  quality pass, and the PHP and TypeScript testing and linting baselines.
- `GUIDELINES/shared/README.md` explains the gate protocol: what an agent asks
  before it changes anything, and what `EXEC` and its relatives authorise.

Both `/AGENTS.md` and this file are generated from the fragments in
`GUIDELINES`. Editing a generated file is wasted work — change the
fragment and re-run `GUIDELINES/shared/tools/sync.sh`.

## Version History

| Version | Date       | Changed sections | Change type | Agent impact |
|---------|------------|------------------|-------------|--------------|
| v1.0.0  | 2026-08-27 | All              | major       | First version. Opens the generated `GUIDELINES/README.md`, which had no human-facing half before v4. |

---


# File Checksum Index & Search — Project Contract (v2.13.0)

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
| Lint command          | `composer cs:check` / `composer cs:fix` (ECS with `mwx/coding-standard`, configured in `ecs.php` over `lib/`, `tests/`, `appinfo/` and `templates/`), `composer psalm` (Psalm in `vendor-bin/psalm`, over `lib/` at level 4 with no baseline: `psalm.xml` suppresses by name only the non-public server classes the app uses, with its reasons); CI's Lint job runs ECS and Psalm. Rector is not a lint command: `rector.php` came with the app skeleton and does not load; frontend `npm run lint`, `npm run stylelint` and `npm run typecheck` (`vue-tsc`, which checks `.vue` files as well as `.ts`; the build strips types without checking them); the manifest `xmllint --noout --schema info.xsd appinfo/info.xml`, with `info.xsd` fetched from `https://raw.githubusercontent.com/nextcloud/appstore/master/nextcloudappstore/api/v1/release/info.xsd`, which is what the app store runs at upload and CI runs first |
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

---


# Working with the agent (v1.7.0)

This is the human half of the contract.
The agent's rules live in `/AGENTS.md`;
this explains what the agent will do to you, what the words it sends you mean,
and what it needs back.

## 1. The short version

The agent does not change your repository and then tell you.
For anything non-trivial it **stops and asks first**,
in a fixed format called a *gate message*, and waits.
You reply with one word — usually `EXEC` — and it proceeds.

Two moments always stop:
before a non-trivial code change (you approve the plan) and before every
`git commit` (you approve the message).
Everything else is ordinary work.

## 2. Gate messages

A gate message is the agent's request for permission.
It always carries the same fields, so you can skim it:

- **Checkpoint** — that this is a stop, not a status update
- **Overall Task** — what you asked for, as the agent understood it
- **Last Action** — what it just did,
  sometimes as bullets where that was several things
- **Pending action** — exactly what it wants to do next
- **CHANGELOG bullet** — the line the change will add to the changelog
- **Proposed commit message** — the exact message,
  wherever a signal would commit
- **Files to be committed** — what would go into that commit
- **Important notes** — anything you need in order to decide and would not otherwise see:
  a bug fixed on the way, something to check before you answer,
  a step that went differently than planned
- **Confirmation needed** — which signal it is waiting for,
  and what each one does

After sending one the agent must be silent until you answer.
If you see it send a gate and then keep working,
that is a violation of its own contract, and worth saying so.

Read the **Pending action** first.
If it does not match what you wanted,
say so in plain words rather than sending a signal — the agent revises and re-gates.

## 3. Execution signals

One word, at the start or anywhere in your reply:

| Signal      | Means |
|-------------|-------|
| `EXEC`      | Do the gated action. Only that. |
| `EXEC+`     | Do it, then carry on with the next planned step. |
| `ROLLBACK`  | Undo the last edit. Required — the agent may not undo unasked. |
| `ERR`       | That went wrong; stop and diagnose before trying again. |
| `PLAN`      | Produce or revise the plan; do not execute yet. |
| `ASK`       | Answer my question; do not treat it as an instruction to act. |

The gate always tells you what `EXEC` will do and where `EXEC+` will go next.
If it doesn't, that is a defect in the gate, not something for you to infer.

`EXEC++` and `EXEC+++` are retired — say it in words instead.

You can also just answer in prose.
"Yes, but rename the second one first" is a perfectly good reply;
the signals exist for speed, not ceremony.

## 4. Action Plans

For anything beyond a trivial edit the agent writes an **Action Plan** before
touching code: what it found, what it intends to do step by step,
and the commit message it expects to propose.
You see a summary in the gate;
the full plan is written to a file (see §5) so it survives the conversation.

Plans are versioned — `AP Bild v1.2` — and each revision is a new file,
never an overwrite.
If you reject a plan, the next one is v1.3 and the reasoning that led there is
still on disk.

## 5. UAMF — where the agent's writing goes

A **User-Accessible Message File** is anything the agent wrote for you to read
rather than for the machine to run: action plans, analyses, migration notes.
They land in `GUIDELINES/messages/` with a timestamp in the name,
and they are **never overwritten** — a revision is always a new file.

This matters when a session ends badly.
The conversation may be gone; the reasoning is still there.

## 6. Recovering from a bad turn

Send `ERR`.
The agent stops and replies with a compact recovery block:
what it believes happened, what state things are in, and what it proposes.
Send `ERR` twice in a row and it escalates — it stops proposing and asks you to direct it.

`ROLLBACK` is separate and stronger: it authorises undoing the last edit.
The agent cannot do this on its own initiative, by design,
so if something needs reverting you have to say so.

## 7. What the agent must never do without you

- Commit — every commit is gated, with the full message shown first
- Undo your work — `ROLLBACK` only
- Overwrite a UAMF — revisions are new files
- Delete and recreate a file to make a large edit — it edits in place,
  so your IDE's local history survives
- Push, or anything else that leaves your machine, unless you asked

If any of these happens without your say-so, the agent has broken its contract.
Tell it; the rule it violated is in `/AGENTS.md` and it can name the section.

## 8. If this directory looks empty

Everything under `GUIDELINES/shared` lives in a git submodule,
which a plain clone leaves unpopulated.
The document you are reading is committed in the project,
so it survives that — but every link into `GUIDELINES/shared` will be dead until:

```bash
git submodule update --init GUIDELINES/shared
```

Cloning with `--recurse-submodules` does it up front.
In an overlay - a `GUIDELINES` that is a repository of its own,
in a project that does not carry the guidelines -
this document and the submodule are the overlay's,
and the command is run there:
`git -C GUIDELINES submodule update --init`.
If the same checkout is used from both Windows and WSL,
this saves a recurring annoyance:

```bash
git -C GUIDELINES/shared config core.fileMode false
```

## 9. Document Governance

- This document follows the shared governance rules in `GUIDELINES/shared/GOVERNANCE.md`.
