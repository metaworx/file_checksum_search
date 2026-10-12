# NOTE ReviewFixes v1.1: How the run went

Where Blocks 1–3 went differently from the plan. The run stopped after
Block 3, on M13.

## Block 1

- The tag message was rewritten before the `[FIX]` commit, not after:
  `changelog.sh check` fails on a released section its tag no longer says.
- A rule's computable algorithms are `AlgorithmCatalogue::computable()`,
  not a `RuleService` method: it is the allowlist's question, and the
  hashing service holds the catalogue already. `fcias:hash` reads a rule
  through it too.

## Block 2

- None.

## Block 3

- **M13 is not fixed.** Nextcloud's scan keeps a file's ETag while it
  finds the storage mtime unchanged (`Scanner.php`, `REUSE_ETAG` on a
  storage without reliable ETags), so a file put back under its old mtime
  keeps its ETag, and the ETag in the gone mark cannot see it come back.
  The mark carries the ETag still: a gone file whose ETag a scan moved on,
  and a 0.25.0 mark without one, are queued again. README and FAQ say that
  a file put back under its old mtime stays gone until written to.
- The "not found" count is cleared whenever a file leaves the queue, by
  `writeState()`: one more delete for each file the drain finishes.
