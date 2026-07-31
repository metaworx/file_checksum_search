# Agent Tools (v1.1.0)

This document provides concise entry points to canonical tool workflows used by AI agents.

## Contents

1. Commit Tools (`/.aiassistant/tools/`)
2. PHPUnit Wrapper Docs
3. Document Governance
4. Version History

## 1. Commit Tools (`/.aiassistant/tools/`)

Canonical commit policy, commit-gate behavior, commit message format, and commit toolchain
workflow are defined in `/.aiassistant/COMMIT.md`, including Section `6` (`Commit Tools`).

## 2. PHPUnit Wrapper Docs

Wrapper usage and supported wrapper-specific options (`--use-diff-stats`, `--env`, `--no-trace`)
are documented in `/.aiassistant/tools/phpunit.md`.

## 3. Document Governance

Document governance rules (numbering, `Contents`, versioning, and history conventions) are
canonically defined in `/.aiassistant/CHANGELOG.md`.

## 4. Version History

| Version | Date       | Changed sections      | Change type | Agent impact                                                                  |
|---------|------------|-----------------------|-------------|-------------------------------------------------------------------------------|
| v1.1.0  | 2026-04-23 | Title, Contents, 3, 4 | minor       | Aligns this document with governance rules from `/.aiassistant/CHANGELOG.md`. |
| v1.0.0  | 2026-04-23 | Initial document      | minor       | Baseline tool-reference document for agent-local workflows.                   |
