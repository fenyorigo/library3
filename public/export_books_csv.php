<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
$me = require_login();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$backup_status = catalog_backup_dir_status();
$check_mode = isset($_GET['check']) && $_GET['check'] === '1';

if ($check_mode) {
    if (!$backup_status['enabled']) {
        json_out(['ok' => true, 'mode' => 'stream']);
    }
    if ($backup_status['status'] !== 'ready') {
        json_fail(catalog_backup_dir_error($backup_status), 500);
    }
    json_out(['ok' => true, 'mode' => 'server', 'dir' => $backup_status['dir']]);
}

if ($backup_status['enabled'] && $backup_status['status'] !== 'ready') {
    json_fail(catalog_backup_dir_error($backup_status), 500);
}

$server_side = $backup_status['enabled'] && $backup_status['status'] === 'ready';
$backup_dir = $backup_status['dir'] ?? '';

$pdo = pdo();

try {
    $server_version = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $driver_name = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
} catch (Throwable $e) {
    $server_version = '';
    $driver_name = '';
}

if ($driver_name === 'mysql') {
    $db_vendor = stripos($server_version, 'mariadb') !== false ? 'mariadb' : 'mysql';
} else {
    $db_vendor = $driver_name ?: 'db';
}

if (PHP_OS_FAMILY === 'Darwin') {
    $os_label = 'macos';
} elseif (PHP_OS_FAMILY === 'Linux') {
    $os_label = 'linux';
    $os_release = @file_get_contents('/etc/os-release');
    if ($os_release !== false) {
        if (preg_match('/^ID=([a-z0-9._-]+)$/mi', $os_release, $m)) {
            if (strtolower($m[1]) === 'fedora') {
                $os_label = 'fedora';
            }
        }
    }
} else {
    $os_label = strtolower(PHP_OS_FAMILY);
}

// Default to frontend package.json version when available.
$app_version = '';
$pkg_path = dirname(__DIR__) . '/frontend/package.json';
$pkg_raw = @file_get_contents($pkg_path);
if ($pkg_raw !== false) {
    $pkg = json_decode($pkg_raw, true);
    if (is_array($pkg) && !empty($pkg['version'])) {
        $app_version = 'v' . $pkg['version'];
    }
}

try {
    $total_books = (int)$pdo->query("SELECT COUNT(*) FROM Books")->fetchColumn();
} catch (Throwable $e) {
    $total_books = 0;
}

$timestamp = date('Ymd_His');
$schema_version = 'schema' . SCHEMA_VERSION;
$suffix_parts = array_filter([$os_label, $db_vendor, $app_version, $schema_version]);
$suffix = $suffix_parts ? '_' . implode('_', $suffix_parts) : '';
$export_name = "export_{$total_books}_books_{$timestamp}{$suffix}.csv";
$export_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $export_name);

if (!$server_side) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $export_name . '"');
    header('Cache-Control: no-store');
}

/**
 * Inputs:
 * - q, sort, dir  (same logic as list_books.php; sort allows series)
 */
$q      = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$is_admin = (($me['role'] ?? '') === 'admin');
$record_status_in = strtolower(trim((string)($_GET['record_status'] ?? 'active')));
$record_status_filter = 'active';
if ($is_admin && in_array($record_status_in, ['active', 'deleted', 'all'], true)) {
    $record_status_filter = $record_status_in;
}
$sort_in = strtolower((string)($_GET['sort'] ?? 'title'));
$dir_in  = strtolower((string)($_GET['dir']  ?? 'asc'));
$dir_sql = ($dir_in === 'desc') ? 'DESC' : 'ASC';

/** Sorting whitelist */
$sortable = [
    'id'        => 'b.book_id',
    'title'     => 'b.title',
    'subtitle'  => 'b.subtitle',
    'series'    => 'b.series',
    'publisher' => 'p.name',
    'year'      => 'b.year_published',
    'authors'   => "CASE WHEN authors IS NULL THEN 1 ELSE 0 END, authors",
    'bookcase'  => 'pl.bookcase_no, pl.shelf_no',
    'notes'     => 'b.notes',
];
$order_by = $sortable[$sort_in] ?? $sortable['title'];

/** WHERE conditions */
$where_chunks = [];
$params = [];

if ($q !== '') {
    $tokens = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    foreach ($tokens as $i => $tok) {
        $like = '%' . $tok . '%';

        $ph = [
            "t{$i}_title"    => $like,
            "t{$i}_subtitle" => $like,
            "t{$i}_series"   => $like,
            "t{$i}_isbn"     => $like,
            "t{$i}_lccn"     => $like,
            "t{$i}_pub"      => $like,
            "t{$i}_an"       => $like,
            "t{$i}_afn"      => $like,
            "t{$i}_aln"      => $like,
            "t{$i}_asn"      => $like,
        ];

        $where_chunks[] = "("
            . "b.title LIKE :t{$i}_title OR "
            . "b.subtitle LIKE :t{$i}_subtitle OR "
            . "b.series LIKE :t{$i}_series OR "
            . "b.isbn LIKE :t{$i}_isbn OR "
            . "b.lccn LIKE :t{$i}_lccn OR "
            . "p.name LIKE :t{$i}_pub OR "
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

        foreach ($ph as $k => $v) { $params[$k] = $v; }
    }
}

if (books_table_has_record_status($pdo)) {
    if ($record_status_filter === 'deleted') {
        $where_chunks[] = "b.record_status = 'deleted'";
    } elseif ($record_status_filter === 'active') {
        $where_chunks[] = "b.record_status = 'active'";
    }
}

$where_sql = $where_chunks ? ('WHERE ' . implode(' AND ', $where_chunks)) : '';

/** MAIN SELECT */
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
  b.loaned_to, b.loaned_date,
  b.cover_image,
  p.name AS publisher,
  (
    SELECT GROUP_CONCAT(DISTINCT
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
             ORDER BY ba.author_ord SEPARATOR '; ')
      FROM Books_Authors ba
      JOIN Authors a ON a.author_id = ba.author_id
     WHERE ba.book_id = b.book_id
  ) AS authors,
  (
    SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR '; ')
      FROM Books_Subjects bs
      JOIN Subjects s ON s.subject_id = bs.subject_id
     WHERE bs.book_id = b.book_id
  ) AS subjects,
  pl.bookcase_no,
  pl.shelf_no
FROM Books b
LEFT JOIN Publishers p ON p.publisher_id = b.publisher_id
LEFT JOIN Placement  pl ON pl.placement_id = b.placement_id
$where_sql
ORDER BY $order_by $dir_sql, b.book_id ASC
";

try {
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) { $st->bindValue(':' . $k, $v, PDO::PARAM_STR); }
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $copy_map = fetch_book_copies_map($pdo, array_map(static fn (array $row): int => (int)$row['id'], $rows));

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage();
    exit;
}

/** CSV output */
$out_path = $server_side ? rtrim($backup_dir, "/\\") . '/' . $export_name : '';
$out = $server_side
    ? fopen($out_path, 'w')
    : fopen('php://output', 'w');

if ($out === false) {
    json_fail('Failed to open export target for writing', 500);
}

/* Fix PHP 8.1+ deprecation: explicitly pass escape char */
$bytes_written = 0;
$bytes = fputcsv($out, [
    'ID', 'Title', 'Subtitle', 'Series', 'Language', 'Copy Count', 'Year', 'ISBN', 'LCCN', 'Notes',
    'Publisher', 'Authors', 'Subjects', 'Loaned To', 'Loaned Date', 'Record Status',
    'Bookcase', 'Shelf', 'Cover Image', 'Cover Filename', 'Copies JSON'
], ',', '"', "\\");
$bytes_written += is_int($bytes) ? $bytes : 0;

foreach ($rows as $r) {
    $copies = $copy_map[(int)$r['id']] ?? [];
    $cover_fn = $r['cover_image'] ? basename($r['cover_image']) : '';

    $row = [
        $r['id'],
        $r['title'],
        $r['subtitle'],
        $r['series'],
        normalize_book_language($r['language'] ?? 'unknown'),
        total_book_copy_quantity($copies, (int)($r['copy_count'] ?? 1)),
        $r['year_published'],
        $r['isbn'],
        $r['lccn'],
        $r['notes'],
        $r['publisher'],
        $r['authors'],
        $r['subjects'],
        $r['loaned_to'],
        $r['loaned_date'],
        normalize_book_record_status($r['record_status'] ?? 'active'),
        $r['bookcase_no'],
        $r['shelf_no'],
        $r['cover_image'],
        $cover_fn,                    // <--- NEW FINAL COLUMN
        json_encode($copies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $row = array_map('sanitize_csv_value', $row);
    $bytes = fputcsv($out, $row, ',', '"', "\\");
    $bytes_written += is_int($bytes) ? $bytes : 0;
}

fclose($out);

if ($server_side) {
    clearstatcache(true, $out_path);
    $size_bytes = is_file($out_path) ? (int)filesize($out_path) : $bytes_written;
    error_log(sprintf(
        'BookCatalog backup completed: type=%s mode=%s file=%s size=%d bytes',
        'csv',
        'server',
        $export_name ?? '-',
        $size_bytes
    ));
    json_out([
        'ok' => true,
        'mode' => 'server',
        'dir' => $backup_dir,
        'filename' => $export_name,
        'path' => $out_path,
    ]);
}

error_log(sprintf(
    'BookCatalog backup completed: type=%s mode=%s file=%s size=%d bytes',
    'csv',
    'download',
    $export_name ?? '-',
    $bytes_written
));
