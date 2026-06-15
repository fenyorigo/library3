# CSV Export/Import Format Reference

BookCatalog v3 supports two CSV formats on import (auto-detected) and produces one canonical
export format (v3). This document describes both, the ebook filename parsing rules used by
the NeoFinder converter, and round-trip fidelity.

---

## Format Auto-Detection

`import_csv.php` reads the first line of the uploaded file and scores it against two header
sets using a normalized key comparison (lowercase, spaces→underscore, non-alphanumeric
stripped):

| Format   | Required score | Keys used for scoring                                                                                   |
|----------|---------------:|---------------------------------------------------------------------------------------------------------|
| v3 export | ≥ 6 matches   | id, title, subtitle, series, language, copy_count, year, isbn, lccn, notes, publisher, authors, subjects, record_status, loaned_to, loaned_date, bookcase, shelf, cover_image, cover_thumb, cover_filename, copies_json, authors_json, authors_metadata_json |
| v2 legacy | ≥ 2 matches   | title, subtitle, year_published, authors                                                                |

Delimiter is chosen by whichever delimiter (`,` or `;`) produces the winning score. If no
header is matched at all, the file is treated as headerless v2 with `;` delimiter (fallback,
`fseek(0)` rewind).

---

## v2 — Legacy Format

The v2 format originates from the BookCatalog v2 application.

**Delimiter:** `;` (semicolon)

**Header:** Optional. If present, columns must be named `title`, `subtitle`, `year_published`,
`authors`. If absent, columns are read positionally.

### Columns (positional order / header names)

| # | Header name      | Notes                              |
|---|------------------|------------------------------------|
| 0 | `title`          | Required. Row is skipped if empty. |
| 1 | `subtitle`       |                                    |
| 2 | `year_published` | Numeric; non-numeric ignored.      |
| 3 | `authors`        | Semicolon-separated display names. |

### Defaults applied on import

| Field          | Default          |
|----------------|------------------|
| `series`       | `null`           |
| `language`     | inferred (see §Language Inference) |
| `record_status`| `active`         |
| `publisher`    | `null`           |
| `isbn`, `lccn` | `null`           |
| `notes`        | `null`           |
| `subjects`     | none             |
| Copies         | Single print copy, quantity 1 |

v2 imports never carry `book_id`, so they always get a new auto-increment ID.

---

## v3 — Export/Import Format

This is the canonical format produced by `export_books_csv.php` and by the NeoFinder
converter (`convert_ebook_inventory.php`).

**Delimiter:** `,` (comma)  
**Quoting:** `"` (double-quote), escape char `\`  
**Encoding:** UTF-8 (BOM stripped on import)

### Column reference

All 22 columns are present in the export header. Import recognizes them by normalized name,
so column order is flexible on import.

| # | Export header         | Normalized key          | Notes |
|---|-----------------------|-------------------------|-------|
| 1 | `ID`                  | `id`                    | Integer book_id. Empty or 0 → auto-assign. |
| 2 | `Title`               | `title`                 | Required. |
| 3 | `Subtitle`            | `subtitle`              | |
| 4 | `Series`              | `series`                | |
| 5 | `Language`            | `language`              | Normalized on export/import; see §Language. |
| 6 | `Copy Count`          | `copy_count`            | Derived from Copies JSON on export; used as fallback on import when Copies JSON absent. |
| 7 | `Year`                | `year`                  | Integer publication year. Import also accepts `year_published`. |
| 8 | `ISBN`                | `isbn`                  | |
| 9 | `LCCN`                | `lccn`                  | |
| 10 | `Notes`              | `notes`                 | |
| 11 | `Publisher`           | `publisher`             | Free-text name; resolved to publisher_id via lookup-or-create on import. |
| 12 | `Authors`             | `authors`               | Semicolon-separated display names, ordered by `author_ord`. |
| 13 | `Authors Metadata JSON` | `authors_metadata_json` | JSON array; see §Authors Metadata JSON. Import also accepts key `authors_json`. |
| 14 | `Subjects`            | `subjects`              | Semicolon-separated subject names. |
| 15 | `Loaned To`           | `loaned_to`             | Free-text borrower name, or empty. |
| 16 | `Loaned Date`         | `loaned_date`           | `YYYY-MM-DD` or empty. Required to be a valid date if `loaned_to` is set. |
| 17 | `Record Status`       | `record_status`         | `active` or `deleted`. |
| 18 | `Bookcase`            | `bookcase`              | Integer bookcase number. |
| 19 | `Shelf`               | `shelf`                 | Integer shelf number. Both bookcase and shelf must be > 0 to create a Placement record. |
| 20 | `Cover Image`         | `cover_image`           | Relative path, e.g. `uploads/42/cover.jpg`. |
| 21 | `Cover Filename`      | `cover_filename`        | Basename of the cover file (e.g. `cover.jpg`). Used on import to derive cover_thumb when cover_thumb column is absent. |
| 22 | `Copies JSON`         | `copies_json`           | JSON array of copy objects; see §Copies JSON. |

### Copies JSON

Each element of the `copies_json` array is a copy object:

```json
{
  "format": "epub",
  "quantity": 1,
  "physical_location": null,
  "file_path": "/Volumes/Books/Author - Title.epub",
  "file_size": 73335,
  "notes": null
}
```

| Field               | Type            | Notes |
|---------------------|-----------------|-------|
| `format`            | string          | `print`, `epub`, `mobi`, `azw3`, `pdf`, `djvu`, `lit`, `prc`, `rtf`, `odt` |
| `quantity`          | integer         | Number of physical copies (typically 1). |
| `physical_location` | string \| null  | Free-text shelf location. |
| `file_path`         | string \| null  | Absolute path to the ebook file; null for print. |
| `file_size`         | integer         | File size in bytes. 0 for print copies or when unknown. Displayed in MB in the UI. |
| `notes`             | string \| null  | Per-copy notes. |

If `copies_json` is empty or absent on import, the importer falls back to creating a single
print copy using `copy_count` and the `bookcase`/`shelf` placement.

### Authors Metadata JSON

A JSON array with one element per author, in `author_ord` order:

```json
[
  {
    "name": "Terry Pratchett",
    "is_hungarian": false,
    "author_alias": "T. Pratchett"
  }
]
```

| Field          | Type              | Notes |
|----------------|-------------------|-------|
| `name`         | string            | Display name as stored in the Authors table. |
| `is_hungarian` | boolean           | `true` → name order is "Last First" (Hungarian convention). |
| `author_alias` | string \| absent  | Pseudonym used in this book's junction record (`Books_Authors.author_alias`). Omitted when null. |

On import, `authors_metadata_json` is merged with the `authors` column: names are matched
by position first, then by case-insensitive lookup. Unmatched names fall back to
`is_hungarian: null`.

---

## Export Filename Convention

`export_books_csv.php` generates a timestamped filename:

```
export_<N>_books_<YYYYMMDD_HHmmss>_<os>_<db>_v<appver>_schema<schemaver>.csv
```

Example: `export_312_books_20260612_143022_macos_mysql_v3.2.3_schema3.1.0.csv`

Non-alphanumeric characters (except `.`, `_`, `-`) are replaced with `_`.

---

## NeoFinder Ebook Filename Convention

`convert_ebook_inventory.php` converts a NeoFinder TSV export (columns: `Name`, `Path`,
`Size`) to v3 CSV. The `Name` column contains the ebook filename, which encodes
bibliographic metadata in a structured format. The `Size` column (bytes) is stored as
`file_size` on each copy. Rows without a file extension (NeoFinder folder summary rows,
e.g. `0_HU`, `1_EN`, `2_DE`, `3_FR`) are silently skipped.

### Filename structure

```
<Authors> - <Title> [- <Subtitle>] [{metadata-block} …].<ext>
```

- **Author–title split:** a dash surrounded by spaces (`\s[-–—]\s`; supports en-dash and
  em-dash). The part before the first such dash is the author segment; the part after is the
  title; any further dash-separated parts become the subtitle.
- **Extension:** determines the ebook format (epub, mobi, azw3, pdf, djvu, lit, prc, rtf,
  odt). The `Kind` field is checked first; the file extension is a fallback.

### Author segment

Multiple authors are separated by `;`. The author segment (and later any stored author
name string) is parsed according to the rules below. The same rules apply whenever an
author name free-text is resolved to an `Authors` DB row.

#### Author name parsing rules (priority order)

| Priority | Syntax | Meaning | Example |
|----------|--------|---------|---------|
| 1 | `@name@` | Collective / organizational name — treated as a single indivisible unit stored entirely in `last_name`. No `first_name`. | `@Szerkesztők@` → last: `Szerkesztők` |
| 2 | `_NoAuthor` | Conventional placeholder for anonymous/authorless works. Imported as a literal author named `_NoAuthor` — intentional, so these records can be found by searching for that value. | `_NoAuthor - Title.epub` |
| 3 | `Last\|First` | Explicit pipe separator for Hungarian names where automatic detection would be ambiguous (typically 3-word names). Pipe takes priority over all other splitting. | `B Szabó\|János` → last: `B Szabó`, first: `János` |
| 4 | `Last, First` | Western "Last, First" form. Presence of a comma → `is_hungarian = false`. | `King, Stephen` → last: `King`, first: `Stephen` |
| 5 | `First … Last` | No comma, no pipe → Hungarian name order assumed (`is_hungarian = true`). Last token is `last_name`, remainder is `first_name`. | `Szabó Magda` → last: `Szabó`, first: `Magda` |

**Hungarian 3-word heuristic** (applies when rule 5 and 3+ tokens):

When `is_hungarian = true` and there is no pipe, the parser checks for a short prefix/infix
token (≤ 2 UTF-8 characters) to locate the split point:

- If `parts[0]` is short → `last_name = parts[0] + parts[1]`, `first_name = rest`
  (e.g. `B Szabó János` → last: `B Szabó`, first: `János`)
- Else if `parts[-2]` is short → `last_name = all but last token`, `first_name = last token`
- Else → `last_name = parts[0]`, `first_name = rest`

Use the explicit `|` separator for any 3-word Hungarian name where this heuristic would
pick the wrong split.

```
Terry Pratchett; Neil Gaiman - Good Omens.epub
→ authors: ["Terry Pratchett", "Neil Gaiman"]  (is_hungarian: true each, no comma)

B Szabó|János - Valami cím.epub
→ author: last="B Szabó", first="János"  (pipe overrides heuristic)
```

### Metadata blocks (`{...}`)

Curly-brace blocks in the **title** or **subtitle** segment carry extra metadata:

| Block syntax         | Effect |
|----------------------|--------|
| `{aka <name>}`       | Author alias (pseudonym). Assigned to the first author's `author_alias` field. |
| `{<anything else>}`  | Series name. Multiple series blocks are joined with `; `. |

Multiple `{aka ...}` blocks are allowed; they are joined with `; ` into a single alias
string.

**Example:**

```
Stephen King - The Dark Tower {The Dark Tower} {aka Richard Bachman}.epub
```

Parsed as:
- author: `Stephen King`, alias: `Richard Bachman`
- title: `The Dark Tower`
- series: `The Dark Tower`

**Example with multiple authors:**

```
Terry Pratchett; Paul Kidby - The Art of Discworld {aka Terry Pratchett & Paul Kidby}.epub
```

Parsed as:
- authors: `Terry Pratchett; Paul Kidby`
- first author alias: `Terry Pratchett & Paul Kidby`
- title: `The Art of Discworld`

---

## Language Inference

When `language` is `unknown` (or absent in v2), the importer calls
`infer_import_language_from_metadata()` with the title, subtitle, and authors string.
The same inference runs for v3 imports if the language field is explicitly `unknown`.

Languages inferred by heuristics (character set analysis, Hungarian name patterns, etc.).
The result is stored as a language code string (e.g. `hu`, `en`, `unknown`).

---

## Round-Trip Fidelity (v3 export → v3 import)

| Data                        | Round-trips?   | Notes |
|-----------------------------|---------------|-------|
| `title`, `subtitle`, `series` | ✓ exact      | |
| `year`, `isbn`, `lccn`, `notes` | ✓ exact   | |
| `publisher`                 | ✓ by name     | Re-resolved via lookup-or-create; name is preserved. |
| `authors` (names + order)   | ✓ exact       | |
| `author_alias`              | ✓ exact       | Carried in `authors_metadata_json`. |
| `is_hungarian` flag         | ✓ on the junction | Written to `Books_Authors`; the `Authors` table row is not updated if the author already exists. |
| `subjects`                  | ✓ by name     | Re-resolved via lookup-or-create. |
| `loaned_to`, `loaned_date`  | ✓ exact       | |
| `record_status`             | ✓ exact       | A soft-deleted record can be restored by import if the copies are still valid. |
| `placement` (bookcase/shelf) | ✓ by value   | Re-resolved via lookup-or-create. |
| Copies (format, quantity, path, location, notes) | ✓ exact | Via `copies_json`. |
| `book_id`                   | ✓ in `keep_ids` mode | In `new_catalog` mode, IDs are reassigned sequentially. |
| `cover_image`               | ✓ in `keep_ids` mode | In `new_catalog` mode, the path is remapped to the new ID (`uploads/<new_id>/…`). Cover files must be present in the ZIP bundle; without them the DB path is updated but the file is absent. |
| `cover_thumb`               | ⚠ derived     | Not exported as its own column. On import it is derived as `<cover_dir>/cover-thumb.<ext>`. This matches the convention used by the app, but any non-standard thumb path in the source DB is not preserved. |

### ZIP bundle import

When the upload is a `.zip` file, the importer looks for the CSV at `data/books.csv` or
`books.csv` (falls back to the first `.csv` found). Cover images are expected at
`uploads/<book_id>/cover.<ext>` and `uploads/<book_id>/cover-thumb.<ext>` inside the ZIP.
Path traversal is blocked (entries containing `..`, absolute paths, or drive letters are
rejected). Covers are only copied when the `with_covers` checkbox is enabled.
