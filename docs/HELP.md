# FCIAS — User Help

This page explains the day-to-day features for regular users of File
Checksum Index & Search.

## The duplicate browser

Open **Duplicates** from the top navigation (or the URL
`/apps/file_checksum_search/duplicates`) to browse files that share the
same checksum.

- **Algorithm** — filter results to a single hash algorithm (SHA-1, MD5,
  SHA-256, SHA-512, SHA3-256, SHA3-512, CRC32) or show all.
- **Min** — the minimum number of files a group must contain to be listed.
- **Limit** — how many groups to show per page.
- **Refresh** — reload the list.
- **Verify hashes** — recalculate every hash in the list from file content
  and flag groups whose hashes no longer match. Recalculation is rate limited
  to 20 files per minute, so a long list stops partway with a message; wait a
  minute and click again to continue with the rest.
- **Only matching** — show only groups that passed verification.

Click a group header to expand it and see the files in the group, with
links that open each file in the Files app.

## Finding files by hash (global search)

You can search for a file by its checksum directly from Nextcloud's global
search bar (Unified Search):

1. Type a hash value — either the raw hex string or an `algo:hash` pair,
   for example `sha256:e3b0c44298fc1c149afbf4c8996fb924`.
2. Pick the **File Checksum Index & Search** result to jump to matching
   files.

This works without browsing folders and is useful for identifying known
files (e.g. a known-good ISO image) or finding every copy of a file.

## The file detail pane

Select any file in the Files app and open the **Checksums** tab in the
sidebar (the file detail pane). It shows:

- The checksums computed for the selected file, per algorithm.
- A **Recalculate** action that recomputes the hash from current content.
- A **Find duplicates** action that lists files sharing hash values with
  the selected file.

> **Note:** FCIAS computes checksums according to the rules described below.
> Some algorithms may be missing until the background job has processed the
> file. If **Recalculate** reports that the file is excluded, a rule blocks
> hashing it entirely — see *Your hashing rules*.

## Your hashing rules

Open **Personal settings → File Checksum Index & Search** to see which rules
decide your files. Every file is handled by the **first rule that matches it**,
and that decision is final — no later rule gets a say.

The table lists the rules in exactly the order they are checked, grouped into
bands. Each band opens with a header saying what it is, and each rule shows its
priority as `<band>.<position>` — `4.2` is the second rule in band 4. Lower is
stronger, so the top of the table wins and the catch-all `**` rule at the
bottom only decides files nothing else matched. Every heading has an **i**
button explaining what that column's values mean.

You will normally see three kinds of row:

- **Above yours** — rules your administrator enforced. They come first, you
  cannot change or disable them, and nothing of yours can outrun them.
- **Your own rules** — the ones you may edit, delete and reorder among
  themselves. They decide a file only where no enforced rule matched it first.
- **Below yours** — the administrator's defaults, including the catch-all.
  They apply where nothing more specific matched, which means one of your own
  rules can override them.

Rows you may not change show **Read-only** in place of the buttons. If you see
"You are not allowed to edit rules", your administrator has not granted the
permission; you can still read the table. Even with the permission, you can
only create a rule for a path in a folder you can write to.

### What the Type column means

| Type | Automatic hashing | The sidebar's Recalculate button |
|------|-------------------|----------------------------------|
| `include` | yes | works |
| `ignore` | no | works — hashing on request is exactly what `ignore` allows |
| `exclude` | no | refused |

`exclude` is a blanket "do not read these files", so it blocks every route,
your own Recalculate included. This is normally deliberate: it is the setting
used for storage that is slow or costs money to read.

### Reordering your rules

Drag a rule by the handle on its left to move it. A rule can only be dropped
inside its own band — elsewhere the cursor shows "no drop" — because moving it
between bands would change who it can outrank. To move a rule to a different
band, change what it *is*: its scope or, for an administrator, its enforced
flag. Reordering currently needs a pointer; there is no keyboard equivalent.
