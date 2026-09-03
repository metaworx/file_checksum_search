# AP SuggestedFeatures v1.0: Design new features

**Source report:** [`.aiassistant/messages/2026-08-22_01-22_MSG_FCIASReview_v1.0_consistency_and_quality_review.md`](.aiassistant/messages/2026-08-22_01-22_MSG_FCIASReview_v1.0_consistency_and_quality_review.md), §8 (Potential Missing Features)

---

## Discussion

The source report's §8 offered 7 feature ideas as candidates, not commitments. The user selected 5 of those for design in this AP, then added 2 more of their own (Features 6-7 — not from the source report):

1. **Act on duplicates** — bulk delete/move from the duplicate browser, not just view.
2. **Exclude-globs on rules** — carve exceptions out of a broad include-path rule.
3. **Export a duplicate report from the UI** — the CLI already has `--output=json`; the browser page doesn't.
4. **API rate limiting** — `docs/api-v1.md` documents a detailed scheme (config keys, 429s, `retry_after`) that is **completely unimplemented** (confirmed by grep — zero references anywhere in `lib/`, not even registered in `ConfigLexicon`). The user asked directly: implement it, or strip the docs?
5. **Push notifications on drift** — notify a file's owner when its content silently changed (hash mismatch on recalculation), instead of purely pull-based checking.
6. **Permission to calculate new hashes** — an admin-configurable allow-list (all users / groups / users) gating who may trigger on-demand recalculation, mirroring the existing rule-editing permission model.
7. **Permission to use the REST API** — an admin-configurable allow-list gating who may call the public API surface at all.

**Deliberately excluded from this AP** (not selected): the instance-wide scheduled drift report, and clarifying file-version hash behavior. Both remain candidates for a future pass if wanted.

**Relationship to the settings-Vue-migration AP:** Feature 2 (exclude-globs) touches the rule form UI. It's written here against the *current* vanilla-JS forms so it isn't blocked on the Vue migration landing first, but if both are approved, doing the Vue migration first means Feature 2's UI piece is built once, in Vue, instead of once now and once after the migration. That ordering call is left to the user — Block ordering below assumes exclude-globs' backend/matching logic ships independently of frontend timing either way.

Each feature below is designed to the point of concrete implementation blocks, but genuinely open product decisions are called out explicitly and **this AP should be re-confirmed per feature before execution** — unlike a bug-fix AP, "design" work has more than one reasonable answer at several points.

---

## Feature 1: Act on duplicates

### Analysis

**Decision points — the following are the user's explicit decisions, not recommendations under discussion:**

- **Keep-policy: manual.** Confirmed. A single "keep" target is chosen per group (radio selector); no automatic oldest/newest/shortest-path heuristic in the UI. Automatic presets remain a possible v2 convenience for the UI, and are the *only* option for the non-interactive CLI (see Design below), since scripted use has no one to ask.
- **Action: delete + merge. Move is dropped entirely** — no use case, per explicit instruction. This removes all `--action=move`/`targetFolder` plumbing from the design.
  - **Delete is a selectable set, not a single-keep-implies-remove-all-others radio.** The user checks an arbitrary subset of the *other* files in the group (not the kept one) for removal via checkboxes — a partial resolution that leaves some duplicates untouched for later is valid. At least one file (the kept one) must always remain; the UI must not allow checking every file in the group.
  - **Merge means merge version history**, not a filesystem reference/symlink (the earlier draft of this AP had wrongly assumed "merge" implied a virtual-file mechanism NC doesn't support, and recommended dropping it — that recommendation is superseded). Concretely: before a duplicate is deleted, its Nextcloud version history (previous content revisions tracked by the separate `files_versions` app) is imported into the kept file's version history, so the historical revisions aren't silently lost when the duplicate goes away.
- **Safety net.** `Node::delete()` goes to the trash bin by default — already reversible without extra work. No custom undo mechanism needed. Merge failures (see below) must never block deletion — a duplicate whose history couldn't be merged is still safe to remove; the user just loses that particular file's history, same as an ordinary delete would do anyway.
- **Scope: personal-only for v1.** An admin acting on *other users'* duplicates raises the same cross-user considerations Blocks 1–3 of the parent AP just finished fixing. **Recommend v1 ships personal-only** (a user resolves duplicates among files they themselves can access, using the same ownership-scoped `findByHash`/`findAllDuplicates` path already fixed), with an admin cross-user variant deferred to a follow-up if wanted.
- **Deciding "which to keep" needs more than a path.** Each duplicate shows its date, size, an open-in-new-tab link, and a preview — i.e. don't make someone choose between 3 identically-named `report.pdf` copies with no way to tell them apart.
- **Reusing Nextcloud's core previewer:** confirmed — the bundled `viewer` app exposes a public JS integration point, `window.OCA.Viewer.open({ path, list?, fileInfo? })` (the same mechanism the Files app, Photos, and Text use for their own "open preview" actions). This means "preview window" doesn't require building anything — call the existing global API with the duplicate's path, and Nextcloud's own viewer (image/video/PDF/text/etc. handlers) takes over. **Verify the exact call signature against the installed NC version's `viewer` app at implementation time** rather than trusting this description — this is public but not `OCP`-versioned API, so treat it as "very likely correct, confirm before shipping," not as already-checked code.
- **The real API for "merge version history": found and confirmed.** Nextcloud's file versioning is **not** part of core `OCP` — like `viewer`, it's owned entirely by a separate, normally-bundled app, `files_versions`. That app exposes a genuine `@since`-versioned public API for exactly this use case: `OCA\Files_Versions\Versions\IVersionManager` (`getBackendForStorage(IStorage): IVersionBackend`), whose backend can optionally implement `IVersionsImporterBackend` (`@since 29.0.0`) — `importVersionsForFile(IUser $user, Node $source, Node $target, array $versions): void`, explicitly documented for importing one node's existing version history into another (its doc comment even notes "the source might not exist anymore," i.e. it's designed to run around/after the source's removal — a strong signal this is the intended integration point for exactly this kind of operation, not a repurposed one). Concretely: `getVersionsForFile($user, $sourceFileInfo)` to list the duplicate's versions, then `importVersionsForFile($user, $sourceNode, $targetNode, $versions)` to copy them onto the kept file's history. **Caveat, same shape as the `viewer` dependency:** treat `files_versions` as an optional app dependency — check `class_exists(IVersionManager::class)` and that the resolved backend `instanceof IVersionsImporterBackend` before attempting a merge (not every storage backend supports import — e.g. some object-storage or external-storage backends may not); if unsupported or the app is disabled, skip the merge for that file, log it, and still proceed with deletion rather than blocking the whole operation.

### Design

- **Backend:** `FilecacheService::batchLookupFilecachePaths()` already joins against `filecache`, which natively has `mtime` and `size` columns — extend its `SELECT` to include them (`fc.mtime, fc.size`), free of any extra query cost, and add `mtime`/`size` to its returned shape and to `DuplicateService::findByHash()`/`findAllDuplicates()`'s per-file result. No new query needed anywhere this data already flows through.
- New `DuplicateService::resolveGroup(string $userId, string $keepFileId, array $fileIdsToRemove, bool $mergeHistory): array`. Before acting, re-verifies server-side that every file ID in the group (kept + all to-remove) still shares the same full hash (defense in depth — never trust a client-submitted group membership) and that `$userId` can access every file (reusing the `userCanAccessFile()` pattern from `ChecksumApi`). Rejects with a clear error if `$fileIdsToRemove` is empty or includes `$keepFileId`. For each file in `$fileIdsToRemove`: if `$mergeHistory`, attempt the version-history import described above via a new small `VersionMergeService` wrapping the `files_versions` dependency (best-effort — availability/support failures are caught, logged, and reported per-file in the response as `mergeSkipped: true` with a reason, never thrown); then call `Node::delete()`. Returns a per-file result array (`fileId`, `deleted: bool`, `merged: bool`, `mergeSkipped?: string reason`) so the UI can show exactly what happened, including partial outcomes.
- **API:** `POST /api/v1/duplicates/resolve` — body `{ keep: fileId, remove: [fileId, ...], mergeHistory: bool }`. Ownership-scoped to the caller (no `$requestingUser = null` bypass from this endpoint — always scoped, even for admins, since this is a mutating/destructive action on the caller's own files by design). The existing `findAllDuplicates`/`findByHash` responses now also carry `mtime`/`size` per file (see above) — no new endpoint needed for the metadata itself.
- **CLI:** new command `file-checksum-search:resolve-duplicates --user=<uid> --algo=<algo> --keep=<strategy> [--merge-history] [--dry-run]`, where `--keep` accepts `first`/`newest`/`oldest` for scripted use. **Documented UI/CLI asymmetry:** the CLI always targets the full remainder of each group (every file except the one selected by `--keep`) as the remove set — a non-interactive script has no equivalent to the UI's arbitrary checkbox subset, so partial/selective resolution is a UI-only capability. `--merge-history` applies the same best-effort merge per removed file, printing a per-file summary line (removed / merged / merge-skipped-and-why).
- **Frontend (`DuplicateGroup.vue`):** each file row gains a formatted date (`mtime`) and human-readable size, alongside the existing path/name. A "Preview" icon-button per row calls `window.OCA.Viewer.open({ path: file.path })` (guarded by `typeof OCA?.Viewer?.open === 'function'`; falls back to the existing open-in-new-tab link if unavailable, never hard-depend on it). A "Keep" radio selector picks the single survivor; every *other* row gets a "Remove" checkbox — an arbitrary subset may be checked, but the UI disables submitting a state where every non-kept file is unchecked (nothing to do) as well as one where the kept file is somehow also checked. A "Also merge removed files' version history into the kept file" toggle (off by default, since it's an extra operation with its own failure mode to surface) sits above the "Resolve" button. Clicking Resolve shows a confirmation dialog (which files are removed, whether merge is requested, and — after the call completes — which files' merges actually succeeded vs. were skipped, from the per-file response) before/after calling the new endpoint. A group re-renders showing only the files that remain after a successful (possibly partial) resolution.

### Implementation blocks

**Block 1 — Backend: expose `mtime`/`size` on duplicate results + tests**
Extend `FilecacheService::batchLookupFilecachePaths()`'s query and return shape; thread the two new fields through `DuplicateService`/`ChecksumApi`/`docs/api-v1.md`'s response schema. Tests: mocked filecache rows with known mtime/size come through unchanged on the response.

**Block 2 — Backend: `VersionMergeService` (files_versions integration) + tests**
A small service wrapping `OCA\Files_Versions\Versions\IVersionManager`: resolve the backend for a node's storage, check `instanceof IVersionsImporterBackend`, list the source's versions, import them onto the target. Defensive against the app being absent/disabled (`class_exists()` guard) or the backend not supporting import — both cases return a typed "skipped" result rather than throwing. Tests: successful import (mocked manager/backend), app absent, backend doesn't support `IVersionsImporterBackend`, backend supports it but import throws (caught and reported, not propagated).

**Block 3 — Backend: `resolveGroup()` + API endpoint + tests**
Add `DuplicateService::resolveGroup()` (using Block 2's service when `$mergeHistory` is true), the `#[NoAdminRequired]` `POST /api/v1/duplicates/resolve` route on `PublicApiController`, ownership + full-hash re-verification. Unit tests covering: happy-path delete-only, happy-path delete+merge (merge succeeds), delete+merge where merge is skipped (still deletes), a file that fails re-verification (hash doesn't actually match — must be rejected), a file the caller doesn't own (must be rejected), `remove` empty or containing `keep` (must be rejected).

**Block 4 — CLI: `resolve-duplicates` command + tests**
New command with `--dry-run` support (prints what would happen without acting), the `first`/`newest`/`oldest` keep strategies, and `--merge-history`. Unit tests per strategy, `--dry-run`, and `--merge-history` on/off.

**Block 5 — Frontend: date/size display + Viewer preview + keep/remove/merge UI + tests**
`DuplicateGroup.vue` changes (metadata columns, preview button with the `OCA.Viewer` availability guard, keep-radio, per-file remove-checkboxes with the "at least one file must remain" guard, merge-history toggle, Resolve button + confirmation/result dialog), a `useDuplicates.ts` `resolveGroup()` action, and Vitest coverage: metadata renders correctly formatted; preview button calls `OCA.Viewer.open` with the right path when available, and falls back to the existing link when it isn't; selection guards (can't check every file, can't check the kept file); resolve flow with and without merge, including a mixed merged/skipped response rendering correctly; cancel the confirmation, assert no call.

**Block 6 — Docs**
`README.md` (Duplicate File Browser section — mention date/size/preview and the resolve action, including the merge-history feature and its `files_versions` app dependency caveat), `docs/api-v1.md` (new endpoint, `mtime`/`size` fields on existing ones, per-file merge result shape), `docs/api-v1-openapi.yaml`, `docs/HELP.md` (user-facing walkthrough), CLI reference table.

---

## Feature 2: Exclude-globs on rules

### Analysis

**Superseded design.** The initial draft proposed a per-rule `excludePath` field bolted onto each include rule. The user's explicit correction replaces this: **rules themselves get a `type: include | exclude` field, and matching is first-match-wins over the rule list in stored order** (not per-rule exclude globs, and not directory-depth/specificity-based — purely list order, which is how `findFirstMatchingRule()` already iterates today). This is a cleaner mechanism: instead of every include rule needing its own exclusion carve-out, an administrator places a narrow *exclude* rule earlier in the list to carve an exception out of a broader *include* rule that comes after it — the same `path` glob field does double duty for both rule types, so no second glob field is needed at all.

**Prerequisite gap found: rule order isn't currently user-controllable.** `RuleService`'s docblock already states "rules are processed in order (first = highest priority)," but a grep of `SettingsController`/the admin JS turned up no reorder mechanism — rules are evaluated in whatever order they're stored in (append order), with no up/down/drag control in the UI. This was a latent gap even before exclude rules (an admin who wanted a narrower include rule to win over a broader later one already had no way to guarantee that), but it becomes load-bearing once exclude rules exist, since an exclude rule placed after the include rule it's meant to carve an exception out of would simply never be reached. **Recommend adding basic reordering (move up/move down per rule row) as part of this feature**, not deferred — otherwise "first match wins, in order of the rules" is a design promise the UI can't actually let an admin fulfill.

### Design

- **Data model:** add `type: 'include' | 'exclude'` to the rule definition shape (stored in the same JSON blob `RuleService` already persists via `IAppConfig` — no DB migration needed). Rules with no `type` (all pre-existing rules) default to `'include'` — fully backward compatible, no data migration required.
- **Matching:** `RuleService::findFirstMatchingRule()` and `processRule()` keep their existing sequential-order iteration unchanged. The only new behavior is at the first `path`-matching rule (after the existing userScope filtering): if `type === 'exclude'`, stop and return "no rule applies" immediately — do **not** continue scanning for a later include match. If `type === 'include'` (or absent), behave exactly as today. This makes rule order a real priority list for both rule kinds together, not two independent lists.
- **Rule reordering:** add a `position`/array-index-based move-up/move-down affordance. Simplest implementation: `SettingsController`/`PersonalSettingsController` gain a `reorderRules(array $orderedIds)` endpoint that rewrites the stored rule array in the given order (validated to be a permutation of the existing IDs, nothing added/removed); the UI's up/down buttons submit the whole new order after a local swap. (A full drag-and-drop reorder is a nicer UX but a materially bigger frontend change — up/down buttons deliver the same capability with a small, well-tested surface; flag if drag-and-drop is wanted instead before Block 2.)
- **Backend validation:** `type` is validated against the two allowed values, same trust-boundary treatment as other client-supplied rule fields. `path` keeps its existing glob validation for both rule types — an exclude rule's glob is not exempt from whatever sanity checks include-rule globs already get.
- **UI:** the rule form gains a "Rule type" selector (Include / Exclude) placed above the Path field, since it changes what the rest of the form means. When `type === 'exclude'` is selected, the Algorithm/Mode fields are hidden (an exclude rule computes nothing, so there's nothing to configure there) — only Path and the scope/userScope fields remain relevant. The rule list gains move-up/move-down buttons per row, disabled at the top/bottom edges.

### Implementation blocks

**Block 1 — `RuleService` matching logic + tests**
Add `type` handling to `findFirstMatchingRule()` and `processRule()`: first `path`-matching rule with `type === 'exclude'` short-circuits to "no rule applies"; default-to-`'include'` for rules with no `type`. Tests: an exclude rule placed before a broader include rule carves out the exception; the same exclude rule placed *after* that include rule has no effect (order matters, confirming the "no fallback scan" behavior); a rule list with only legacy (no-`type`) rules behaves identically to before this change.

**Block 2 — Reordering endpoint + UI move-up/move-down + tests**
`reorderRules()` on both `SettingsController` and `PersonalSettingsController` (permutation-only, rejecting any ID set mismatch), plus up/down buttons in the current vanilla rule-list UI (or the Vue `RuleTable.vue` if the migration AP has landed by the time this executes). Tests: valid reorder persists and is reflected in subsequent `findFirstMatchingRule()` evaluation order; a reorder payload with a missing/extra/duplicate ID is rejected without mutating stored state.

**Block 3 — Admin/personal form: rule-type field + tests**
`SettingsController`/`PersonalSettingsController` accept and persist `type`; form gains the Include/Exclude selector with the Algorithm/Mode fields conditionally hidden for Exclude. Tests for save/round-trip of both rule types, and that omitting `type` on save still defaults correctly.

**Block 4 — Docs**
README's Hash Generation Rules table (explain include vs. exclude rules and that order determines priority for both), `docs/FAQ.md`'s "How do rules work?" pointer section, `docs/HELP.md` if the personal form is user-facing enough to need a mention.

---

## Feature 3: Export a duplicate report from the UI

### Analysis

The duplicate browser already holds the currently-loaded groups client-side (`useDuplicates.ts`'s `groups` ref). **Recommend a client-side export** (no new backend endpoint) for v1: generate a JSON or CSV Blob from the currently-filtered result set and trigger a browser download. This covers the common case (export what you're looking at) with zero backend risk. A "full export beyond the current page/limit" is a natural v2 if the current-page export proves insufficient — noted but not built here.

### Design

- Two buttons next to the existing "Verify hashes" control in `App.vue`: **Export JSON** and **Export CSV**, both operating on `filteredGroups.value` (respecting the "Only matching" toggle already in place).
- CSV shape: one row per file, columns `algo, hash_value, fileid, path, name, verified` (verified columns blank if verification hasn't run) — flattening the group structure so it opens sensibly in a spreadsheet.
- Uses a `Blob` + temporary `<a download>` object-URL, the standard browser pattern — no server round-trip.

### Implementation blocks

**Block 1 — Export buttons + serialization + tests**
Add `toJson(groups)` / `toCsv(groups)` pure functions (easily unit-testable in isolation) plus the two buttons wired to trigger a download. Vitest coverage for the serialization functions (given a sample group list, assert the exact JSON/CSV output, including correct escaping of commas/quotes in path names for CSV).

**Block 2 — Docs**
README's Duplicate File Browser section, `docs/HELP.md`.

---

## Feature 4: API rate limiting — implement, or remove the documentation?

### Analysis

`docs/api-v1.md` describes a fully custom scheme: `occ config:app:set` keys (`rate_limit_enabled`, `rate_limit_max_requests`, `rate_limit_window_seconds`), a 429 response shape, `retry_after`. None of it exists in code. Two honest paths forward:

- **(A) Implement it — but simpler than documented.** Nextcloud's `OCP\AppFramework\Http\Attribute\UserRateLimit` / `AnonRateLimit` attributes give real, standard rate limiting per-route with almost no code (`#[UserRateLimit(limit: 60, period: 60)]` on a controller method) — no custom config keys, no custom limiter to build and maintain. **This AP recommends (A)** using the built-in attributes on the genuinely expensive endpoints (`lookup`, `findAllDuplicates`, and especially `recalcHash`, which does real file I/O), and **rewriting** the docs' Rate Limiting section to describe this simpler, real mechanism instead of the aspirational config-key scheme.
- **(B) Remove the documentation.** If rate limiting isn't actually a priority right now, deleting the section is strictly better than leaving it as currently written — a reader who follows the documented `occ config:app:set` commands today gets no error and no effect, silently believing they've enabled protection that doesn't exist.

**This is the one feature in this AP where "do nothing structural, just fix the docs" is a fully legitimate outcome** — flagging for explicit confirmation before Block 1 below is executed, since it picks (A) as the default.

### Design (path A — implement)

- Add `#[UserRateLimit(limit: 60, period: 60)]` to `lookup()` and `findAllDuplicates()` on `PublicApiController` (and the legacy `LookupController::byHash()`); a tighter `#[UserRateLimit(limit: 20, period: 60)]` on `recalcHash()` given it triggers real file reads. Limits are illustrative starting points, not final — tunable before merge.
- No admin-facing config UI for v1 (matching "simpler than documented" — fixed, sane limits shipped in code, not admin-configurable) unless the user specifically wants that surface back, in which case `IAppConfig`-backed limit values read at attribute-evaluation time would need a small custom middleware instead of the static attribute (a materially bigger change — flag before choosing this).

### Implementation blocks

**Block 1 — Decision:** confirm (A) implement-simplified vs. (B) docs-only removal, before any code changes.

**Block 2 (if A) — Apply rate-limit attributes + tests**
Add the attributes; add integration tests hitting the real endpoints past the limit and asserting a 429 (NC's rate limiter needs a real backend cache — verify the ddev test environment has one configured, or mark this integration-only).

**Block 2 (if B) — Strip the documentation**
Remove `docs/api-v1.md`'s Rate Limiting section and its ToC entry, and the two `retry_after` schema fields in `docs/api-v1-openapi.yaml` — no code changes.

---

## Feature 5: Push notifications on drift

### Analysis

"Drift" here means **integrity drift**: a file's recalculated hash no longer matches what FCIAS had stored — i.e. the content changed without going through a path FCIAS's own event listeners observed (an external tool wrote to storage directly, a restore from an external backup, etc.). This is distinct from "a new duplicate group was found," which isn't inherently bad and would be noisier to notify on. **Recommend v1 scope to integrity-drift notifications only**; "new duplicate found" notifications are a candidate v2, not built here.

### Design

- Register an `INotifier` (`lib/Notification/Notifier.php`) via `Application::register()`'s `IRegistrationContext::registerNotifierService()`; implements `prepare()` to format the notification (icon, translated message referencing the file path, a link into the Files app).
- **Trigger point:** `HashCalculationService::recalcFileHash()`/`processFile()` already has the "old hash" (before clearing) and "new hash" (after recompute) in scope during a recalculation. When both exist and differ, and the mode wasn't `force` (which *intentionally* discards and recomputes — not drift, that's the admin/rule asking for it), dispatch a notification to the file's owner via `\OCP\Notification\IManager::createNotification()->setApp(...)->setUser($ownerUid)->setDateTime(...)->setObject('file', $fileId)->setSubject('checksum_drift', [...])`, then `$manager->notify($notification)`.
- **Opt-out:** a personal-settings toggle ("Notify me when a file's checksum changes unexpectedly") defaulting to **on** for `force`/`lazy`-independent auto/missing recalculation drift, since silent content changes are exactly the kind of thing a checksum tool exists to catch — but must be easy to turn off to avoid noise for users who recalculate frequently on purpose.
- **Avoid notification storms:** a bulk `occ file-checksum-search:rebuild` or `:generate` run touching thousands of files must not fire thousands of notifications. Recommend batching: accumulate drift events during a single command/job run and send **one summary notification** ("12 files changed unexpectedly since their last checksum — click to review") rather than one per file, linking to a filtered view rather than enumerating paths in the notification itself.

### Implementation blocks

**Block 1 — Notifier registration + summary-notification plumbing**
`Notifier.php`, registration, and a small `DriftNotificationCollector` service that `HashCalculationService` reports drift events into during a single command/job run, flushed to one notification at the end of that run (not one per file).

**Block 2 — Wire drift detection into recalculation**
`recalcFileHash()`/`processFile()` reports to the collector when an old hash existed, differs from the new one, and the mode isn't `force`. Tests: drift detected → collector receives an event; `force` mode → no event; no prior hash → no event (that's not drift, that's a first hash).

**Block 3 — Personal-settings opt-out toggle**
A boolean preference (`IUserPreferences`-backed), default on, surfaced in personal settings (vanilla form now, or `RuleForm`'s page if the Vue migration lands first — this toggle isn't part of a rule, so it's a standalone settings-page control either way).

**Block 4 — Docs**
`docs/FAQ.md` (new "Will I be notified if a file's checksum changes?" entry), README feature list, `docs/HELP.md`.

---

## Feature 6: Permission to calculate new hashes

### Analysis

Today, any authenticated user can trigger on-demand recalculation of their own files — the sidebar's Recalculate button, and the `/api/v1/file/{fileId}/recalc` endpoint (ownership-scoped since the parent AP's Block 1). There's no admin control over whether a given user is *allowed* to do this at all, distinct from whether they *own* the file. This mirrors the existing rule-editing permission gap that already has a solution in this codebase — `RuleService`'s `isAllUsersEnabled()` / `getRuleEditorGroups()` / `getRuleEditorUsers()` / `canUserEditRules()` — so this feature is largely "build the same triple again for a different action" rather than new design territory.

**Decision point: where should this permission live?** `RuleService`'s own docblock scopes it to "rule evaluation engine for hash-generation rules" — a manual-recalc permission isn't about rules at all, and Feature 7 (API-use permission) needs the identical allow-all/groups/users shape a third time. **Recommend extracting a small, generic `PermissionService`** with a reusable `isAllowed(string $permissionKey, string $userId): bool` plus `getAllUsersEnabled()`/`getGroups()`/`getUsers()`/setters parameterized by a permission key (`'rule_editing'`, `'manual_recalc'`, `'api_access'`), and migrating `RuleService`'s existing three config keys to it under the hood (config key names stay the same on disk — `rule_editors_all_users` etc. — only where the logic *lives* changes, so no data migration needed). This avoids a third copy-pasted permission triple and gives Feature 7 the same mechanism for free. Alternative: keep bolting onto `RuleService` and accept the naming mismatch — simpler diff, worse cohesion. Flagging for confirmation before Block 1.

**What does "not permitted" mean in practice?** The user still owns their files and can still see hashes already computed (read access is unaffected — this gates *triggering new computation*, not visibility). A denied recalc attempt should behave like the existing `admin_enforced` rule case: a clear 403 with an explanatory message, and the sidebar's Recalculate button hidden/disabled rather than present-but-failing.

### Design

- `PermissionService::isAllowed('manual_recalc', $userId)`, checked in `ChecksumApi::recalcHash()` (in addition to, not instead of, the existing ownership check) — both `PublicApiController` and the legacy `LookupController` already thread a `$requestingUser`/scope through this call (Block 1), so the permission check slots into the same place.
- Admin settings gains a "Manual Recalculation Permission" section, identical in shape to the existing "Rule-editing permissions" section (allow-all toggle, groups list, users list) — same UI pattern, different backing key.
- Sidebar: `useSidebarHashes.ts`'s `recalc()` action and the Recalculate button become conditional on a new `canRecalc` flag, sourced from `getHashes`'s response (add a `canRecalc: boolean` field there, computed server-side the same way `canEdit` is computed for personal rules) rather than a second round-trip.

### Implementation blocks

**Block 1 — `PermissionService` (generalizing the existing pattern) + tests**
Extract the generic service; migrate `RuleService` to delegate to it for rule-editing permission (behavior-preserving refactor, covered by existing `RuleServiceTest.php` cases continuing to pass unchanged). Add its own focused tests for the generic `isAllowed()`/group/user logic.

**Block 2 — Admin settings section + backend enforcement + tests**
`SettingsController` endpoints to read/save the manual-recalc permission triple (mirroring `getAdminOptions`/`saveAdminOptions`). `ChecksumApi::recalcHash()` enforcement. Tests: allowed user recalculates successfully; denied user gets 403; `canRecalc` appears correctly in `getHashes`'s response.

**Block 3 — Frontend: gate the Recalculate button + tests**
Sidebar hides/disables Recalculate when `canRecalc` is false, with an explanatory tooltip. Vitest coverage for both states.

**Block 4 — Docs**
README (new permissions section, alongside the existing rule-editing permissions description), `docs/FAQ.md`, `docs/api-v1.md` (note the new 403 case on `/recalc`).

---

## Feature 7: Permission to use the REST API

### Analysis

**The central design nuance:** the app's *own* Vue UI (duplicate browser, sidebar) already calls these exact same `/api/v1/*` routes internally via `fetch()`, using the browser's Nextcloud session cookie. If "permission to use the REST API" is enforced against *every* call to these routes indiscriminately, a user denied this permission loses the in-app features too — almost certainly not the intent. README documents three auth methods: session cookie, HTTP Basic Auth, and Bearer token. **Recommend this permission gates only Basic-Auth and Bearer-token authenticated requests** (i.e. external/programmatic access — scripts, app passwords, third-party integrations) **while same-origin session-cookie requests (the bundled UI) are always allowed** for a user's own data, since those already go through the app's own permission-gated screens.

**How to distinguish the two at request time:** Nextcloud's request pipeline authenticates Basic-Auth/Bearer-token requests through a different path than an existing browser session; the cleanest signal available to app code is typically whether a real, persistent NC session exists for the request versus token-based re-authentication on every call. This needs a concrete implementation check against the actual NC version's request/session APIs before Block 1 is executed — flagging as a technical spike, not a settled design point, since getting this distinction wrong either leaks the bypass (permission does nothing) or breaks the in-app UI (permission blocks everyone).

**Decision point: which endpoints does this gate?** Recommend: all of `/api/v1/*` and the legacy `/api/1.0/*` — the entire public API surface, both versions — since a user denied "API access" presumably means "no programmatic access to this app's data by any documented route," not a per-endpoint carve-out.

### Design

- Uses the same `PermissionService` from Feature 6, a third permission key: `'api_access'`.
- New middleware-style check (an NC `Middleware` subclass registered for this app, or a shared trait/method called at the top of every public-API controller action) that: (1) determines if the current request is browser-session-based (allow unconditionally) or Basic/Bearer-token-based (check `PermissionService::isAllowed('api_access', $userId)`); (2) returns 403 with a clear message if denied.
- Admin settings gains an "API Access Permission" section, same allow-all/groups/users shape as the other two.
- Applies to `PublicApiController` and `LookupController` (both API surfaces) — not to `DuplicatesController`/`SettingsController`/`PersonalSettingsController`, which back the in-app pages themselves and aren't "the public API" in the sense this permission is scoped to.

### Implementation blocks

**Block 1 — Spike: reliably distinguish session vs. token authentication**
Read the target Nextcloud version's actual request/session/auth-backend code (not assumed from memory) to find the correct signal; write a small `ApiAuthContext::isTokenAuthenticated(IRequest $request): bool` helper with unit tests against constructed request/session fixtures for both cases, before building anything on top of it.

**Block 2 — `PermissionService` third permission key + middleware + tests**
Add the `'api_access'` key (reusing Feature 6's `PermissionService` if that landed first, or the class as introduced there). Add the enforcement point across `PublicApiController`/`LookupController`. Tests: token-authenticated + denied → 403; token-authenticated + allowed → normal response; session-authenticated + denied-for-api → still succeeds (proves the UI isn't broken).

**Block 3 — Admin settings section**
Same shape as Feature 6's Block 2, third config triple.

**Block 4 — Docs**
`docs/api-v1.md` (new "API Access Permission" section explaining the session-vs-token distinction explicitly, since it's the part most likely to confuse an integrator), README, `docs/FAQ.md`.

---

## Feature 8: Cron execution mode + run history

### Analysis

**Origin:** discovered while executing the settings-Vue-migration AP's Block 3. `settings-admin.ts`'s crontab-snippet-generator UI (`generateSnippet()`/`copySnippet()`) turned out to be completely dead — it references DOM ids (`#fcias-snippet-form`, `#fcias-btn-generate-snippet`, etc.) that were never rendered by `templates/settings-admin.php`, so the feature has never been reachable from the UI, despite README.md/`docs/FAQ.md` documenting it as available. The backend endpoint (`SettingsController::getCrontabSnippet()`) works and produces a real, correctly-escaped `occ file-checksum-search:generate ...` crontab line.

**What the snippet actually offers:** a way to run hash generation *outside* Nextcloud's own shared background-job cron tick — e.g. wrapped in `ionice`/`nice`, on a custom cadence, or as a dedicated system user — which NC's own background-job scheduler gives an admin no control over. This is a real but narrow, self-hosted-admin-only benefit (multi-tenant/managed instances typically can't touch system crontab at all), and the generator itself adds no capability beyond what the documented CLI flags already support — it just saves hand-authoring the crontab syntax.

**Decision (user-directed, expanding the original idea):** rather than just resurrecting the dead snippet-generator panel, build a real cron **execution-mode** control plus **run-history** visibility:
- A per-installation (or per-job?) mode selector with three states: **(1) `cron.php`** — piggy-back on Nextcloud's normal background-job cron (today's only mode, and the default); **(2) custom cron only** — disable FCIAS's own background-job registration entirely, so hash generation runs *only* via an admin-configured system crontab entry calling the CLI directly; **(3) parallel** — both run (NC's background job *and* the external cron entry), for admins who want redundancy or intentionally split workloads. Open question to confirm before implementation: does this mode apply per registered job (`RuleProcessingJob`, `ProcessPendingUpdates`, `SeedPendingUpdates` — the three-job pipeline from the 0.7.0 changelog) individually, or as one global switch covering all of them? The user's phrasing ("on the line of each cron") suggests per-job.
- **Run history per job:** last-run timestamp, duration, and exit code, displayed next to each configured cron line. This needs a small persistence mechanism — NC's `IJob` base class doesn't track this itself, so each job's `run()` must record start/end time and outcome (success/exception) somewhere durable (`IAppConfig` keyed per job, or a small dedicated table if history beyond "last run" is wanted later — start with "last run only," matching the user's stated fields, not a full run log).

**Explicitly scoped as its own commit/feature, not part of the settings-Vue-migration AP:** the user was clear this needs its own design pass and shouldn't block Block 3 of that AP. Block 3 proceeds without any snippet/cron-mode panel; this feature starts from scratch once picked up.

**Interim state (in effect now):** README.md/`docs/FAQ.md`'s crontab-snippet-generator claims have been removed (they described a feature that never worked); the backend endpoint remains in code, unused by any UI, until this feature's design resumes.

### Design

*Deferred — genuinely open questions above (per-job vs. global mode; whether "custom cron only" should also suppress the job's NC-side registration entirely or just no-op its `run()`; how "parallel" avoids double-processing the same files if both paths fire close together) need to be resolved with the user before a concrete implementation plan is written. This section intentionally left for a future design pass.*

### Implementation blocks

*Deferred — write once the Design section above is resolved.*

---

## Proposed Commit Messages

Each feature's blocks get their own commit(s) at execution time, following the pattern already established: `[TASK]` for new capability, `[FIX]`/`[SECURITY]` if execution surfaces a bug the way prior blocks did, tests included in the same commit as the code they cover. Concrete messages will be drafted per-block when a feature is actually approved for execution, since exact wording depends on the final shape agreed upon at that decision point (especially Feature 4's A/B choice).

---

## Recommended Priority

Added 2026-08-22 at the user's request. This is a recommendation, not a decision — each feature
still needs the per-feature re-confirmation the Discussion section calls for. Every claim below was
checked against the code on the date above; re-verify before acting on it.

**Verified state at the time of writing:**

- Rate limiting: no implementation anywhere (`grep -rn "RateLimit|rate_limit" lib/` returns nothing),
  while `docs/api-v1.md` documents `occ config:app:set` keys and `docs/api-v1-openapi.yaml` declares
  a `429` response with `retry_after`.
- Rule reordering: no reorder mechanism exists, although `RuleService::findFirstMatchingRule()` is
  order-dependent and the service's docblock promises "first = highest priority".
- The permission triple (`isAllUsersEnabled()` / `getRuleEditorGroups()` / `getRuleEditorUsers()` /
  `canUserEditRules()`) lives in `RuleService` and is otherwise unduplicated.
- No notification infrastructure exists (`grep -rn "INotifier" lib/` returns nothing).
- The settings-Vue migration has landed, so Feature 2's UI-timing concern is moot.
- `SettingsController::getCrontabSnippet()` and its route in `src/routes.ts` are still present with
  no UI consumer.

### 1. Feature 4 — API rate limiting

Do this first. It is the only item where the current state actively misleads: an administrator can
follow the documented `occ` commands, see them succeed, and believe protection is enabled that does
not exist. The OpenAPI spec is worse than the prose, since generated clients may carry retry logic
for a status code no code path can emit.

Recommend path (A): `recalcHash` does real file I/O and genuinely warrants a limit, and NC's
built-in `#[UserRateLimit]` attributes make it a few lines plus a documentation rewrite. Path (B)
(delete the section) is a legitimate outcome if limits are not wanted. Leaving it as-is is not.

### 2. Feature 2's Block 2 alone — rule reordering

Split this out of Feature 2 and do it early. It is a latent gap today, independent of exclude
rules: the service documents an order-based priority that an administrator has no way to control.
Small, self-contained, and it is what makes Feature 2 worth building.

### 3. Feature 6's Block 1 alone — extract `PermissionService`

Split this out too, and land it before either permission feature. It is a behaviour-preserving
refactor already covered by the existing `RuleServiceTest.php` cases, and it stops the third
copy-paste of the permission triple before it happens.

### 4. Feature 2 remainder — include/exclude rule types

Design settled by the v1.3 correction, no DB migration, backward compatible through the
`type`-absent default, and the Vue components it needs now exist. The best capability-per-risk
ratio in this AP.

### 5. Feature 3 — export a duplicate report

The cheapest item here: two pure functions and two buttons, no backend, no new endpoint. Low value,
near-zero risk — a good gap-filler.

### 6. Feature 6 remainder — manual-recalc permission

Straightforward once `PermissionService` exists.

### 7. Feature 1 — act on duplicates

Highest value, but six blocks, the only destructive feature, and dependent on two optional app APIs
(`viewer`, `files_versions`) that this AP itself flags for re-verification at implementation time.
Schedule it when there is room to do it carefully, not as a filler.

### 8. Feature 5 — drift notifications

Fully greenfield, and the storm-batching design needs real-world tuning.

### Not schedulable yet

These are design tasks, not implementation tasks, and should not be given an implementation slot
until they are resolved:

- **Feature 7** — Block 1 is an explicit spike, and this AP names the failure mode in both
  directions (leak the bypass, or break the in-app UI). The spike is cheap and can run at any time;
  the feature cannot be estimated until it is done.
- **Feature 8** — its Design and Implementation-blocks sections are marked *Deferred*, with three
  open questions.

### Unrelated cleanup

`SettingsController::getCrontabSnippet()` and its route are dead — the documentation claiming the
feature was already removed, but the endpoint is still reachable. Recommend deleting both: if
Feature 8 goes ahead, it will not reuse a snippet generator.

---

## Change History

| Version | Date | Changes |
|---------|------|---------|
| v1.5 | 2026-08-22 | Added a **Recommended Priority** section ordering the eight features, at the user's request. Recommends splitting rule reordering (Feature 2 Block 2) and the `PermissionService` extraction (Feature 6 Block 1) out as standalone early items, putting Feature 4 first because the documented rate-limiting config silently does nothing today, and treating Features 7 and 8 as design tasks that are not yet schedulable. Claims verified against the code on 2026-08-22. |
| v1.4 | 2026-08-22 | Added Feature 8 (cron execution mode + run history), discovered mid-execution of the settings-Vue-migration AP's Block 3: the documented crontab-snippet-generator was found to be completely dead frontend code (never-rendered DOM ids), and the user directed that it be rebuilt as a real feature — a per-job cron.php/custom-cron-only/parallel mode selector plus last-run timestamp/duration/exit-code display — scoped as its own future commit, not part of that migration. README.md/docs/FAQ.md's inaccurate claims about the generator were removed in the interim. |
| v1.3 | 2026-08-22 | Two designs revised per explicit user correction, overriding earlier recommendations: **Feature 1** — "move" dropped entirely; "delete" redesigned from a single keep-radio-implies-remove-all-others model to a checkbox-based selectable subset; "merge" redefined as merging Nextcloud version history (not a filesystem reference, which the previous draft had wrongly assumed infeasible and recommended dropping) — grounded in the confirmed `files_versions` app API (`OCA\Files_Versions\Versions\IVersionManager`/`IVersionsImporterBackend::importVersionsForFile()`), treated as an optional app dependency with the same defensive-availability pattern already used for `viewer`; blocks renumbered (now 6, with a new Block 2 for the version-merge integration). **Feature 2** — per-rule `excludePath` glob replaced with a rule-level `type: include\|exclude` field and pure list-order first-match-wins semantics; surfaced and addressed a prerequisite gap (no existing rule-reorder UI) as a new Block 2, since exclude rules' value depends on controllable rule order. |
| v1.2 | 2026-08-22 | Feature 1 (Act on duplicates) refined per user follow-up: each duplicate file now shows date/size (free — `filecache.mtime`/`size` via the existing join) and a Preview action reusing Nextcloud's core `viewer` app (`window.OCA.Viewer.open()`, confirmed as the real, documented integration point other NC apps use), with a graceful fallback to the existing open-in-new-tab link if the `viewer` app isn't installed. Added as a new Block 1 (metadata) ahead of the previously-numbered blocks, which shifted down by one. |
| v1.1 | 2026-08-22 | Added Features 6-7 (user-proposed, not from the source report): permission to calculate new hashes, and permission to use the REST API. Both recommend extracting a generic `PermissionService` from `RuleService`'s existing allow-all/groups/users pattern rather than copy-pasting a third triple. Feature 7 flags a technical spike (Block 1) to reliably distinguish session-based UI calls from token-based external API calls before enforcement can be built, since getting that distinction wrong either leaks the bypass or breaks the in-app UI. |
| v1.0 | 2026-08-22 | Initial design AP for 5 user-selected features from the source report's §8: act on duplicates, exclude-globs, UI export, API rate limiting (implement-vs-remove decision), and push notifications on integrity drift. |
