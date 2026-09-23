# ANALYSIS SessionReview v1.0: four reviewers on 2b5e61b..ab5f268

> **Status:** analysis only, no code changed. 2026-09-23, against `ab5f268`.
> Four independent reviewers (Opus 5, no shared context with the author)
> each took one dimension of the 23 commits: security and authorisation,
> frontend correctness, test fidelity, records and documentation. Each
> was read-only on the repository; the security reviewer probed the 34
> instance with GETs as `admin`. Their reports are consolidated here,
> deduplicated, each finding carrying the author's own verdict. The plan
> that acts on it is AP ReviewFixes v1.0.

## Verdict

No critical or high defect in shipped code. One medium security defect
(S1) predates the session and the session made it the one-click path.
Five medium frontend defects, two of them the author's own misses (F1,
F2), three older ones the real components surfaced (F3–F5). One high
records gap (R1), older too. The rest is low or informational.

Method notes worth keeping: happy-dom fires `hashchange` on
`history.pushState`/`replaceState`, which browsers do not; a spec of
`App.vue` must fire the listener by hand (F-reviewer). And `track-by` is
not a prop of `NcSelect` or vue-select at all (F4).

## Tier 1 — fix before this ships

| Id | Sev | Where | Finding | Verdict |
|---|---|---|---|---|
| S1 | medium | `SudoScope::membersOfLedGroups()`, `resolveSet()` group branch, `selectableFor()` | A leader's ceiling and any named group include every member, administrators and delegated admins among them; core's `isUserAccessible()` refuses those and the app applies it only to an account named directly. Since `05683a2` (*All my groups*, `all=1` from the sidebar) this is the default path. | **Confirmed** by the author on the code; the comment at `resolveSet` line 193 states the wrong premise. Fix: filter members through `isUserAccessible()` in all three places; unit + `ReachTest` case with an admin inside a led group. |
| S2 | low | `PublicApiController::sudoFindAllDuplicates`, `SudoScope::resolveSet()` | A plain account sending `users[0][]=x` reaches the set path with an empty set and gets an empty 200 where every other cross-account request is 403; permission is no longer asked before confirmation. No data can leak from an empty reach. | **Confirmed** by the author on the code; HTTP as admin showed the empty set accepted. Fix: `resolveSet()` returns false when `!isSudoer && !mayCross`; `ReachTest` refusal list gains the case. In Tier 1 because it is two lines beside S1. |
| F1 | medium | `App.vue` `setTab`/`onOthersParams`; the Others listing is `v-if` | The Others listing remounts from `othersParams`, which only a `hashchange` sets, while the address is written from `othersState`; after a tab round-trip the page shows the older filters and the address the newer. | **Confirmed** by the author on the code; reviewer reproduced in a scratch spec. Fix: `setTab('others')` carries `othersState` into `othersParams`. |
| F2 | medium | `App.vue` `setTab` | A tab clicked before the first listing answers does not clear `pendingTab`; when `canSudo` arrives the viewer is pulled into Others with a prompt. | **Confirmed** by the author. Fix: `setTab` clears `pendingTab` when not from the URL. |
| F3 | medium | `App.vue:164`, `DuplicateListing.vue` `algorithmIds` watcher | An unknown `algo` in an Others URL at page load is never dropped: the list arrives before the Others listing mounts, and the watcher is not immediate. | Reviewer reproduced in a scratch spec. Fix: validate on mount against `algorithmIds`, or make the watcher immediate after `applyParams`. |
| F4 | medium | `TargetPicker.vue:194` and five more pickers | `track-by="id"` is not a prop; vue-select keys options by `id`, so a group and an account sharing an id (`admin` on a stock instance) are one option: picking the group marks the user selected, removing one removes both. Older than the session; the comment beside it names the very case. | **Confirmed** by the author against both libraries' sources. Fix: `:get-option-key="o => \`${o.kind}:${o.id}\`"` in the picker; drop the dead attribute from the other five. |
| F5 | medium | `TargetPicker.vue` `optionFor` | Past the prefill threshold a picked account's label reverts to its uid as soon as the scope is fed back, since `optionFor` looks only in the latest search answer. | Reviewer reproduced. Fix: look in `selected` first. |
| R1 | high | `CHANGELOG.md` [Unreleased] Removed | `GET /duplicates/data` and `GET`/`POST /settings/admin-options*`, shipped in 0.19.0, are gone with no bullet; the Security bullet on `minCount` still names "browser routes". | **Confirmed** by the author by diffing route attributes 0.19.0→HEAD. Fix: two Removed bullets; trim the `minCount` bullet. |
| T1 | medium | `TargetPicker.spec.ts` | Nothing asserts `:filterable="prefill"` any more; the label the server returns contains the typed text, so NcSelect's own filter keeps it either way. | Reviewer proved it with a mutant. Fix: a search answer whose label does not contain the search term. |
| T2 | medium | `checksums.cy.js` nobody half | The link is absent for `nobody` because the file has no hash, not because `canSudo` is false; the case is vacuous and cron could make it flaky. | **Confirmed** by the author: the half uploads and never hashes. Fix: hash as `nobody`, wait for a hash row, then assert. |

## Tier 2 — should follow, in the same AP

| Id | Sev | Where | Finding | Verdict |
|---|---|---|---|---|
| S3 | low | `SudoScope::mayCross()` vs `resolve()` | `isSubAdmin()` is true for a delegated admin and for a stale `group_admin` row; `getSubAdminsGroups()` is empty for both, so the tab and the link are offered and every request is 403. Fails closed. | Plausible. Fix: `mayCross = isSudoer || resolve() !== false`. |
| S4 | low | `App.vue` `setTab` | A `#others?all=1&hash=…` link opened within 30 min of login loads other people's rows with no prompt and no click; the banner "because you asked for it by name" is no longer true. | Design point, not a leak. Decision 1 below. |
| S5 | low | `getHashes` / `sudoGetHashes` | The only twin pair with no rate limit; the sudo twin costs a leader one mount resolution per member, twice. | Confirmed by reading. Fix: `#[UserRateLimit(60,60)]` on both; the pairing test enforces it. |
| F6 | low | `App.vue` `applyHash` | A `hashchange` on Others assigns a new scope object even when equal, so the deep watcher reloads at offset 0 and the params watcher reloads again. | Reviewer reproduced. Fix: assign only on change. |
| F7 | low | `App.vue` | For a viewer who may not cross, `#others?…` stays in the address while Mine shows; `pendingTab` never clears. | Reviewer reproduced. Fix: the listing reports `canSudo` after every load; on false, clear and rewrite. |
| F8 | low | `DuplicateListing.vue` hash debounce | The 300 ms timer is not cleared on unmount or when `applyParams` runs. | By reading. Fix: clear in `onBeforeUnmount` and in `applyParams`. |
| F9 | low | `DuplicateListing.vue` `onMinCount`/`onLimit` | Reload even when the clamped value is unchanged. | By reading. Fix: compare first. |
| F10 | low | `urlState.ts` `bounded` | `limit=` empty gives 1, not 50. | By reading. Fix: empty string as missing. |
| F11 | low | `TargetPicker.vue` styles dropped in `05683a2` | The "Whose files" label lost its weight and the control its `min-width: 0; width: 100%`. | By diff. Fix: restore the scoped style. |
| F14 | info | unopenable spans, `LocationIcon` | "Not in your files" lives only in a `title` on a span; keyboard and screen readers never get it. | Fix: visually hidden text or `aria-describedby`. |
| T13 | info | `TargetPicker`, `RuleForm` target pickers, `PermissionSection` | 44 runtime warnings: `inputLabel` or `labelOutside` should be set. An a11y defect the stand-ins hid. | Fix: `label-outside` where an external `<label for>` exists. |
| R2 | medium | CHANGELOG backup bullet | Reads as if queue state can be imported and every format carries all three slices. | Confirmed by reading `Import.php`. Fix: reword. |
| R3 | medium | CHANGELOG CSRF bullet | Drops the consequence: a cookie-session client sends the request token or `OCS-APIRequest: true`. | Fix: append the clause. |
| R4 | medium | OpenAPI `StatusResponse`, `api-v1.md` §6 | Requires fields a non-admin never gets. | Fix: require `version` only; say the rest is admin-only. |
| R5–R8 | low | `api-v1.md` CSRF list, catalog rows 15–17, OpenAPI 429 on `selectable`, two PHP signatures | Stale or incomplete. | Fix each as the reviewer says. |
| R9–R11 | low | CHANGELOG bullets on `ChecksumApi`, `fcias:hash`, five dropped operator facts | `findByHash`'s fourth parameter changed type (breaking for positional callers); `--algo` defaults to `auto` and `--mode missing` refreshes outdated hashes; the Advanced tab's job rows, `unindexed-hashes` skipped by a plain run, `default_algorithm`, the API permission not gating the pages, the recalc audit line. | Fix: short clauses in the existing bullets. |
| R12, R13 | low | `[0.19.0]` and `[0.14.1]` sections | Two facts lost in the rewrite: the Rule Editing Permission page's Save left-aligned and the global rule pinned server-side; the 0.14.1 exposure (paths and hashes readable, recalculation forced by hash or id). | Confirmed by reading the old text. Fix: restore the clauses; `changelog.sh tag … update --force --push` for the two tags. |
| R14 | info | all 36 tags | The tag rewrite gave every tag today's tagger date. Sections keep the release dates. | Decision 2 below. |
| T3 | low | `checksums.cy.js` three-row assertion | Metadata rows of a deleted account outlive it until the purge. | Not reproduced: two full runs after deletions passed, and the listing drops a row whose filecache row is gone (`HashIndexService` resolves through `batchLookupFilecachePaths`). Cheap hardening: `--step orphaned-metadata` in `before()`. |
| T4 | low | `RuleForm.spec.ts` cancel case, `RuleForm.vue` `onOpenChange` | The case tests the form's own document listener, not the dialog; `onOpenChange` is dead with `no-close`. | Fix: rename, delete the dead handler. |
| T5 | low | `.eslintrc.cjs` | The selector misses `vi.doMock`, `vi.mock('@nextcloud/vue')`, `vi.mock(import(…))`, and `stubs: { NcX: true }`. | Reviewer proved with a throwaway spec. Fix: widen the selectors. |
| T6, T7, T14 | low | `src/test-utils/` | Two wrong comments (mousedown on the search input does open; the arrow on an open menu moves, Enter selects); `openSelect` returns `[]` silently when the menu did not open; two helpers wait differently. | Fix: correct, throw, unify. |
| T8 | low | specs | Most wrappers never unmount; menus and keydown listeners pile up in the body; `sessionStorage` cleared in one spec only. Harmless today. | Fix: `enableAutoUnmount(afterEach)` and a `sessionStorage.clear()` in the setup file. |
| T9 | low | e2e reaper | Unquoted uid in the shell; `user:list` default limit 500; a swallowed parse failure; deletes a concurrent run's accounts on a shared instance. | Fix: `--limit`, quoting, a `cy.log`, a README sentence. |
| T10, T11 | low | `checksums.cy.js`, `duplicates.cy.js` | The sidebar case depends on earlier cases having hashed the files; Cypress does not reload on a hash-only `cy.visit`, so the "shared address opens" step tests `hashchange`, not load. | Fix: hash in `before()`; `cy.reload()` after the visit. |

## Tier 3 — noted, not planned

S6 (occ text form prints the path, not the location, for an ownerless
row; JSON gives `""` where the API gives `null`), S7 (a stale `user` key
in a `HashIndexServiceTest` fixture), S8 (the hash in the sidebar link's
fragment is acceptable; a sentence on browser history in the docs),
F12–F13 (a transient bare fragment before the listing reports; *anywhere*
without a term not written), T12 (the clear path in `AlgorithmSelect` is
unreachable), R15 (OpenAPI `default: sha1` for recalc where the code uses
the instance default).

## Decisions for the user

1. **S4, a link that loads without a click.** Keep as is (the data is the
   viewer's to see, and the link is the point of SidebarScope), or
   require one click on a scope that came from the address. Recommended:
   keep, and say so in the banner text.
2. **R14, the tag dates.** Leave the tagger dates at 2026-09-22, or re-tag
   all 36 with `GIT_COMMITTER_DATE` set to each release date and force-push
   once more. Recommended: re-tag; a forge lists tags by that date.
3. **Scope of the AP.** Tier 1 alone, or Tiers 1 and 2. Recommended: both,
   ordered so Tier 1 lands first.

## Verified correct by the reviewers, in short

`canSudo` computed from the session uid only and added on 200; `selectable`
sets `all` only after admitting the caller; every cross-account read
authorised and confirmed server-side whatever a URL names; an empty reach
matches nothing on every path; `openable` against the viewer's mounts
only; rate-limit pairs in step but one; no `user` reader left; no XSS
sink reached by URL-derived strings; no loop between the listing's emit
and its prop; one request on mount; back and forward restore each tab;
`generateUrl` correct on sub-directory installs; every rewritten spec
asserts as much as before or more but T1; ResizeObserver and Teleport
stubs sound for every spec; `define` the right place; `changelog.sh check`
and `sync-docs.sh --check` clean; all 24 commit messages valid; every
shipped-path commit carries or exempts its bullet; 36 tags on their
release commits; OpenAPI parses with all 122 refs resolving and 23
operations matching the routes.
