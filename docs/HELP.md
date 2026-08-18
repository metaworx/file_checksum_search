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
  and flag groups whose hashes no longer match.
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

> **Note:** FCIAS computes checksums according to the rules configured by
> your administrator. Some algorithms may be missing until the background
> job has processed the file.
