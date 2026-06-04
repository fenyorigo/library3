# Changelog

## 3.1.2 - 2026-06-04

- Fixed placement sorting in the book list and CSV/bundle exports.
- Prevented stale `BookCopies.physical_location` values such as `#16/4` from restoring a cleared book placement during edit saves.
- Preserved explicit empty placement when saving books with copy rows.

## 3.1.1 - 2026-06-04

- Fixed CSRF handling in the Import books modal.
- Preserved CSV cover paths during import.
- Included `uploads/.htaccess` in full and selected bundle exports when covers are exported.
