# AP SidebarScope v1.1: a link to the Others tab

> **Status: proposal, 2026-09-23.** Supersedes v1.0, which is immutable.
> v1.0 put a cross-account switch into the sidebar's Duplicates section. The
> user's review replaced it: the sidebar links to the Duplicates page's
> *Others* tab with the hash filled in, which needs the page's state to live
> in the URL — and a search that lives in the URL can be shared. Blocks 1,
> 4 and 5 of v1.0 stand; block 2 is two blocks now. Retiring SubAdminPicker
> v1.0/v1.1 and HashFilter v1.0 waits for this AP's last gate.

## What changed against v1.0

1. **No switch.** One cross-account surface, the Others tab: it has the
   picker, the amber ground, the confirmation and the row labels, and a
   second copy in a narrow pane would be a smaller, worse one.
2. **The page's state is the URL.** Tab, filters, page and scope, written
   on every change and read on load, so a bookmark survives a reload and a
   search can be handed to someone. Today only the tab name is.
3. **The link names a scope.** The Others tab lists nothing until a target
   is named, so a link with a hash alone would land on an empty page. The
   picker's *All accounts* becomes the caller's whole reach — *All accounts*
   for a sudoer, *All my groups* for a group leader — which the API already
   answers when nothing is named; the link carries `all=1`.
4. **One button, the hash in the link.** Not one per hash row, and not the
   file id: `GET /api/v1/file/{id}/hashes` answers only for a file the
   caller holds, so a link by id would be dead in a colleague's hands. The
   hash is the thing being shared. The button uses the preferred
   algorithm's hash where the file has one, else the first row's.

## Analysis

The sidebar shows the viewer's own file, always; only its *Find duplicates*
would ever want another reach. `GET /api/v1/sudo/file/{id}/duplicates`
exists, and so does everything a row needs (`owner`, `location`,
`openable`). What the sidebar lacks is `canSudo`, and what the page lacks is
a way to arrive with a question already asked.

The fragment, not the query string: `#others?hash=…&algo=…&all=1`. The
fragment never reaches the server or its logs, which matters for a URL
that may name accounts, and the tab already lives there. `history.
replaceState` on change, not `pushState`: typing in the hash field must not
leave a history entry per keystroke. Honouring `users[]`/`groups[]`/`all`
from a URL is safe for the reason `#others` already is — every
cross-account read is authorised and confirmed server-side; a leader given
a link naming a stranger gets the page's 403.

## Blocks

1. **[TASK] `canSudo` on the hashes response.** `hashesFor()` adds
   `canSudo: SudoScope::mayCross( $actingUser )` beside `canRecalc`; the
   `/sudo/` twin says the same. Controller unit test, sudoer and plain
   account; OpenAPI `FileHashesResponse` and `docs/api-v1.md` §2.

2. **[TASK] The page's state in the URL, both ways.** `#<tab>?<params>`,
   parsed with `URLSearchParams`: `hash`, `anywhere`, `algo`, `min`,
   `limit`, `offset` on either tab; `users[]`, `groups[]`, `all` on Others.
   Each tab writes its own state when it is the active one and reads it
   when it becomes so, on load and on `hashchange`; `tabFromHash()` splits
   on `?` before it decides the tab. The picker's *All* option is offered
   to everyone who may cross: `GET /api/v1/sudo/selectable` answers
   `all: true` for any such caller and gains `reach: 'everyone' | 'groups'`,
   from which the picker labels it *All accounts* or *All my groups*. A
   URL naming both `all=1` and accounts is `all`. Vitest: parse and write
   round-trip for every parameter, the picker's label by `reach`;
   `SudoRouteTest`: a leader's `selectable` says `all: true, reach: groups`.

3. **[TASK] The sidebar's button.** *Find across accounts* in the Duplicates
   section beside *Find duplicates*, rendered when `canSudo` and the file
   has at least one hash. An `<a>` to `generateUrl( FRONTEND.duplicates )`
   plus `#others?hash=<hash>&algo=<algo>&all=1`, `target="_blank"`, the
   hash being the preferred algorithm's where the file has one, else the
   first row's. Vitest: hidden without `canSudo`, hidden without hashes, the
   href for both hash choices.

4. **[TASK] One icon per namespace.** As v1.0 block 3: `LocationIcon` over
   inlined MDI paths — *account* for a home, *folder-account* for a group
   folder, *harddisk* for another storage — before a location label on
   both duplicate lists and never before a plain path; the rules table's
   Scope column from the selector kind (`home:<uid>` account, `group:`
   account-group, `home:*` home-group, `groupfolder:` the folder glyph,
   `storage:` the disk, `*` an asterisk), each with a `title`. The text
   stays `describe()`'s. Vitest for the mapping.

5. **[TASK] The three `/sudo/` reads rate limited like their twins.** As
   v1.0 block 4: `#[UserRateLimit( limit: 60, period: 60 )]` on
   `sudoFindDuplicates`, `sudoLookup`, `sudoFindAllDuplicates`; a
   reflection test that every `/api/v1/` read carries its twin's limit;
   `docs/api-v1.md` §Rate Limiting and the OpenAPI 429s.

6. **[TASK] The e2e and the docs.** `duplicates.cy.js`: a visit to
   `#others?hash=<h>&all=1` opens the tab with the field filled and the
   group listed without a click; changing a filter changes the address
   bar; a plain account's picker offers no *All*. `checksums.cy.js`: on the
   pattern of the label case, a minted `owner` holds a copy of file A; the
   administrator's sidebar shows the button, following it lands on Others
   with the group holding both copies, the owner's as a location; a minted
   plain account sees no button. User guide: *The file detail pane* and
   *Looking at other accounts* (the button, and that the address bar is the
   search); README's sidebar line; CHANGELOG under Changed, §5.3.

## Tests that must exist before this ships

- Controller unit: `canSudo` on the ordinary hashes route, both answers.
- `SudoRouteTest`: a leader's `selectable` carries `all: true` and
  `reach: groups`; `sudo/duplicates` with nothing named answers their
  members (exists) — the pair that makes *All my groups* true.
- Reflection: every `/api/v1/` read has the rate limit its twin has.
- Vitest: URL round-trip, picker label by `reach`, the sidebar href.
- e2e: the two cases above, administrator and plain account.

## Not in this AP

- A target picker in the sidebar, or cross-account recalculation from it.
- Shortening a home location to `alice: Templates/…` (decision 1).
- `pushState` history for filters: a shared URL is the point, back-button
  navigation between searches is not.

## Open decisions

1. **The location text beside the glyph.** `describe()` verbatim
   (recommended), or a display form without `/files/`. Before block 4.
2. **The rate limit on the `/sudo/` reads.** Block 5 as written
   (recommended), or strike it. Before block 5.
3. **The Scope column's glyph for `*`.** Asterisk (recommended) or earth.
4. **What a URL with an unknown `algo` or a `limit` out of range does.**
   Recommended: the field's own clamping and the *All algorithms* fallback
   the page already has, silently — a shared link should open, not refuse.

## Gate

PHP suite, `npm run lint`, `npx vitest run`, `npm run build`, the
`duplicates` and `checksums` e2e; full e2e at the end. One commit per
block; block 5 moves a limit and is its own commit. At block 6's gate:
retire this AP, v1.0, SubAdminPicker v1.0/v1.1 and HashFilter v1.0, on the
user's word.

## Change History

- v1.1 (2026-09-23): the switch replaced by a link to the Others tab; the
  page's state in the URL; *All* as the caller's reach; one button, the
  hash in the link.
- v1.0 (2026-09-22): written from SubAdminPicker v1.1's *Companion* note.
