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

Usage:

```bash
php 00-basedata/scripts/convert_ebook_inventory.php <input.tsv> [output.csv]
```

Expected TSV header:

```text
Name    Path    Kind
```

What the converter does:

- parses filenames into author, title, and subtitle
- groups multiple ebook formats of the same work into one record
- skips duplicate copies
- writes a v3-compatible import CSV
- leaves language as `unknown`; imports still try title/subtitle-based detection, but only use the author-name Hungarian fallback when the source format omits the language field entirely

Notes:

- NeoFinder exports may contain legacy macOS `:` path separators.
- Path normalization is handled during import.
- Filename parsing supports hyphen, en dash, and em dash separators.

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

Current application version: **3.2.0**  
Current schema version: **3.1.0**
