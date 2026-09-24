# AP CaptureFindings v1.0: what the screenshots showed

> **Status: proposal, 2026-09-24.** Six defects seen while capturing the
> screenshot set (commits `3ed3c59` and `31f2d5f` name them). None is in
> a released version's changelog as a fix yet; four are in unreleased
> features and amend their bullets, two are older and go under Fixed.

## Analysis

1. **Own-file labels carry a `files/` prefix.** `fileLabel()` returns
   `file.path` for the viewer's own rows, and the filecache path starts
   with `files/`. The user guide promises "the path they know".
2. **The admin rules table overflows the settings column at 1440 px.**
   `RuleTable.vue` fixes nine column widths summing to 92 % under
   `table-layout: fixed`; the actions column is `nowrap` and
   `overflow: visible`, so pen, menu and *Create rule* push past the
   table's box. At 1600 px it fits.
3. **Trashed files show as duplicates, with hashes a reset should have
   cleared.** Five `files_trashbin/…` rows headed every group on the first
   capture, carrying five algorithms where the one enabled rule computes
   two, so they predated the run. `clearHashesNow()` walks every
   `files_metadata_index` row whose key is a checksum, path or no path;
   so either those files had documents without index rows, or the rows
   came back after the reset. Which, an experiment says (block 4). Either
   way two things hold: a file in the trash is governed by nothing, per
   the contract, so the listing should not offer it as a duplicate of a
   live file; and a reset must leave nothing hashed behind.
4. **The provider-missing badge is hidden by the Scope cell.** The badge
   sits after the label inside a cell that ellipsises; a label like
   *Team Folders: 99 (#99)* fills it. The rules e2e asserts existence, not
   visibility.
5. **The sidebar's refusal text is near-invisible on the light theme.**
   `src/sidebar.css` sets `.fcias-error` to `--color-error-text` and says
   why; the scoped `.fcias-error { color: var(--color-error) }` in
   `ChecksumsSidebarTab.vue` wins by specificity, and `--color-error` is a
   fill.
6. **The refusal says "an administrator rule" of any exclude rule.**
   `ChecksumApi::recalcHash()` returns one fixed string beside the
   `ruleId` it already knows; alice reads it of her own rule.

## Implementation Plan

1. **[FIX] Own files by the path the viewer knows.** `fileLabel()` strips
   one leading `files/` from an own row's path; a location is untouched.
   A `fileLabel.spec.ts` case; the sidebar's duplicate list, which uses
   the same function, checked once by eye. CHANGELOG: the *Every file
   row* bullet under Changed, amended.
   **Verification:** Vitest; `duplicates` e2e.

2. **[FIX] The rules table fits its column, and the badge is seen.** The
   actions column gets a width in the `<colgroup>` and Path gives it up;
   below 1280 px the pen moves into the menu. The badge moves to the
   Status cell beside the state pill, where a fixed-width cell cannot
   swallow it, and `rules-admin.cy.js` asserts it is visible. CHANGELOG:
   the *Rules table* badge bullet amended; the overflow is unreleased
   layout and amends the *Both settings pages* bullet.
   **Verification:** Vitest for `RuleTable`/`RuleRow`; `rules-admin` and
   `rules-personal` e2e at 1280 and 1600 wide.

3. **[FIX] The refusal readable, and honest about whose rule it is.** The
   scoped colour rule deleted, so `sidebar.css` applies. The API answers
   `error` as "Hashing is excluded for this path by a rule" plus `ruleId`
   and `ruleOwner` (`admin` for an enforced or administrator-made rule,
   else the uid), and the sidebar says "by your rule" or "by an
   administrator's rule" from that. A `ChecksumApiTest` case per owner;
   a sidebar spec asserting the computed colour and both wordings.
   CHANGELOG: the *Sidebar: a refused recalculation* bullet under Fixed,
   amended.
   **Verification:** PHP suite; Vitest; `rules-personal` e2e, whose
   refusal case reads the new text.

4. **[FIX] Nothing in the trash is hashed, listed or kept.** First the
   experiment, recorded in the gate: trash a hashed file, run
   `fcias:reset --hashes --force --now`, read its metadata document and
   its index rows, then run one sweep and read again. Then, by what it
   says: the sweep and the file-event path skip `files_trashbin/`,
   `files_versions/` and `appdata_*` as the contract already claims; the
   listing and the lookup drop rows whose path begins with those; the
   reset clears a document that has no index row. Integration cases for
   each of the three. CHANGELOG: one bullet under Fixed.
   **Verification:** PHP suite; `duplicates` e2e; the capture spec's
   trash cleanup in `before()` removed, since the page no longer needs it.

## Proposed commit message

One per block, `[FIX]`, each naming this plan and its block. The last
retires the plan on the user's word.

## Open decisions

1. **Block 4's scope.** Skipping the trash in the sweep changes what an
   enabled `home:*` rule hashes; a user who wanted trash hashed for
   recovery has no way to ask. Recommended: skip it, as the contract says,
   and say so in the README's *When hashes go away*.
2. **The badge's cell.** Status, or the row's glyph. Recommended: Status.

## Gate

PHP suite, `npm run lint`, `npx vitest run`, `npm run build`, the e2e
named per block; the full e2e once at the end. Block 4 waits for its
experiment's reading at its own gate before any code changes.

## Change History

- v1.0 (2026-09-24): written from the six findings in `3ed3c59` and
  `31f2d5f`.
