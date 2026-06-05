# Changelog

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
