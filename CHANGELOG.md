# Changelog

All notable changes to the **File Checksum Index & Search** (FCIAS) app will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions below `1.0.0` are pre-release: fixes/security patches increment the patch digit, and
any addition or change (including breaking changes) increments the minor digit, until
the first stable release.

## [Unreleased]

### Added

- Add a GitHub Actions App Store release pipeline (build, package, optional signing) and refresh README/info.xml to describe the files-metadata-based architecture.

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
