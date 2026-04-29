<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
$me = require_login();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');

try {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_fail('Missing or invalid book id', 400);
    }

    $pdo = pdo();

    $sql = "
    SELECT
      b.book_id AS id,
      b.title, b.subtitle, b.series,
      " . (books_table_has_record_status($pdo) ? "b.record_status," : "'active' AS record_status,") . "
      " . (books_table_has_language($pdo) ? "b.language," : "'unknown' AS language,") . "
      b.copy_count,
      b.year_published,
      b.isbn, b.lccn,
      b.notes,
      b.cover_image, b.cover_thumb,
      (b.cover_image IS NOT NULL AND b.cover_image <> '') AS has_cover,
      b.loaned_to,
      b.loaned_date,
      CASE
        WHEN (b.loaned_to IS NOT NULL AND b.loaned_to <> '') OR b.loaned_date IS NOT NULL
          THEN 'Loaned'
        ELSE 'In collection'
      END AS loan_status,
      b.publisher_id,
      p.name AS publisher,
      b.placement_id,
      pl.bookcase_no, pl.shelf_no,
    (
      SELECT GROUP_CONCAT(
               DISTINCT
               NULLIF(
                 TRIM(
                   COALESCE(
                     a.name,
                     CASE
                       WHEN a.is_hungarian = 1
                         THEN CONCAT(COALESCE(a.last_name,''),' ',COALESCE(a.first_name,''))
                       ELSE CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,''))
                     END
                   )
                 ), ''
               )
               ORDER BY ba.author_ord
               SEPARATOR '; '
             )
        FROM Books_Authors ba
        JOIN Authors a ON a.author_id = ba.author_id
       WHERE ba.book_id = b.book_id
    ) AS authors,
    (
      SELECT GROUP_CONCAT(
               DISTINCT
               CASE WHEN a.is_hungarian = 1 THEN 'HU' ELSE 'No' END
               ORDER BY ba.author_ord
               SEPARATOR '; '
             )
        FROM Books_Authors ba
        JOIN Authors a ON a.author_id = ba.author_id
       WHERE ba.book_id = b.book_id
    ) AS authors_hu,
    (
      SELECT CASE
               WHEN SUM(CASE WHEN a.is_hungarian = 1 THEN 1 ELSE 0 END) > 0
                AND SUM(CASE WHEN a.is_hungarian = 0 THEN 1 ELSE 0 END) > 0
                 THEN NULL
               WHEN SUM(CASE WHEN a.is_hungarian = 1 THEN 1 ELSE 0 END) > 0
                 THEN 1
               ELSE 0
             END
        FROM Books_Authors ba
        JOIN Authors a ON a.author_id = ba.author_id
       WHERE ba.book_id = b.book_id
    ) AS authors_hu_flag,
    (
      SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR '; ')
        FROM Books_Subjects bs
        JOIN Subjects s ON s.subject_id = bs.subject_id
       WHERE bs.book_id = b.book_id
    ) AS subjects
    FROM Books b
    LEFT JOIN Publishers p ON p.publisher_id = b.publisher_id
    LEFT JOIN Placement  pl ON pl.placement_id = b.placement_id
    WHERE b.book_id = :id
    LIMIT 1
    ";

    $st = $pdo->prepare($sql);
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        json_fail('Book not found', 404);
    }

    $record_status = normalize_book_record_status($row['record_status'] ?? 'active');
    $is_admin = (($me['role'] ?? '') === 'admin');
    if ($record_status === 'deleted' && !$is_admin) {
        json_fail('Book not found', 404);
    }

    $row['has_cover'] = !empty($row['cover_image']);
    $row['record_status'] = $record_status;
    $row['language'] = normalize_book_language($row['language'] ?? 'unknown');
    $row['copies'] = fetch_book_copies($pdo, $id);
    $row['copy_count'] = total_book_copy_quantity($row['copies'], (int)($row['copy_count'] ?? 1));
    $row['format_summary'] = summarize_book_formats($row['copies']);

    json_out([
        'ok' => true,
        'data' => $row,
    ]);
} catch (Throwable $e) {
    json_fail($e->getMessage(), 500);
}
