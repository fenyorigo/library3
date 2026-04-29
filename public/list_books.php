<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
$me = require_login();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');

$pdo = pdo();

/**
 * Inputs
 * - q:         free-text search (tokenized on whitespace)
 * - format:    exact BookCopies.format filter
 * - record_status: active|deleted|all (admins only for deleted/all)
 * - page:      1-based page index
 * - per:       page size (alias used by frontend)
 * - sort:      one of: id|title|subtitle|series|publisher|language|format|record_status|year|copy_count|authors|authors_hu|bookcase|cover|status|isbn|loaned_to|loaned_date|subjects|notes
 * - dir:       asc|desc (default: desc)
 */
$q        = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$format_filter_raw = strtolower(trim((string)($_GET['format'] ?? '')));
$allowed_formats = ['print', 'epub', 'mobi', 'azw3', 'pdf', 'djvu', 'lit', 'prc', 'rtf', 'odt'];
$format_filter = in_array($format_filter_raw, $allowed_formats, true) ? $format_filter_raw : null;
$is_admin = (($me['role'] ?? '') === 'admin');
$record_status_in = strtolower(trim((string)($_GET['record_status'] ?? 'active')));
$record_status_filter = 'active';
if ($is_admin && in_array($record_status_in, ['active', 'deleted', 'all'], true)) {
  $record_status_filter = $record_status_in;
}
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = max(1, min(200, (int)($_GET['per'] ?? 25)));
$sort_in   = strtolower((string)($_GET['sort'] ?? 'id'));
$dir_in    = strtolower((string)($_GET['dir']  ?? 'desc'));
$dir_sql   = ($dir_in === 'asc') ? 'ASC' : 'DESC';
$offset   = ($page - 1) * $limit;

/**
 * Sorting whitelist (avoid SQL injection).
 * - authors: sort by computed column; NULLS LAST via CASE for MySQL 5.7+ compatibility
 * - bookcase: requires Placement join
 */
$sortable = [
  'id'        => 'b.book_id',
  'title'     => 'b.title',
 'subtitle'  => 'b.subtitle',
 'series'    => 'b.series',
 'publisher' => 'p.name',
 'language'  => 'b.language',
 'format'    => "CASE WHEN format_sort IS NULL OR format_sort = '' THEN 1 ELSE 0 END, format_sort",
 'record_status' => 'b.record_status',
 'year'      => 'b.year_published',
 'copy_count'=> 'b.copy_count',
 'authors'   => "CASE WHEN authors IS NULL THEN 1 ELSE 0 END, authors",
 'authors_hu'=> "CASE WHEN authors_hu_flag IS NULL THEN 1 ELSE 0 END, authors_hu_flag",
 'bookcase'  => 'pl.bookcase_no, pl.shelf_no',
  'cover'     => '(b.cover_image IS NOT NULL AND b.cover_image <> "")',
  'status'    => "CASE WHEN (b.loaned_to IS NOT NULL AND b.loaned_to <> '') OR b.loaned_date IS NOT NULL THEN 1 ELSE 0 END",
  'isbn'      => 'b.isbn',
  'loaned_to' => 'b.loaned_to',
  'loaned_date' => 'b.loaned_date',
  'subjects'  => "CASE WHEN subjects IS NULL THEN 1 ELSE 0 END, subjects",
  'notes'     => 'b.notes',
];
$order_by = $sortable[$sort_in] ?? $sortable['id'];

/**
 * Build WHERE from tokenized q (AND across tokens; each token matches multiple fields)
 * Fields matched: title, subtitle, series, authors (first/last/sort), publisher, isbn, lccn, notes
 *
 * IMPORTANT: Use **unique named placeholders** instead of positional `?` to avoid
 * HY093 mismatches across drivers / emulation settings.
 */
$where_chunks = [];
$params = [];
$tokens = [];

if ($q !== '') {
  $tokens = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  foreach ($tokens as $i => $tok) {
    $like = '%' . $tok . '%';

    // unique placeholder names per token
    $ph = [
      "t{$i}_title"   => $like,
      "t{$i}_subtitle"=> $like,
      "t{$i}_series"  => $like,
      "t{$i}_isbn"    => $like,
      "t{$i}_lccn"    => $like,
      "t{$i}_notes"   => $like,
      "t{$i}_pub"     => $like,
      "t{$i}_format"  => $like,
      "t{$i}_an"      => $like,
      "t{$i}_afn"     => $like,
      "t{$i}_aln"     => $like,
      "t{$i}_asn"     => $like,
    ];

    $where_chunks[] = "("
      . "b.title LIKE :t{$i}_title OR "
      . "b.subtitle LIKE :t{$i}_subtitle OR "
      . "b.series LIKE :t{$i}_series OR "
      . "b.isbn LIKE :t{$i}_isbn OR "
      . "b.lccn LIKE :t{$i}_lccn OR "
      . "b.notes LIKE :t{$i}_notes OR "
      . "p.name LIKE :t{$i}_pub OR "
      . "EXISTS ("
      . "  SELECT 1 FROM BookCopies bcq "
      . "  WHERE bcq.book_id = b.book_id "
      . "    AND bcq.format LIKE :t{$i}_format"
      . ") OR "
      . "EXISTS ("
      . "  SELECT 1 FROM Books_Authors ba "
      . "  JOIN Authors a ON a.author_id = ba.author_id "
      . "  WHERE ba.book_id = b.book_id "
      . "    AND (a.name       LIKE :t{$i}_an "
      . "         OR a.first_name LIKE :t{$i}_afn "
      . "         OR a.last_name  LIKE :t{$i}_aln "
      . "         OR a.sort_name  LIKE :t{$i}_asn)"
      . ")"
      . ")";

    // merge placeholders into $params
    foreach ($ph as $k => $v) { $params[$k] = $v; }
  }
}

if ($format_filter !== null) {
  $where_chunks[] = "EXISTS (
      SELECT 1 FROM BookCopies bcf
      WHERE bcf.book_id = b.book_id
        AND bcf.format = :format_filter
    )";
  $params['format_filter'] = $format_filter;
}

if (books_table_has_record_status($pdo)) {
  if ($record_status_filter === 'deleted') {
    $where_chunks[] = "b.record_status = 'deleted'";
  } elseif ($record_status_filter === 'active') {
    $where_chunks[] = "b.record_status = 'active'";
  }
}

$where_sql = $where_chunks ? ('WHERE ' . implode(' AND ', $where_chunks)) : '';

/**
 * COUNT (distinct books)
 * Use same FROM/JOINs as main query except computed columns.
 */
$sql_count = "
  SELECT COUNT(*) AS c
  FROM Books b
  LEFT JOIN Publishers p ON p.publisher_id = b.publisher_id
  LEFT JOIN Placement  pl ON pl.placement_id = b.placement_id
  $where_sql
";
try {
    $st = $pdo->prepare($sql_count);
    // Bind named params explicitly to avoid HY093 issues across drivers
    foreach ($params as $k => $v) {
        $st->bindValue(':' . $k, $v, PDO::PARAM_STR);
    }
    $st->execute();
    $total = (int)$st->fetchColumn();
} catch (Throwable $e) {
    json_fail('COUNT query failed: ' . $e->getMessage(), 500);
}

/**
 * MAIN SELECT (one row per book)
 * - cover_thumb is just an alias of cover_image for UI convenience
 * - authors/subjects aggregated via subqueries for stable performance
 */
$safe_limit  = (int)$limit;
$safe_offset = (int)$offset;

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
  b.cover_image,
  b.cover_thumb,
  (b.cover_image IS NOT NULL AND b.cover_image <> '') AS has_cover,
  b.loaned_to,
  b.loaned_date,
  CASE
    WHEN (b.loaned_to IS NOT NULL AND b.loaned_to <> '') OR b.loaned_date IS NOT NULL
      THEN 'Loaned'
    ELSE 'In collection'
  END AS loan_status,
  p.name AS publisher,
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
           /* If you have ba.author_ord, keep ORDER BY; else remove the line */
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
  SELECT GROUP_CONCAT(
           DISTINCT bc.format
           ORDER BY FIELD(
             bc.format,
             'print', 'epub', 'mobi', 'azw3', 'pdf', 'djvu', 'lit', 'prc', 'rtf', 'odt'
           ), bc.format
           SEPARATOR ', '
         )
    FROM BookCopies bc
   WHERE bc.book_id = b.book_id
) AS format_sort,
(
  SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR '; ')
    FROM Books_Subjects bs
    JOIN Subjects s ON s.subject_id = bs.subject_id
   WHERE bs.book_id = b.book_id
) AS subjects
FROM Books b
LEFT JOIN Publishers p ON p.publisher_id = b.publisher_id
LEFT JOIN Placement  pl ON pl.placement_id = b.placement_id
$where_sql
ORDER BY $order_by $dir_sql, b.book_id DESC
LIMIT $safe_limit OFFSET $safe_offset
";
try {
    $st = $pdo->prepare($sql);
    // Bind named params explicitly to avoid HY093 issues across drivers
    foreach ($params as $k => $v) {
        $st->bindValue(':' . $k, $v, PDO::PARAM_STR);
    }
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $copy_map = fetch_book_copies_map($pdo, array_map(static fn (array $row): int => (int)$row['id'], $rows));
    foreach ($rows as &$row) {
        $row['has_cover'] = !empty($row['cover_image']);
        $row['record_status'] = normalize_book_record_status($row['record_status'] ?? 'active');
        $row['language'] = normalize_book_language($row['language'] ?? 'unknown');
        $row['copies'] = $copy_map[(int)$row['id']] ?? [];
        $row['copy_count'] = total_book_copy_quantity($row['copies'], (int)($row['copy_count'] ?? 1));
        $row['format_summary'] = summarize_book_formats($row['copies']);
    }
    unset($row);
} catch (Throwable $e) {
    json_fail('MAIN query failed: ' . $e->getMessage(), 500);
}

// Output JSON
json_out([
  'ok' => true,
  'data' => $rows,
  'meta' => [
    'total' => $total,
    'page' => $page,
    'per_page' => $limit,
  ],
]);
