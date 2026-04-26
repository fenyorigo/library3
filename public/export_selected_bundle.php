<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

ini_set('memory_limit', '512M');
set_time_limit(600);
ignore_user_abort(true);

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
    if ($os_release !== false && preg_match('/^ID=([a-z0-9._-]+)$/mi', $os_release, $m)) {
        if (strtolower($m[1]) === 'fedora') $os_label = 'fedora';
    }
} else {
    $os_label = strtolower(PHP_OS_FAMILY);
}

$app_version = '';
$pkg_path = dirname(__DIR__) . '/frontend/package.json';
$pkg_raw = @file_get_contents($pkg_path);
if ($pkg_raw !== false) {
    $pkg = json_decode($pkg_raw, true);
    if (is_array($pkg) && !empty($pkg['version'])) {
        $app_version = 'v' . $pkg['version'];
    }
}

$timestamp_in = trim((string)($_GET['ts'] ?? ''));
$timestamp = preg_match('/^\d{8}_\d{6}$/', $timestamp_in) ? $timestamp_in : date('Ymd_His');

$q       = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$sort_in = strtolower((string)($_GET['sort'] ?? 'title'));
$dir_in  = strtolower((string)($_GET['dir'] ?? 'asc'));
$dir_sql = ($dir_in === 'desc') ? 'DESC' : 'ASC';

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
            . "    AND (a.name LIKE :t{$i}_an "
            . "         OR a.first_name LIKE :t{$i}_afn "
            . "         OR a.last_name LIKE :t{$i}_aln "
            . "         OR a.sort_name LIKE :t{$i}_asn)"
            . ")"
            . ")";
        foreach ($ph as $k => $v) $params[$k] = $v;
    }
}
$where_sql = $where_chunks ? ('WHERE ' . implode(' AND ', $where_chunks)) : '';

$sql = "
SELECT
  b.book_id AS id,
  b.title, b.subtitle, b.series,
  b.copy_count,
  b.year_published,
  b.isbn, b.lccn,
  b.notes,
  b.loaned_to, b.loaned_date,
  b.cover_image, b.cover_thumb,
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

$st = $pdo->prepare($sql);
foreach ($params as $k => $v) $st->bindValue(':' . $k, $v, PDO::PARAM_STR);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$book_count = count($rows);

if (!class_exists('ZipArchive')) {
    json_fail('ZipArchive not available in PHP runtime', 500);
}

$tmp_candidates = [sys_get_temp_dir(), '/tmp', __DIR__];
if ($server_side && $backup_dir !== '') array_unshift($tmp_candidates, $backup_dir);
$tmp_root = '';
foreach ($tmp_candidates as $dir) {
    if (is_dir($dir) && is_writable($dir)) {
        $tmp_root = $dir;
        break;
    }
}
if ($tmp_root === '') {
    json_fail('No writable temp directory available for zip', 500);
}

$tmp_zip_path = rtrim($tmp_root, '/\\') . '/bookcatalog_selected_export_' . $timestamp . '.zip';
$zip = new ZipArchive();
if ($zip->open($tmp_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    json_fail('Zip open failed', 500);
}

// CSV inside zip
$csv_path = rtrim($tmp_root, '/\\') . '/bookcatalog_selected_export_' . $timestamp . '.csv';
$csv = fopen($csv_path, 'w');
if ($csv === false) {
    $zip->close();
    @unlink($tmp_zip_path);
    json_fail('Failed to create temporary CSV', 500);
}
fputcsv($csv, [
    'ID', 'Title', 'Subtitle', 'Series', 'Copy Count', 'Year', 'ISBN', 'LCCN', 'Notes',
    'Publisher', 'Authors', 'Subjects', 'Loaned To', 'Loaned Date',
    'Bookcase', 'Shelf', 'Cover Image', 'Cover Filename'
], ',', '"', "\\");
foreach ($rows as $r) {
    $cover_fn = $r['cover_image'] ? basename((string)$r['cover_image']) : '';
    $line = [
        $r['id'],
        $r['title'],
        $r['subtitle'],
        $r['series'],
        $r['copy_count'] ?? 1,
        $r['year_published'],
        $r['isbn'],
        $r['lccn'],
        $r['notes'],
        $r['publisher'],
        $r['authors'],
        $r['subjects'],
        $r['loaned_to'],
        $r['loaned_date'],
        $r['bookcase_no'],
        $r['shelf_no'],
        $r['cover_image'],
        $cover_fn,
    ];
    $line = array_map('sanitize_csv_value', $line);
    fputcsv($csv, $line, ',', '"', "\\");
}
fclose($csv);
$zip->addFile($csv_path, 'data/books.csv');

// Cover files only for selected/filtered books
$uploads_dir = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
$cover_paths = [];
foreach ($rows as $r) {
    $book_id = (int)($r['id'] ?? 0);
    if ($book_id > 0) {
        $cover_paths['uploads/' . $book_id . '/cover.jpg'] = true;
        $cover_paths['uploads/' . $book_id . '/cover.jpeg'] = true;
        $cover_paths['uploads/' . $book_id . '/cover.png'] = true;
        $cover_paths['uploads/' . $book_id . '/cover.webp'] = true;
        $cover_paths['uploads/' . $book_id . '/cover.gif'] = true;
        $cover_paths['uploads/' . $book_id . '/cover-thumb.jpg'] = true;
        $cover_paths['uploads/' . $book_id . '/cover-thumb.jpeg'] = true;
        $cover_paths['uploads/' . $book_id . '/cover-thumb.png'] = true;
        $cover_paths['uploads/' . $book_id . '/cover-thumb.webp'] = true;
        $cover_paths['uploads/' . $book_id . '/cover-thumb.gif'] = true;
    }

    $ci = trim((string)($r['cover_image'] ?? ''));
    $ct = trim((string)($r['cover_thumb'] ?? ''));
    if ($ci !== '' && strpos($ci, 'uploads/') === 0) $cover_paths[$ci] = true;
    if ($ct !== '' && strpos($ct, 'uploads/') === 0) $cover_paths[$ct] = true;
}
// Include default cover for portability (both known filenames).
$cover_paths['uploads/default-cover.jpg'] = true;
$cover_paths['uploads/default_cover.jpg'] = true;

$cover_book_ids = [];
foreach (array_keys($cover_paths) as $rel) {
    $rel_clean = ltrim(str_replace('\\', '/', $rel), '/');
    if (strpos($rel_clean, 'uploads/') !== 0) continue;
    $abs = __DIR__ . '/' . $rel_clean;
    if (!is_file($abs) || !is_readable($abs)) continue;
    $zip->addFile($abs, $rel_clean);
    // Logical cover count for filename: actual book covers only (no thumbs/default).
    if (
        preg_match('#^uploads/(\d+)/#', $rel_clean, $m)
        && strpos($rel_clean, '/cover-thumb.') === false
        && !preg_match('#^uploads/default[-_]cover\.jpg$#', $rel_clean)
    ) {
        $cover_book_ids[(int)$m[1]] = true;
    }
}
// Keep naming intuitive: number of covers, not number of uploaded image files.
$cover_count = count($cover_book_ids);

$generated_at = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
$readme = <<<TXT
BookCatalog – Selected export (CSV + covers)
Generated: {$generated_at}
Timestamp key: {$timestamp}

Includes:
- data/books.csv (filtered by current search)
- uploads/... cover files referenced by exported rows
- uploads/default-cover.jpg or uploads/default_cover.jpg (if present)
TXT;
$zip->addFromString('README.txt', $readme);
$zip->close();

@unlink($csv_path);

$schema_version = 'schema' . SCHEMA_VERSION;
$suffix_parts = array_filter([$os_label, $db_vendor, $app_version, $schema_version]);
$suffix = $suffix_parts ? '_' . implode('_', $suffix_parts) : '';
$export_name = "export_{$book_count}_books_{$cover_count}_covers_{$timestamp}{$suffix}.zip";
$export_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $export_name);

if ($server_side) {
    $final_path = rtrim($backup_dir, "/\\") . '/' . $export_name;
    if (!@rename($tmp_zip_path, $final_path)) {
        @unlink($tmp_zip_path);
        json_fail('Failed to move zip into backup directory', 500);
    }
    clearstatcache(true, $final_path);
    $size_bytes = is_file($final_path) ? (int)filesize($final_path) : 0;
    error_log(sprintf(
        'BookCatalog backup completed: type=%s mode=%s file=%s size=%d bytes',
        'selected_bundle',
        'server',
        $export_name ?? '-',
        $size_bytes
    ));
    json_out([
        'ok' => true,
        'mode' => 'server',
        'dir' => $backup_dir,
        'filename' => $export_name,
        'path' => $final_path,
    ]);
}

clearstatcache(true, $tmp_zip_path);
if (!is_file($tmp_zip_path) || !is_readable($tmp_zip_path)) {
    json_fail('Zip missing or unreadable', 500);
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $export_name . '"');
header('Content-Length: ' . filesize($tmp_zip_path));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
while (ob_get_level() > 0) ob_end_clean();
$fh = fopen($tmp_zip_path, 'rb');
if ($fh === false) {
    json_fail('Failed to open zip for download', 500);
}
while (!feof($fh)) {
    $chunk = fread($fh, 1048576);
    if ($chunk === false) break;
    echo $chunk;
}
fclose($fh);
@unlink($tmp_zip_path);
