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

- `GET /api/v1/file/{fileId}/hashes`: `canSudo`, whether the caller may
  look across accounts.

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

## [0.19.0] - 2026-08-23

### Changed

- Rule dialog: an `NcDialog` on both settings pages in place of the
  inline form; the first editable field focused on open, Escape cancels,
  no close button of its own.

- Global rule: an ordinary row in its own table above the additional
  rules; scope and path as plain text, no Delete button.

- Help button on every rule and permission setting, from the sidebar's
  popover component.

- Settings forms: inputs, algorithm multiselect and permission selects at
  full row width; the rule tables on one column grid; full values as
  tooltips on cells and the Path field.

- Settings pages: Save and Cancel centred; the "Users may not edit this
  rule" toggle is the settings switch; every tab panel indented on both
  pages; one shared header partial, so the personal page shows the logo.

- Personal rules page: a Priority column.

- Rule tables: "Algos" is "Algorithms"; action buttons left-aligned; the
  admin-enforced switch labelled "Enforced".

- Status table: the label column capped.

- Permission selects: hidden until the saved options have loaded.

### Fixed

- Admin settings: the global rule's stored algorithms show; the
  multiselect tracks the option list as well as the value.

- Admin settings: the app-name heading is back, inside the Vue mount.

## [0.18.0] - 2026-08-22

### Added

- Vitest coverage for the settings pages' HTML escaping and rendering.

- `useAdminSettings`, `usePersonalSettings`: the settings pages' fetch
  logic as composables, with a stale-response guard.

### Changed

- `DatabaseService`: `safeBool`/`safeInt`/`safeString`/`safeArray`
  documented as sentinel-on-failure.

- `RuleTable`, `RuleRow`, `RuleForm`: shared Vue components for both
  settings pages.

- Admin settings page: one Vue app, four build entries become one; the
  dead crontab snippet generator and its documentation are gone.

- Personal settings page: Vue, on the same components; the vanilla
  `settings-personal` and `tabs.ts` modules are gone.

## [0.17.1] - 2026-08-22

### Fixed

- `generate --mark`: catches `Throwable`, so a vanished user is skipped
  rather than crashing the run.

## [0.17.0] - 2026-08-22

### Changed

- Algorithm-whitelist validator: one, shared by `SettingsController` and
  `PersonalSettingsController`.

- Hashing: every required checksum of a file in one read pass, not one
  read per algorithm.

- Default duplicate-group page size: one constant in place of six copies.

- `adler32`: documented in README, FAQ, API docs and OpenAPI; canary
  tests keep the PHP and TypeScript algorithm lists in step.

## [0.16.1] - 2026-08-22

### Fixed

- `useDuplicates`: in-flight requests cancelled when filters change, so a
  stale response cannot overwrite a newer one.

## [0.16.0] - 2026-08-22

### Removed

- The legacy vanilla-JS duplicates bundle and its loading listener: gone.

## [0.15.1] - 2026-08-22

### Fixed

- Metadata-index seeding: skipped, with a warning naming the rebuild
  command, while its target table does not exist yet.

## [0.15.0] - 2026-08-22

### Changed

- `auto` mode: documented as recalculating existing stale hashes only,
  never a first hash.

## [0.14.1] - 2026-08-22

### Fixed

- Concurrent recalculations of different algorithms on one file: metadata
  saved before the lock is released, so none is dropped.

- Truncated-hash comparisons for SHA-256/SHA3-256 and SHA-512/SHA3-512:
  full-hash searches match, and a duplicate group is verified against the
  untruncated hash.

### Security

- `lookup`, `getHashes`, `recalcHash` on the public and legacy API:
  scoped to the requesting user's own files.

- `SettingsController` rule endpoints: administrators only.

- Personal rule mutation and file-event rule matching: `userScope`
  ownership enforced, so a rule cannot be altered or triggered by
  guessing its id.

## [0.14.0] - 2026-08-21

### Added

- Vitest scaffold: tests for the algorithm and tab helpers,
  `useClipboard`, `useSidebarHashes` and `RecalcButton`.

## [0.13.1] - 2026-08-21

### Fixed

- `generate` without `--batch-size`: unlimited, not zero files.

## [0.13.0] - 2026-08-21

### Added

- FAQ and user help: in-app Help tabs on the personal settings and
  Duplicates pages, and a public help endpoint.

### Changed

- Admin page: its own settings section, and a read-only Documentation tab
  rendering the bundled docs.

- Personal settings page: users edit and create hash-generation rules; a
  per-rule `admin_enforced` flag locks one, allowed groups and users gate
  access.

- README, `info.xml`, API docs, changelog: the current feature set;
  database triggers, MariaDB-only support and Webpack no longer named.

- `info.xml`: App Store metadata (PHP and database dependencies,
  documentation links, HTTPS repository URL), and screenshots.

- Checksums sidebar tab: a Vue custom element with a loading spinner;
  sharing-tab-style sections with help popovers, an algorithm selector
  with a recalc button, full-hash tooltips.

- Algorithm checkboxes on both settings pages: one shared `NcSelect`
  multiselect.

### Removed

- Redundant `.gitkeep` files: gone.

## [0.12.1] - 2026-08-16

### Fixed

- `FilecacheService::setHashes()`: pairs that would overflow
  `oc_filecache.checksum` are dropped, so a multi-algorithm file saves.

## [0.12.0] - 2026-08-15

### Added

- GitHub Actions App Store release pipeline: build, package, optional
  signing.

### Changed

- README, `info.xml`: the files-metadata-index architecture, any-DB
  support, the 7 CLI commands, rule-based hashing.

## [0.11.1] - 2026-08-15

### Fixed

- Background jobs: registered once via `info.xml`, so NC 33's
  `JobList::add()` no longer resets `last_run` and the pending queue
  drains.

- `file-checksum-updated_at`: marked as an indexed metadata value on
  save, so the pending queue drains under NC 33.

- Metadata keys: registered at install, not on every boot; no recurring
  debug warning.

## [0.11.0] - 2026-08-15

### Changed

- `cron.php`: `pending:new` entries are processed, the matching rule
  resolved before dispatch; the `HashCalculationService`/`RuleService`
  dependency cycle is gone.

## [0.10.1] - 2026-08-07

### Fixed

- Sidebar tab: registers; the SVG icon is imported as raw XML, and
  registration is logged.

- `recalcHash`: reads `algo` from the query string when the body is
  empty, instead of defaulting to SHA-1.

- Duplicate search: `updated_at` excluded from grouping, JSON-array hash
  values handled, the indexed hash as fallback.

- Vue duplicates template: the check and cross characters render.

- Settings page Status: a total pending count beside the per-mode
  breakdown, a Refresh button, a Last Updated timestamp.

- `HashSearchProvider` results: open the file's details sidebar, not the
  directory root.

## [0.10.0] - 2026-08-07

### Added

- `SettingsControllerTest`: 14 tests over the six controller methods;
  `readRequestBody()` mockable.

- Developer tooling: ESLint, PHP-CS-Fixer, Psalm, Rector, Stylelint,
  TypeScript, Vite; committed build artifacts removed.

### Changed

- Project metadata: code of conduct, license, app info, composer info.

- Routing: `#[ApiRoute]`/`#[FrontpageRoute]` attributes in place of
  `appinfo/routes.php`.

- Frontend: TypeScript; the duplicates index page in a new
  `PageController`; URLs from `@nextcloud/router` with central route
  constants.

- Frontend build: Vite in place of Webpack; the global duplicate browser
  is a Vue 3 SPA.

## [0.9.1] - 2026-08-07

### Fixed

- `HashSearchProvider::search()`: the parsed hash is used; `$hash` was
  undefined.

## [0.9.0] - 2026-08-07

### Added

- Class-level PHPDoc and `@throws` across services and listeners.

- `FciasUnitTestCase`; unit tests for `MetadataService`
  `queryDuplicates`/`queryByHash` and
  `HashCalculationService::processFile`.

### Changed

- Code: decorative section comments removed, pending-mode strings as
  `MetadataService` constants, glob matching in `PathUtil`.

## [0.8.1] - 2026-08-07

### Fixed

- Post-migration audit: the hash search regex accepts ADLER32/CRC32
  lengths; `auto` mode recomputes only existing algorithms; file copies
  are marked with the right pending mode.

## [0.8.0] - 2026-08-07

### Added

- Rule processing: self-dispatching background jobs when a batch fills,
  rule matching in `FileListener`, path-based rule search with glob
  pagination, folder-based hash marking.

### Removed

- The dropped hash/pending tables and the trigger/stored-procedure
  infrastructure: every remaining reference gone; `ChecksumApi` and
  `StatusService` go through `MetadataService`.

## [0.7.1] - 2026-08-07

### Fixed

- `HashIndexService::generateMissingHashes()`: an undefined argument no
  longer throws on every call.

- `MetadataService::queryDuplicates()`: the column alias, so duplicate
  detection returns hash values.

## [0.7.0] - 2026-08-07

### Added

- `file-checksum-search:status`: cron job definitions, plain text and
  JSON.

- Integration tests for the pending-queue drain and `FileListener`; two
  doubled table prefixes (`oc_oc_…`) fixed on the way.

- `FilecacheService`, `HashCalculationService::processFile()`: filecache
  checksums and metadata kept in sync; `FileOperationService` gone.

- Background pipeline: `RuleProcessingJob`, `ProcessPendingUpdates`,
  `SeedPendingUpdates`, `MetadataListener`.

### Changed

- `MetadataService` and a fresh migration onto `oc_files_metadata`; every
  earlier migration dropped.

- `FileListener`: clears metadata and marks files pending; hashing is the
  job's.

- Search and duplicate detection: query `oc_files_metadata`, reading
  hashes from the JSON column.

- CLI: deferred processing, metadata-aware status/rebuild/benchmark;
  table and trigger management commands gone.

- Configuration: rules (`RuleService`) and an admin UI in place of the
  cron/trigger settings; six classes of the old infrastructure gone.

## [0.6.1] - 2026-08-05

### Fixed

- `DatabaseService`: query errors go to stderr, so the status command's
  JSON stays valid.

- `FileListener`: the `locked` key null-coalesced.

## [0.6.0] - 2026-08-05

### Added

- "Files with same hash": a *Find duplicates* sidebar button over a
  self-join on the hash table.

- Event-driven index maintenance for write, create, delete and copy: a
  pending-update queue table, a draining job, per-event configuration.

- `ILockingProvider` file locking on hash operations; a locked file is
  retried through the queue.

- Global duplicate locator: REST API, CLI command, standalone UI;
  access-controlled, paginated.

- `show-config` CLI command; `status --output=json`.

- Logging in controllers, commands, listeners and migrations.

- `updated_at` on the hash table: current hashes skipped on
  recalculation; shown in status and the sidebar tooltip.

### Changed

- Event listeners: dedicated self-registering classes.

- Hash lookup and path resolution: `HashIndexService` methods, no direct
  database dependencies elsewhere.

- README: every CLI command and feature; docblocks on controllers and
  commands.

- `HashIndexService`: split into hash calculation, pending queue,
  duplicates and file operations; the facade stays.

- `escapeHtml`: one JS utility module.

- `StatusService`: `safeIntQuery`/`safeExistsQuery` helpers.

- Public API: `ChecksumApi` and `/api/v1` (versioning, authentication,
  rate limiting, compatibility); `LookupController` delegates to it and
  the legacy `/api/1.0` routes are removed.

## [0.5.1] - 2026-08-05

### Fixed

- Unified Search: `HashSearchProvider` registered via
  `IRegistrationContext`, since NC 33 ignores `info.xml`'s `<search>`;
  a fingerprint app icon.

- App icon: `img/app.svg` everywhere, no inline SVG copies.

## [0.5.0] - 2026-08-04

### Added

- Scheduled hash generation: NC background jobs with admin CRUD, or a
  generated crontab snippet; `CronJobService`, `SUPPORTED_ALGOS`.

## [0.4.1] - 2026-08-04

### Fixed

- Sidebar tab: reloads hashes when the node changes, not only on first
  connect.

- Recalc: the algorithm prefix matched case-insensitively, so no
  duplicate hash entries.

- `recalcFileHash`: a null filecache checksum no longer 500s.

- Checksums tab: files only, not folders.

## [0.4.0] - 2026-08-04

### Added

- Sidebar tab: click-to-copy values, SHA-1/MD5 recalculation buttons, a
  "Checksums" file menu entry; a `recalcFileHash` endpoint.

### Changed

- `HashIndexService`, `TriggerInitializationService`: the CLI/controller
  logic, once.

## [0.3.1] - 2026-08-04

### Fixed

- Sidebar tab registration: `@nextcloud/files` v4
  `getSidebar().registerTab()`, with a webpack build; NC 33 removed
  `OCA.Files.Sidebar.registerTab()`.

## [0.3.0] - 2026-08-04

### Added

- Restore commands (CLI and admin buttons): recreate the hash table,
  triggers and stored procedures, idempotently.

### Changed

- Admin settings JavaScript: whitespace.

## [0.2.1] - 2026-08-04

### Fixed

- Compatibility-test status indicators: background-colour badges,
  readable on dark themes.

- Admin maintenance dialogs: `OC.dialogs.message()`, with an OK button
  and record counts.

## [0.2.0] - 2026-08-04

### Changed

- `StatusService`, `HashIndexService`: the status and index-maintenance
  logic of the CLI and the settings controller, once.

## [0.1.1] - 2026-08-04

### Fixed

- `TableNameService` in place of the non-existent
  `IDBConnection::getPrefix()`, so every CLI command and admin endpoint
  runs; the `dbtableprefix` config key is read.

- `triggers_deployed`: a config lexicon entry, so no info message per
  boot.

- `GenerateHashes`: processes the root folder, no `File::setChecksum()`
  call; debug logging and verbosity-based progress.

## [0.1.0] - 2026-08-03

### Added

- MariaDB migration: shadow table, stored procedure, insert/update/delete
  triggers in sync with the filecache.

- Application bootstrap: Unified Search provider and sidebar scripts
  registered at runtime.

- REST API controller: files by hash, all hashes for a file id.

- Unified Search provider: raw hex hashes or `algo:hash` queries against
  the indexed files the user can access.

- Core CLI commands: rebuild, search, generate, benchmark.

- Administrative CLI commands: status, purge, tear down triggers, remove
  the shadow table.

- Admin settings page: a compatibility test and maintenance actions
  (purge, rebuild, tear down, remove).

- Files sidebar tab: a file's checksums as algorithm badges with values.

### Changed

- App lifecycle, dependency injection, the API and assets: reworked after
  the initial code audit.
