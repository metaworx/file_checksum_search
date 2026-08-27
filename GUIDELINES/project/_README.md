> **Fragment** — inlined by `tools/sync.sh`; not a standalone document.

# {{project_name}} (v1.0.0)

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
- `{{shared_root}}` holds the shared metaworx conventions: commit policy, the
  quality pass, and the PHP and TypeScript testing and linting baselines.
- `{{shared_root}}/README.md` explains the gate protocol: what an agent asks
  before it changes anything, and what `EXEC` and its relatives authorise.

Both `/AGENTS.md` and this file are generated from the fragments in
`{{guidelines_root}}`. Editing a generated file is wasted work — change the
fragment and re-run `{{shared_root}}/tools/sync.sh`.

## Version History

| Version | Date       | Changed sections | Change type | Agent impact |
|---------|------------|------------------|-------------|--------------|
| v1.0.0  | 2026-08-27 | All              | major       | First version. Opens the generated `{{guidelines_root}}/README.md`, which had no human-facing half before v4. |
