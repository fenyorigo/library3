<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

// Never leak warnings/notices into JSON
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('memory_limit', '512M');
// Long ZIP restore can exceed proxy/FPM defaults; keep script-side timeout unlimited.
set_time_limit(0);
ignore_user_abort(true);

function import_size_to_bytes(string $value): int {
    $v = trim($value);
    if ($v === '') return 0;
    $unit = strtolower(substr($v, -1));
    $num = (float)$v;
    switch ($unit) {
        case 'g': return (int)($num * 1024 * 1024 * 1024);
        case 'm': return (int)($num * 1024 * 1024);
        case 'k': return (int)($num * 1024);
        default: return (int)$num;
    }
}

function import_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            import_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function import_parse_bool(array $source, string $key, bool $default = false): bool {
    if (!array_key_exists($key, $source)) return $default;
    $v = $source[$key];
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string)$v));
    return !($s === '' || $s === '0' || $s === 'false' || $s === 'no' || $s === 'off');
}

function import_resolve_upload(array $file): array {
    $name = (string)($file['name'] ?? '');
    $tmp_path = (string)($file['tmp_name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($ext === 'zip') {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Zip import requires ZipArchive extension');
        }

        $tmp_root = rtrim(sys_get_temp_dir(), '/\\') . '/bookcatalog_import_' . bin2hex(random_bytes(6));
        if (!@mkdir($tmp_root, 0775, true) && !is_dir($tmp_root)) {
            throw new RuntimeException('Unable to prepare temporary directory for ZIP import');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp_path) !== true) {
            import_rrmdir($tmp_root);
            throw new RuntimeException('Unable to open ZIP file');
        }

        if (!$zip->extractTo($tmp_root)) {
            $zip->close();
            import_rrmdir($tmp_root);
            throw new RuntimeException('Unable to extract ZIP file');
        }
        $zip->close();

        $candidates = [
            $tmp_root . '/data/books.csv',
            $tmp_root . '/books.csv',
        ];
        $csv_path = null;
        foreach ($candidates as $cand) {
            if (is_file($cand) && is_readable($cand)) {
                $csv_path = $cand;
                break;
            }
        }

        if ($csv_path === null) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp_root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $fi) {
                if (!$fi->isFile()) continue;
                if (strtolower($fi->getExtension()) === 'csv') {
                    $csv_path = $fi->getPathname();
                    break;
                }
            }
        }

        if ($csv_path === null) {
            import_rrmdir($tmp_root);
            throw new RuntimeException('ZIP does not contain a readable CSV (expected data/books.csv)');
        }

        return [
            'kind' => 'zip',
            'csv_path' => $csv_path,
            'extract_root' => $tmp_root,
        ];
    }

    return [
        'kind' => 'csv',
        'csv_path' => $tmp_path,
        'extract_root' => null,
    ];
}

function import_find_cover_source(string $extract_root, int $old_id, ?string $preferred_rel): ?string {
    $roots = [];

    $book_dir = $extract_root . '/uploads/' . $old_id;
    $fallbacks = [
        $book_dir . '/cover.jpg',
        $book_dir . '/cover.jpeg',
        $book_dir . '/cover.png',
        $book_dir . '/cover.webp',
        $book_dir . '/cover.gif',
    ];
    foreach ($fallbacks as $f) $roots[] = $f;

    if ($preferred_rel !== null && $preferred_rel !== '') {
        $preferred_rel = ltrim(str_replace('\\', '/', $preferred_rel), '/');
        if (strpos($preferred_rel, 'uploads/') === 0) {
            $roots[] = $extract_root . '/' . $preferred_rel;
        }
    }

    foreach ($roots as $path) {
        if (is_file($path) && is_readable($path)) return $path;
    }
    return null;
}

function import_find_thumb_source(string $extract_root, int $old_id, ?string $preferred_rel): ?string {
    $roots = [];

    $book_dir = $extract_root . '/uploads/' . $old_id;
    $fallbacks = [
        $book_dir . '/cover-thumb.jpg',
        $book_dir . '/cover-thumb.jpeg',
        $book_dir . '/cover-thumb.png',
        $book_dir . '/cover-thumb.webp',
        $book_dir . '/cover-thumb.gif',
    ];
    foreach ($fallbacks as $f) $roots[] = $f;

    if ($preferred_rel !== null && $preferred_rel !== '') {
        $preferred_rel = ltrim(str_replace('\\', '/', $preferred_rel), '/');
        if (strpos($preferred_rel, 'uploads/') === 0) {
            $roots[] = $extract_root . '/' . $preferred_rel;
        }
    }

    foreach ($roots as $path) {
        if (is_file($path) && is_readable($path)) return $path;
    }
    return null;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <meta charset="utf-8">
    <title>Import books</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; margin: 2rem; }
        form { display: grid; gap: .75rem; max-width: 680px; }
        label { font-weight: 600; }
    </style>
    <h1>Import books</h1>
    <p>Supported formats:<br>
        <code>books_export.csv</code>, or ZIP bundle from <code>Export selected books (CSV + covers)</code>.</p>
    <form method="post" enctype="multipart/form-data">
        <label>Import file
            <input type="file" name="file" accept=".csv,.zip,text/csv,text/plain,application/zip" required>
        </label>
        <label><input type="checkbox" name="with_covers" value="1"> Import covers too (ZIP only, overwrite target cover files)</label>
        <label>ID handling
            <select name="id_mode">
                <option value="keep_ids">Use IDs from import file</option>
                <option value="new_catalog">Use the next free ID (ignore imported IDs)</option>
            </select>
        </label>
        <label><input type="checkbox" name="dry_run" value="1" checked> Dry run (validate only; don’t insert)</label>
        <button type="submit">Upload &amp; Import</button>
    </form>
    <?php
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    if ($method !== 'POST') {
        json_fail('Method Not Allowed', 405);
    }

    $content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $post_max_raw = (string)ini_get('post_max_size');
    $upload_max_raw = (string)ini_get('upload_max_filesize');
    $post_max_bytes = import_size_to_bytes($post_max_raw);
    if ($post_max_bytes > 0 && $content_length > $post_max_bytes) {
        json_fail(
            'Uploaded request is larger than PHP post_max_size. '
            . 'CONTENT_LENGTH=' . $content_length . ' bytes, '
            . 'post_max_size=' . $post_max_raw . ', '
            . 'upload_max_filesize=' . $upload_max_raw
            . '. Increase PHP limits before retry.',
            413
        );
    }

    if (!isset($_FILES['file']) || (int)$_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_fail('No file uploaded or upload error', 400);
    }

    $dry_run = import_parse_bool($_POST, 'dry_run', false);
    $with_covers = import_parse_bool($_POST, 'with_covers', false);
    $id_mode = trim((string)($_POST['id_mode'] ?? 'keep_ids'));
    if (!in_array($id_mode, ['keep_ids', 'new_catalog'], true)) {
        json_fail('Invalid id_mode', 400);
    }

    $upload = import_resolve_upload($_FILES['file']);
    $source_kind = (string)$upload['kind'];
    $csv_path = (string)$upload['csv_path'];
    $extract_root = $upload['extract_root'];

    $fh = @fopen($csv_path, 'rb');
    if (!$fh) {
        if (is_string($extract_root)) import_rrmdir($extract_root);
        json_fail('Unable to open import CSV', 400);
    }

    $pdo = pdo();

    // Stats
    $total = 0;
    $inserted = 0;
    $skipped = 0;
    $errors = [];
    $warnings = [];
    $id_conflicts = [];
    $id_map = [];
    $covers_copied = 0;
    $covers_missing = 0;

    $normalize_year = static function ($s) {
        $s = trim((string)$s);
        if ($s === '') return null;
        $n = (int)$s;
        return (string)$n === $s || is_numeric($s) ? $n : null;
    };

    $strip_bom = static function (string $s): string {
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) return substr($s, 3);
        return $s;
    };

    $normalize_header = static function (string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/\s+/', '_', $s);
        $s = preg_replace('/[^a-z0-9_]+/', '', $s);
        return $s;
    };

    $first_line = fgets($fh);
    if ($first_line === false) {
        fclose($fh);
        if (is_string($extract_root)) import_rrmdir($extract_root);
        json_fail('Empty file', 400);
    }
    $first_line = utf8_clean($strip_bom($first_line));

    $comma_fields = str_getcsv($first_line, ',', '"', '\\');
    $semi_fields = str_getcsv($first_line, ';', '"', '\\');
    $comma_norm = array_map($normalize_header, $comma_fields);
    $semi_norm = array_map($normalize_header, $semi_fields);

    $export_keys = [
        'id','title','subtitle','series','language','copy_count','year','isbn','lccn','notes','publisher','authors','subjects',
        'record_status',
        'loaned_to','loaned_date','bookcase','shelf','cover_image','cover_thumb','cover_filename','copies_json'
    ];
    $legacy_keys = ['title','subtitle','year_published','authors'];

    $score_export = static function (array $norm, array $keys): int {
        return count(array_intersect($norm, $keys));
    };

    $export_score_comma = $score_export($comma_norm, $export_keys);
    $export_score_semi = $score_export($semi_norm, $export_keys);
    $legacy_score_comma = $score_export($comma_norm, $legacy_keys);
    $legacy_score_semi = $score_export($semi_norm, $legacy_keys);

    $delimiter = ';';
    $header_norm = null;
    $mode = 'legacy';

    if ($export_score_comma >= 6) {
        $delimiter = ',';
        $header_norm = $comma_norm;
        $mode = 'export';
    } elseif ($export_score_semi >= 6) {
        $delimiter = ';';
        $header_norm = $semi_norm;
        $mode = 'export';
    } elseif ($legacy_score_semi >= 2) {
        $delimiter = ';';
        $header_norm = $semi_norm;
        $mode = 'legacy';
    } elseif ($legacy_score_comma >= 2) {
        $delimiter = ',';
        $header_norm = $comma_norm;
        $mode = 'legacy';
    }

    if ($header_norm === null) {
        fseek($fh, 0);
        $mode = 'legacy';
    }

    $header_map = null;
    if ($header_norm !== null) {
        $header_map = [];
        foreach ($header_norm as $i => $name) {
            $header_map[$i] = $name;
        }
    }

    $is_valid_date = static function (?string $val): bool {
        if ($val === null || $val === '') return true;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return false;
        $dt = DateTime::createFromFormat('Y-m-d', $val);
        return $dt && $dt->format('Y-m-d') === $val;
    };

    $cover_jobs = [];
    $push_warning = static function (array &$warnings, int $line, string $warning): void {
        if (count($warnings) < 50) {
            $warnings[] = ['line' => $line, 'warning' => $warning];
        }
    };
    $next_new_book_id = null;
    if ($id_mode === 'new_catalog') {
        $next_new_book_id = (int)$pdo->query('SELECT COALESCE(MAX(book_id), 0) + 1 FROM Books')->fetchColumn();
        if ($next_new_book_id < 1) $next_new_book_id = 1;
    }

    while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
        $total++;

        if ($row === [null] || $row === false) { $skipped++; continue; }

        $row = array_map(static function ($v) {
            return utf8_clean((string)($v ?? ''));
        }, $row);

        if ($header_map === null) {
            for ($i = 0; $i < 4; $i++) {
                if (!array_key_exists($i, $row)) $row[$i] = '';
            }
            $data = [
                'title' => $row[0],
                'subtitle' => $row[1],
                'year_published' => $row[2],
                'authors' => $row[3],
            ];
        } else {
            $data = [];
            foreach ($header_map as $i => $key) {
                $data[$key] = $row[$i] ?? '';
            }
        }

        $title = N($data['title'] ?? null);
        if (!$title) {
            $skipped++;
            if (count($errors) < 25) $errors[] = ['line' => $total, 'error' => 'Missing title'];
            continue;
        }

        $id_in = null;
        $cover_image_rel = null;
        $cover_thumb_rel = null;
        $language_field_present = false;
        if ($mode === 'legacy') {
            $subtitle = N($data['subtitle'] ?? null);
            $year = $normalize_year($data['year_published'] ?? null);
            $authors_csv = N($data['authors'] ?? null);
            $series = null;
            $language = 'unknown';
            $record_status = 'active';
            $publisher_id = null;
            $isbn = null;
            $lccn = null;
            $notes = null;
            $placement_id = null;
            $loaned_to = null;
            $loaned_date = null;
            $subjects_csv = null;
            $copy_count = 1;
            $copies_in = [[
                'format' => 'print',
                'quantity' => 1,
                'physical_location' => null,
                'file_path' => null,
                'notes' => null,
            ]];
            $source_old_id = null;
        } else {
            $id_in = (int)($data['id'] ?? 0);
            if ($id_in <= 0) $id_in = null;
            $source_old_id = $id_in;
            $subtitle = N($data['subtitle'] ?? null);
            $series = N($data['series'] ?? null);
            $language_field_present = array_key_exists('language', $data);
            $language = normalize_book_language($data['language'] ?? 'unknown');
            $record_status = normalize_book_record_status($data['record_status'] ?? 'active');
            $year = $normalize_year($data['year'] ?? ($data['year_published'] ?? null));
            $isbn = N($data['isbn'] ?? null);
            $lccn = N($data['lccn'] ?? null);
            $notes = N($data['notes'] ?? null);
            $publisher_id = getPublisherId($pdo, N($data['publisher'] ?? null));
            $authors_csv = N($data['authors'] ?? null);
            $subjects_csv = N($data['subjects'] ?? null);
            $loaned_to = N($data['loaned_to'] ?? null);
            $loaned_date = N($data['loaned_date'] ?? null);
            $copy_count = (int)($data['copy_count'] ?? 1);
            if ($copy_count < 1) $copy_count = 1;
            if ($loaned_to === null) {
                $loaned_date = null;
            }
            if (!$is_valid_date($loaned_date)) {
                $skipped++;
                if (count($errors) < 25) $errors[] = ['line' => $total, 'error' => 'Invalid loaned_date'];
                continue;
            }

            $bookcase_raw = N($data['bookcase'] ?? null);
            $shelf_raw = N($data['shelf'] ?? null);
            $placement_id = null;
            if ($bookcase_raw !== null && $shelf_raw !== null) {
                $bookcase_no = (int)$bookcase_raw;
                $shelf_no = (int)$shelf_raw;
                if ($bookcase_no > 0 && $shelf_no > 0) {
                    $placement_id = getOrCreatePlacementId($pdo, [
                        'bookcase_no' => $bookcase_no,
                        'shelf_no' => $shelf_no,
                    ]);
                }
            }

            $copies_in = null;
            $copies_json = N($data['copies_json'] ?? null);
            if ($copies_json !== null) {
                $decoded = json_decode($copies_json, true);
                if (is_array($decoded)) {
                    $copies_in = [];
                    foreach ($decoded as $copy) {
                        if (is_array($copy)) $copies_in[] = $copy;
                    }
                }
            }
            if ($copies_in === null || !$copies_in) {
                $copies_in = [[
                    'format' => 'print',
                    'quantity' => $copy_count,
                    'physical_location' => format_physical_location_from_placement(
                        isset($bookcase_no) ? $bookcase_no : null,
                        isset($shelf_no) ? $shelf_no : null
                    ),
                    'file_path' => null,
                    'notes' => null,
                ]];
            }

            $cover_image_rel = N($data['cover_image'] ?? null);
            $cover_thumb_rel = N($data['cover_thumb'] ?? null);
            if ($cover_thumb_rel === null) {
                $cover_filename = N($data['cover_filename'] ?? null);
                if ($cover_filename !== null && $id_in !== null) {
                    $cover_thumb_rel = 'uploads/' . $id_in . '/' . basename($cover_filename);
                }
            }
        }

        if ($language === 'unknown') {
            $language = infer_import_language_from_metadata(
                $title,
                $subtitle,
                $authors_csv,
                null,
                !$language_field_present
            );
        }

        if ($dry_run) {
            continue;
        }

        try {
            $pdo->beginTransaction();
            $book_id = null;
            $restoring_existing_deleted = false;
            $target_book_id = null;

            if ($id_mode === 'keep_ids' && $id_in !== null) {
                $exists = $pdo->prepare(
                    books_table_has_record_status($pdo)
                        ? 'SELECT book_id, record_status FROM Books WHERE book_id = ? LIMIT 1 FOR UPDATE'
                        : 'SELECT book_id, \'active\' AS record_status FROM Books WHERE book_id = ? LIMIT 1 FOR UPDATE'
                );
                $exists->execute([$id_in]);
                $existing = $exists->fetch(PDO::FETCH_ASSOC);
                if ($existing && normalize_book_record_status($existing['record_status'] ?? 'active') !== 'deleted') {
                    $pdo->rollBack();
                    $skipped++;
                    if (count($errors) < 25) $errors[] = ['line' => $total, 'error' => 'book_id already exists'];
                    $id_conflicts[] = [
                        'line' => $total,
                        'existing_id' => $id_in,
                        'new_id' => null,
                        'title' => $title,
                        'authors' => $authors_csv,
                    ];
                    continue;
                }
                if ($existing) {
                    $restoring_existing_deleted = true;
                    $stmt = $pdo->prepare(
                        "UPDATE Books
                            SET title = ?, subtitle = ?, series = ?, language = ?, copy_count = ?, publisher_id = ?,
                                year_published = ?, isbn = ?, lccn = ?, notes = ?, placement_id = ?,
                                record_status = ?, loaned_to = ?, loaned_date = ?
                          WHERE book_id = ?"
                    );
                    $stmt->execute([
                        $title,
                        $subtitle,
                        $series,
                        $language,
                        $copy_count,
                        $publisher_id,
                        $year,
                        $isbn,
                        $lccn,
                        $notes,
                        $placement_id,
                        $record_status,
                        $loaned_to,
                        $loaned_date,
                        $id_in,
                    ]);
                    $book_id = $id_in;
                    $pdo->prepare('DELETE FROM Books_Authors WHERE book_id = ?')->execute([$book_id]);
                    $pdo->prepare('DELETE FROM Books_Subjects WHERE book_id = ?')->execute([$book_id]);
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO Books
                          (book_id, title, subtitle, series, language, copy_count, publisher_id, year_published,
                           isbn, lccn, notes, cover_image, cover_thumb, placement_id, record_status,
                           loaned_to, loaned_date)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    );
                    $stmt->execute([
                        $id_in,
                        $title,
                        $subtitle,
                        $series,
                        $language,
                        $copy_count,
                        $publisher_id,
                        $year,
                        $isbn,
                        $lccn,
                        $notes,
                        null,
                        null,
                        $placement_id,
                        $record_status,
                        $loaned_to,
                        $loaned_date,
                    ]);
                    $book_id = $id_in;
                }
            } else {
                if ($id_mode === 'new_catalog' && $next_new_book_id !== null) {
                    $target_book_id = $next_new_book_id++;
                    $stmt = $pdo->prepare(
                        "INSERT INTO Books
                          (book_id, title, subtitle, series, language, copy_count, publisher_id, year_published,
                           isbn, lccn, notes, cover_image, cover_thumb, placement_id, record_status,
                           loaned_to, loaned_date)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    );
                    $stmt->execute([
                        $target_book_id,
                        $title,
                        $subtitle,
                        $series,
                        $language,
                        $copy_count,
                        $publisher_id,
                        $year,
                        $isbn,
                        $lccn,
                        $notes,
                        null,
                        null,
                        $placement_id,
                        $record_status,
                        $loaned_to,
                        $loaned_date,
                    ]);
                    $book_id = $target_book_id;
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO Books
                          (title, subtitle, series, language, copy_count, publisher_id, year_published,
                           isbn, lccn, notes, cover_image, cover_thumb, placement_id, record_status,
                           loaned_to, loaned_date)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    );
                    $stmt->execute([
                        $title,
                        $subtitle,
                        $series,
                        $language,
                        $copy_count,
                        $publisher_id,
                        $year,
                        $isbn,
                        $lccn,
                        $notes,
                        null,
                        null,
                        $placement_id,
                        $record_status,
                        $loaned_to,
                        $loaned_date,
                    ]);
                    $book_id = (int)$pdo->lastInsertId();
                }
            }

            if (bookcopies_table_exists($pdo)) {
                $saved_copies = replace_book_copies($pdo, $book_id, $copies_in);
                $sync = sync_book_copy_derived_fields($pdo, $book_id, $saved_copies);
                $copy_count = (int)($sync['copy_count'] ?? $copy_count);
            } else {
                upsert_default_print_copy($pdo, $book_id, $copy_count, $copies_in[0]['physical_location'] ?? null);
                $sync = sync_book_copy_derived_fields($pdo, $book_id);
                $saved_copies = $sync['copies'] ?? [];
                $copy_count = (int)($sync['copy_count'] ?? $copy_count);
            }

            if ($restoring_existing_deleted && $record_status === 'active' && !can_restore_book_from_copies($saved_copies ?? [])) {
                $pdo->prepare("UPDATE Books SET record_status = 'deleted' WHERE book_id = ?")->execute([$book_id]);
                $push_warning($warnings, $total, 'Restore kept record deleted because ebook paths are no longer valid and no print copy exists.');
            }

            if ($authors_csv) {
                attachAuthors($book_id, $authors_csv);
            }

            if ($subjects_csv) {
                $parts = preg_split('/[;,]+/', $subjects_csv) ?: [];
                $seen = [];
                foreach ($parts as $part) {
                    $name = trim($part);
                    if ($name === '') continue;
                    $key = strtolower($name);
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $sid = getOrCreateIdByName($pdo, 'Subjects', 'subject_id', 'name', $name);
                    if ($sid) {
                        $pdo->prepare('INSERT INTO Books_Subjects (book_id, subject_id) VALUES (?, ?)')
                            ->execute([$book_id, $sid]);
                    }
                }
            }

            if ($id_in !== null && $book_id !== $id_in) {
                $id_map[] = [
                    'line' => $total,
                    'existing_id' => $id_in,
                    'new_id' => $book_id,
                    'title' => $title,
                    'authors' => $authors_csv,
                ];
            }

            if ($with_covers && is_string($extract_root) && $source_old_id !== null) {
                $cover_jobs[] = [
                    'old_id' => (int)$source_old_id,
                    'new_id' => $book_id,
                    'cover_image' => $cover_image_rel,
                    'cover_thumb' => $cover_thumb_rel,
                ];
            }

            $pdo->commit();
            $inserted++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (count($errors) < 25) {
                $errors[] = ['line' => $total, 'error' => $e->getMessage()];
            }
        }
    }

    fclose($fh);

    if (!$dry_run && $with_covers && is_string($extract_root)) {
        $uploads_root = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
        foreach ($cover_jobs as $job) {
            $old_id = (int)$job['old_id'];
            $new_id = (int)$job['new_id'];
            $cover_src = import_find_cover_source($extract_root, $old_id, $job['cover_image']);
            $thumb_src = import_find_thumb_source($extract_root, $old_id, $job['cover_thumb']);

            if ($cover_src === null && $thumb_src === null) {
                $covers_missing++;
                continue;
            }

            $target_dir = $uploads_root . DIRECTORY_SEPARATOR . $new_id;
            if (!is_dir($target_dir) && !@mkdir($target_dir, 0775, true) && !is_dir($target_dir)) {
                $covers_missing++;
                continue;
            }

            // Always normalize target cover paths and regenerate thumbnail from the copied cover.
            // This avoids stale/mismatched thumb files after ID remapping restores.
            foreach (glob($target_dir . DIRECTORY_SEPARATOR . 'cover*.*') ?: [] as $oldf) { @unlink($oldf); }
            foreach (glob($target_dir . DIRECTORY_SEPARATOR . 'cover-thumb*.*') ?: [] as $oldf) { @unlink($oldf); }

            $source_image = $cover_src ?? $thumb_src;
            if ($source_image === null) {
                $covers_missing++;
                continue;
            }

            $ext = strtolower(pathinfo($source_image, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $ext = 'jpg';
            }

            $cover_dst_abs = $target_dir . DIRECTORY_SEPARATOR . 'cover.' . $ext;
            $thumb_dst_abs = $target_dir . DIRECTORY_SEPARATOR . 'cover-thumb.' . $ext;
            if (!@copy($source_image, $cover_dst_abs)) {
                $covers_missing++;
                continue;
            }

            $thumb_ok = make_thumb($cover_dst_abs, $thumb_dst_abs, 200);
            $rel_cover = 'uploads/' . $new_id . '/cover.' . $ext;
            $rel_thumb = $thumb_ok ? ('uploads/' . $new_id . '/cover-thumb.' . $ext) : $rel_cover;

            $upd = $pdo->prepare('UPDATE Books SET cover_image = ?, cover_thumb = ? WHERE book_id = ?');
            $upd->execute([$rel_cover, $rel_thumb, $new_id]);
            $covers_copied++;
            if ($thumb_ok) $covers_copied++;
        }
    }

    if (is_string($extract_root)) {
        import_rrmdir($extract_root);
    }

    $note = 'CSV import completed.';
    if ($source_kind === 'zip') {
        $note = 'ZIP bundle import completed.';
    }
    if ($with_covers && $source_kind !== 'zip') {
        $note .= ' Covers were requested but source is not ZIP; no cover files imported.';
    }

    json_out([
        'ok' => true,
        'data' => [
            'dry_run' => $dry_run,
            'source_kind' => $source_kind,
            'with_covers' => $with_covers,
            'id_mode' => $id_mode,
            'total' => $total,
            'inserted' => $dry_run ? 0 : $inserted,
            'skipped' => $skipped,
            'errors' => $errors,
            'warnings' => $warnings,
            'id_conflicts' => $id_conflicts,
            'id_remaps' => $id_map,
            'covers_copied' => $dry_run ? 0 : $covers_copied,
            'covers_missing' => $dry_run ? 0 : $covers_missing,
            'note' => $note,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($extract_root) && is_string($extract_root)) {
        import_rrmdir($extract_root);
    }
    json_fail($e->getMessage(), 500);
}
