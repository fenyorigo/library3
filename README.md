# BookCatalog v3

BookCatalog v3 is a PHP and Vue-based personal catalog application for managing **print books and ebooks in one shared catalog**.

## Local Development Note

On the macOS development machine, the active BookCatalog v3 project repository is:

```bash
~/Projects/library3
```

The older `~/Projects/library` path belongs to the retired v2 line and should not be used for current v3 work.

It is built on the v2 line, but extends the data model to support:

- mixed print and ebook records
- multiple copies and multiple ebook formats per title
- ebook file path tracking
- language-aware imports
- soft delete and restore workflows

## What It Is

BookCatalog is a **catalog system**, not an ebook storage or DRM system.

It stores metadata such as:

- title and subtitle
- authors
- publisher
- language
- print or ebook format
- file path for ebook copies
- notes, subjects, and placement data

The application does **not** ingest or manage ebook binaries themselves. Ebook files stay in your own filesystem; the catalog only keeps their metadata and path references.

## v3 Data Model

The main structural change in v3 is the split between **bibliographic records** and **copies**.

- One bibliographic record can represent a title once.
- That record can contain one or more copies.
- Copies may be print, epub, pdf, mobi, azw3, and other supported ebook formats.
- Covers remain attached to the bibliographic record, not to an individual copy.

### Master Item Rule

- If a print copy exists, the master item remains the print item.
- If all print copies are deleted, ebooks are not promoted to master automatically.
- Soft delete preserves record history and enables restore workflows.

## Import Compatibility

BookCatalog v3 keeps the existing CSV-based workflow and remains compatible with earlier catalog exports.

Supported import styles:

- v2-style CSV exports for print-only catalogs
- v3-style CSV files for mixed print and ebook records

Selected exports use the currently active search, filter, and sort order. When importing as a new catalog with reassigned IDs, that exported row order also determines the new ID sequence.

Migration note for v3.1.0 schema: existing databases need `ALTER TABLE Books_Authors ADD COLUMN author_alias varchar(255) DEFAULT NULL AFTER author_ord;` to store relation-level ebook pseudonyms from `{aka ...}` filename metadata.

During import, the system can infer language heuristically from title and subtitle metadata. The current heuristic is tuned mainly for:

- Hungarian
- English
- German
- French

Manual correction is still expected for ambiguous cases.

## NeoFinder Ebook Conversion

The repository includes a helper script for converting NeoFinder exports into BookCatalog v3 import CSV:

`00-basedata/scripts/convert_ebook_inventory.php`

### Usage

```bash
php 00-basedata/scripts/convert_ebook_inventory.php <input.tsv> [output.csv]
```

If `output.csv` is omitted the output file is written next to the input as `<input>.bookcatalog_v3.csv`.

A JSON summary (book count, copy count, warning count) is printed to stdout. Individual parse warnings go to stderr.

### Producing the NeoFinder export

In NeoFinder: **File → Export catalog** (or the equivalent Find Results export). Choose **Tab-separated** format and make sure the columns **Name**, **Path**, and **Size** are included. The `Kind` column is optional and ignored.

The script scans forward from the top of the file until it finds a header row containing all three required columns, so any metadata lines NeoFinder prefixes before the header are automatically skipped.

### Required TSV columns

| Column | Content |
|--------|---------|
| `Name` | Filename including extension (`Author - Title.epub`) |
| `Path` | Absolute filesystem path to the file |
| `Size` | File size in bytes (integer) |

### Filename conventions

Filenames must follow the pattern:

```
Author - Title.ext
Author - Title - Subtitle.ext
Author1; Author2 - Title {Series Name} [hu].ext
```

- The separator between author and title is ` - ` (space, hyphen/en-dash/em-dash, space).
- Multiple authors are separated by `;`.
- Supported formats: `epub`, `mobi`, `azw3`, `pdf`, `djvu`, `lit`, `prc`, `rtf`, `odt`.
- Entries with no file extension (folder rows) are skipped automatically.

**Language tags** — append `[hu]`, `[en]`, `[de]`, `[fr]` etc. to the title or subtitle:

```
Author - Title [hu].epub          → language: hu
Author - Title - Subtitle [en].epub → language: en (from subtitle)
```

If no language tag is present the script also checks the path for segments matching `0_HU`, `1_EN`, etc.:

```
/Volumes/Data/0_HU/Author - Title.epub → language: hu
```

**Metadata blocks** — curly-brace blocks anywhere in the title or subtitle carry extra metadata:

```
Author - Title {Series Name}.epub           → series: "Series Name"
Author - Title {Series Name} {aka Pen Name}.epub → series + alias
```

- `{aka Pen Name}` sets an author alias (`Books_Authors.author_alias`) on the first author.
- Any other `{...}` block is treated as a series name.

**Single-author display names** — wrap in `@...@` to prevent the name from being split into family/given parts:

```
@Dante@ - Title.epub
```

### Grouping logic

Multiple files with the same author(s) + title + subtitle + series are grouped into one book record with multiple copies. Exact duplicates (same format + same path) within a group are skipped with a warning.

### What the converter writes

- A v3-compatible CSV importable via **Import books → CSV only**.
- Language is set from filename tags or path segment; falls back to `unknown` (import-time title heuristics then apply).
- `copies_json` column carries all copy rows (format, file_path, file_size, sha256) for the record.
- `authors_metadata_json` carries structured author data including aliases.
- ID, Year, ISBN, Publisher, Subjects columns are left empty — fill in manually after import if needed.

## Security

v3.1.1 includes a full security hardening pass (backport from v2.7.0):

- HTTP security headers on every response (X-Frame-Options, CSP, X-Content-Type-Options, X-XSS-Protection)
- CSRF token system for all authenticated POST endpoints
- Login rate limiting (10 failed attempts per 15 minutes per IP)
- Image upload magic byte validation
- PHP execution blocked in `/uploads/` via `.htaccess`
- ZIP path traversal protection on import and backup
- Session regeneration on admin password reset
- Log injection filtering on auth event fields

## Status

Current state:

- core v3 catalog model is implemented
- ebook import pipeline is working
- admin-triggered initial SHA256 checksum build is available for known ebook copies
- ebook paths are stored as canonical NFC logical paths and resolved against macOS/APFS NFD filenames at file access time
- incremental ebook repository rescan uses SHA256 to detect new, moved, renamed, missing, and changed files without re-importing unchanged records
- full ebook integrity check verifies every known ebook copy from DB to filesystem and reports missing files, SHA gaps, content mismatches, and size drift
- soft delete and restore are implemented
- NeoFinder conversion tooling is included
- import-time language inference is implemented
- security hardening applied (v3.1.1)

Current application version: **3.5.10**
Current schema version: **3.3.0**

## Catalog Statistics CSV

Admins can export a **Catalog statistics CSV** from the export/admin tools. This is an audit/support export, not an import file. It writes rows in the form:

```csv
section,key,value,notes
summary,bibliographic_records_active,1234,
ebooks,total_files,8907,Active ebook file rows.
ebooks,sha256_missing,0,
```

The statistics export follows the currently active list filters, including search text, record status, language, and format (`Print`, `Ebooks`, or a specific ebook format). Use it to validate export/import completeness:

1. Export statistics before a full, print-only, or ebook-only export.
2. Export/import the selected records into a test instance.
3. Export statistics again with the same filters.
4. Compare the two CSV files.

The report includes overall active/deleted record counts, print and ebook copy counts, ebook format and language breakdowns, SHA256/path health, total ebook file size, and placeholders for integrity/orphan snapshots that are not persisted yet.

## Deployment PHP Limits

Large catalogs can produce large import/export archives. In the current production catalog, ebook-only exports can be around 0.7 GB and full print+ebook exports can exceed 1 GB. Treat PHP upload, POST, and memory limits as deployment configuration owned by the server administrator, not as fixed application logic.

Recommended baseline for large installations:

```ini
upload_max_filesize = 2048M
post_max_size = 2048M
memory_limit = 768M
```

If PHP-FPM/CGI `user_ini` support is enabled, `public/.user.ini` can override `/etc/php.ini`; check both when diagnosing large import failures. The admin Preferences dialog shows the effective PHP runtime values for `upload_max_filesize`, `post_max_size`, `memory_limit`, `max_execution_time`, and `max_input_time`.

## Unicode Path Handling

BookCatalog stores ebook copy paths as canonical NFC logical paths under `/Books/...`. On macOS/APFS, the filesystem may report filenames in decomposed/NFD Unicode form even when Finder displays the same visual name. For file access, BookCatalog first tries the exact path and then compares entries in the expected parent directory after canonicalizing both database and filesystem names.

PHP `intl` / `Normalizer` is required for reliable Unicode path handling. Do not force NFC filenames on disk from the application; keep the database canonical and let the resolver map to the filesystem-native path.

## Ebook Repository Availability

`ebook_library_root` is a local machine setting, not portable catalog data. After moving or importing a catalog on another host, configure the mount point for that host and use **Check ebook repository** before running ebook maintenance tools. The check verifies the configured mount point, the `Books` directory below it, readability, and an optional temporary write/read/delete test.

Repository-dependent admin tools are disabled until the current host can read `ebook_library_root + /Books`. This prevents false orphan, missing, or integrity reports when an external SSD is simply not mounted or is mounted at a different path. Normal catalog browsing, CSV import/export, statistics export, print tools, and DB-only tools remain available.

On macOS, APFS/HFS+ filename behavior is handled by BookCatalog's Unicode path resolver. For one external ebook repository shared between macOS and Linux, use exFAT or another filesystem both systems can read and write reliably. If the repository is not mounted on Linux, do not run ebook orphan maintenance, incremental rescan, SHA build, integrity check, or ebook cover extraction.

## Incremental Ebook Rescan

The admin rescan tool scans `ebook_library_root + /Books`, calculates SHA256 for discovered ebook files, and compares results with `BookCopies.sha256`. Matching SHA values identify the same physical file content: if the stored database path is missing but the same SHA is found elsewhere in the scanned repository, the file is reported as `same_sha_path_changed` and treated as a path update candidate, not a missing copy or a new import. If the same SHA is found at multiple scanned paths, it is reported as `same_sha_multiple_paths_on_disk` for admin review. Same-path files with different SHA are reported as content changes because EPUB metadata, cover, OPF/package changes, OCR fixes, or replacement files all change the physical checksum.

The rescan reports new file candidates, same-SHA path changes, same-SHA multiple-path cases, missing old records whose replacement copy already exists, filename metadata mismatches, same-path different-SHA cases, duplicate SHA values in the database, duplicate files on disk, missing files, and errors. A database copy is reported as missing only when its stored path is absent, its stored SHA256 is not found anywhere in the scanned repository, and no already-cataloged replacement can be matched. New file candidates can be exported as an `Import books` CSV with parsed metadata, `/Books/...` path, file size, and SHA256. Duplicate files on disk and duplicate SHA values in the database can both be exported as SHA-grouped CSV reports. If a known file's parsed filename authors/title/subtitle/series/language differ from the catalog record, the admin can export a candidate CSV before explicitly applying those bibliographic updates; applied updates also produce a CSV audit report. Applying path updates, replacement-record soft deletes, filename metadata updates, or same-path SHA/file-size updates requires explicit admin confirmation; the tool does not create new bibliographic records directly and never physically deletes ebook files.

## Ebook Integrity Tools

BookCatalog has three separate ebook maintenance actions:

- **Initial SHA build** fills missing `BookCopies.sha256` values for known ebook copies only.
- **Ebook orphan maintenance** is the catalog cleanup pass for stale ebook records. It starts from active database ebook copies, resolves their `/Books/...` paths, groups records by SHA256, and reports old active records whose file path no longer exists or whose copy row duplicates another active record for the same physical file. The admin can export a CSV report and explicitly soft-delete stale catalog records, including missing records without a known replacement; no ebook files are physically deleted. Running this before an incremental repository rescan keeps the rescan focused on real filesystem changes, and any real files still present in the repository can be offered again as new candidates by the incremental scan.
- **Incremental repository rescan** is the repository-to-database check: it answers "what changed in the filesystem?" It scans `ebook_library_root + /Books` and uses SHA256 to detect new files, moved/renamed files, duplicates, missing files, filename metadata mismatches, and same-path content changes without re-importing unchanged records. This is where repair decisions happen: exporting new file candidates as an import CSV, updating paths when the SHA is unchanged, applying parsed filename authors/title/subtitle/series/language updates after review, and explicitly accepting same-path SHA/file-size updates when file content changes are intentional.
- **Full ebook integrity check** is the database-to-repository validation: it answers "does what the catalog claims still exist on disk, and does it still match?" It starts from database copies and verifies that each known ebook still resolves on disk and matches its stored SHA256. It reports missing files, `sha256` gaps, SHA mismatches, OK-SHA file-size mismatches, and errors. After incremental repairs, a full integrity check should ideally finish with all copies OK.

Recommended workflow after manually changing the ebook repository on disk, especially after deleting files:

1. Run **Ebook orphan maintenance** first. Manual disk cleanup can leave active catalog records behind, and those stale database rows can confuse later repository scans. Soft-delete stale/missing ebook records here before asking the scanner what changed on disk.
2. Run **Incremental repository rescan** next. Handle any reported changes: export/import new file candidates, approve same-SHA path updates, review filename metadata candidates from CSV before applying them, and explicitly accept intentional same-path SHA/file-size changes.
3. Run **Full ebook integrity check** last. This validates the repaired catalog against the repository; after the first two steps, it should ideally finish with all known ebook copies present and matching their stored SHA256.

SHA256 identifies physical file content. EPUB cover, metadata, OPF/package, OCR/text fixes, corruption, or file replacement can all change the checksum even when bibliographic metadata should remain unchanged.
