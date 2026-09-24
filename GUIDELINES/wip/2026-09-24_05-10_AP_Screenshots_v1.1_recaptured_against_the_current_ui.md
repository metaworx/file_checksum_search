# AP Screenshots v1.1: recaptured against the current UI

> **Status: proposal, 2026-09-24.** Re-validation of v1.0 (2026-08-29)
> against the product as of `ad32951`. v1.0's dependencies (UserDocs,
> CodeDocs, E2ETests) were all retired on 2026-09-02/03; nothing blocks
> a capture session now.

## Discussion

What v1.0 got right still holds: the five PNGs in `docs/Screenshots/`
are unchanged since 2026-08-19, `appinfo/info.xml` is their only
consumer, no document embeds an image, and two of the five show UI that
no longer exists. What has changed since v1.0 is the product, in ways
that touch every shot:

- The admin page has five tabs — *Settings*, *Permissions*, *Sudo
  tokens*, *Advanced*, *Documentation*. *Status Info* and the picker
  prefill threshold live on *Advanced*; the permissions on their own
  tab. v1.0's shot 1 assumed one page and shot 6 a status block on it.
- The personal page has a *Preferred algorithm* section and a *Sudo
  tokens* section beside the rules.
- The Duplicates page has *Mine*, *Others* and *Help* tabs, **Verify
  all** per group and **Verify** per file, a *Hash* field with *Search
  anywhere*, and its state in the address. The page-wide *Verify hashes*
  button and *Only matching* are gone. The *Others* tab has the target
  picker (*All accounts* / *All my groups*) and location glyphs on rows
  that are not the viewer's.
- The sidebar has *Find across accounts* for those who may cross, and
  shows a rule's refusal reason under *Recalculate*.
- `docs/image-1788466286321.png` (untracked, 1000×660, light theme) is
  a Duplicates page capture that already shows the removed *Verify
  hashes* and *Only matching* controls. Not usable; delete it.

Two things the re-validation settles that v1.0 left open:

- **The in-app Documentation tab cannot show a relative image.**
  `DocsViewer` renders through `NcRichText`, which does parse markdown
  images, but a relative `docs/Screenshots/x.png` resolves against the
  settings page and nothing serves it. The `info.xml` form — an absolute
  GitLab raw URL on `master` — works in every viewer. Embeds use that
  form, and the in-app tab shows them only with network access, which
  the text says.
- **Captures can be a spec.** The e2e suite already seeds what the shots
  need (fixtures, `fciasResetRules`, `fciasMakeAccount`) and Cypress
  takes screenshots at a set viewport. A spec outside the default
  pattern, run by hand, makes every recapture repeatable at the next UI
  change, at the cost of the account names it mints (`fcias_e2e_…`),
  which display names can hide. Recommended over a manual session.

## Instance state, checked 2026-09-24

- Rules: exactly the two shipped defaults, both disabled (7.1 `home:*`,
  8.1 `*`). v1.0's leftover rules are gone; the reset recipe is met.
- Accounts: `admin`, `alice`, `bob`. Group folders 1 *Team Docs*
  (admin), 2 *Design Assets* (no group). Groups: `admin`, `test group`
  (bob). No `designers` group yet.
- Commands: every `fcias:` name in v1.0 is an alias of a
  `file-checksum-search:` command and works; `maintenance:repair` is
  `occ fcias:repair` here, which recreates the two defaults disabled.

## Implementation Plan

Viewport 1440×900, default light theme, English. Every capture is a
case in `tests/e2e/screenshots.cy.js`, excluded from `specPattern` and
run as `npx cypress run --spec tests/e2e/screenshots.cy.js` with the
usual environment; `cy.screenshot` with `clip` where a crop is wanted.
Files land in `docs/Screenshots/`, the five existing names kept.

1. **The spec's scaffold.** Viewport, light theme forced through the
   `theming` user setting, display names *Alice Example* and *Bob
   Example* on the minted accounts, a `designers` group holding both
   with write on folder 2, the `duplicates` fixture imported, the
   `home:*` default enabled plus one `home:<alice>` rule (`/Photos/**`,
   sha256) and one `groupfolder:1` rule (`**`, sha1, enforced). Teardown
   returns the instance to the two disabled defaults.
   **Verification:** the spec runs green end to end with no capture yet.

2. **The five existing names, replaced.** `Admin-Settings.png` (the
   *Settings* tab: banded table with headers, `<band>.<position>`, pen
   and menu, one `tr[data-placeholder]` row with *Create rule*),
   `User-Settings.png` (alice's personal page: enforced band, her own
   rule, the defaults, *Preferred algorithm*), `File-Detail-Pane.png`
   (a hashed file's Checksums tab with *Find duplicates* and, as admin,
   *Find across accounts*), `Duplicates-Page.png` (*Mine*, two groups,
   one expanded with **Verify**), `Unified_Search.png` (a real hash from
   the fixture, two results).
   **Verification:** no *Global Rule*, no *User Scope*, no *Verify
   hashes* in any shot; `info.xml` untouched and its URLs still valid.

3. **The surfaces no shot shows.** `Admin-Idle-Banner.png` (both
   defaults disabled), `Rule-Dialog.png` (from the `groupfolder:2`
   placeholder, the *Team Folders* picker open), `Rule-Row-Menu.png`
   (one row's menu open), `Admin-Permissions.png`, `Admin-Advanced.png`
   (*Status Info* after `fcias:queue:drain` and one sweep, the prefill
   threshold), `Admin-Sudo-Tokens.png` and `User-Sudo-Tokens.png` (one
   grant listed), `Duplicates-Others.png` (*Others* with *All accounts*
   named, an own row behind a house and another account's behind a
   person), `Sidebar-Excluded.png` (the refusal reason under
   *Recalculate*), `Provider-Missing.png` (a `groupfolder:99` rule,
   deleted after).
   **Verification:** every file exists, under 1 MB, light theme, no
   name beyond the minted ones.

4. **`Drag-Reorder.webm`** — dropped from the gate. Cypress records
   whole runs, not a clip; a 10-second screencast is a manual capture
   with a screen recorder, done once if wanted, outside this spec.

5. **Wiring.** `info.xml`: lead with `Admin-Settings.png`, add
   `Duplicates-Others.png` and `Rule-Dialog.png`, seven entries. README
   (rules chapter, sidebar, Others tab), `docs/user-guide.md` (personal
   page, sidebar, Duplicates page) and `docs/FAQ.md` (status) embed by
   absolute raw URL, with one sentence in the user guide that the images
   need network access in the in-app tab. `docs/image-1788466286321.png`
   deleted. CHANGELOG: one bullet for `info.xml`.
   **Verification:** links resolve on GitLab; the in-app tab renders the
   text with or without the images.

## Tests that must exist before this ships

- `tests/e2e/screenshots.cy.js`, excluded from the default run, green.

## Not in this AP

A dark-theme set; the screencast; captures of the Nextcloud 33 instance
(the UI is the same bundle).

## Open decisions

1. **Spec or manual session.** Recommended: the spec.
2. **Absolute raw URLs on `master`** for the embeds, as `info.xml` does.
   Recommended: yes; the alternative is a route serving the folder.
3. **Seven `info.xml` entries** (the store shows them all). Recommended.

## Gate

One commit per step; the spec lands in step 1's commit and grows with
each. `npx cypress run --spec tests/e2e/screenshots.cy.js` green at
every gate; the full e2e once at the end, since the spec shares the
instance.

## Change History

- v1.1 (2026-09-24): re-validated against `ad32951`. Dependencies
  retired; instance already at baseline; the admin tabs, personal
  sections, Duplicates tabs, picker, sidebar link and refusal reason
  added to the shot list; the screencast dropped from the gate; captures
  as an excluded Cypress spec; embeds by absolute URL, the in-app viewer
  having no way to serve a relative image; the stray `docs/image-…png`
  named for deletion.
- v1.0 (2026-08-29): initial preliminary plan, written from the
  analysis-only session.
