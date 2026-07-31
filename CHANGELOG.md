# Changelog

All notable changes to the **File Checksum Index & Search** (FCIAS) app will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions below `1.0.0` are pre-release: fixes/security patches increment the patch digit, and
any addition or change (including breaking changes) increments the minor digit, until
the first stable release.

## [Unreleased]

### Added

- Add the MariaDB migration deploying the shadow table, stored procedure, and insert/update/delete triggers that keep file checksum data in sync with the filecache.
- Add the Application bootstrap that registers the Unified Search provider and the sidebar frontend scripts at runtime.
- Add a REST API controller for looking up files by hash and retrieving all hashes for a given file ID.
- Add a Unified Search provider that matches raw hex hashes or algo:hash queries against indexed files the user has access to.
- Add the core CLI commands for rebuilding the hash index, searching by hash, generating checksums, and benchmarking indexed lookup performance.
- Add administrative CLI commands for reporting index status, purging, tearing down triggers, and removing the shadow table.
- Add an admin settings page with a compatibility test and maintenance actions for purging, rebuilding, tearing down, and removing the index.
