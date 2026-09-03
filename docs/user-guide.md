# FCIAS — User Guide

This page explains the day-to-day features of File Checksum Index & Search.
It is written for anyone using Nextcloud; your administrator's reference is
[FAQ.md](FAQ.md).

## What this app does for you

A **checksum** is a short string calculated from a file's contents, the same
way every time. Two files with the same checksum are almost certainly the
same file; a file whose checksum has changed is definitely not the file it
was.

FCIAS keeps those checksums for your files and lets you use them:

- **Find copies of a file.** The duplicate browser groups files that share a
  checksum, so you can see what you are storing more than once before you go
  looking through folders.
- **Search by checksum.** If you know a file's hash — from a download page,
  a colleague, a receipt — paste it into Nextcloud's search bar to find every
  copy of that exact file in your account.
- **Check a file is intact.** The Checksums tab in the file sidebar shows
  what was computed and lets you recompute it from the current contents. If
  the two differ, the file changed since it was last checked.

Which files get checksums, and which algorithms are used, is decided by
**rules** — some set by your administrator, some possibly your own. See
*Your hashing rules* below.

If your files have no checksums yet, that is most likely because automatic
hashing has not been switched on for this server: the app does nothing on its
own until someone enables a rule. The **Recalculate** button in the file
sidebar still works in the meantime — it computes one file's checksum when you
ask for it.

## The duplicate browser

Open **Duplicates** from the top navigation (or the URL
`/apps/file_checksum_search/duplicates`) to browse files that share the
same checksum. The page has two tabs: **Duplicates**, and **Help**, which
is this guide.

- **Algorithm** — filter to one hash algorithm, or *All algorithms*. The list
  offers whatever your administrator has enabled on this server.
- **Min** — the minimum number of files a group must contain to be listed.
- **Limit** — how many groups to show per page.
- **Refresh** — reload the list.
- **Verify hashes** — recalculate every hash in the list from file content
  and flag groups whose hashes no longer match. Recalculation is rate limited
  to 20 files per minute, so a long list stops partway with a message; wait a
  minute and click again to continue with the rest.
- **Only matching** — show only groups that passed verification.

Click a group header to expand it and see the files in the group; each file
opens in the Files app **in a new tab**. Long lists are paged with
**← Previous** and **Next →** at the bottom. An empty page says which of two
things it means: *No duplicate files found*, or — with **Only matching** on —
*No matching duplicate files found*.

## Finding files by hash (global search)

You can search for a file by its checksum directly from Nextcloud's global
search bar (Unified Search):

1. Type a hash value — either the raw hex string or an `algo:hash` pair,
   for example `sha256:e3b0c44298fc1c149afbf4c8996fb924`.
2. Pick the **File Checksums** result to jump to matching files.

This works without browsing folders and is useful for identifying known
files (e.g. a known-good ISO image) or finding every copy of a file.

## The file detail pane

Select any file in the Files app and open the **Checksums** tab in the
sidebar (the file detail pane). It has three sections:

- **Checksums** — what has been computed for this file, one row per
  algorithm. Click a value to copy it.
- **Recalculate** — one or two quick buttons, then a picker with every other
  algorithm this server computes and its own **Recalculate** button. The first
  quick button is *your preferred algorithm* (see *Your hashing rules*), or the
  server's default if you have not chosen one; the second is the first
  algorithm the rule governing this file computes, when that is a different
  one. So the buttons follow both you and the file. The whole section is absent
  if your administrator has not allowed you to calculate by hand; what is
  already computed still shows.
- **Duplicates** — a **Find duplicates** button that lists, in place, the
  other files sharing a checksum with this one.

> **Note:** FCIAS computes checksums according to the rules described below.
> Some algorithms may be missing until the background job has processed the
> file. If a recalculation is refused with *Hashing is excluded for this path
> by an administrator rule.*, a rule blocks hashing the file by every route —
> see *Your hashing rules*.

## Your hashing rules

Open **Personal settings → File Checksum Index & Search**. The page has two
tabs: **Rules**, and **Help**, which is this guide.

Above the rules sits **Your preferred algorithm**: the one the sidebar's first
quick button offers. Its first entry reads *Default (…)* and names the
server's default. Pick one to have it first for every file; leave it on the
default to follow the server. If your choice stops being available — your
administrator can change which algorithms this server computes — it is kept
but not applied, and a line beneath the select says so for as long as that
lasts.

If your account may use the API, **Sudo tokens** follows: your app passwords,
each with a switch. A granted app password may read across accounts through
the `/api/v1/sudo/` routes without anyone typing a password — for a script,
which cannot. Granting asks for your password, since it is a standing
authorisation; every grant is visible to your administrators, who can revoke
it. Only an app password can be granted, and only one allowed to access
files; create it under *Security* first. Whether you may look across accounts
at all is decided by your administrator, not by the grant.

The **Rules** tab shows which rules decide your files. Every file is handled
by the **first rule that matches it**, and that decision is final — no later
rule gets a say.

The table lists the rules in exactly the order they are checked, grouped into
bands. Each band opens with a header saying what it is, and each rule shows its
priority as `<band>.<position>` — `5.2` is the second rule of its group in
band 5. Lower is stronger, so the top of the table wins and a catch-all rule
only decides files nothing else matched. Every heading has an **i** button explaining what
that column's values mean.

The **Scope** column says what each rule is about: your own files, everyone's
home folders, a group you are in, a team folder, or everything on the server.
A file shared with you is decided by its owner's rules, not by yours — the rule
follows the file, not the person looking at it.

You will normally see three kinds of row:

- **Above yours** — rules your administrator enforced. They come first, you
  cannot change or disable them, and nothing of yours can outrun them.
- **Your own rules** — the ones you may edit, delete and reorder among
  themselves. They decide a file only where no enforced rule matched it first.
- **Below yours** — the administrator's defaults, including the catch-all.
  They apply where nothing more specific matched, which means one of your own
  rules can override them.

Rows you may not change show **Read-only** in place of the menu. If you see
"You are not allowed to edit rules", your administrator has not granted the
permission; you can still read the table. Even with the permission, you can
only create a rule for a folder in **your own files** — a folder someone shared
with you, or a team folder, is refused with an explanation, because a rule of
yours could not decide those files anyway.

Each row you may change carries a pen for editing and a **⋯** menu with the
rest: enable or disable the rule, delete it, and **Re-apply** — which asks the
server to go through every file that rule currently governs, rather than
waiting for the files to be touched.

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
among the rules addressing the same thing it does — elsewhere the cursor shows
"no drop" — because moving it further would change who it can outrank. A
catch-all rule — one whose path is `**`, `/`, or left empty — always stays at
the end of its group, so a new rule of yours never has to be dragged past it
to take effect. To move a rule
somewhere else entirely, change what it *is*: what it applies to, or, for an
administrator, its enforced flag. Reordering currently needs a pointer; there
is no keyboard equivalent.
