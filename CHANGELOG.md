# Changelog

All notable changes to the **File Checksum Index & Search** (FCIAS) app will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions below `1.0.0` are pre-release: fixes/security patches increment the patch digit, and
any addition or change (including breaking changes) increments the minor digit, until
the first stable release.

## [Unreleased]

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
