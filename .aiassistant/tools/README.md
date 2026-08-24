# Agent Tools (v1.2.0)

This document provides concise entry points to canonical tool workflows used by AI agents.

> **Agent host note:** Commands below are WSL-native (bare). Windows-hosted agents need a `wsl --cd` prefix and a `cwd: "C:\\"` tool argument — see `.aiassistant/ENVIRONMENTS.md` before running them.

## Contents

1. Commit Tools (`/.aiassistant/tools/`)
2. PHPUnit Wrapper Docs
3. Document Governance
4. Version History

## 1. Commit Tools (`/.aiassistant/tools/`)

Canonical commit policy, commit-gate behavior, commit message format, and commit toolchain
workflow are defined in `/.aiassistant/COMMIT.md`, including Section `6` (`Commit Tools`).

For FCIAS, the WSL-native commit workflow is:

```bash
/home/mdr/bin/git commit -F .aiassistant/tools/commit-msg.txt --trailer "Co-authored-by: Agent <agent@example.com>"
```

See `.aiassistant/ENVIRONMENTS.md` §1 for the Windows-hosted agent form of this command.

## 2. PHPUnit Wrapper Docs

Wrapper usage and supported wrapper-specific options (`--use-diff-stats`, `--env`, `--no-trace`)
are documented in `/.aiassistant/tools/phpunit.md`.

> **FCIAS note:** The phpunit wrapper depends on Kunstarchiv-specific classes (`mwx\Tests\ConditionalDiffFilter`)
> and does NOT work in FCIAS. FCIAS uses `vendor/bin/phpunit` directly. See `.aiassistant/TESTING.md`.

## 3. Document Governance

Document governance rules (numbering, `Contents`, versioning, and history conventions) are
canonically defined in `/.aiassistant/CHANGELOG.md`.

## 4. Version History

| Version | Date       | Changed sections      | Change type | Agent impact                                                                  |
|---------|------------|-----------------------|-------------|-------------------------------------------------------------------------------|
| v1.2.0  | 2026-08-24 | Title, Contents, 1                            | minor       | Stripped the Windows-agent `wsl --cd` prefix from the §1 example and moved the duplicated `cwd: "C:\\"` note to `.aiassistant/ENVIRONMENTS.md` §1; added a top-of-document pointer. |
| v1.1.0  | 2026-04-23 | Title, Contents, 3, 4 | minor       | Aligns this document with governance rules from `/.aiassistant/CHANGELOG.md`. |
| v1.0.0  | 2026-04-23 | Initial document      | minor       | Baseline tool-reference document for agent-local workflows.                   |
