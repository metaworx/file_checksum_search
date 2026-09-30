# NOTE Languages v1.5: style check passes again

> **2026-09-29.** A step added to AP Languages v1.5, at the user's request.

`composer cs:check` fails at HEAD on one extra blank line in
`lib/Migration/Version010000Date20260806100000.php`, before
`postSchemaChange()` (`NoExtraBlankLinesFixer`), which no commit of this
plan touched. It is fixed in a commit of its own, as the plan's last step
before the worktrees are removed: `[CLEANUP] The style check passes again`,
the one blank line removed and nothing else, CHANGELOG exempt as a
whitespace change.
