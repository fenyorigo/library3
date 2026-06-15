# BookCatalog v3

BookCatalog v3 is a PHP and Vue-based personal catalog application for managing **print books and ebooks in one shared catalog**.

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
- `copies_json` column carries all copy rows (format, file_path, file_size) for the record.
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
- soft delete and restore are implemented
- NeoFinder conversion tooling is included
- import-time language inference is implemented
- security hardening applied (v3.1.1)

Current application version: **3.3.0**  
Current schema version: **3.1.1**
