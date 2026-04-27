# Changelog

All notable changes to this project will be documented in this file.

## [2.6.3] - 2026-04-27
### Added
- Installer supports `--params-file=<path>` to preload non-secret install options from a line-based argument file
- Added `install.params.example` template for reusable unattended installer defaults

### Changed
- Fedora installer default vhost filename format is now `/etc/httpd/conf.d/<port>-<app>.conf` (`<app>` from target directory basename)

## [2.6.2] - 2026-04-26
### Added
- Installer now applies Fedora-specific post-install hardening: app/backup ownership and permissions, plus post-install verification checks

### Changed
- Installer execution flow now includes concrete install steps after confirmation (extract, post-extract precheck, DB/bootstrap, config/vhost generation)
- Fedora install flow now updates listen/firewall/SELinux settings from installer inputs

### Fixed
- Installer now updates existing MySQL/MariaDB app users with the provided password before grants (prevents app-user auth mismatch)
- Fedora config hardening now aligns config file ownership/mode for Apache readability and secret protection

## [2.6.1] - 2026-04-26
### Added
- App-local PHP runtime limits via `public/.user.ini` for large import uploads and longer execution/input timeouts

### Changed
- Selected export naming now reports logical cover count (book covers only), excluding thumbnails and default cover assets
- Import/Export UI wording updated to `Import books` and `Export selected books (CSV + covers)`
- Selected export now also includes `uploads/default_cover.jpg` if present (in addition to `uploads/default-cover.jpg`)
- Catalog purge now preserves `uploads/default-cover.jpg` / `uploads/default_cover.jpg`

### Fixed
- Import modal now handles upstream `504 Gateway Timeout` responses gracefully for long-running restore requests
- Purge result reporting now separates cover files and thumbnail files to avoid misleading doubled cover counts
- Full backup ZIP now includes non-JPG covers (e.g. PNG/WEBP/GIF) using DB-referenced cover paths instead of `cover.jpg`-only collection

## [2.6.0] - 2026-04-26
### Added
- Unified admin export: `Export selected (CSV + covers)` creates one ZIP bundle with shared timestamp and current filter support
- ZIP bundle import support (CSV + covers) with DR/full restore options
- Import modal progress indicator for long-running restore operations

### Changed
- Import options now support `CSV only` vs `CSV + covers` and `keep IDs` vs `new catalog IDs`
- Cover restore during import now remaps files to target IDs and regenerates thumbnails for consistency
- List API now returns real `cover_thumb` values (thumbnail rebuild results are visible immediately)
- CSV export filename now includes schema version suffix
- DB config key is standardized to `db.dbname` (no `db.name` fallback)

### Fixed
- Duplicate merge cleanup stability and reference-safe cover cleanup behavior
- Import error reporting now returns clearer details for oversized uploads and non-JSON backend failures

## [2.5.0] - 2026-04-25
### Added
- Duplicate merge-as-copies workflow with admin-only `MERGE` confirmation and atomic backend transaction
- `Books.copy_count` field (default `1`) plus duplicate review system status `MERGED`
- Admin merge audit logging and post-commit duplicate cover/thumb cleanup (reference-safe)

### Changed
- Duplicate candidates UI now supports per-group master selection and destructive merge confirmation modal
- `MERGED` duplicate groups are system-managed/read-only (not selectable in review dropdown)
- Catalog exports/imports now include `copy_count` (older imports default to `1`)
- Book list/detail/edit flows include `copy_count` display/support
- Added migration SQL for schema `2.3.5`

## [2.4.3] - 2026-04-25
### Added
- Admin-only catalog purge from frontend (DB catalog tables + cover/thumb uploads wipe) with audit logging

## [2.4.2] - 2026-02-15
### Changed
- Version logic centralized

## [2.4.1] - 2026-02-15
### Changed
- Changed method of loading env variables

## [2.3.5] - 2026-01-23
### Added
- Notes field for books (entry, display, list column toggle)
- Maintenance SQL scripts for zero-width cleanup and NFC accent normalization

### Changed
- CSV import/export and backups now include book notes
- Book search now matches notes content

## [2.3.6] - 2026-01-26
### Fixed
- Orphan maintenance now displays orphan publishers correctly

## [2.3.4] - 2026-01-18
### Added
- Status endpoint reports app and schema versions

### Changed
- Established a cross-platform baseline schema for macos and fedora
- SystemInfo is checked for version correctness on status

## [2.3.3] - 2026-01-18
### Added
- Duplicate candidates CSV export (status-filtered)
- Server-side duplicate review persistence (`duplicate_review`)

### Changed
- Duplicate detection now considers subtitle
- Author identity in duplicate detection is based on normalized `sort_name`
- Duplicate grouping is stable across systems (MySQL / MariaDB)
- SystemInfo schema/app versions are synchronized on login

### Notes
- Existing duplicate reviews must be reset when upgrading to this version.
