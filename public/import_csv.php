<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

// Never leak warnings/notices into JSON
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // Minimal upload form for convenience
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <meta charset="utf-8">
    <title>Import CSV</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; margin: 2rem; }
        form { display: grid; gap: .75rem; max-width: 520px; }
        label { font-weight: 600; }
    </style>
    <h1>Import CSV</h1>
    <p>Supported formats:<br>
        <code>books_export.csv</code> (comma-separated export from this app), or<br>
        legacy <code>title;subtitle;year_published;authors</code> (semicolon separated).</p>
    <form method="post" enctype="multipart/form-data">
        <label>CSV file <input type="file" name="file" accept=".csv,text/csv,text/plain" required></label>
        <label><input type="checkbox" name="dry_run" value="1" checked> Dry run (validate only; don’t insert)</label>
        <button type="submit">Upload &amp; Import</button>
    </form>
    <?php
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    if ($method !== 'POST') {
        http_response_code(405);
        json_fail('Method Not Allowed', 405);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        json_fail('No file uploaded or upload error', 400);
    }

    $dry_run = isset($_POST['dry_run']) && (string)$_POST['dry_run'] !== '' && $_POST['dry_run'] !== '0';

    $tmp_path = $_FILES['file']['tmp_name'];
    $fh = @fopen($tmp_path, 'rb');
    if (!$fh) {
        http_response_code(400);
        json_fail('Unable to open uploaded file', 400);
    }

    $pdo = pdo();

    // Stats
    $total = 0;
    $inserted = 0;
    $skipped = 0;
    $errors = [];
    $id_conflicts = [];

    // Helpers
    $normalize_year = static function ($s) {
        $s = trim((string)$s);
        if ($s === '') return null;
        $n = (int)$s;
        return (string)$n === $s || is_numeric($s) ? $n : null;
    };

    $strip_bom = static function (string $s): string {
        // Remove UTF-8 BOM if present
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
        json_fail('Empty file', 400);
    }
    $first_line = utf8_clean($strip_bom($first_line));

    $comma_fields = str_getcsv($first_line, ',', '"', '\\');
    $semi_fields = str_getcsv($first_line, ';', '"', '\\');
    $comma_norm = array_map($normalize_header, $comma_fields);
    $semi_norm = array_map($normalize_header, $semi_fields);

    $export_keys = [
        'id','title','subtitle','series','copy_count','year','isbn','lccn','notes','publisher','authors','subjects',
        'loaned_to','loaned_date','bookcase','shelf','cover_image','cover_filename'
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
        // No header; rewind and assume legacy format.
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

    while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
        $total++;

        if ($row === [null] || $row === false) { $skipped++; continue; }

        $row = array_map(static function ($v) {
            return utf8_clean((string)($v ?? ''));
        }, $row);

        if ($header_map === null) {
            // Legacy (no header): title;subtitle;year_published;authors
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
        if ($mode === 'legacy') {
            $subtitle = N($data['subtitle'] ?? null);
            $year = $normalize_year($data['year_published'] ?? null);
            $authors_csv = N($data['authors'] ?? null);
            $series = null;
            $publisher_id = null;
            $isbn = null;
            $lccn = null;
            $notes = null;
            $placement_id = null;
            $loaned_to = null;
            $loaned_date = null;
            $subjects_csv = null;
            $cover_filename = null;
            $copy_count = 1;
        } else {
            $id_in = (int)($data['id'] ?? 0);
            if ($id_in <= 0) $id_in = null;
            $subtitle = N($data['subtitle'] ?? null);
            $series = N($data['series'] ?? null);
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

            $cover_filename = N($data['cover_filename'] ?? null);
            if ($cover_filename === null) {
                $cover_image = N($data['cover_image'] ?? null);
                if ($cover_image) {
                    $cover_filename = basename($cover_image);
                }
            }
        }

        if ($dry_run) {
            continue;
        }

        try {
            $id_conflict = false;
            if ($id_in !== null) {
                $exists = $pdo->prepare('SELECT 1 FROM Books WHERE book_id = ? LIMIT 1');
                $exists->execute([$id_in]);
                $id_conflict = (bool)$exists->fetchColumn();
            }

            if ($id_in !== null && !$id_conflict) {
                $stmt = $pdo->prepare("
        INSERT INTO Books
          (book_id, title, subtitle, series, copy_count, publisher_id, year_published,
           isbn, lccn, notes, cover_image, cover_thumb, placement_id,
           loaned_to, loaned_date)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
                $stmt->execute([
                    $id_in,
                    $title,
                    $subtitle,
                    $series,
                    $copy_count,
                    $publisher_id,
                    $year,
                    $isbn,
                    $lccn,
                    $notes,
                    null,
                    null,
                    $placement_id,
                    $loaned_to,
                    $loaned_date,
                ]);
                $book_id = $id_in;
            } else {
                $stmt = $pdo->prepare("
        INSERT INTO Books
          (title, subtitle, series, copy_count, publisher_id, year_published,
           isbn, lccn, notes, cover_image, cover_thumb, placement_id,
           loaned_to, loaned_date)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
                $stmt->execute([
                    $title,
                    $subtitle,
                    $series,
                    $copy_count,
                    $publisher_id,
                    $year,
                    $isbn,
                    $lccn,
                    $notes,
                    null,
                    null,
                    $placement_id,
                    $loaned_to,
                    $loaned_date,
                ]);
                $book_id = (int)$pdo->lastInsertId();
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

            if ($cover_filename) {
                $rel = 'uploads/' . $book_id . '/' . $cover_filename;
                $upd = $pdo->prepare("UPDATE Books SET cover_image = ?, cover_thumb = ? WHERE book_id = ?");
                $upd->execute([$rel, $rel, $book_id]);
            }

            if ($id_conflict && $id_in !== null) {
                if (count($id_conflicts) < 200) {
                    $id_conflicts[] = [
                        'line' => $total,
                        'existing_id' => $id_in,
                        'new_id' => $book_id,
                        'title' => $title,
                        'authors' => $authors_csv,
                    ];
                }
            }

            $inserted++;
        } catch (Throwable $e) {
            if (count($errors) < 25) {
                $errors[] = ['line' => $total, 'error' => $e->getMessage()];
            }
        }
    }

    fclose($fh);

    json_out([
        'ok' => true,
        'data' => [
            'dry_run' => $dry_run,
            'total' => $total,
            'inserted' => $dry_run ? 0 : $inserted,
            'skipped' => $skipped,
            'errors' => $errors,       // at most 25 samples
            'id_conflicts' => $id_conflicts,
            'note' => 'CSV formats: export_books_csv.php output (comma-delimited) or legacy title;subtitle;year_published;authors (semicolon-delimited). If an ID already exists, a new ID is assigned and reported in id_conflicts.',
        ],
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    json_fail($e->getMessage(), 500);
}
