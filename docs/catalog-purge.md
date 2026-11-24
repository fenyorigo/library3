# Purge and restore (3.9.1)

**Purge catalog is permanent deletion, distinct from reversible book deletion.**
Create a verified backup first. The admin-only action requires an authenticated
session, CSRF protection and the existing two confirmations, including `DELETE`.

## What is cleared

- All active and archived books, copies, author/subject links, duplicate decisions,
  authors, publishers, subjects and placements.
- Condition-photo records; acquisition documents and all shared-copy links.
- Valuation imports, complete assessment history, price observations and source links.
- Portable-package import audit records.
- Files below this installation's `public/uploads/`, including orphaned covers.
- Files below the configured private attachment root, including orphaned photos/PDFs.

Shared invoices/sources are deleted once, after their links. Foreign-key checks
remain enabled. Older schemas without migrations 05–07 are supported too.

## What is retained

Users/password hashes, preferences and profile logos (`public/user-assets/`),
Settings, SystemInfo/schema version and AuthEvents authentication history remain.
The endpoint adds an audit event for the purge itself. Default placeholder covers
(`default-cover.jpg`, `default_cover.jpg`) and root `.htaccess`/`.gitignore` files
are retained. Ebook repository binaries and backup ZIP/SQL files outside the two
media roots are untouched. Symlinks inside media roots are removed as links,
never traversed. A symlink media root, a private directory inside `public/`, or
an attachment directory overlapping the ebook repository is rejected.

ID counters are reset when privileges allow. A user without ALTER can still
purge and restore completely; Keep IDs import preserves source IDs independently
of the next-ID counter.

## Failure behavior

Files are first moved into a same-filesystem `.catalog-purge-<random>` directory
inside each media root. All DELETE statements run in one database transaction.
If preparation or a DELETE fails, database changes roll back and staged files
are moved back to their original names. No automatic retry disables foreign keys.

After a successful database commit, the staged files are removed permanently.
If filesystem permissions prevent cleanup, the result explicitly lists a warning
and the recovery directory. The catalog is already empty; remaining staged files
have not been reported as deleted. Correct permissions/SELinux, then remove the
reported committed staging directory. Inspect the `.purge-state*.json` recovery journal first:
`database_committed` identifies post-commit cleanup, while `prepared` may need
file recovery after an interrupted process. Unexpected power loss cannot make a
filesystem move and a SQL commit one atomic operation; retain the backup until
restoration is verified. An unfinished staging directory blocks another purge
until inspected, rather than silently destroying potential recovery files.

Do not use the catalog during purge/restore. For migration, stop other uploads,
imports and maintenance jobs first. Only media in the destination installation
is cleared; never run purge against the iMac master when preparing Fedora.

## Fedora migration and regular refresh

Starting with app 3.6.0/schema 3.3.0 on Fedora:

1. Back up the destination. Purge its old catalog with the existing application.
2. Apply migrations **05 → 06 → 07**, without `--force`; stop on any SQL error.
3. Deploy/build app **3.9.1**, including the tracked `public/uploads/.htaccess`
   security file (older purge versions may have removed it). No additional migration
   beyond schema **3.6.0** is needed.
4. Check Apache/PHP permissions, SELinux and upload-size limits. Create a dedicated
   private attachment directory outside `public/`.
5. Rsync ebook repository files from the iMac.
6. Import the iMac **Full backup ZIP**, choosing **Keep IDs**, first Dry run, then Import.

For subsequent refreshes on 3.9.1/schema 3.6.0: destination Purge catalog → ebook
rsync → Full backup ZIP import. Users and settings already on Fedora stay in place.
Covers, condition photos and acquisition PDFs are restored from the ZIP; their
separate rsync is unnecessary. Code/schema updates are needed only on version changes.

## Developer validation

On a disposable database whose name begins `library3_schema_test`, run
`LIBRARY3_SCHEMA_TEST_DSN='mysql:unix_socket=...;dbname=library3_schema_test_purge' php tests/catalog_purge.php`.
The test account needs disposable database and CREATE USER/GRANT permissions;
optional `LIBRARY3_SCHEMA_TEST_USER` and `LIBRARY3_SCHEMA_TEST_PASSWORD` select it.
Test media is isolated in a new temporary directory, never the live uploads.
Coverage includes shared/archived evidence, rollback, post-commit cleanup errors,
low-privilege application access, protected files/settings and legacy-schema upgrade.
