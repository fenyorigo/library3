<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_fail('Method Not Allowed', 405);
    }

    $is_valid_date = function (?string $val): bool {
        if ($val === null || $val === '') return true;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return false;
        $dt = DateTime::createFromFormat('Y-m-d', $val);
        return $dt && $dt->format('Y-m-d') === $val;
    };

    $pdo = pdo();

    // Start a TX only if none is active (so we won’t clash with helpers)
    $started_tx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started_tx = true;
    }

    // --- parse body ---
    $content_type = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    $use_multipart = (!empty($_FILES) || stripos($content_type, 'multipart/form-data') !== false);
    if ($use_multipart) {
        $raw = $_POST['payload'] ?? null;
        if ($raw !== null && $raw !== '') {
            $d = json_decode((string)$raw, true);
        } else {
            $d = $_POST;
            unset($d['payload']);
            if (isset($d['bookcase_no']) || isset($d['shelf_no'])) {
                $d['placement'] = [
                    'bookcase_no' => $d['bookcase_no'] ?? null,
                    'shelf_no' => $d['shelf_no'] ?? null,
                ];
            }
        }
    } else {
        $d = json_in();            // from functions.php
    }
    if (!is_array($d)) {
        if ($started_tx) $pdo->rollBack();
        json_fail('Invalid JSON body', 400);
    }

    // required
    $title = N($d['title'] ?? null);
    if (!$title) {
        if ($started_tx) $pdo->rollBack();
        json_fail('Title is required', 400);
    }

    // publisher: id has priority; else resolve/create by name; both absent → NULL
    if (isset($d['publisher_id']) && $d['publisher_id'] !== '' && $d['publisher_id'] !== null) {
        $publisher_id = (int)$d['publisher_id'];
        if ($publisher_id <= 0) $publisher_id = null;
    } else {
        $publisher_id = getPublisherId($pdo, N($d['publisher'] ?? null)); // may be null
    }

    // placement (optional)
    $placement_id = null;
    if (!empty($d['placement']) && is_array($d['placement'])) {
        $placement_id = getOrCreatePlacementId($pdo, $d['placement']); // may be null
    }

    // optionals
    $subtitle = N($d['subtitle'] ?? null);
    $series   = N($d['series'] ?? null);
    $isbn     = N($d['isbn'] ?? null);
    $lccn     = N($d['lccn'] ?? null);
    $notes    = N($d['notes'] ?? null);
    $copy_count = (int)($d['copy_count'] ?? 1);
    if ($copy_count < 1) $copy_count = 1;
    $loaned_to = N($d['loaned_to'] ?? null);
    $loaned_date = N($d['loaned_date'] ?? null);
    if ($loaned_to === null) {
        $loaned_date = null;
    }
    if (!$is_valid_date($loaned_date)) {
        if ($started_tx) $pdo->rollBack();
        json_fail('Invalid loaned_date (expected YYYY-MM-DD)', 400);
    }

    // YEAR: normalize to NULL (never 0)
    $year_published = null;
    if (array_key_exists('year_published', $d)) {
        $yp = $d['year_published'];
        if ($yp === '' || $yp === null) {
            $year_published = null;
        } else {
            $y = (int)$yp;
            $year_published = ($y === 0) ? null : $y;
        }
    }

    // Authors CSV (optional)
    $authors_csv = N($d['authors'] ?? null);
    $authors_is_hu = array_key_exists('authors_is_hungarian', $d)
        ? (int)!!$d['authors_is_hungarian']
        : null;

    // Subjects CSV (optional)
    $subjects_csv = N($d['subjects'] ?? null);

    // cover fields (if client pre-fills; usually null at create)
    $cover_image_in = N($d['cover_image'] ?? null);
    $cover_thumb_in = N($d['cover_thumb'] ?? null);

    // INSERT — matches your schema (no added_date; no back_image)
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
        $year_published,
        $isbn,
        $lccn,
        $notes,
        $cover_image_in,
        $cover_thumb_in,
        $placement_id,
        $loaned_to,
        $loaned_date,
    ]);

    $book_id = (int)$pdo->lastInsertId();

    // Link authors if provided (this must NOT start its own TX if one is already open)
    if ($authors_csv) {
        attachAuthors($book_id, $authors_csv, $authors_is_hu);
    }

    // Link subjects if provided
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

    $thumb_max_w = isset($d['thumb_max_w']) ? (int)$d['thumb_max_w'] : 0;
    if ($thumb_max_w < 64 || $thumb_max_w > 4096) {
        $thumb_max_w = 200;
    }

    $cover_uploaded = false;
    $cover_result = null;
    if (!empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $cover_result = process_cover_upload($pdo, $book_id, $_FILES['image'], $thumb_max_w);
        $cover_uploaded = true;
    }

    // Auto-fill cover if uploads/<id>/cover.jpg already exists
    $uploads_dir = __DIR__ . '/uploads';
    $disk_cover  = $uploads_dir . '/' . $book_id . '/cover.jpg';
    if (!$cover_image_in && !$cover_uploaded && is_file($disk_cover)) {
        $rel = 'uploads/' . $book_id . '/cover.jpg';
        $upd = $pdo->prepare("UPDATE Books SET cover_image=?, cover_thumb=? WHERE book_id=?");
        $upd->execute([$rel, $rel, $book_id]);
    }

    if ($started_tx) $pdo->commit();

    json_out([
        'ok' => true,
        'data' => [
            'id' => $book_id,
            'affected_rows' => $stmt->rowCount(),
            'cover_image' => $cover_result['path'] ?? null,
            'cover_thumb' => $cover_result['thumb'] ?? null,
        ],
        'message' => 'Book created.',
    ], 201);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        // Only rollback if the TX is actually open
        $pdo->rollBack();
    }
    $code = (int)$e->getCode();
    if ($code >= 400 && $code < 600) {
        json_fail($e->getMessage(), $code);
    }
    json_fail('Insert failed: ' . $e->getMessage(), 500);
}
