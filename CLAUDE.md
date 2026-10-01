<!-- GENERATED FILE - DO NOT EDIT.
     Source: shared/assistants/claude/_CLAUDE.md
     Regenerate: GUIDELINES/shared/tools/sync-docs.sh
     Contract version: v1.2.0 -->


# File Checksum Index & Search — Claude Code (v1.2.0)

Claude Code loads this file into its system prompt automatically.
The contract lives in `/AGENTS.md`.

**The contract is `/AGENTS.md`.
Read it.**
Gate mechanism, Action Plans, instruction precedence,
commit policy — all of it is there,
generated from the shared guidelines plus this project's own contract.
Nothing in this file repeats any of it:
two prompt-resident documents saying the same thing is how they come to disagree.

## What is here that is not there

- **Host and tool-call conventions:**
  `GUIDELINES/shared/ENVIRONMENTS.md` §2.2,
  including which IDE tools exist under which names, that they arrive deferred,
  and that scripts must go through a file rather than an inline command string.
- **Git behaviours that fail silently** in a mixed WSL/Windows checkout:
  `GUIDELINES/shared/ENVIRONMENTS.md` §3.
- **What the human sees:**
  `GUIDELINES/README.md` explains the gate protocol from the other side
  — what `EXEC` authorises and what it does not.

## Where writing goes

- An Action Plan goes in `GUIDELINES/wip/`,
  and is registered, noted and retired as `GUIDELINES/shared/COMMIT.md` §6 says.
  Never delete one unasked: say it looks finished, in the commit gate,
  and let the user decide.
- Analyses and records go in `GUIDELINES/messages/`,
  which is not tracked.
  Nothing may cite them — not a document, not a commit message.
- Scratch goes in `GUIDELINES/temp/`, and may be deleted wholesale.

## How Claude Code works here

- **Files are changed with Read, Edit and Write.**
  Bash runs commands and looks at files;
  it does not rewrite them with `sed`, a heredoc or a Python script.
  An edit script that failed half-way once left a commit half-empty,
  and the edit tools keep the IDE's local history,
  as `/AGENTS.md` §1.5 asks.
- **The guidelines are read again before the next commit
  whenever what is known of them may be out of date:**
  - after a context compaction, or on resuming a session,
    since a summary keeps what was salient, not what is binding;
  - when the pin of `GUIDELINES/shared` moves,
    since the documents under it are now another release's;
  - when `/AGENTS.md`'s version changes,
    whether it was regenerated after an update or edited in this session.

  They are `/AGENTS.md`, `GUIDELINES/shared/COMMIT.md`
  and `GUIDELINES/shared/GATE_WORKFLOW.md`.
- **A background task's notification is not the user.**
  It answers no gate and authorises nothing.
  Resuming from one, report what finished and gate the next step;
  never push or publish on it.
- **Another session may be working in the same checkout.**
  Staged changes, edits this session did not make, or commits it did not write
  mean asking before writing anything, a `wip/` plan included.
  The user's edits are taken as they stand: included where they belong,
  and never reverted.
- **`ROLLBACK` covers every way of undoing an edit.**
  Claude Code has no `undo_edit` (`/AGENTS.md` §1.4);
  its equivalents are `git checkout -- <path>`, `git restore`, `git stash`,
  and overwriting a file that holds edits the user made.
  Each needs `ROLLBACK`.
  A file that must stay out of a commit is left out with a pathspec,
  not by undoing it.

## Document Governance

- This document follows the shared governance rules in `GUIDELINES/shared/GOVERNANCE.md`.
