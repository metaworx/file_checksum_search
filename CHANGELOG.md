# Changelog

All notable changes to the **File Checksum Index & Search** (FCIAS) app will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions below `1.0.0` are pre-release: fixes/security patches increment the patch digit, and
any addition or change (including breaking changes) increments the minor digit, until
the first stable release.

## [Unreleased]

## [0.20.2] - 2026-09-25

### Added

- App store listing: a slideshow of the six screenshots as its first
  image.

### Fixed

- App store listing: the screenshots did not show, and the description
  rendered as code.

## [0.20.1] - 2026-09-25

### Fixed

- Admin status panel: a failed status request read as `Total: 0`.

## [0.20.0] - 2026-09-25

### Added

- `findByHash()` with `$withLocalPath`, `/api/v1/sudo/lookup?localPath=1`
  and `occ file-checksum-search:search --local-path`: each file's
  absolute path on the server's disk.

- `occ fcias:backup`: configuration, queue state and hashes as `json`;
  hashes alone as `csv` or a checksum listing.

- `occ fcias:import`: configuration and hashes back in, with `--merge`
  or `--replace`. The queue is not imported.

- `occ fcias:reset`: a report until `--force`.

- `occ fcias:repair`: every repair step by name (`--list`, `--step`,
  `--dry-run`, `--include-expensive`).

- `occ fcias:repair --step unindexed-hashes`: files the index forgot,
  run only when named or with `--include-expensive`.

- `occ fcias:repair --step orphaned-metadata`, `orphan_purge_interval`:
  this app's metadata for files the filecache no longer has, purged
  daily and when an account is deleted.

- `occ fcias:queue:drain`: hash what the rules queued, now
  (`--batch-size`, `--all`).

- `occ fcias:hash --algo`: repeatable, `auto` (the governing rule's
  list) by default.

- `occ fcias:hash --mode missing`: outdated hashes refreshed as well.

- `occ fcias:hash --unmatched`, `--mark`, `--with-ignored`,
  `--ignore-rule`, `-v`/`-vv`: new.

- `occ file-checksum-search:rules:list|add|modify|delete|apply`: rule
  management from the shell, `list` in JSON.

- `/api/v1/rules`: `GET`, `POST`, `PUT /{id}`, `DELETE /{id}`,
  `PUT /order`, `POST /{id}/apply`, one resource for both settings pages.

- `POST /api/v1/rules`, `PUT /api/v1/rules/{id}`: the stored rule in the
  answer.

- `ChecksumApi::listRules()`, `createRule()`, `updateRule()`,
  `deleteRule()`, `applyRule()`, `findDuplicatesFor()`, `recalcMany()`,
  `openableBy()`: new.

- `ChecksumApi`: `$actingUser` and `?array $reachUids` in place of
  `?string $requestingUser`. **A positional fourth argument to
  `findByHash()` must change.**

- `GET /api/v1/algorithms`, admin *Hash Algorithms*: the algorithms an
  instance allows and its default (`default_algorithm`).

- `GET`/`PUT /api/v1/preferences/preferred_algorithm`, personal
  settings: the algorithm the sidebar offers first.

- Rule verdicts `include`, `ignore`, `exclude`: the first matching rule
  decides.

- Rule verdict `exclude`: hashing refused by every route, the sidebar
  included.

- Permissions *Who may calculate by hand* (`manual_recalc`), *look
  across accounts* (`instance_view`), *use the API* (`api_access`):
  beside *edit rules*, the first and the last allowed by default.

- `api_access`: gates a request authenticated as the API, never the
  bundled pages.

- `canRecalc` on `GET /api/v1/file/{fileId}/hashes`: whether the caller
  may recalculate.

- `/api/v1/sudo/`: twins of `file/{fileId}/hashes`,
  `file/{fileId}/duplicates`, `lookup`, `duplicates`,
  `file/{fileId}/recalc` and `file/many/recalc`, behind a password
  confirmation or a granted app password.

- `/api/v1/sudo/` reach: every account for a sudoer, their members for a
  group leader.

- `users[]`, `groups[]` on `GET /api/v1/sudo/duplicates`: a set of
  accounts, expanded and authorised server-side.

- `GET /api/v1/sudo/selectable`: the groups and accounts the caller may
  name, with `all` and `reach`.

- Sudo tokens: a grant per app password, on the personal page.

- Admin *Sudo tokens* tab: every grant, revocable.

- Duplicates page *Others* tab: other accounts' duplicates, named by
  account, by group, or as the caller's whole reach.

- Duplicates page: the tab, its filters, the page and the scope in the
  URL fragment.

- Sidebar *Find across accounts*: the *Others* tab on the file's hash,
  for those who may cross.

- `POST /api/v1/file/many/recalc` and its `/sudo/` twin: up to 25 files
  or 100 MiB per request.

- `#[UserRateLimit]` 60/min: `lookup`, `duplicates`,
  `file/{fileId}/duplicates`, `file/{fileId}/hashes`, their `/sudo/`
  twins, `sudo/selectable`.

- `#[UserRateLimit]` 20/min: every recalc route.

- The 429 answer: Nextcloud's own, with an empty body.

- `ratelimit_overwrite` in `config/config.php`: the limits, per route.

- `hash`, `anywhere` on `GET /api/v1/duplicates` and its twin, *Hash*
  and *Search anywhere* on the page: only the groups a hash names.

- `stale:eroded`, `stale:reset`: hashes dropped on write or disowned by
  a reset, counted on the Advanced tab and in
  `occ file-checksum-search:status`.

- Stale hashes: out of search, lookup and duplicate groups.

- Admin page idle banner: shown while no enabled `include` rule exists,
  *Acknowledged* kept in `idle_banner_ack`.

- Advanced tab: the background jobs' last runs.

- Rules table: a *Create rule* row for every namespace without a
  catch-all.

- Rules table: a *provider missing* badge beneath the name of a rule
  naming a gone one.

- Rule row actions menu: Edit, Enable/Disable, *Re-apply*, Delete.

- Rule dialog: user, group and group-folder targets picked by name.

- Audit log: every rule mutation at INFO, WARNING for an admin-enforced
  rule, with the surface that asked.

- Audit log: every cross-account recalculation, with the acting account.

### Changed

- Rule **selector** `home:<uid>`, `group:<gid>`, `home:*`,
  `groupfolder:<id>`, `storage:<id>`, `*`: in place of the user scope.
  Stored rules migrate.

- Rule bands: eight, enforced 1–4 and unenforced 5–8, each selector
  value its own segment with a trailing defaults partition.

- Shipped default rules `home:*` and `*`: created disabled where absent,
  recreated disabled by the repair step.

- Rule matching: by the file's canonical identity (`FileLocation`),
  never the acting user's path.

- Rule sweeps: by storage.

- Trash, versions and appdata: governed by no rule.

- A personal rule path into a share or a mounted storage: refused, with
  the reason.

- Pending queue: a row only for a file that was queued, hashed or
  eroded.

- Queue drain: the file's rule resolved at action time, its algorithm
  list honoured.

- Install: the checksums the filecache already holds copied, nothing
  computed.

- `occ file-checksum-search:generate`: renamed `occ fcias:hash`, no
  alias.

- Metadata keys: `file-checksum-hash-<algo>`, existing rows renamed by
  the repair step.

- Admin page tabs: *Settings*, *Permissions*, *Sudo tokens*, *Advanced*,
  *Documentation*.

- Advanced tab: diagnostics and the picker prefill threshold
  (`cross_account_prefill_limit`, 21).

- `GET`/`PUT /settings/global`: one resource.

- `appinfo/info.xml` screenshots: six, retaken from the current UI and
  pinned to the release.

- Both settings pages: one banded rules table that fits its column, help
  on every column and band, `<band>.<position>` as text.

- Personal rules page: the enforced rules above and the defaults below
  the user's own.

- Rule rows: a pen icon beside the menu.

- Group folders: named as the groupfolders app names them.

- Rules table Scope column: the same glyph per kind of place as the
  duplicate rows.

- Duplicates page: **Verify all** per group and **Verify** per file in
  place of the page-wide button and *Only matching*.

- Verification run: stopped by a 429 with a message, resumed by the next
  click.

- Duplicates page controls: Nextcloud's, labelled, with help buttons.

- Every file row: `owner` and `location` (`FileLocation::describe()`).

- File rows: the viewer's own by the path the Files app shows, the
  others by their location, each with a glyph for the kind of place.

- Cross-account file rows: `openable`, linked only where true.

- `GET /api/v1/status`: a non-administrator gets `version` alone.

- `GET /api/v1/file/{fileId}/hashes`: `canSudo`, whether the caller may
  look across accounts.

- Duplicates listing: the filter pages 200 groups at a time.

- Truncated hash groups: confirmed in one read.

- Settings pages: Save disabled with nothing to save, yellow with
  changes.

- Settings pages: secondary text at normal size in the max-contrast
  colour, logical CSS properties throughout.

- Algorithm pickers: one `AlgorithmSelect`.

- `docs/HELP.md`: now `docs/user-guide.md`, the Help tab of the
  Duplicates page and of personal settings.

- `docs/FAQ.md`: the administrator's.

- README *How hashing happens*, the rules chapter, `docs/api-v1.md`, the
  OpenAPI document: against the shipped routes.

### Removed

- `PersonalSettingsController`, `/settings/cron/*`, `/personal/rules/*`:
  gone, `/api/v1/rules` in their place.

- `GET /settings/cron/snippet`: gone.

- `GET /duplicates/data`: gone, `GET /api/v1/duplicates` in its place.

- `GET /settings/admin-options`, `POST /settings/admin-options/save`:
  gone, `/settings/global` in their place.

- `/api/1.0/`, `LookupController`: gone, `/api/v1/` has every
  operation.

- `occ file-checksum-search:rebuild`: gone, `occ fcias:repair` in its
  place.

- Queue seeding job, `pending:new`: gone.

- Rule mode `off`: gone, such a rule becomes `ignore`.

- Rule flag `pinned`: gone.

- `openapi.json` at the repository root: gone, `docs/api-v1-openapi.yaml`
  is the specification.

### Fixed

- `occ file-checksum-search:search`: printed each file's name twice.

- Recalculating every algorithm a file carries: computed nothing.

- Search for `sha3-256:`, `sha3-512:` and upper-case prefixes: found
  nothing.

- Search for SHA-256, SHA-512 and SHA3 hashes: found nothing, the index
  row never written. The repair step adds the missing rows.

- Cleared hashes: kept answering searches, lookups and duplicate groups.

- Duplicate groups, lookups, `occ file-checksum-search:find-duplicates`:
  a hashed file in the trash, in the versions or in an app's data was
  offered as a copy.

- `process_pending_interval`, `pending_batch_limit`: undeclared, a
  lexicon warning per drain run.

- Documented REST URLs: under `/ocs/v2.php/apps/file_checksum_search/`,
  `minCount` not `min_count`, the `info.xml` documentation link.

- `occ fcias:hash` without `--mark`: `exclude` rules not honoured,
  unmatched files hashed.

- Sidebar: a refused recalculation said "Error", not whose rule refused
  it, and said it in a colour the light theme cannot show.

- A modified file no rule maintains: its stale hashes kept.

- Rule priority: the catch-all evaluated first. **On upgrade, additional
  rules that never applied start applying.**

- Rule editing: a non-administrator could change an instance-wide rule.

- Dark theme: error lines, verification verdicts and the sidebar's
  algorithm badges coloured with the palette's background fills.

- Settings pages: notices did not show, `OC.Notification` being gone in
  Nextcloud 34.

- `occ file-checksum-search:find-duplicates`: `owner` was the storage
  id's last segment, `location` missing, and an ownerless row printed a
  path that names nowhere.

- `appinfo/info.xml`: four elements out of the app store's order, and
  two documentation links padded with whitespace, which its schema
  refuses.

### Security

- Periodic rule sweep: stale rows only, exclusion resolved per file.

- Unified search: the limit capped at 100.

- `GET /api/v1/lookup`, the unified search, the own listing: scoped to
  the caller's home, received shares and group folders before the limit
  applies.

- `POST …/recalc`, `POST /rules/{id}/apply`: `#[NoCSRFRequired]`
  removed. A cookie session sends the request token or
  `OCS-APIRequest: true`.

- `/settings/status`: administrators only.

- `RulesController`: a failed write answers *Internal server error.*,
  the detail in the log.

- `minCount`: clamped to 2 or more on every route.

- Administrators: the ordinary routes answer their own files. **Scripts
  calling the API with administrator credentials receive the
  administrator's own files.**

- Checksums adopted from `oc_filecache.checksum`: only an allowed
  algorithm with a hex value of its length.

- `GET /api/v1/file/{fileId}/duplicates`: the reference file resolved
  within the caller's reach.

- `/api/v1/sudo/`, the *Others* picker: a group leader reaches their
  members, not the administrators among them.

- `/api/v1/sudo/`: an account that may not cross is refused, an empty
  set included.

## [0.19.0] - 2026-08-23

### Changed

- Rule dialog: an `NcDialog` on both settings pages in place of the
  inline form; the first editable field focused on open, Escape cancels,
  no close button of its own.

- Global rule: an ordinary row in its own table above the additional
  rules; scope and path as plain text, pinned server-side, no Delete
  button.

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

- Rule Editing Permission page: Save left-aligned.

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
  scoped to the requesting user's own files. **Before: other users' paths
  and hashes were readable, and a recalculation could be forced on their
  files by hash or file id.**

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
