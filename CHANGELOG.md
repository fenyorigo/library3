# Changelog

All notable changes to this project will be documented in this file.

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
