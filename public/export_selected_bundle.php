<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$selected_export_cli_job = PHP_SAPI === 'cli' && getenv('BOOKCATALOG_SELECTED_EXPORT_JOB') === '1';
if ($selected_export_cli_job) {
    $query_json = getenv('BOOKCATALOG_SELECTED_EXPORT_QUERY');
    $query = $query_json !== false ? json_decode((string)$query_json, true) : null;
    if (is_array($query)) $_GET = $query;
} else {
    require __DIR__ . '/auth.php';
    require_admin();
}

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

set_time_limit(600);
ignore_user_abort(true);

$backup_status = catalog_backup_dir_status();
$check_mode = isset($_GET['check']) && $_GET['check'] === '1';

function selected_export_job_dir(string $backup_dir): string {
    $dir = rtrim($backup_dir, "/\\") . '/.bookcatalog_jobs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function selected_export_job_token(): string {
    return bin2hex(random_bytes(16));
}

function selected_export_job_path(string $backup_dir, string $token): string {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new InvalidArgumentException('Invalid export job token');
    }
    return selected_export_job_dir($backup_dir) . '/selected_export_' . $token . '.json';
}

function selected_export_write_job(string $path, array $data): void {
    $data['updated_at'] = date(DateTimeInterface::ATOM);
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function selected_export_read_job(string $path): array {
    if (!is_file($path)) throw new RuntimeException('Export job not found');
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) throw new RuntimeException('Invalid export job status');
    return $data;
}

function selected_export_cli_binary(): string {
    $candidates = [];
    $bindir = defined('PHP_BINDIR') ? (string)PHP_BINDIR : '';
    if ($bindir !== '') $candidates[] = rtrim($bindir, '/\\') . '/php';
    $candidates[] = '/opt/homebrew/bin/php';
    $candidates[] = '/usr/local/bin/php';
    $candidates[] = '/usr/bin/php';
    foreach (array_values(array_unique($candidates)) as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) return $candidate;
    }
    return 'php';
}

function selected_export_log_error(?string $log_path): ?string {
    if (!$log_path || !is_file($log_path)) return null;
    $log = trim((string)file_get_contents($log_path));
    if ($log === '') return null;
    if (stripos($log, 'Usage: php-fpm') !== false) {
        return 'Background job tried to run php-fpm instead of PHP CLI. Please retry with the updated export runner.';
    }
    if (stripos($log, 'Fatal error') !== false || stripos($log, 'Parse error') !== false || stripos($log, 'Uncaught') !== false) {
        return mb_substr($log, 0, 1000, 'UTF-8');
    }
    return null;
}

if ($check_mode) {
    if (!$backup_status['enabled']) {
        json_out(['ok' => true, 'mode' => 'stream']);
    }
    if ($backup_status['status'] !== 'ready') {
        json_fail(catalog_backup_dir_error($backup_status), 500);
    }
    json_out(['ok' => true, 'mode' => 'server_async', 'dir' => $backup_status['dir']]);
}

if ($backup_status['enabled'] && $backup_status['status'] !== 'ready') {
    json_fail(catalog_backup_dir_error($backup_status), 500);
}

$server_side = $backup_status['enabled'] && $backup_status['status'] === 'ready';
$backup_dir = $backup_status['dir'] ?? '';

if (!$selected_export_cli_job && $server_side) {
    $action = (string)($_GET['action'] ?? '');
    if ($action === 'start_async') {
        $token = selected_export_job_token();
        $job_path = selected_export_job_path($backup_dir, $token);
        $log_path = selected_export_job_dir($backup_dir) . '/selected_export_' . $token . '.log';
        $query = $_GET;
        unset($query['action'], $query['check'], $query['token']);
        selected_export_write_job($job_path, [
            'ok' => true,
            'status' => 'running',
            'token' => $token,
            'started_at' => date(DateTimeInterface::ATOM),
            'log_path' => $log_path,
            'message' => 'Selected export is running on the server.',
        ]);

        $env_parts = [
            'BOOKCATALOG_SELECTED_EXPORT_JOB=1',
            'BOOKCATALOG_SELECTED_EXPORT_STATUS=' . escapeshellarg($job_path),
            'BOOKCATALOG_SELECTED_EXPORT_QUERY=' . escapeshellarg(json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
        ];
        $config = getenv('BOOKCATALOG_CONFIG');
        if ($config !== false && trim((string)$config) !== '') {
            $env_parts[] = 'BOOKCATALOG_CONFIG=' . escapeshellarg((string)$config);
        }
        $catalog_backup_dir = getenv('CATALOG_BACKUP_DIR');
        if ($catalog_backup_dir !== false && trim((string)$catalog_backup_dir) !== '') {
            $env_parts[] = 'CATALOG_BACKUP_DIR=' . escapeshellarg((string)$catalog_backup_dir);
        }
        $cmd = implode(' ', $env_parts)
            . ' ' . escapeshellarg(selected_export_cli_binary())
            . ' ' . escapeshellarg(__FILE__)
            . ' > ' . escapeshellarg($log_path)
            . ' 2>&1 &';
        exec($cmd, $out, $code);
        if ($code !== 0) {
            selected_export_write_job($job_path, [
                'ok' => false,
                'status' => 'error',
                'token' => $token,
                'started_at' => date(DateTimeInterface::ATOM),
                'error' => 'Failed to start selected export background job.',
            ]);
            json_fail('Failed to start selected export background job', 500);
        }
        json_out(['ok' => true, 'mode' => 'server_async', 'token' => $token, 'status' => 'running']);
    }

    if ($action === 'status') {
        $token = (string)($_GET['token'] ?? '');
        $job_path = selected_export_job_path($backup_dir, $token);
        $job = selected_export_read_job($job_path);
        if (($job['status'] ?? '') === 'running') {
            $log_path = $job['log_path'] ?? (selected_export_job_dir($backup_dir) . '/selected_export_' . $token . '.log');
            $log_error = selected_export_log_error($log_path);
            if ($log_error !== null) {
                $job['ok'] = false;
                $job['status'] = 'error';
                $job['error'] = $log_error;
                $job['log_path'] = $log_path;
                selected_export_write_job($job_path, $job);
            }
        }
        json_out(['ok' => true, 'mode' => 'server_async', 'job' => $job]);
    }
}

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
$format_in = strtolower(trim((string)($_GET['format'] ?? '')));
$language_in = strtolower(trim((string)($_GET['language'] ?? '')));
$record_status_in = strtolower(trim((string)($_GET['record_status'] ?? 'active')));
$record_status_filter = in_array($record_status_in, ['active', 'deleted', 'all'], true) ? $record_status_in : 'active';
$sort_in = strtolower((string)($_GET['sort'] ?? 'title'));
$dir_in  = strtolower((string)($_GET['dir'] ?? 'asc'));
$dir_sql = ($dir_in === 'desc') ? 'DESC' : 'ASC';

$supported_formats = ['print', 'ebooks', 'epub', 'mobi', 'azw3', 'pdf', 'djvu', 'lit', 'prc', 'rtf', 'odt'];
$format_filter = in_array($format_in, $supported_formats, true) ? $format_in : '';
$language_filter = $language_in !== '' ? normalize_book_language($language_in) : '';

$sortable = [
    'id'        => 'b.book_id',
    'title'     => 'b.title',
    'subtitle'  => 'b.subtitle',
    'series'    => 'b.series',
    'publisher' => 'p.name',
    'year'      => 'b.year_published',
    'authors'   => "CASE WHEN authors IS NULL THEN 1 ELSE 0 END, authors",
    'bookcase'  => 'pl.bookcase_no',
    'notes'     => 'b.notes',
];
$order_by = $sortable[$sort_in] ?? $sortable['title'];
$order_sql = $order_by . ' ' . $dir_sql . ', b.book_id ASC';
if ($sort_in === 'bookcase') {
    $order_sql = 'CASE WHEN pl.placement_id IS NULL THEN 1 ELSE 0 END ASC, '
        . 'pl.bookcase_no ' . $dir_sql . ', '
        . 'pl.shelf_no ' . $dir_sql . ', '
        . 'b.book_id ASC';
}

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
if (books_table_has_record_status($pdo)) {
    if ($record_status_filter === 'deleted') {
        $where_chunks[] = "b.record_status = 'deleted'";
    } elseif ($record_status_filter === 'active') {
        $where_chunks[] = "b.record_status = 'active'";
    }
}
if ($format_filter === 'ebooks') {
    $where_chunks[] = "EXISTS (
        SELECT 1 FROM BookCopies bcf
        WHERE bcf.book_id = b.book_id
          AND bcf.format <> 'print'
    )";
} elseif ($format_filter !== '') {
    $where_chunks[] = "EXISTS (
        SELECT 1 FROM BookCopies bcf
        WHERE bcf.book_id = b.book_id
          AND bcf.format = :format_filter
    )";
    $params['format_filter'] = $format_filter;
}
if ($language_filter !== '') {
    $where_chunks[] = "b.language = :language_filter";
    $params['language_filter'] = $language_filter;
}
$where_sql = $where_chunks ? ('WHERE ' . implode(' AND ', $where_chunks)) : '';

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
ORDER BY $order_sql
";

$st = $pdo->prepare($sql);
foreach ($params as $k => $v) $st->bindValue(':' . $k, $v, PDO::PARAM_STR);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$book_ids = array_map(static fn (array $row): int => (int)$row['id'], $rows);
$copy_map = fetch_book_copies_map($pdo, $book_ids);
$authors_metadata_map = fetch_book_authors_metadata_map($pdo, $book_ids);
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
    'ID', 'Title', 'Subtitle', 'Series', 'Language', 'Copy Count', 'Year', 'ISBN', 'LCCN', 'Notes',
    'Publisher', 'Authors', 'Authors Metadata JSON', 'Subjects', 'Loaned To', 'Loaned Date', 'Record Status',
    'Bookcase', 'Shelf', 'Cover Image', 'Cover Filename', 'Copies JSON'
], ',', '"', "\\");
foreach ($rows as $r) {
    $copies = $copy_map[(int)$r['id']] ?? [];
    $authors_metadata_json = build_authors_metadata_json($authors_metadata_map[(int)$r['id']] ?? []);
    $cover_fn = $r['cover_image'] ? basename((string)$r['cover_image']) : '';
    $line = [
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
        $authors_metadata_json,
        $r['subjects'],
        $r['loaned_to'],
        $r['loaned_date'],
        normalize_book_record_status($r['record_status'] ?? 'active'),
        $r['bookcase_no'],
        $r['shelf_no'],
        $r['cover_image'],
        $cover_fn,
        json_encode($copies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
$cover_paths['uploads/.htaccess'] = true;
$cover_paths['uploads/default-cover.jpg'] = true;
$cover_paths['uploads/default_cover.jpg'] = true;

$cover_book_ids = [];
foreach (array_keys($cover_paths) as $rel) {
    $rel_clean = ltrim(str_replace('\\', '/', $rel), '/');
    if (strpos($rel_clean, 'uploads/') !== 0) continue;
    if (archive_should_skip_path($rel_clean)) continue;
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
- uploads/.htaccess (if present)
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
    $job_status_path = getenv('BOOKCATALOG_SELECTED_EXPORT_STATUS');
    if ($selected_export_cli_job && $job_status_path !== false && trim((string)$job_status_path) !== '') {
        selected_export_write_job((string)$job_status_path, [
            'ok' => true,
            'status' => 'complete',
            'completed_at' => date(DateTimeInterface::ATOM),
            'mode' => 'server',
            'dir' => $backup_dir,
            'filename' => $export_name,
            'path' => $final_path,
            'size_bytes' => $size_bytes,
        ]);
    }
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
