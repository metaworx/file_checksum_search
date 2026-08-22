---
description: "Cut a release — promote CHANGELOG.md's [Unreleased] entries to a new version, bump appinfo/info.xml, and tag"
argument-hint: "[EXEC|EXEC+] [version override]"
---

# /release — Cut a Release

Promotes the accumulated `## [Unreleased]` entries in `CHANGELOG.md` to a new version section, bumps
`appinfo/info.xml`'s `<version>`, commits as a dedicated `[RELEASE]` commit, and creates an annotated, signed
`vX.Y.Z` tag whose message is that version's release notes. Follows `.aiassistant/COMMIT.md` §4.4.

## 1. When to Use

Run `/release` when:
- The user explicitly asks to cut/tag a release, or
- `CHANGELOG.md`'s `## [Unreleased]` section is non-empty and a natural batch of work (one AP, one feature) has
  just landed.

Do not run it after every commit — batch related work into one version, matching the granularity of the
existing `CHANGELOG.md` history.

## 2. Workflow

### 2.1 Determine the Version Bump

1. Read `CHANGELOG.md`'s current `## [Unreleased]` section and note its bullets.
2. Read `git log` since the last `[RELEASE]` commit (or last `vX.Y.Z` tag) to see which commit tags
   (`[TASK]`/`[FIX]`/`[SECURITY]`/`[CLEANUP]`/`[UPDATE]`) contributed to `[Unreleased]`.
3. Read the current version from `appinfo/info.xml`. Apply the pre-`1.0.0` rule: if **any** contributing commit
   was `[FIX]` or `[SECURITY]`, bump the **patch** digit; otherwise bump the **minor** digit (resetting patch to
   `0`). Once the project reaches `1.0.0`, switch to standard SemVer (breaking changes bump major).
4. If `[Unreleased]` is empty, stop and tell the user there is nothing to release.

### 2.2 Promote CHANGELOG.md

- Insert `## [{version}] — {today's date}` directly below `## [Unreleased]`, moving all of `[Unreleased]`'s
  subsections (`### Added` / `### Changed` / `### Fixed` / `### Security` / `### Removed`, in that order) under
  the new header, preserving bullet order within each.
- Leave `## [Unreleased]` in place above it, with no subsections (empty, ready for the next batch of work).

### 2.3 Bump appinfo/info.xml

- Update only `<version>{version}</version>`. No other changes to this file.

### 2.4 Commit

Diff scope MUST be exactly two files — `CHANGELOG.md` and `appinfo/info.xml` — nothing else. Message:

```
[RELEASE] v{version}

Promote accumulated [Unreleased] entries to {version}.

Co-authored-by: Agent <agent@example.com>
```

### 2.5 Tag

After the commit lands, create an annotated, signed tag whose message is that version's release notes (the
same bullets, without the markdown `##`/`###` headers — plain `Section:` labels instead, one blank line between
sections):

```
git tag -a v{version} -m "$(cat <<'EOF'
File Checksum Index & Search {version} ({date})

{Section}:
- {bullet}
- {bullet}

{Section}:
- {bullet}
EOF
)"
```

This is what forges (GitHub/GitLab Releases) read by default when publishing a release from the tag.

## 3. Commit Gate

Same signal family as `.aiassistant/COMMIT.md` §2: `EXEC` (commit + tag exactly as gated), `EXEC+` (commit + tag,
then continue to the next planned step). Present a gate before running `git commit` / `git tag`:

```
Checkpoint: Cut release v{version}
Overall Task: {one-line summary}
Proposed commit message: {full [RELEASE] message}
Proposed tag message: {full release notes}
Files to be committed: CHANGELOG.md, appinfo/info.xml
Confirmation needed: EXEC / EXEC+ / ROLLBACK
```

Follow `AGENTS.md` §1.1 Universal Gate Template and its `ask_followup_question` delivery mechanism (see
`.roo/commands/cm.md` §2.7 for the reference implementation of this pattern).

## 4. Edge Cases

| Scenario | Action |
|---|---|
| `[Unreleased]` is empty | Stop; tell the user there's nothing to release. |
| User supplies a version override in the argument | Use it, but warn if it skips the patch/minor rule (e.g. a minor bump requested when only `[FIX]`/`[SECURITY]` commits landed). |
| A commit in the range has no `CHANGELOG.md` bullet (missed `.aiassistant/COMMIT.md` §4.3) | Flag it to the user before proceeding — don't silently omit it from the release notes; offer to add it now. |
| `appinfo/info.xml`'s current version doesn't match the last `[RELEASE]` tag | Flag the mismatch before proceeding; something bypassed the release workflow. |
