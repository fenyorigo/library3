# Changelog

## 3.5.10 - 2026-07-04

- Added browser close/reload protection while long-running catalog operations are active, including ebook scans, SHA builds, cover extraction, backup/export polling, import, purge, and ebook orphan maintenance.
- Added close guards to Import books and Ebook orphan maintenance dialogs so in-progress jobs are not accidentally hidden without confirmation.

## 3.5.9 - 2026-07-03

- Fixed incremental ebook rescan classification so a catalog-known path with changed SHA256 is reported only as `same_path_different_sha`, not also as a new file candidate.
- Added a complete downloadable CSV report for incremental rescan `missing_on_disk` results, while keeping the modal summary short.

## 3.5.8 - 2026-06-27

- Added an Import books option to calculate SHA256 checksums for imported ebook copies during import. Print copies are never hashed; imported SHA values are validated, mismatches are reported, and the checksum of the current physical ebook file is stored when calculation is enabled.
- Added an admin ebook language tag audit that scans repository folders such as `0_HU`, `1_EN`, `2_DE`, and `3_FR` and reports missing or mismatched filename language suffixes like `[hu]`, `[en]`, `[de]`, and `[fr]` with an optional CSV report.
- Import result summaries now include SHA256 calculation counters and display import warnings in the UI.

## 3.5.7 - 2026-06-26

- Fixed ebook CSV import path normalization when the configured ebook root is a symlink, for example `/data` pointing to `/Users/bajanp/data`. Absolute paths exported through the real target path are now accepted via `realpath()` comparison and stored as canonical `/Books/...` catalog paths instead of being nulled.
- Added regression coverage for ebook root symlink/realpath aliases in path helper tests.

## 3.5.6 - 2026-06-20

- Changed Ebook orphan maintenance analysis to run as an asynchronous background job with UI polling, avoiding gateway timeout errors and preventing stale HTTP 504 responses from poisoning repository health state.
- Changed Ebook orphan maintenance CSV export to use the already loaded report data instead of rerunning the long backend analysis.
- Fixed incremental ebook rescan filename metadata false positives after export/purge/import by comparing display author names against structured filename author names in both plausible name orders.
- Added regression coverage for imported display authors such as Miklos Zrinyi versus Zrinyi|Miklos, foreign structured names such as Girard, Patrick, and multi-author Hungarian filenames.

## 3.5.5 - 2026-06-20

- Fixed add-book form resets triggered by repository health polling by passing a stable empty book object to the create dialog.
- Added admin **Catalog statistics CSV** export for audit/support workflows. The report follows the current search, record status, format, and language filters and includes summary counts, print and ebook counts, ebook format/language breakdowns, SHA256/path health, and total ebook file size.
- Documented the statistics export as a validation aid for full, print-only, and ebook-only export/import tests; it is not an import file.
- Fixed selected export filter propagation so `Export selected books (CSV + covers)` honors the active `Print`, `Ebooks`, specific ebook format, and language filters.
- Changed server-side full backup to run as an asynchronous background job with UI polling, avoiding gateway timeout errors while large ZIP archives are being generated.
- Added real cover-file counts to the Catalog statistics CSV export, counting `uploads/<book_id>/cover.*` files separately from `cover-thumb.*` thumbnails.
- Added optional downloadable CSV reporting for ebook cover extraction runs.
- Fixed ebook cover extraction batching to use a stable `book_id` cursor instead of `OFFSET`, avoiding skipped candidates when newly extracted covers remove rows from the pending set during the run.
- Changed server-side selected export ZIP generation to use the same asynchronous background-job/polling flow as full backup, avoiding gateway timeout errors for large full/ebook exports.
- Changed book import to run as an asynchronous background job with UI polling after upload staging, avoiding gateway timeout errors for large CSV/ZIP imports while preserving the existing import result summary.
- Raised the packaged `.user.ini` large-import baseline to 2048M upload/POST and 768M memory, removed script-level `memory_limit` overrides from import/export/backup code, and added admin PHP runtime diagnostics for effective upload, POST, memory, and timeout settings.
- Added central ebook repository availability checks, an admin "Check ebook repository" action, visible repository status, UI gating for repository-dependent ebook maintenance actions, and server-side guards to prevent false missing/orphan reports when the configured external repository is not mounted on the current host.

## 3.5.0 - 2026-06-18

Major ebook repository maintenance release.

Added complete SHA256-based ebook maintenance workflow:

- Initial SHA build.
- Ebook orphan maintenance.
- Incremental repository rescan.
- Full ebook integrity check.
- New ebook candidate CSV export.
- Same-SHA path update handling.
- Same-path content-change review.
- Duplicate file and duplicate SHA reporting.

This release separates database cleanup, repository scanning, and integrity validation into distinct admin tools. The SHA256 extension evolved from a simple checksum feature into a complete ebook repository maintenance workflow.

## 3.4.0 - 2026-06-16

- Added global `Settings.ebook_library_root` admin setting for the mounted ebook SSD root, for example `/Volumes/SanDisk 2T`.
- Changed ebook copy path storage: `BookCopies.file_path` now stores stable `/Books/...` POSIX paths instead of per-machine `/Volumes/...` absolute paths.
- CSV import accepts absolute NeoFinder paths under the configured mount point and stores them as `/Books/...`; paths outside the configured root are warned/rejected instead of being stored silently.
- Ebook cover extraction now resolves physical files from `ebook_library_root + BookCopies.file_path`.
- Added `BookCopies.sha256` with `idx_bookcopies_sha256`; CSV import/export now round-trips optional copy-level SHA256 checksums.
- Added admin batch action to build missing SHA256 checksums for existing ebook copies, with progress counters and per-copy problem report.
- Added Unicode-canonical ebook path handling: BookCatalog stores NFC logical paths and resolves macOS/APFS NFD filenames by canonical parent-directory matching; PHP intl/Normalizer is required.
- Added admin incremental ebook repository rescan using SHA256 to report unchanged, same-SHA path changes, duplicate files on disk, new file candidates, same-path content changes, duplicate SHA values, missing files, and errors; path/SHA updates require explicit confirmation, new file candidates can be exported as an Import books CSV, and duplicate-on-disk files can be exported as a SHA-grouped CSV report.
- Added explicit filename metadata repair in incremental rescan for known ebook files whose parsed filename authors/title/subtitle/series/language no longer match the catalog record.
- Fixed incremental rescan classification order so missing DB paths are relinked by SHA before being reported as missing; multiple scanned paths with the same DB SHA are reported for admin review.
- Added downloadable CSV audit report for filename metadata repair updates.
- Added pre-apply CSV export for filename metadata repair candidates so admins can review proposed changes before confirming updates.
- Added detection for missing old catalog records whose replacement ebook copy already exists in the database, with explicit soft-delete confirmation.
- Filename metadata repair now skips unchanged rows and reports only actual DB changes in its CSV audit output.
- Added downloadable CSV report for duplicate SHA values in the database.
- Added Ebook orphan maintenance for stale ebook catalog records: groups active ebook copies by SHA256, identifies missing/duplicated catalog rows, exports a CSV report, and soft-deletes stale or missing records only after explicit admin confirmation.
- Made book list search accent/umlaut-insensitive for common Latin characters and decomposed Unicode combining marks, so searches like `Westfalische` can match `Westfälische` / `Westfälische`.
- Added full ebook integrity check for known DB copies: verifies resolved files, SHA256, file_size, missing-on-disk cases, and exports grouped reports; all repair actions require explicit confirmation.
- Added migrations `v3_add_settings_ebook_library_root.sql` and `v3_add_bookcopy_sha256.sql`; bumped schema version to 3.3.0.

## 3.3.0 - 2026-06-15

- **Ebook cover extraction** as a separate admin action ("Extract ebook covers" button): polls books with epub/pdf copies that have no cover, extracts covers in batches of 5, shows live progress overlay. epub: ZipArchive + OPF manifest (EPUB3 `cover-image` property, EPUB2 `<meta name="cover">`). PDF: `magick` via `proc_open` with 10 s hard timeout (avoids PHP-FPM hangs from Ghostscript).
- **Format filter** in book list: added "Ebooks" group option (matches all non-print formats); removed azw3, lit, odt individual options; options now use display-case labels (Print, Ebooks, EPUB, MOBI, PDF, DJVU, PRC, RTF).
- **schema.sql** regenerated from live DB as the new authoritative baseline; incremental migration files are no longer needed for fresh installs from this version onward.
- README: expanded NeoFinder conversion section with full filename convention reference, TSV column table, language tag and metadata block documentation.
- Fixed `show_file_size` preference not saving (missing `fd.append` in `api.js`).
- Schema version: 3.1.1 (unchanged). DB: `books3`.

## 3.2.5 - 2026-06-15

- Schema 3.1.1: added `file_size` (BIGINT, bytes) to `BookCopies` and `show_file_size` to `UserPreferences`.
- NeoFinder TSV converter: supports `Name`/`Path`/`Size` columns (no `Kind`); skips folder entries; extracts language from `[hu]`/`[en]`/`[de]`/`[fr]` tags in titles and from path segments (`0_HU` etc.); handles `\r`-only Mac line endings.
- BookList: new toggleable **Size (MB)** column showing total ebook file size per book (sum of copies, 1 decimal).
- BookDialog: file size shown read-only in View and Edit modals.
- Preferences: added File size toggle to Personalize menu.

## 3.2.4 - 2026-06-13

- BookList: added fixed-width CSS classes `.w-subtitle` (160 px), `.w-series` (140 px), `.w-authors` (180 px) with wrapping text (no ellipsis truncation).
- BookList: authors column now renders each semicolon-separated author on its own line instead of a single concatenated string.

## 3.2.3 - 2026-06-12

- Renamed `public/addBook.php` → `add_book.php` to follow the snake_case endpoint naming convention; updated `api.js` references.
- Added `CLAUDE.md` with project documentation and coding conventions.
- Added `docs/csv-format.md` with full CSV format and ebook filename reference.

## 3.2.2 - 2026-06-07

- Fixed restored catalogs whose `Books` AUTO_INCREMENT value could remain higher than the actual book ID range.
- Avoided requiring ALTER privileges during manual book creation by assigning the next logical book ID explicitly.
- Normalized the Books AUTO_INCREMENT after imports when the database user has permission, with a warning fallback otherwise.

## 3.2.1 - 2026-06-06

- Display relation-level author aliases in book detail and edit views.
- Allow editing per-book author aliases from the book edit modal.
- Show author aliases in the Authors modal as usage metadata, excluding aliases attached only to deleted books.

## 3.2.0 - 2026-06-06

- Added relation-level ebook author aliases via `Books_Authors.author_alias` and the `{aka ...}` filename metadata convention.
- Added `@...@` single-author phrase parsing so display names such as `@Dante@` are not split into family/given parts.
- Extended ebook inventory CSV conversion and author metadata JSON roundtrip for alias-safe import/export without adding a new top-level CSV column.

## 3.1.5 - 2026-06-05

- Added CSRF headers to cover upload and cover deletion requests in the book dialog.
- Added cache-busting for local cover asset URLs after import, purge, and cover changes to avoid stale thumbnails after restores.

## 3.1.4 - 2026-06-05

- Show a busy overlay while catalog purge is running after the `DELETE` confirmation.
- Exclude macOS metadata files such as `.DS_Store`, `._*`, `.AppleDouble`, `.LSOverride`, and `Icon\r` from full and selected export ZIP archives.

## 3.1.3 - 2026-06-05

- Reset the `Books` auto-increment counter after new-catalog imports so newly added books continue from the remapped ID range.

## 3.1.2 - 2026-06-04

- Fixed placement sorting in the book list and CSV/bundle exports.
- Prevented stale `BookCopies.physical_location` values such as `#16/4` from restoring a cleared book placement during edit saves.
- Preserved explicit empty placement when saving books with copy rows.

## 3.1.1 - 2026-06-04

- Fixed CSRF handling in the Import books modal.
- Preserved CSV cover paths during import.
- Included `uploads/.htaccess` in full and selected bundle exports when covers are exported.
