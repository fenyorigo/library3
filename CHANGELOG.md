# Changelog

## 3.4.0 - 2026-06-16

- Added global `Settings.ebook_library_root` admin setting for the mounted ebook SSD root, for example `/Volumes/SanDisk 2T`.
- Changed ebook copy path storage: `BookCopies.file_path` now stores stable `/Books/...` POSIX paths instead of per-machine `/Volumes/...` absolute paths.
- CSV import accepts absolute NeoFinder paths under the configured mount point and stores them as `/Books/...`; paths outside the configured root are warned/rejected instead of being stored silently.
- Ebook cover extraction now resolves physical files from `ebook_library_root + BookCopies.file_path`.
- Added `BookCopies.sha256` with `idx_bookcopies_sha256`; CSV import/export now round-trips optional copy-level SHA256 checksums.
- Added admin batch action to build missing SHA256 checksums for existing ebook copies, with progress counters and per-copy problem report.
- Added Unicode-canonical ebook path handling: BookCatalog stores NFC logical paths and resolves macOS/APFS NFD filenames by canonical parent-directory matching; PHP intl/Normalizer is required.
- Added admin incremental ebook repository rescan using SHA256 to report unchanged, same-SHA path changes, duplicate files on disk, new file candidates, same-path content changes, duplicate SHA values, missing files, and errors; path/SHA updates require explicit confirmation, new file candidates can be exported as an Import books CSV, and duplicate-on-disk files can be exported as a SHA-grouped CSV report.
- Added explicit filename metadata repair in incremental rescan for known ebook files whose parsed filename title/subtitle/series/language no longer matches the catalog record.
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
