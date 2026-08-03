# Project Guidelines Entry Point

This file is the project-specific entry point for agent-facing guidance in this repository.

## Table of Contents

- [Primary References](#primary-references)
- [Scope Guidance](#scope-guidance)

## Primary References

- `AGENTS.md` - runtime behavior contract, gating flow, and action-plan workflow.
- `/.aiassistant/COMMIT.md` - canonical commit workflow, commit gating, `EXEC+` variants, and commit message policy.
- `CONTRIBUTING.md` - FCIAS Nextcloud app conventions, including implementation patterns and code style/documentation
  expectations.
- `/.aiassistant/tools/README.md` - helper scripts and usage notes for runtime helper utilities.
- `/.aiassistant/tools/RUNTIME_TOOLS.md` - runtime tool catalog with properties and examples.
- `/.aiassistant/TESTING.md` - testing conventions, gate command, coverage guidance, and testability patterns.
- `/.aiassistant/LINTING.md` - style conventions, ECS linting commands, and documentation style.

## Scope Guidance

- Keep generic/mode/runtime behavior in `AGENTS.md`.
- Keep FCIAS-specific coding and implementation conventions in `CONTRIBUTING.md`.
- Keep tool-specific operational documentation in `/.aiassistant/tools/`.
