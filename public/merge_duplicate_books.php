<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
$me = require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function merge_norm(?string $v): string {
    $v = trim((string)$v);
    $v = mb_strtolower($v, 'UTF-8');
    $v = preg_replace('/\s+/u', ' ', $v);
    return $v ?? '';
}

function merge_norm_title_key(string $title, ?string $subtitle): string {
    $raw = trim($title);
    $sub = trim((string)$subtitle);
    if ($sub !== '') $raw .= '||' . $sub;
    $t = merge_norm($raw);
    $t = preg_replace('/\b([ivxlcdm]+|\d+)\.$/i', '$1', $t);
    return $t ?? '';
}

function merge_norm_list(array $values): string {
    $out = [];
    foreach ($values as $v) {
        $n = merge_norm((string)$v);
        if ($n !== '') $out[] = $n;
    }
    sort($out, SORT_STRING);
    return implode(';', $out);
}

function merge_dup_key_for_book(array $book, array $author_key_map): string {
    $book_id = (int)$book['book_id'];
    $title_key = merge_norm_title_key((string)$book['title'], (string)($book['subtitle'] ?? ''));
    $authors_key = merge_norm_list($author_key_map[$book_id] ?? []);
    return $title_key . '|' . $authors_key;
}

function merge_safe_delete_cover_path(PDO $pdo, string $rel_path, array &$deleted_paths): void {
    $rel_path = ltrim(trim($rel_path), '/');
    if ($rel_path === '' || strpos($rel_path, 'uploads/') !== 0) return;

    $ref = $pdo->prepare("
        SELECT COUNT(*) FROM Books
        WHERE cover_image = :cover_image OR cover_thumb = :cover_thumb
    ");
    $ref->execute([
        ':cover_image' => $rel_path,
        ':cover_thumb' => $rel_path,
    ]);
    if ((int)$ref->fetchColumn() > 0) return;

    $abs_path = __DIR__ . '/' . $rel_path;
    $uploads_root = realpath(__DIR__ . '/uploads');
    $abs_dir = realpath(dirname($abs_path));
    if ($uploads_root === false || $abs_dir === false) return;
    if (strpos($abs_dir, $uploads_root) !== 0) return;
    if (!is_file($abs_path)) return;

    if (@unlink($abs_path)) {
        $deleted_paths[] = $rel_path;
    }
}

function merge_collect_duplicate_cover_candidates(array $dup_ids): array {
    $candidates = [];
    $patterns = [
        'cover.jpg', 'cover.jpeg', 'cover.png', 'cover.webp', 'cover.gif',
        'cover-thumb.jpg', 'cover-thumb.jpeg', 'cover-thumb.png', 'cover-thumb.webp', 'cover-thumb.gif',
    ];
    foreach ($dup_ids as $id) {
        $book_id = (int)$id;
        if ($book_id <= 0) continue;
        foreach ($patterns as $name) {
            $candidates[] = 'uploads/' . $book_id . '/' . $name;
        }
    }
    return $candidates;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_fail('Method Not Allowed', 405);
    }

    $in = json_in();
    $master_id = (int)($in['masterBookId'] ?? 0);
    $dup_ids_in = is_array($in['duplicateBookIds'] ?? null) ? $in['duplicateBookIds'] : [];
    $confirm = (string)($in['confirm'] ?? '');

    if ($confirm !== 'MERGE') {
        json_fail('Confirmation token must be exactly MERGE', 400);
    }
    if ($master_id <= 0) {
        json_fail('Invalid masterBookId', 400);
    }

    $dup_ids = [];
    foreach ($dup_ids_in as $id) {
        $v = (int)$id;
        if ($v > 0 && $v !== $master_id) $dup_ids[$v] = true;
    }
    $dup_ids = array_keys($dup_ids);
    if (!$dup_ids) {
        json_fail('At least one valid duplicateBookId is required', 400);
    }

    $pdo = pdo();
    $pdo->beginTransaction();

    $all_ids = array_merge([$master_id], $dup_ids);
    $ph = [];
    $params = [];
    foreach ($all_ids as $i => $id) {
        $k = ':id' . $i;
        $ph[] = $k;
        $params[$k] = $id;
    }

    $books_sql = "
        SELECT b.book_id, b.title, b.subtitle, b.series, b.year_published, b.isbn, b.notes,
               b.publisher_id, p.name AS publisher_name,
               b.placement_id, pl.bookcase_no, pl.shelf_no,
               b.cover_image, b.cover_thumb, b.copy_count
        FROM Books b
        LEFT JOIN Publishers p ON p.publisher_id = b.publisher_id
        LEFT JOIN Placement pl ON pl.placement_id = b.placement_id
        WHERE b.book_id IN (" . implode(',', $ph) . ")
        FOR UPDATE
    ";
    $st_books = $pdo->prepare($books_sql);
    foreach ($params as $k => $v) $st_books->bindValue($k, $v, PDO::PARAM_INT);
    $st_books->execute();
    $books = $st_books->fetchAll(PDO::FETCH_ASSOC);

    $by_id = [];
    foreach ($books as $row) $by_id[(int)$row['book_id']] = $row;
    if (!isset($by_id[$master_id])) {
        throw new OutOfBoundsException('Master book does not exist', 404);
    }
    foreach ($dup_ids as $id) {
        if (!isset($by_id[$id])) {
            throw new OutOfBoundsException('One or more duplicate books do not exist', 404);
        }
    }

    $authors_sql = "
        SELECT ba.book_id, ba.author_id, ba.author_ord,
               COALESCE(NULLIF(TRIM(a.sort_name), ''), NULLIF(TRIM(a.name), ''), '') AS author_key_name,
               COALESCE(NULLIF(TRIM(a.sort_name), ''), NULLIF(TRIM(a.name), ''), TRIM(CONCAT(COALESCE(a.last_name,''), ', ', COALESCE(a.first_name,'')))) AS author_cmp_name
        FROM Books_Authors ba
        JOIN Authors a ON a.author_id = ba.author_id
        WHERE ba.book_id IN (" . implode(',', $ph) . ")
        ORDER BY ba.book_id, ba.author_ord
    ";
    $st_auth = $pdo->prepare($authors_sql);
    foreach ($params as $k => $v) $st_auth->bindValue($k, $v, PDO::PARAM_INT);
    $st_auth->execute();
    $auth_rows = $st_auth->fetchAll(PDO::FETCH_ASSOC);

    $author_key_map = [];
    $author_cmp_map = [];
    foreach ($auth_rows as $r) {
        $book_id = (int)$r['book_id'];
        $author_id = (int)$r['author_id'];
        $author_key = trim((string)$r['author_key_name']);
        if ($author_key === '') $author_key = 'author#' . $author_id;
        $author_cmp = trim((string)$r['author_cmp_name']);
        $author_key_map[$book_id][] = $author_key;
        if ($author_cmp !== '') $author_cmp_map[$book_id][] = $author_cmp;
    }

    $subjects_sql = "
        SELECT bs.book_id, s.name
        FROM Books_Subjects bs
        JOIN Subjects s ON s.subject_id = bs.subject_id
        WHERE bs.book_id IN (" . implode(',', $ph) . ")
    ";
    $st_subj = $pdo->prepare($subjects_sql);
    foreach ($params as $k => $v) $st_subj->bindValue($k, $v, PDO::PARAM_INT);
    $st_subj->execute();
    $subj_rows = $st_subj->fetchAll(PDO::FETCH_ASSOC);
    $subjects_map = [];
    foreach ($subj_rows as $r) {
        $book_id = (int)$r['book_id'];
        $subjects_map[$book_id][] = (string)$r['name'];
    }

    $master_dup_key = merge_dup_key_for_book($by_id[$master_id], $author_key_map);
    foreach ($dup_ids as $id) {
        $k = merge_dup_key_for_book($by_id[$id], $author_key_map);
        if ($k !== $master_dup_key) {
            throw new InvalidArgumentException('Selected books do not belong to the same duplicate candidate group', 400);
        }
    }

    $profile = static function (array $book, array $author_cmp_map, array $subjects_map): array {
        $book_id = (int)$book['book_id'];
        $placement = '';
        if ($book['bookcase_no'] !== null && $book['shelf_no'] !== null) {
            $placement = (string)$book['bookcase_no'] . '/' . (string)$book['shelf_no'];
        } elseif ($book['placement_id'] !== null) {
            $placement = (string)$book['placement_id'];
        }
        return [
            'title' => merge_norm((string)$book['title']),
            'subtitle' => merge_norm((string)($book['subtitle'] ?? '')),
            'authors' => merge_norm_list($author_cmp_map[$book_id] ?? []),
            'publisher' => merge_norm((string)($book['publisher_name'] ?? '')),
            'year_published' => trim((string)($book['year_published'] ?? '')),
            'isbn' => merge_norm((string)($book['isbn'] ?? '')),
            'series' => merge_norm((string)($book['series'] ?? '')),
            'subjects' => merge_norm_list($subjects_map[$book_id] ?? []),
            'notes' => merge_norm((string)($book['notes'] ?? '')),
            'placement' => merge_norm($placement),
        ];
    };

    $master_profile = $profile($by_id[$master_id], $author_cmp_map, $subjects_map);
    $differences = [];
    foreach ($dup_ids as $id) {
        $cur = $profile($by_id[$id], $author_cmp_map, $subjects_map);
        foreach (['title', 'subtitle', 'authors', 'publisher', 'year_published', 'isbn', 'series', 'subjects', 'notes'] as $field) {
            if (($master_profile[$field] ?? '') !== ($cur[$field] ?? '')) {
                $differences[$field] = true;
            }
        }
        if (($master_profile['placement'] ?? '') !== ($cur['placement'] ?? '')) {
            $differences['placement'] = true; // informational
        }
    }
    $diff_fields = array_keys($differences);
    sort($diff_fields, SORT_STRING);
    $differences_detected = count(array_diff($diff_fields, ['placement'])) > 0;

    $master_book = $by_id[$master_id];
    $old_count = (int)($master_book['copy_count'] ?? 1);
    if ($old_count < 1) $old_count = 1;
    $new_count = $old_count + count($dup_ids);

    $master_cover_image = trim((string)($master_book['cover_image'] ?? ''));
    $master_cover_thumb = trim((string)($master_book['cover_thumb'] ?? ''));
    $adopt_cover_image = null;
    $adopt_cover_thumb = null;
    if ($master_cover_image === '') {
        foreach ($dup_ids as $id) {
            $dup_cover = trim((string)($by_id[$id]['cover_image'] ?? ''));
            if ($dup_cover !== '') {
                $adopt_cover_image = $dup_cover;
                $dup_thumb = trim((string)($by_id[$id]['cover_thumb'] ?? ''));
                $adopt_cover_thumb = $dup_thumb !== '' ? $dup_thumb : $dup_cover;
                break;
            }
        }
    }

    if ($adopt_cover_image !== null) {
        $upd_master = $pdo->prepare("
            UPDATE Books
            SET copy_count = :copy_count, cover_image = :cover_image, cover_thumb = :cover_thumb
            WHERE book_id = :book_id
        ");
        $upd_master->execute([
            ':copy_count' => $new_count,
            ':cover_image' => $adopt_cover_image,
            ':cover_thumb' => $adopt_cover_thumb,
            ':book_id' => $master_id,
        ]);
    } else {
        $upd_master = $pdo->prepare("UPDATE Books SET copy_count = :copy_count WHERE book_id = :book_id");
        $upd_master->execute([
            ':copy_count' => $new_count,
            ':book_id' => $master_id,
        ]);
    }

    $dup_placeholders = [];
    $dup_params = [];
    foreach ($dup_ids as $i => $id) {
        $k = ':d' . $i;
        $dup_placeholders[] = $k;
        $dup_params[$k] = $id;
    }
    $dup_in = implode(',', $dup_placeholders);

    $del_rel = $pdo->prepare("DELETE FROM Books_Authors WHERE book_id IN ($dup_in)");
    foreach ($dup_params as $k => $v) $del_rel->bindValue($k, $v, PDO::PARAM_INT);
    $del_rel->execute();

    $del_sub = $pdo->prepare("DELETE FROM Books_Subjects WHERE book_id IN ($dup_in)");
    foreach ($dup_params as $k => $v) $del_sub->bindValue($k, $v, PDO::PARAM_INT);
    $del_sub->execute();

    $del_books = $pdo->prepare("DELETE FROM Books WHERE book_id IN ($dup_in)");
    foreach ($dup_params as $k => $v) $del_books->bindValue($k, $v, PDO::PARAM_INT);
    $del_books->execute();

    $removed_ids_text = implode(', ', $dup_ids);
    $merge_note = sprintf(
        'Merged into Book ID %d, copy_count increased from %d to %d; removed Book IDs %s',
        $master_id,
        $old_count,
        $new_count,
        $removed_ids_text
    );
    $note_with_diff = $differences_detected
        ? ($merge_note . '; differences detected')
        : ($merge_note . '; no differences detected');

    $merge_review = $pdo->prepare("
        INSERT INTO duplicate_review (dup_key, status, note)
        VALUES (:dup_key, 'MERGED', :note)
        ON DUPLICATE KEY UPDATE
            status = 'MERGED',
            note = CONCAT(COALESCE(note, ''), CASE WHEN COALESCE(note, '') = '' THEN '' ELSE '\n' END, VALUES(note)),
            updated_at = CURRENT_TIMESTAMP
    ");
    $merge_review->execute([
        ':dup_key' => $master_dup_key,
        ':note' => $note_with_diff,
    ]);

    $dup_cover_paths = [];
    foreach ($dup_ids as $id) {
        $ci = trim((string)($by_id[$id]['cover_image'] ?? ''));
        $ct = trim((string)($by_id[$id]['cover_thumb'] ?? ''));
        if ($ci !== '') $dup_cover_paths[$ci] = true;
        if ($ct !== '') $dup_cover_paths[$ct] = true;
    }

    $pdo->commit();

    $skip_paths = [];
    if ($adopt_cover_image !== null) {
        $skip_paths[$adopt_cover_image] = true;
        if ($adopt_cover_thumb !== null) $skip_paths[$adopt_cover_thumb] = true;
    }
    if ($master_cover_image !== '') $skip_paths[$master_cover_image] = true;
    if ($master_cover_thumb !== '') $skip_paths[$master_cover_thumb] = true;

    $cleanup_candidates = array_keys($dup_cover_paths);
    foreach (merge_collect_duplicate_cover_candidates($dup_ids) as $rel_path) {
        $cleanup_candidates[$rel_path] = $rel_path;
    }

    $deleted_paths = [];
    $cleanup_errors = [];
    foreach ($cleanup_candidates as $rel_path) {
        if (isset($skip_paths[$rel_path])) continue;
        try {
            merge_safe_delete_cover_path($pdo, $rel_path, $deleted_paths);
        } catch (Throwable $cleanup_error) {
            // Merge is already committed; cleanup failures are non-fatal.
            $cleanup_errors[] = $rel_path . ': ' . $cleanup_error->getMessage();
        }
    }

    log_auth_event('book_merge_dup', (int)$me['uid'], (string)$me['username'], [
        'master_book_id' => $master_id,
        'merged_book_ids' => $dup_ids,
        'copy_count_old' => $old_count,
        'copy_count_new' => $new_count,
        'differences_detected' => $differences_detected,
        'difference_fields' => $diff_fields,
        'deleted_cover_paths' => $deleted_paths,
        'cover_cleanup_errors' => $cleanup_errors,
    ]);

    json_out([
        'ok' => true,
        'data' => [
            'masterBookId' => $master_id,
            'removedBookIds' => $dup_ids,
            'oldCopyCount' => $old_count,
            'newCopyCount' => $new_count,
            'differencesDetected' => $differences_detected,
            'differenceFields' => $diff_fields,
            'deletedCoverPaths' => $deleted_paths,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $code = (int)$e->getCode();
    if ($e instanceof InvalidArgumentException) $code = 400;
    if ($e instanceof OutOfBoundsException) $code = 404;
    if ($code < 400 || $code > 599) $code = 500;
    json_fail($e->getMessage(), $code);
}
