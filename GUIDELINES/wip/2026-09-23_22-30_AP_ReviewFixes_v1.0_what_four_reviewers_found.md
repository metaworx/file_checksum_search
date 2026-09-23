# AP ReviewFixes v1.0: what four reviewers found

> **Status: proposal, 2026-09-23.** Acts on
> `wip/2026-09-23_22-16_ANALYSIS_SessionReview_v1.0`, the consolidated
> report of four independent reviewers over `2b5e61b..ab5f268`. Tier 1 of
> the analysis is blocks 1–5, in the order they land; Tier 2 is blocks
> 6–8; Tier 3 is not planned. Finding ids (S, F, T, R) are the analysis's.

## Blocks

1. **[SECURITY] A leader reaches their members, not the administrators
   among them.** S1, S2. `SudoScope::membersOfLedGroups()`, the group
   branch of `resolveSet()` and `selectableFor()` keep only members for
   which `ISubAdmin::isUserAccessible( $leader, $member )` holds — the rule
   the app already applies to an account named directly — and the comment
   claiming every member is accessible "by definition" goes. `resolveSet()`
   returns `false` for a caller who may not cross at all, so an empty set
   is a 403 like every other request of theirs. Unit tests on `SudoScope`;
   `ReachTest` gains an administrator inside the leader's group (unreached
   through `all`, `groups[]` and `mayReachFile()`, and not offered by
   `selectable`) and `sudo/duplicates?users[0][]=x` in the plain-account
   refusal list. CHANGELOG under Security.

2. **[FIX] The page's state and its address agree.** F1, F2, F3.
   `setTab( 'others' )` carries `othersState` into `othersParams` before
   the listing remounts; `setTab()` from a click clears `pendingTab`; an
   unknown `algo` is dropped on mount against `algorithmIds` when the list
   is already known, and the watcher covers the other order. A spec for
   `App.vue` — the first — that fires the `hashchange` listener by hand,
   since happy-dom raises it on `pushState`/`replaceState` and browsers do
   not; cases for a tab round-trip on Others, a click before the first
   answer, a bad `algo` in an Others address at load. CHANGELOG: the
   address bullet amended.

3. **[FIX] The picker keys an option by what it is.** F4, F5, F11, T1,
   T13. `TargetPicker.vue`: `:get-option-key` on kind and id, so a group and
   an account called `admin` are two options; `optionFor()` looks in
   `selected` before the latest answer, so a picked account keeps its
   label past the prefill threshold; the scoped style restored;
   `label-outside` where the external `<label for>` is. The dead
   `track-by="id"` dropped from the five other pickers, and `label-outside`
   set on the rules form's target pickers and the permission section's
   group select, which end the 44 runtime warnings. `TargetPicker.spec`: a
   group and a user sharing an id picked and removed independently; a
   label kept across a scope round-trip; a search answer whose label does
   not contain the search term, so `filterable` is asserted again.
   CHANGELOG: the Others bullet amended.

4. **[TASK] The record says what left, and what a script must do.** R1,
   R2, R3, R4, R5–R13, R14 per decision 2. Removed bullets for
   `/duplicates/data` and `/settings/admin-options*`; the `minCount`
   bullet without its "browser routes"; the backup bullet on what each
   format carries and that the queue is derived; the CSRF bullet with its
   consequence; `findByHash`'s parameter type change named as breaking for
   positional callers; `fcias:hash`'s long name, `--algo auto` and
   `--mode missing` refreshing; the five dropped operator facts as
   clauses. `docs/api-v1.md`: the CSRF list as "every non-GET route",
   catalog rows 15–17 as the caller's reach, §6 on the non-admin status
   answer, the two PHP signatures; OpenAPI: `StatusResponse` requiring
   `version` only, a 429 on `selectable`. `[0.19.0]` and `[0.14.1]`
   restore their two lost clauses and their tags are updated; all 36
   re-tagged on their release dates if decision 2 says so.

5. **[FIX] The e2e cases test what they claim.** T2, T10, T11, T9, T3.
   The `nobody` half hashes its file and waits for the hash row before
   asserting the link is absent, and moves to its own case; admin's files
   hashed in `before()` rather than by earlier cases; `cy.reload()` after
   the hash-only visit so the address is read on load; the reaper quotes
   the uid, passes `--limit`, logs a failed parse or delete, and the README
   says one run per instance; `--step orphaned-metadata` in `before()` as
   cheap hardening.

6. **[TASK] The entry question and the ceiling from one source.** S3, S5.
   `mayCross()` is `isSudoer() || resolve() !== false`; `#[UserRateLimit]`
   60/60 on `getHashes` and `sudoGetHashes`, which the pairing test then
   enforces. Docs and CHANGELOG follow.

7. **[FIX] The small page defects.** F6, F7, F8, F9, F10, F14. The scope
   assigned only on change; `canSudo` reported after every load and a
   false answer clearing `pendingTab` and rewriting the address; the hash
   debounce cleared on unmount and on `applyParams`; Min and Limit reload
   only on a changed value; `limit=` empty as missing; "Not in your files"
   as visually hidden text beside the `title`. Vitest for each.

8. **[TASK] The runner and the helpers, tightened.** T4, T5, T6, T7, T8,
   T14. `enableAutoUnmount( afterEach )` and a `sessionStorage.clear()` in
   the setup file, the hand-written unmounts gone; the lint rule widened
   to `doMock`, the bare package, `vi.mock( import( … ) )` and `stubs` keys
   matching `/^Nc[A-Z]/`; the helper comments corrected, `openSelect`
   throwing when the menu did not open, one waiting primitive for both
   helpers; the rules form's cancel case renamed and `onOpenChange`
   deleted as dead under `no-close`.

## Tests that must exist before this ships

- `ReachTest`: an administrator inside a leader's group is out of reach
  by every route and absent from `selectable`; a plain account's empty
  set is 403.
- An `App.vue` spec on the three page defects, with the `hashchange` trap
  handled.
- `TargetPicker.spec`: the id collision, the label round-trip, the
  `filterable` mutant killed.
- `checksums.cy.js`: the `nobody` half fails when `canSudo` is true.

## Not in this AP

Tier 3 of the analysis: S6, S7, S8, F12, F13, T12, R15.

## Open decisions

1. **S4.** A link that arrives with a scope loads without a click.
   Recommended: keep, and say so in the Others banner ("shown because you
   asked for it by name, or arrived by a link that did").
2. **R14.** Re-tag all 36 releases on their release dates
   (`GIT_COMMITTER_DATE`), one more forced push, or leave the tagger dates
   at 2026-09-22. Recommended: re-tag, in block 4.
3. **Scope.** Blocks 1–5 alone, or 1–8. Recommended: all eight.

## Gate

PHP suite, `npm run lint`, `npx vitest run`, `npm run build`, the
`duplicates` and `checksums` e2e after blocks 2, 3, 5 and 7; full e2e at
the end. One commit per block; block 1 is `[SECURITY]`, its own commit,
measured over HTTP in `ReachTest`. At the last gate: retire this AP and
the analysis together, on the user's word.

## Change History

- v1.0 (2026-09-23): written from ANALYSIS SessionReview v1.0.
