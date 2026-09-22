# Changelog

All notable changes to the **File Checksum Index & Search** (FCIAS) app will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions below `1.0.0` are pre-release: fixes/security patches increment the patch digit, and
any addition or change (including breaking changes) increments the minor digit, until
the first stable release.

## [Unreleased]

### Added

- `occ fcias:backup`, `fcias:reset`, `fcias:import`: configuration, queue
  state and hashes out as `json`, `csv` or a checksum listing, and back
  in with `--merge` or `--replace`; a reset reports until `--force`.

- `occ fcias:repair`: every repair step by name (`--list`, `--step`,
  `--dry-run`, `--include-expensive`); `file-checksum-search:rebuild` is
  gone.

- `occ fcias:queue:drain`: hash what the rules queued, now, with
  `--batch-size` and `--all`.

- `occ fcias:hash`: `file-checksum-search:generate` renamed, no alias;
  `--algo` repeatable with `auto`, `--mode`, `--unmatched`, `--mark`,
  `--with-ignored`, `--ignore-rule`, `-v`/`-vv`.

- `occ file-checksum-search:rules:list|add|modify|delete|apply`: rule
  management from the shell; `list` speaks JSON.

- `/api/v1/rules`: `GET`, `POST`, `PUT /{id}`, `DELETE /{id}`,
  `PUT /order`, `POST /{id}/apply`; one resource for both settings pages;
  `POST` and `PUT` return the stored rule.

- `ChecksumApi`: `listRules`, `createRule`, `updateRule`, `deleteRule`,
  `applyRule`, `findDuplicatesFor`, `recalcMany`, `openableBy`;
  `$actingUser` and `$reachUids` in place of `$requestingUser`.

- `GET /api/v1/algorithms`, admin *Hash Algorithms*: the algorithms an
  instance allows, from what its PHP offers, and a designated default;
  every picker reads the list, so `adler32` duplicates can be browsed.

- `GET`/`PUT /api/v1/preferences/preferred_algorithm`, personal settings:
  the algorithm the sidebar offers first, then the file's rule's.

- Rule verdicts `include`, `ignore`, `exclude`: the first matching rule
  decides; `exclude` refuses hashing by every route, sidebar included.

- Permissions: *Who may calculate by hand* (`manual_recalc`), *look
  across accounts* (`instance_view`), *use the API* (`api_access`),
  beside *edit rules*; the first and the last ship allowed. `canRecalc`
  on the hashes response says whether the sidebar offers Recalculate.

- `/api/v1/sudo/`: twins of the per-file hashes, per-file duplicates,
  lookup, listing, recalc and batch recalc, plus `/sudo/selectable`;
  behind a password confirmation or a granted app password. A sudoer
  reaches every account, a group leader what their members hold;
  `users[]`/`groups[]` name a set, expanded and authorised server-side.

- Sudo tokens: a grant per app password on the personal page; every grant
  listed and revocable on the admin *Sudo tokens* tab.

- Duplicates page *Others* tab: other accounts' duplicates, named by
  account and group, as one listing; *Mine* keeps its own filters.

- `POST /api/v1/file/many/recalc` and its `/sudo/` twin: one request
  verifies up to 25 files or 100 MiB; the Duplicates page sends chunks.

- Rate limits per user: 60/min on `lookup`, both duplicates routes and
  `sudo/selectable`; 20/min on every recalc route. The 429 is
  Nextcloud's, empty; `ratelimit_overwrite` changes the limits.

- `hash` and `anywhere` on `/api/v1/duplicates` and its twin, a *Hash*
  field and *Search anywhere* on the page: only the groups a hash names.

- `stale:eroded` and `stale:reset`: hashes dropped on write, or disowned
  by a reset, counted by reason on the Advanced tab and in
  `occ file-checksum-search:status`, and hidden from search and groups.

- `occ fcias:repair --step orphaned-metadata`, a daily purge
  (`orphan_purge_interval`): this app's metadata for files the filecache
  no longer has; due at once when an account is deleted.

- Admin page idle banner: shown while no enabled `include` rule exists;
  *Acknowledged* persists (`idle_banner_ack`) until one does.

- Rules table coverage rows: *Create rule* for every namespace without a
  catch-all; a *provider missing* badge on a rule naming a gone one.

- Rule row actions menu: Edit, Enable/Disable, *Re-apply* (new), Delete;
  user, group and group-folder targets picked by name.

- Audit log: every rule mutation at INFO, WARNING for an admin-enforced
  rule, naming the surface that asked.

### Changed

- Rules: a **selector** (`home:<uid>`, `group:<gid>`, `home:*`,
  `groupfolder:<id>`, `storage:<id>`, `*`) replaces the user scope;
  stored rules migrate. Eight bands, enforced 1–4 and unenforced 5–8,
  each selector value its own segment with a trailing defaults partition;
  reordering stays within a segment. Two shipped defaults, `home:*` and
  `*`, both created disabled; the `pinned` flag is gone and a deleted
  default is recreated disabled by the repair step.

- Rule matching: by the file's canonical identity (`FileLocation`), never
  the acting user's path; sweeps run by storage; trash, versions and
  appdata are governed by nothing; a personal rule path into a share or a
  mounted storage is refused with the reason.

- Pending queue: a row exists only for a file that was queued, hashed or
  eroded; the drain resolves each file's rule at action time and honours
  its algorithm list; the seeding job and `pending:new` are gone.

- Install: copies the checksums the filecache already holds and computes
  nothing; the shipped defaults are created disabled where absent.

- Metadata keys: hashes under `file-checksum-hash-<algo>`; the repair
  step renames existing rows, and `--step unindexed-hashes` finds files
  the index forgot.

- Admin page tabs: *Settings*, *Permissions*, *Sudo tokens*, *Advanced*
  (diagnostics and the picker prefill threshold, 21 by default),
  *Documentation*. `GET`/`PUT /settings/global` is one resource.

- Both settings pages: one banded rules table with help on every column
  and band, `<band>.<position>` as text, the personal page showing the
  enforced rules above and the defaults below the user's own; a pen
  icon beside each row's menu; group folders named as the groupfolders
  app names them.

- Duplicates page: **Verify all** per group and **Verify** per file
  replace the page-wide button and the *Only matching* filter; a 429
  stops the run with a message and the next click resumes. Controls are
  Nextcloud's, labelled, with help buttons.

- Every file row: `owner` and `location` (`FileLocation::describe()`),
  shown in place of the path where the file is not the viewer's own;
  cross-account rows carry `openable`, and link only where true.

- `GET /api/v1/status`: a non-administrator gets the version alone.

- Duplicates listing: the filter pages 200 groups at a time and stops
  when the page is full; a truncated group is confirmed in one read.

- Settings pages: Save disabled with nothing to save, yellow with
  changes; secondary text at normal size in the max-contrast colour;
  logical CSS properties, so right-to-left locales lay out correctly;
  one `AlgorithmSelect` behind every algorithm picker.

- Documentation: `docs/HELP.md` is `docs/user-guide.md`, the Help tab of
  the Duplicates page and personal settings; `docs/FAQ.md` is the
  administrator's; README *How hashing happens* and the rules chapter;
  `docs/api-v1.md` and the OpenAPI document against the shipped routes.

### Removed

- `PersonalSettingsController`, `/settings/cron/*`, `/personal/rules/*`:
  gone; `/api/v1/rules` replaces them.

- `GET /settings/cron/snippet`: gone.

- `/api/1.0/` and `LookupController`: gone; `/api/v1/` has every
  operation.

- Rule mode `off`: gone; a rule carrying it becomes an `ignore` rule.

- `openapi.json` at the repository root: gone; `docs/api-v1-openapi.yaml`
  is the specification.

### Fixed

- Recalculating every algorithm a file carries: computed nothing.

- Search for `sha3-256:`, `sha3-512:` and upper-case prefixes: found
  nothing.

- Search for SHA-256, SHA-512 and SHA3 hashes: the index row was never
  written; it is written truncated now, the repair step adds the missing
  rows, and a long hash is confirmed from the document.

- Cleared hashes: their index rows are collected on save, so they stop
  answering searches, lookups and duplicate groups.

- `process_pending_interval`, `pending_batch_limit`: declared, so the
  drain no longer logs a lexicon warning per run.

- Documented REST URLs: under `/ocs/v2.php/apps/file_checksum_search/`;
  `minCount`, not `min_count`; the `info.xml` user-documentation link.

- `occ fcias:hash` without `--mark`: consults the rules, so `exclude`
  holds and unmatched files are left alone.

- Sidebar: a refused recalculation shows the rule's reason, not "Error".

- A modified file no rule maintains: its stored hashes are dropped.

- Rule priority: the catch-all evaluates last. **On upgrade, additional
  rules that never applied start applying.**

- Rule editing: a non-administrator can no longer change an instance-wide
  rule.

- Dark theme: error lines and verification verdicts use the palette's
  text colours, not its background fills.

- Settings pages: notices show again, through `@nextcloud/dialogs`;
  `OC.Notification` no longer exists in Nextcloud 34.

### Security

- Periodic rule sweep: asks for stale rows only and resolves exclusion
  per file; its cost no longer scales with the whole instance.

- Unified search: the limit is capped at 100.

- `GET /api/v1/lookup`, the unified search, the own listing: scoped to
  what the caller holds — home, received shares to their subtree, group
  folders — before the limit applies, so a file no longer hides behind
  foreign copies.

- `POST …/recalc`, `POST /rules/{id}/apply`: `#[NoCSRFRequired]` removed.

- `/settings/status`: administrators only.

- `RulesController`: a failed write answers *Internal server error.*, the
  detail in the log.

- `minCount`: clamped to 2 or more on every route; the browser routes
  are rate limited like their API twins.

- Administrators: the ordinary routes answer their own files; the
  instance-wide view is `/api/v1/sudo/`. **Scripts calling the API with
  administrator credentials receive the administrator's own files.**

- Checksums adopted from `oc_filecache.checksum`: kept only for an
  allowed algorithm with a hex value of that algorithm's length.

- `GET /api/v1/file/{fileId}/duplicates`: the reference file is resolved
  within the caller's reach before its hashes are read.

## [0.19.0] — 2026-08-23

### Changed

- Move rule creation and editing into an NcDialog popup on both the admin and personal settings pages, instead of a form that expanded inline below the rule list.

- Show the global rule as an ordinary rule row in its own table above the additional rules, replacing the separate always-visible form, so both kinds of rule read and are edited the same way. The global rule shows its fixed User Scope and Path as plain text rather than disabled inputs, keeps them pinned server-side, and has no Delete button — disable it instead.

- Add a help button with a short explanation to every rule and permission setting, reusing the sidebar's popover as a shared component.

- Stretch the rule form's inputs, the algorithm multiselect and the permission group/user selects to the full width of their row, so controls line up on a common right edge instead of stopping at their intrinsic widths.

- Give the rule tables percentage column widths, so the single-row global rule table and the additional-rules table below it share one column grid.

- Show the full value as a tooltip on rule table cells and on the rule form's Path field, for values too long for the space.

- Centre the settings pages' Save and Cancel buttons and give them room to breathe, and match the rule dialog's "Users may not edit this rule" toggle to the switch used elsewhere in the settings.

- Focus the first editable field when the rule dialog opens, instead of the first help button, and close the dialog on Escape as if Cancel had been pressed — an open help popover or select dropdown takes the first Escape for itself. The dialog's own close button is gone as a result: the built-in close had to be turned off for Escape to be handled in the right order, and Cancel already sits next to Save.

- Indent every tab panel, the page heading and the tab buttons on both settings pages, while the tab underline still runs the full width. The indent was previously scoped to the admin page's Settings panel, leaving the personal page flush against the edge.

- Share one server-rendered header partial between the admin and personal settings pages, so the personal page shows the app logo too and the two cannot drift apart.

- Show the Priority column on the personal rules page. Personal rules are an ordered subset evaluated first-match-wins, so their position is a real priority, numbered as on the admin page.

- Rename the rule tables' "Algos" column to "Algorithms", give all three rule tables one shared column grid, and left-align their action buttons.

- Label the rule dialog's admin-enforced switch "Enforced" so it lines up with the other fields, and left-align the Rule Editing Permission page's Save button while the dialog's own buttons stay centred.

- Cap the status table's label column so its values are not pushed across the page.

- Keep the permission group and user selects hidden until the saved options have loaded, instead of rendering and then hiding them on every page load.

### Fixed

- Fix the admin settings page never showing the global rule's stored algorithms: the algorithm multiselect captured its selection once at setup, when the asynchronously loaded algorithm list was still empty, and stayed blank from then on. It now tracks both the bound value and the option list.

- Fix the admin settings page losing its app-name heading: the Vue migration left the `<h3>` as a sibling of `#fcias-admin-settings`, which the Vue app then overwrote on mount. The heading and a new inner mount point now live inside that container again.

## [0.18.0] — 2026-08-22

### Added

- Add Vitest coverage for the vanilla-JS admin and personal settings pages, testing their manual HTML-escaping and DOM rendering black-box to guard against XSS regressions.

- Add useAdminSettings and usePersonalSettings composables that port the settings pages' fetch logic to the existing composable pattern, adding an AbortController stale-response guard neither page had before.

### Changed

- Document DatabaseService's safeBool/safeInt/safeString/safeArray sentinel-on-failure pattern so callers can tell a genuine empty result from a logged DB failure.

- Extract shared RuleTable, RuleRow, and RuleForm Vue components to replace the duplicated string-concatenated markup in the admin and personal settings pages.

- Migrate the admin settings page to a single Vue app built on the new RuleTable/RuleForm components, consolidating four build entries into one and removing the unreachable dead crontab snippet generator and its inaccurate documentation.

- Migrate the personal settings page to Vue using the personal RuleTable/RuleForm variant and usePersonalSettings composable, removing the now-dead vanilla settings-personal and tabs.ts modules.

## [0.17.1] — 2026-08-22

### Fixed

- Fix generate --mark to catch Throwable instead of a non-existent OCP UserNotFoundException class, so a vanished user no longer crashes the whole run instead of being skipped.

## [0.17.0] — 2026-08-22

### Changed

- Extract a shared algorithm-whitelist validator to remove the duplicated validation logic between SettingsController and PersonalSettingsController.

- Compute all required checksums for a file in a single read pass instead of one read per algorithm, cutting up to eight reads down to one on remote and local storage alike.

- Extract a shared default-duplicate-limit constant to replace six hardcoded copies of the default duplicate-group page size.

- Document adler32 support in README, FAQ, API docs, and OpenAPI spec, and add canary tests on both the PHP and TypeScript sides so the two supported-algorithm lists can no longer silently drift apart.

## [0.16.1] — 2026-08-22

### Fixed

- Guard useDuplicates against stale-response races by cancelling in-flight requests when filters change, matching the pattern already used by useSidebarHashes.

## [0.16.0] — 2026-08-22

### Removed

- Remove the unused legacy vanilla-JS duplicates bundle and its loading listener, since the real duplicates page has used the Vue bundle instead.

## [0.15.1] — 2026-08-22

### Fixed

- Guard metadata-index seeding against running when its target table doesn't yet exist, warning with the rebuild command instead of silently leaving the index unpopulated.

## [0.15.0] — 2026-08-22

### Changed

- Document why auto mode intentionally does nothing for newly created files, since it only recalculates existing stale hashes rather than generating a first hash.

## [0.14.1] — 2026-08-22

### Fixed

- Fix a race where concurrent hash recalculations for different algorithms on the same file could silently drop metadata by saving before releasing the file lock instead of after.

- Fix truncated-hash comparisons for SHA-256/SHA3-256 and SHA-512/SHA3-512 so full-hash searches match correctly and duplicate groups are verified against the untruncated hash instead of risking false positives.

### Security

- Scope the public and legacy API hash lookups (lookup, getHashes, recalcHash) to the requesting user's own files so other users' paths and hashes can no longer be read or force-recalculated by hash or fileId.

- Require admin privileges for SettingsController's rule management endpoints so non-admins can no longer install or lock a global force-recalculate rule for the whole instance.

- Enforce userScope ownership checks on personal rule mutation and on real-time file-event rule matching so a user can no longer alter or trigger another user's rule by guessing its ID.

## [0.14.0] — 2026-08-21

### Added

- Add a Vitest-based frontend unit-test scaffold with tests for the algorithm/tab helpers and the useClipboard, useSidebarHashes, and RecalcButton components.

## [0.13.1] — 2026-08-21

### Fixed

- Fix the generate command reporting zero files hashed when --batch-size was omitted, since the missing value defaulted to 0 and was treated as "collect nothing" instead of unlimited.

## [0.13.0] — 2026-08-21

### Added

- Add FAQ and user help documentation with in-app help tabs on the personal settings and duplicates pages, plus a public help endpoint.

### Changed

- Give the admin page its own dedicated settings section and add a read-only Documentation tab that renders the bundled docs, including Markdown.

- Let users edit and create hash-generation rules from a personal settings page, with admins able to lock individual rules via a per-rule admin_enforced flag and control access via allowed groups/users.

- Revise README, info.xml, API docs, and the changelog to reflect the current feature set, removing stale references to database triggers, MariaDB-only support, and Webpack.

- Update info.xml for App Store metadata compliance (PHP/database dependencies, documentation links, HTTPS repository URL) and add app store screenshots.

- Migrate the checksums sidebar tab from a hand-rolled HTMLElement to a Vue custom element with a loading spinner during hash load and recalculation.

- Replace the admin and personal settings algorithm checkboxes with a shared NcSelect-based multiselect component.

- Rework the checksums sidebar into sharing-tab-style sections with help popovers, an algorithm selector with a recalc button, and full-hash tooltips on a container-constrained hash table.

### Removed

- Remove redundant .gitkeep placeholder files.

## [0.12.1] — 2026-08-16

### Fixed

- Guard against overflowing the oc_filecache.checksum column by dropping algorithm/hash pairs that don't fit, preventing multi-algo files from failing to save in FilecacheService::setHashes().

## [0.12.0] — 2026-08-15

### Added

- Add a GitHub Actions App Store release pipeline (build, package, optional signing) and refresh README/info.xml to describe the files-metadata-based architecture.

### Changed

- Refresh README and info.xml to describe the files-metadata-index architecture, any-DB support, the 7 CLI commands, and rule-based hashing.

## [0.11.1] — 2026-08-15

### Fixed

- Register background jobs once via info.xml instead of on every boot, fixing NC 33's JobList::add() resetting last_run and preventing the pending-updates queue from ever draining.

- Mark file-checksum-updated_at as an indexed metadata value when saving, so the pending queue actually drains after successful hash processing under NC 33.

- Register metadata keys during install rather than on every boot, eliminating a recurring debug warning from NC 33's lazy AppConfig loading.

## [0.11.0] — 2026-08-15

### Changed

- Fix cron.php never processing pending:new entries by resolving the matching rule before dispatch, and break a circular dependency between HashCalculationService and RuleService by relocating responsibilities to their natural owners.

## [0.10.1] — 2026-08-07

### Fixed

- Fix the sidebar tab silently failing to register by importing the SVG icon as raw XML instead of a data URL, and add diagnostic logging around tab/action registration.

- Fix recalcHash always defaulting to SHA-1 by also reading the algo parameter from the query string when the request body is empty.

- Fix duplicate search to exclude the updated_at metadata field from grouping, correctly handle hash values stored as JSON arrays, and fall back to the indexed hash when extraction yields an empty value.

- Fix the Vue duplicates template rendering literal unicode escape sequences instead of the check and cross characters.

- Show a total pending count alongside the per-mode breakdown on the settings page to match the CLI output.

- Add a Refresh button and a Last Updated timestamp to the settings page Status section.

- Fix HashSearchProvider search results so clicking a result opens the file details sidebar instead of just navigating to the directory root.

## [0.10.0] — 2026-08-07

### Added

- Add SettingsControllerTest with 14 tests covering all six controller methods, and expose a mockable readRequestBody() method on SettingsController.

- Add developer tooling configuration (ESLint, PHP-CS-Fixer, Psalm, Rector, Stylelint, TypeScript, Vite) and remove committed build artifacts.

### Changed

- Update project metadata (code of conduct, license, app info, and composer info).

- Migrate routing from appinfo/routes.php to PHP 8 attribute-based routing (#[ApiRoute]/#[FrontpageRoute]) across all controllers, per the Nextcloud 31+ standard.

- Convert frontend scripts to TypeScript, extract the duplicates index page into a new PageController, fix the Vite entry point, and switch URL generation to @nextcloud/router with centralized route constants.

- Migrate the frontend build from Webpack to Vite and introduce a Vue 3 SPA for the global duplicate file browser.

## [0.9.1] — 2026-08-07

### Fixed

- Fix undefined $hash variable in HashSearchProvider::search() by using the correctly parsed $parsed['hash'] value.

## [0.9.0] — 2026-08-07

### Added

- Add and improve class-level PHPDoc and @throws documentation across several services and listeners with no behavioral changes.

- Add FciasUnitTestCase base class and unit tests covering MetadataService queryDuplicates/queryByHash and HashCalculationService processFile.

### Changed

- Clean up code by removing decorative section-header comments, replacing magic pending-mode strings with named MetadataService constants, and extracting glob matching into a shared PathUtil helper.

## [0.8.1] — 2026-08-07

### Fixed

- Fix several medium-severity issues from the post-migration audit, including a hash search regex that excluded ADLER32/CRC32 lengths, auto mode recomputing all algorithms instead of only existing ones, and file copies being marked with the wrong pending mode.

## [0.8.0] — 2026-08-07

### Added

- Add missing rule-processing features: self-dispatching background jobs when a batch fills up, rule matching in FileListener, path-based rule search with glob pagination, and folder-based hash marking.

### Removed

- Remove all remaining references to the dropped hash/pending tables and trigger/stored-procedure infrastructure, routing ChecksumApi and StatusService through MetadataService instead.

## [0.7.1] — 2026-08-07

### Fixed

- Fix two critical post-migration bugs: an undefined argument that always threw an exception in HashIndexService::generateMissingHashes(), and a wrong column alias in MetadataService::queryDuplicates() that caused duplicate detection to return empty hash values.

## [0.7.0] — 2026-08-07

### Added

- Add cron job definitions to the file-checksum-search:status output in both plain-text and JSON formats.

- Add integration tests covering the full pending-queue drain pipeline and fix a double-prefix bug in PendingQueueService that produced an invalid table name (oc_oc_file_checksum_search_pending).

- Add FileListener integration tests covering all update-hash-on-write/create/delete modes and fix a double-prefix bug in HashCalculationService that caused queries against a doubled table name.

- Add FilecacheService and a centralized HashCalculationService::processFile() to keep filecache checksums and metadata in sync, expand MetadataService, and remove the now-redundant FileOperationService.

- Add a three-job background pipeline (RuleProcessingJob, ProcessPendingUpdates, SeedPendingUpdates) plus a MetadataListener to seed and process the pending-hash queue based on configured rules.

### Changed

- Introduce MetadataService and a fresh migration to integrate with Nextcloud's oc_files_metadata table, dropping all previous migrations since the app had never been deployed.

- Refactor FileListener to only clear metadata and mark files pending instead of computing hashes directly, deferring actual hash computation to the ProcessPendingUpdates job.

- Rewrite search and duplicate detection to query oc_files_metadata directly, reading hash values from the JSON column to avoid truncation for longer hash algorithms.

- Overhaul CLI commands for the metadata-based architecture, adding deferred processing and metadata-aware status/rebuild/benchmark commands while removing obsolete table- and trigger-management commands.

- Replace the cron/trigger-based configuration with a rule-based system (RuleService) and matching admin UI, removing six now-obsolete classes tied to the old trigger/stored-procedure and queue infrastructure.

## [0.6.1] — 2026-08-05

### Fixed

- Fix DatabaseService writing query errors to stdout, which could corrupt JSON output of the status command, by sending them to stderr instead.

- Guard against an undefined array key warning by adding a null-coalesce for the "locked" key in FileListener.

## [0.6.0] — 2026-08-05

### Added

- Add a "Files with same hash" feature that finds duplicate files via a self-join on the hash table and lets users browse matches with a new "Find duplicates" sidebar button.

- Add event-driven hash index maintenance for file write, create, delete, and copy operations, backed by a new pending-update queue table, a draining background job, and per-event-type admin configuration.

- Add ILockingProvider-based file locking to hash operations so concurrent cron, CLI, and event-listener processes can no longer hash the same file simultaneously, retrying locked files via the pending queue instead of dropping them.

- Add a global duplicate file locator that finds all groups of files sharing identical hashes across the system, with a REST API, CLI command, standalone UI, and access-controlled, paginated results.

- Add a show-config CLI command to display app configuration and extend the status command with a machine-readable --output=json option.

- Add logging to previously silent controllers, commands, listeners, and migrations, and log errors before returning error responses in SettingsController.

- Add an updated_at column to the hash table so hash recalculation can be skipped when the value is already current, and surface it in the status command and sidebar tooltip.

### Changed

- Refactor closure-based event listeners into dedicated listener classes that self-register, simplifying Application boot and registration.

- Consolidate duplicated hash lookup and path resolution queries into new HashIndexService methods, removing direct database dependencies from several classes.

- Expand the README to document all CLI commands and features, and add descriptive docblocks to controller and command classes.

- Split the large HashIndexService into focused service classes for hash calculation, pending queue, duplicates, and file operations, keeping HashIndexService as a backward-compatible facade.

- Move the shared escapeHtml helper into a common JS utility module to remove duplicated implementations in the sidebar and duplicates scripts.

- Extract shared safeIntQuery/safeExistsQuery helpers in StatusService to eliminate repeated try/catch/log patterns across its status checks.

- Design a stable public API (ChecksumApi class and /api/v1 HTTP endpoints) covering versioning, authentication, rate limiting, and backward compatibility, while keeping legacy /api/1.0 routes.

- Refactor LookupController to delegate entirely to ChecksumApi and remove the legacy /api/1.0 routes now that all consumers use the /api/v1 endpoints.

## [0.5.1] — 2026-08-05

### Fixed

- Fix Unified Search never returning results by programmatically registering HashSearchProvider via IRegistrationContext, since NC v33 no longer processes the info.xml <search> block, and add a fingerprint app icon.

- Use img/app.svg as the single source of truth for the app icon across the sidebar and admin settings instead of duplicating inline SVG markup.

## [0.5.0] — 2026-08-04

### Added

- Add scheduled hash generation via NC background jobs with full admin CRUD management and via a generated system crontab snippet, centralizing algorithm support and job management in new CronJobService and SUPPORTED_ALGOS constants.

## [0.4.1] — 2026-08-04

### Fixed

- Fix the sidebar tab showing stale content when switching files by reloading hashes on node property changes instead of relying only on the one-time connectedCallback.

- Fix duplicate hash entries on recalc by matching the algorithm prefix case-insensitively, since stored checksums use uppercase algorithm names.

- Fix a 500 error in recalcFileHash by coalescing a null filecache checksum to an empty string before calling explode().

- Fix the Checksums tab incorrectly appearing on folder nodes by strictly checking node.type === 'file' instead of falling back to a fileid check.

## [0.4.0] — 2026-08-04

### Added

- Add click-to-copy hash values, server-side SHA-1/MD5 recalculation buttons, and a "Checksums" file menu entry to the sidebar tab, backed by a new centralized recalcFileHash endpoint.

### Changed

- Extract duplicated CLI/controller logic into HashIndexService and TriggerInitializationService to eliminate roughly 130 lines of duplication.

## [0.3.1] — 2026-08-04

### Fixed

- Migrate the sidebar tab registration to the @nextcloud/files v4 getSidebar().registerTab() API and add a webpack build step, since NC v33 removed OCA.Files.Sidebar.registerTab().

## [0.3.0] — 2026-08-04

### Added

- Add idempotent restore commands (CLI and admin UI buttons) to recreate the hash table and triggers/stored procedures after they were torn down, so users no longer have to disable/re-enable the app or re-run migrations.

### Changed

- Reformat whitespace in the admin settings JavaScript for consistency.

## [0.2.1] — 2026-08-04

### Fixed

- Fix unreadable compatibility-test status indicators on dark themes by switching from colored text to background-color badges.

- Fix admin settings maintenance actions showing dialogs with no OK button by switching to OC.dialogs.message() and including record counts in the result message.

## [0.2.0] — 2026-08-04

### Changed

- Extract duplicated status and index-maintenance logic from the CLI commands and settings controller into shared StatusService and HashIndexService classes.

## [0.1.1] — 2026-08-04

### Fixed

- Fix every CLI command and admin API endpoint crashing with an unhandled error by replacing the non-existent IDBConnection::getPrefix() with a centralized TableNameService, also correcting the wrong dbtableprefix config key that had been silently ignored.

- Register a config lexicon for the triggers_deployed app config key to stop Nextcloud from logging an info message on every boot request.

- Fix GenerateHashes failing to process the root folder and crashing on the non-existent File::setChecksum(), and add debug logging plus verbosity-based progress output.

## [0.1.0] — 2026-08-03

### Added

- Add the MariaDB migration deploying the shadow table, stored procedure, and insert/update/delete triggers that keep file checksum data in sync with the filecache.

- Add the Application bootstrap that registers the Unified Search provider and the sidebar frontend scripts at runtime.

- Add a REST API controller for looking up files by hash and retrieving all hashes for a given file ID.

- Add a Unified Search provider that matches raw hex hashes or algo:hash queries against indexed files the user has access to.

- Add the core CLI commands for rebuilding the hash index, searching by hash, generating checksums, and benchmarking indexed lookup performance.

- Add administrative CLI commands for reporting index status, purging, tearing down triggers, and removing the shadow table.

- Add an admin settings page with a compatibility test and maintenance actions for purging, rebuilding, tearing down, and removing the index.

- Add a Files app sidebar tab that displays a file's checksums as algorithm badges with hash values.

### Changed

- Rework app lifecycle handling, dependency injection, the API, and assets to address findings from the initial code audit.
