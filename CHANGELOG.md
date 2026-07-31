# Changelog

All notable changes to the **File Checksum Index & Search** (FCIAS) app will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-07-31

### Added

- Initial release of the FCIAS app
- MariaDB shadow table `file_checksum_search_hashes` with indexed hash lookup
- Stored procedure `fcias_parse_file_hashes` for checksum string parsing
- Three triggers (`t_fcias_after_insert`, `t_fcias_after_update`, `t_fcias_after_delete`) maintaining the shadow table automatically
- REST API endpoints for hash lookup (`GET /api/1.0/lookup`) and file-hash retrieval (`GET /api/1.0/file/{fileId}/hashes`)
- Unified Search provider supporting raw hex hash and `algo:hash` syntax
- Eight CLI commands: `rebuild`, `search`, `generate`, `test-perf`, `status`, `purge`, `teardown`, `remove-table`
- Admin settings page with compatibility test and maintenance actions
- Frontend sidebar tab for file checksum display
- Full README.md documentation with CLI reference, REST API docs, and troubleshooting guide
- PHPUnit test infrastructure with controller test stub
- Compatibility with Nextcloud v33–v34 and PHP ≥8.2
