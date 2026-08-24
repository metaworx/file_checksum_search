# Changelog

All notable changes to the **File Checksum Index & Search** (FCIAS) app will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions below `1.0.0` are pre-release: fixes/security patches increment the patch digit, and
any addition or change (including breaking changes) increments the minor digit, until
the first stable release.

## [Unreleased]

### Changed

- Reformat the rule and permission services, the two settings controllers and their tests to the project code style. No behaviour change — layout, alignment and trailing commas only.
- Extract the allow-all-users/groups/users permission logic out of `RuleService` into a new generic `PermissionService`, keyed by permission. Rule editing is its first key and keeps its existing config keys (`rule_editors_all_users`, `rule_editors_groups`, `rule_editors_users`), so nothing needs migrating and the permission behaves exactly as before. `RuleService` no longer exposes it at all — the admin and personal settings controllers ask `PermissionService` directly — so permissions added later reuse one mechanism through one door, rather than copying the triple a third time.

### Added

- Rate limit the expensive public API endpoints per user, using Nextcloud's own `#[UserRateLimit]` attribute: 60 requests/minute on `lookup` and `duplicates`, and 20 requests/minute on `recalc`, which reads file content from storage.

### Fixed

- Fix `docs/api-v1.md` documenting a rate-limiting scheme that did not exist. It described `occ config:app:set` keys (`rate_limit_enabled`, `rate_limit_max_requests`, `rate_limit_window_seconds`) that were never read by any code, so an administrator following it saw the commands succeed and believed protection was enabled when none was. The section now documents the limits that are actually enforced, the empty-bodied 429 Nextcloud returns, and the `ratelimit_overwrite` system setting that changes them.
- Fix the duplicate browser's Verify hashes run misreporting a rate limit as hash drift. It POSTs one recalculation per file and never checked the response status, so once the new limit is reached it would parse the empty 429 body, find no `success` field, and mark every remaining file as a mismatch. It now stops at the limit, leaves the unchecked files and the interrupted group's counts untouched, says why, and resumes from that point on the next run.
- Fix `docs/api-v1-openapi.yaml` declaring a `429` response with a `Retry-After` header and a `retry_after` body field on all six endpoints. No code path could emit that response, so generated clients could carry retry logic for it. The `429` is now declared only on the three rate-limited endpoints, with the empty body Nextcloud actually sends.

### Removed

- Remove the legacy `/api/1.0/` REST routes and the `LookupController` that served them. The app was never published, so nothing external can be relying on them, and keeping a second, frozen copy of the same four operations meant every API change had to be made and reviewed twice. Everything they did is available on `/api/v1/`: `lookup/{hash}` → `lookup?hash=…`, and `file/{id}/hashes`, `file/{id}/duplicates` and `file/{id}/recalc` under the same names.

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
