# Portable catalog archives (3.9.0)

## One file for transfer

Use **Export selected books (CSV + images/PDFs)** for the current selection, or
**Full backup** for every active and deleted book. Both produce one ZIP containing:

- `data/books.csv`, readable independently;
- `library3-package.json`: original IDs, bibliography and author/subject links, all copies, deleted flags, document links and full valuation/source history;
- `uploads/`: referenced covers and thumbnails, including your own photographs;
- `copy-attachments/`: condition images and invoice/receipt PDF scans;
- `portable-sha256sums.txt`: manifest and file checksums.

A selected package includes shared documents/sources once, with only the selected
ownership links. A complete backup preserves all such links. Full backup also
retains its existing CSV/JSON support files. The portable manifest is authoritative
for restoration. Files required by the manifest must all exist; missing files fail
export rather than producing an incomplete package.

## Restore on the destination

Install application 3.9.0/schema 3.6.0, configure a writable private attachment
folder outside `public/`, then open **Import books** and select the complete ZIP.
Use **Keep IDs** and run **Dry run** before importing. The portable importer always
restores covers and attachment files, regardless of the legacy CSV cover checkbox.
Prefer an empty destination catalog. Existing rows/files are reused only when
identical; differing IDs, uniqueness constraints or file contents stop the import.
Repeated import of an unchanged package does not duplicate metadata. Database
changes roll back and newly published files are removed if restoration fails.
Preview validates within a rolled-back transaction; auto-increment sequences may
advance, but no catalog rows or files are retained.

Ebook binaries, users/passwords, application settings and the server configuration
are not part of the portable package. Move the ebook repository separately. A full
SQL dump plus both file directories remains an alternative server-level migration.
Do not extract private scans into a web-accessible folder.

## Deletion and preservation

Ordinary deletion is reversible archiving. The printed copy, condition photos,
acquisition links, assessments and source links receive `deleted` status. Their
files are retained. A shared document or price observation becomes deleted only
when it has no active owner. Original invoice location notes remain unchanged.
Whole-book restoration reactivates only copies archived with that book. Previously
removed physical copies remain archived; administrators can restore them in the
book dialog under **Archivált nyomtatott példányok**. Archive information remains
admin-only. An active ebook-only record hides physical valuation/documentation;
its archived physical copies remain recoverable by the administrator.

Migration `07_v3_add_evidence_lifecycle.sql` is repeatable and initializes flags
for already-deleted books; it never removes original image/PDF files.

## Large archives and existing cover defects

The full iMac archive currently exceeds 1 GB. For browser restoration set PHP
`upload_max_filesize=2048M`, `post_max_size=2100M`, `memory_limit=512M` or higher,
and allow the request body in the web server (for Apache, `LimitRequestBody 0`
inside this application's virtual host). These limits are server configuration,
not database settings; include them when configuring Fedora. Background jobs
inherit the invoking PHP memory limit and configured private attachment directory.
A package is limited to 4 GB uncompressed and 64 MB per media file.

Two pre-existing cover files (`uploads/6077/cover.png` and
`uploads/10617/cover.png`) contain unrecognized binary data. Their exact bytes are
preserved; restoration reports a warning instead of silently losing them.
Private condition photos and invoice PDFs still require recognized media content.


### Catalog replacement with 3.9.1

Purge catalog now clears all catalog/evidence rows and both media directories,
retaining users, settings, preferences and ebook files. Use **3.9.1** for this
purge/restore workflow; the schema stays **3.6.0**. See [catalog-purge.md](catalog-purge.md)
for the first Fedora upgrade from schema 3.3.0 and subsequent refreshes.
