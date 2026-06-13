# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

BookCatalog v3 is a personal book catalog web application for managing mixed print/ebook collections. It stores bibliographic metadata; ebook files are not ingested—only file paths are recorded. The current schema version is 3.1.0.
Repository: https://github.com/fenyorigo/library3

## Commands

All frontend commands run from `frontend/`:

```bash
npm run dev          # Vite dev server (proxies /bookcatalog to http://bookcatalogv2.local)
npm run build        # Type-check + Vite build → outputs to public/dist/
npm run type-check   # vue-tsc only
npm run test:unit    # Vitest unit tests
npm run test:e2e     # Playwright E2E (requires E2E_ADMIN_USER and E2E_ADMIN_PASS env vars)
npm run lint         # ESLint --fix
npm run format       # Prettier on src/
```

Running a single unit test file:
```bash
npm run test:unit -- src/__tests__/App.spec.ts
```

Running a single E2E spec:
```bash
npm run test:e2e -- e2e/user-management.spec.ts
```

PHP tests in `tests/` are standalone CLI scripts, not a test framework—run directly with `php tests/<file>.php`.

## Architecture

**Stack:** Vue 3 (Composition API) + TypeScript frontend, PHP + PDO + MySQL backend, Apache web server.

### Frontend (`frontend/src/`)

Single-page application served from `public/dist/index.html`. Key files:

- `api.js` — all backend communication; injects CSRF tokens on every mutating request; handles session expiry redirects
- `App.vue` — top-level layout, auth state, global modal orchestration, admin action menu
- `components/` — feature components (BookList, BookDialog for add/edit, CsvImportModal, AuthorsMaintenance, UserManagement, OrphanMaintenance, etc.)
- `composables/useAuth.js` — session/auth state composable
- Pinia store usage is minimal; most state is passed via props or managed in `App.vue`

Build output goes to `public/dist/` (configured in `vite.config.ts`).

### Backend (`public/`)

~40 individual PHP endpoint files (no framework, no router). Each file handles one operation. Shared logic lives in:

- `functions.php` (~69 KB) — DB factory (`getDB()`), session management, CSRF token generation/validation, rate limiting, error handling helpers, image magic-byte validation

Endpoint naming is direct: `list_books.php`, `add_book.php`, `update_book.php`, `delete_book.php`, `import_csv.php`, `export_*.php`, `backup_full.php`, etc.

All endpoints validate:
1. Active session
2. CSRF token (on mutations)
3. User role where required

### Data Model

**Books** — bibliographic record (title, ISBN, publisher, year, language, placement, loan status, soft-delete via `record_status`)

**BookCopies** (new in v3) — one or more copies per book, tracking `format` (print/epub/mobi/azw3/pdf/djvu/etc.), `quantity`, physical location, and ebook `file_path`

**Authors** linked via `Books_Authors` junction; the junction row carries `author_alias` for pseudonyms (used when importing ebook filenames with `{aka ...}` metadata)

### Configuration

Copy `config.sample.php` to `config.php` for DB credentials. This file is gitignored. For first-time installation use `install.php` (CLI script; see `install.params.example`).

Key config files:
- `vite.config.ts` — build output path, dev proxy target
- `vitest.config.ts` — jsdom environment, excludes e2e/
- `playwright.config.ts` — headless on CI, starts dev server for tests
- `public/.htaccess` — blocks PHP execution inside uploads/
- `public/.user.ini` — PHP upload/memory limits

### Database Migrations

Schema baseline: `00-basedata/sql/schema.sql` (v3.1.0). Incremental migrations are in `00-basedata/sql/migrations/`. Apply them manually via MySQL CLI.

### CSV Import

The app supports two CSV formats: v2 (legacy) and v3. The NeoFinder → v3 converter is at `00-basedata/scripts/convert_ebook_inventory.php`.

Full CSV format and ebook filename conventions: `docs/csv-format.md`

### Security

Mutations require CSRF tokens (set in session, sent as `X-CSRF-Token` header by `api.js`). File uploads validate magic bytes server-side. Cover images are stored in `public/uploads/` which has PHP execution blocked by `.htaccess`. ZIP imports are protected against path traversal.

## Conventions (authoritative — follow these, not existing inconsistencies)

### Naming
- PHP endpoint files: snake_case (`list_books.php`, `add_book.php`).
  Never create camelCase endpoint files.
- Vue components: PascalCase filenames, Composition API with `<script setup>`.

### API responses
- Every endpoint uses the standard envelope via the `json_out()` /
  `json_fail()` helpers in functions.php:
  - Success: `{ "ok": true, "data": { ... } }`
  - Failure: `{ "ok": false, "error": "message" }`
- Always respond through these helpers; never echo raw JSON.

### Versioning
- App version lives in frontend/package.json (with package-lock.json).
- DB schema version (currently 3.1.0) is independent — bump only when
  the schema actually changes, via a new migration file.
- Do not use `die_with_error()` — it is dead code, scheduled for removal.
- Extra top-level fields beside `data` (e.g. `message`, `mode`) are tolerated
  legacy; new endpoints put everything inside `data`.

### Build requirement
- After any frontend (Vue/TS/JS/CSS) change, run `npm run build` from
  `frontend/` before committing. The `public/dist/` output is checked in
  and served directly — skipping the build means the deployed app does
  not reflect the change.
- PHP-only changes do not require a build.

### Changelog
- Update `CHANGELOG.md` (project root) before every release commit.
- Add a new `## X.Y.Z - YYYY-MM-DD` section at the top describing what
  changed. Write it before committing — not retroactively.

### General
- No new framework, router, or dependency without asking first.
- New shared logic goes into functions.php only if reused by multiple
  endpoints; otherwise keep it local to the endpoint file.