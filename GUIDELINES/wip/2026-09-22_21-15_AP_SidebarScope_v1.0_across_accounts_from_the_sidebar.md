# AP SidebarScope v1.0: across accounts from the sidebar

> **Status: proposal, 2026-09-22.** The companion SubAdminPicker v1.1 named
> and deferred: the sidebar's *Find duplicates* asking across accounts, and
> one icon set for "where a file lives" shared by the duplicate rows and the
> rules table's Scope column. Written after CrossAccount v1.5 closed, so
> every server piece it needs already exists; what is left is the sidebar's
> half and the icons. Retiring SubAdminPicker v1.0/v1.1 and HashFilter v1.0
> waits for this AP's last gate (they are cited here).

## Analysis

**What the sidebar shows is always the viewer's own file.** The Files app
opens the tab on a file the viewer holds, so the hashes and the recalc
buttons never need a cross-account route. Only the Duplicates section does:
*Find duplicates* asks `GET /api/v1/file/{id}/duplicates`, scoped to the
viewer's own reach, and a sudoer looking for other people's copies of their
file has to leave for the Duplicates page.

**Everything the server needs is there.** `GET /api/v1/sudo/file/{id}/duplicates`
(CrossAccount block 10) answers over the caller's whole reach, its rows
carry `owner`/`location` (block 11) and `openable` (block 15), and the
sidebar already renders both: `fileLabel()` for the caption, a plain span
where a link would not open. The password confirmation is the Duplicates
page's `confirmPassword()` from `@nextcloud/password-confirmation`, and
the server checks it again on every call.

**What is missing, in order:**

1. The sidebar does not know whether the viewer may look across accounts.
   The Duplicates listing carries `canSudo`; the hashes response does not.
2. There is no control. SubAdminPicker v1.1 chose a switch for the sidebar
   and a tab for the page — a tab needs room the sidebar has not got, and
   the switch is explicit where the old page switch was not: it sits inside
   the one section it changes, and the results it changes take the amber
   ground the Others tab uses.
3. A location is text only. `/alice/files/…`, `groupfolder:3/…`,
   `storage:7/…` say where a file lives, but a reader has to parse the
   prefix; the rules table's Scope column has the same three kinds and no
   glyph either.
4. The three `/sudo/` reads — per-file duplicates, lookup, listing — carry
   no rate limit, where their ordinary twins carry 60/min. The sidebar will
   now call one of them from a button.

## Blocks

1. **[TASK] `canSudo` on the hashes response.** `hashesFor()` adds
   `canSudo: SudoScope::mayCross( $actingUser )` to the ordinary route's
   answer, beside `canRecalc`; the `/sudo/` twin says the same. Unit test on
   the controller (true for a sudoer, false for a plain account), the
   OpenAPI `FileHashesResponse` and `docs/api-v1.md` §2 gain the field.

2. **[TASK] The switch.** In the sidebar's Duplicates section, an
   `NcCheckboxRadioSwitch` *Across accounts*, rendered only when `canSudo`.
   Turning it on calls `confirmPassword()` once per window (the page's
   `confirmed` pattern); a dismissed dialog leaves the switch off. With it on,
   `useSidebarHashes.toggleDuplicates()` asks the `/sudo/` twin, and the
   results block takes the amber modifier with a one-line caption *Other
   accounts' files included*. Off, or on a 403 *Password confirmation
   required* (the thirty minutes ran out), the section asks again before it
   asks the server. The switch keeps its position across files within the
   window and resets on reload — a sudoer checking several files should not
   re-toggle for each, and nothing is persisted. Vitest: the composable's
   route choice, the 403 re-prompt, the reset on reload; the component's
   switch hidden without `canSudo`.

3. **[TASK] One icon per namespace.** A `LocationIcon` component over
   inlined MDI paths (`icons.ts`, as the row actions already do): *account*
   for a home, *folder-account* for a group folder, *harddisk* for another
   storage, derived from the `location` prefix `FileLocation::describe()`
   writes. Rendered before the label on `DuplicateGroup.vue` rows and the
   sidebar's duplicate rows wherever the label is a location — never before
   a plain path, which needs no explaining. The rules table's Scope column
   renders the same glyphs from the selector kind: `home:<uid>` account,
   `group:<gid>` account-group, `home:*` home-group, `groupfolder:` the
   folder glyph, `storage:` the disk, `*` an asterisk. Each carries a
   `title` naming the kind, so the glyph is never the only cue. The text
   stays exactly `describe()`'s: the API string a reader can grep for, as
   the FAQ documents it. Vitest for the mapping; the existing table and
   label specs follow.

4. **[TASK] The three `/sudo/` reads rate limited like their twins.**
   `#[UserRateLimit( limit: 60, period: 60 )]` on `sudoFindDuplicates`,
   `sudoLookup` and `sudoFindAllDuplicates`. A reflection test beside
   `RouteRequirementsTest` asserts every `/api/v1/` read that its ordinary
   twin limits is limited the same; `docs/api-v1.md` §Rate Limiting and the
   OpenAPI 429s follow (the reference currently says the other `/sudo/`
   reads are not limited — that sentence goes).

5. **[TASK] The e2e and the docs.** `checksums.cy.js` gains one case on
   the pattern of the Duplicates spec's label case: a minted `owner` account
   holds a copy of file A with the same stated hash; the administrator opens
   A's sidebar, *Find duplicates* shows only their own B, the switch on shows
   the owner's copy as a location with the account glyph and as plain text
   (not openable), and the switch is absent for a minted plain account.
   User guide *The file detail pane*: the switch, under whose permission,
   and that a file of somebody else's is text, not a link. README's sidebar
   line. CHANGELOG under Changed, one bullet per §5.3.

## Tests that must exist before this ships

- Controller unit: `canSudo` true for a sudoer and false for a plain
  account on the ordinary hashes route.
- Reflection: every `/api/v1/` read has the rate limit its twin has.
- Vitest: `useSidebarHashes` picks the route by the switch, re-prompts on
  403, forgets the confirmation on reload; `LocationIcon` maps every
  `describe()` prefix and every selector kind, and nothing else.
- e2e: the sidebar case above, as the administrator and as a plain account.

## Not in this AP

- A target picker in the sidebar. The page has one; the sidebar asks over
  the whole reach or not at all, which is what a switch says.
- Cross-account recalculation from the sidebar. The file is the viewer's
  own; the batch route serves the page.
- Shortening a home location to `alice: Templates/…`. Decision 2 below.

## Open decisions

1. **Switch position across files.** Kept for the window (recommended, as
   block 2 says), or reset to own on every file. Confirm before block 2.
2. **The location text beside the glyph.** `describe()` verbatim
   (recommended: one string in the API, the docs, the FAQ and the UI), or
   a display form that drops `/files/` from a home location once the glyph
   says it is a home. Confirm before block 3.
3. **The rate limit on the `/sudo/` reads.** Block 4 as written
   (recommended: same cost, same limit; the password confirmation is not a
   throttle), or leave them unlimited and strike the block.
4. **The Scope column's glyph for `*`.** An asterisk, or the earth glyph
   Nextcloud uses for "everyone". Either; asterisk recommended since the
   selector literally is one.

## Gate

PHP suite, `npm run lint`, `npx vitest run`, `npm run build`, the
`checksums` and `duplicates` e2e; full e2e at the end. One commit per block;
block 4 moves a limit and is its own commit. At block 5's gate: retire this
AP together with SubAdminPicker v1.0, v1.1 and HashFilter v1.0, on the
user's word.

## Change History

- v1.0 (2026-09-22): written from SubAdminPicker v1.1's *Companion* note,
  after CrossAccount v1.5 closed.
