# NeoFinder ebook inventory conversion

These helper scripts convert a NeoFinder ebook export into a BookCatalog v3-compatible CSV import file and optionally normalize ebook file paths after import.

## Scripts

### `convert_ebook_inventory.php`

Converts a NeoFinder tab-delimited export into a BookCatalog v3 CSV file.

Expected NeoFinder input format:

```text
Name<TAB>Path<TAB>Kind

The header must be exactly:
Name    Path    Kind

The script expects ebook filenames in this general form:
Author - Title.ext
Author - Title - Subtitle.ext

Multiple authors may be separated by semicolon:
Author One; Author Two - Book Title.epub

Supported ebook formats:
epub, mobi, azw3, pdf, djvu, lit, prc, rtf, odt

The script groups identical bibliographic records by:
authors + title + subtitle
and writes multiple ebook copies/formats into the Copies JSON column.

Usage
From the BookCatalog project root, run:

php 00-basedata/scripts/convert_ebook_inventory.php /path/to/neofinder_export.tsv

If no output file is specified, the script creates:
/path/to/neofinder_export.bookcatalog_v3.csv

You can also specify the output path explicitly:

php 00-basedate/scripts/convert_ebook_inventory.php /path/to/neofinder_export.tsv /path/to/ebooks_import.csv

The script prints a JSON summary to STDOUT, for example:

{
  "input": "/path/to/neofinder_export.tsv",
  "output": "/path/to/ebooks_import.csv",
  "source_rows": 1000,
  "grouped_books": 850,
  "copy_rows": 980,
  "warnings": 12
}

Warnings are printed to STDERR.
Typical warnings include:
unsupported file format
missing Name, Path, or Kind
filename could not be split into author/title
duplicate ebook copy skipped
Import into BookCatalog v3
After conversion, import the generated CSV through the BookCatalog v3 CSV import workflow.
Recommended order:

php 00-basedata/scripts/convert_ebook_inventory.php neofinder_export.tsv ebooks_import.csv

Then import:

ebooks_import.csv

through the BookCatalog v3 admin import UI.