<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$full_backup_cli_job = PHP_SAPI === 'cli' && getenv('BOOKCATALOG_FULL_BACKUP_JOB') === '1';
if (!$full_backup_cli_job) {
    require __DIR__ . '/auth.php';
    require_admin();
}

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

ini_set('memory_limit', '512M');      // or '1G' if you like
set_time_limit(600);                  // give the backup time
ignore_user_abort(true);              // keep running if client disconnects

$backup_status = catalog_backup_dir_status();
$check_mode = isset($_GET['check']) && $_GET['check'] === '1';

function full_backup_job_dir(string $backup_dir): string {
    $dir = rtrim($backup_dir, "/\\") . '/.bookcatalog_jobs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function full_backup_job_token(): string {
    return bin2hex(random_bytes(16));
}

function full_backup_job_path(string $backup_dir, string $token): string {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new InvalidArgumentException('Invalid backup job token');
    }
    return full_backup_job_dir($backup_dir) . '/full_backup_' . $token . '.json';
}

function full_backup_write_job(string $path, array $data): void {
    $data['updated_at'] = date(DateTimeInterface::ATOM);
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function full_backup_read_job(string $path): array {
    if (!is_file($path)) throw new RuntimeException('Backup job not found');
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) throw new RuntimeException('Invalid backup job status');
    return $data;
}

function full_backup_cli_binary(): string {
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

function full_backup_log_error(?string $log_path): ?string {
    if (!$log_path || !is_file($log_path)) return null;
    $log = trim((string)file_get_contents($log_path));
    if ($log === '') return null;
    if (stripos($log, 'Usage: php-fpm') !== false) {
        return 'Background job tried to run php-fpm instead of PHP CLI. Please retry with the updated backup runner.';
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

if (!$full_backup_cli_job && $server_side) {
    $action = (string)($_GET['action'] ?? '');
    if ($action === 'start_async') {
        $token = full_backup_job_token();
        $job_path = full_backup_job_path($backup_dir, $token);
        $log_path = full_backup_job_dir($backup_dir) . '/full_backup_' . $token . '.log';
        full_backup_write_job($job_path, [
            'ok' => true,
            'status' => 'running',
            'token' => $token,
            'started_at' => date(DateTimeInterface::ATOM),
            'log_path' => $log_path,
            'message' => 'Full backup is running on the server.',
        ]);

        $env_parts = [
            'BOOKCATALOG_FULL_BACKUP_JOB=1',
            'BOOKCATALOG_FULL_BACKUP_STATUS=' . escapeshellarg($job_path),
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
            . ' ' . escapeshellarg(full_backup_cli_binary())
            . ' ' . escapeshellarg(__FILE__)
            . ' > ' . escapeshellarg($log_path)
            . ' 2>&1 &';
        exec($cmd, $out, $code);
        if ($code !== 0) {
            full_backup_write_job($job_path, [
                'ok' => false,
                'status' => 'error',
                'token' => $token,
                'started_at' => date(DateTimeInterface::ATOM),
                'error' => 'Failed to start full backup background job.',
            ]);
            json_fail('Failed to start full backup background job', 500);
        }
        json_out(['ok' => true, 'mode' => 'server_async', 'token' => $token, 'status' => 'running']);
    }

    if ($action === 'status') {
        $token = (string)($_GET['token'] ?? '');
        $job_path = full_backup_job_path($backup_dir, $token);
        $job = full_backup_read_job($job_path);
        if (($job['status'] ?? '') === 'running') {
            $log_path = $job['log_path'] ?? (full_backup_job_dir($backup_dir) . '/full_backup_' . $token . '.log');
            $log_error = full_backup_log_error($log_path);
            if ($log_error !== null) {
                $job['ok'] = false;
                $job['status'] = 'error';
                $job['error'] = $log_error;
                $job['log_path'] = $log_path;
                full_backup_write_job($job_path, $job);
            }
        }
        json_out(['ok' => true, 'mode' => 'server_async', 'job' => $job]);
    }
}

$pdo = pdo();

// ---------- helpers ----------
function tmp_file(string $suffix): string {
    $p = tempnam(sys_get_temp_dir(), 'bc_');
    $new = $p . $suffix;
    rename($p, $new);
    return $new;
}
function write_csv(array $rows, array $header): string {
    $f = tmp_file('.csv');
    $h = fopen($f, 'w');
    // PHP 8.1+: pass escape arg explicitly to avoid deprecation
    fputcsv($h, $header, ',', '"', '\\');
    foreach ($rows as $r) {
        // normalize row order to header
        $line = [];
        foreach ($header as $col) {
            $val = $r[$col] ?? '';
            $line[] = sanitize_csv_value($val);
        }
        fputcsv($h, $line, ',', '"', '\\');
    }
    fclose($h);
    return $f;
}
function add_if_exists(ZipArchive $zip, string $abs, string $in_zip): void {
    if (is_file($abs) && is_readable($abs)) $zip->addFile($abs, $in_zip);
}

// ---------- data pulls ----------
try {
    // Books (include cover references; compute cover_file for CSV last column)
    $books_sql = "
    SELECT
      b.book_id AS id,
      b.title, b.subtitle, b.series,
      " . (books_table_has_record_status($pdo) ? "b.record_status," : "'active' AS record_status,") . "
      " . (books_table_has_language($pdo) ? "b.language," : "'unknown' AS language,") . "
      b.copy_count,
      b.year_published, b.isbn, b.lccn, b.notes,
      b.loaned_to, b.loaned_date,
      b.cover_image,
      b.cover_thumb,
      p.name AS publisher,
      pl.bookcase_no, pl.shelf_no
    FROM Books b
    LEFT JOIN Publishers p ON p.publisher_id = b.publisher_id
    LEFT JOIN Placement  pl ON pl.placement_id = b.placement_id
    ORDER BY b.book_id ASC
  ";
    $books = $pdo->query($books_sql)->fetchAll(PDO::FETCH_ASSOC);

    $authors = $pdo->query("SELECT author_id, first_name, last_name, sort_name FROM Authors ORDER BY author_id")->fetchAll(PDO::FETCH_ASSOC);
    $publishers = $pdo->query("SELECT publisher_id, name FROM Publishers ORDER BY publisher_id")->fetchAll(PDO::FETCH_ASSOC);
    $subjects = $pdo->query("SELECT subject_id, name FROM Subjects ORDER BY subject_id")->fetchAll(PDO::FETCH_ASSOC);
    if (bookcopies_table_exists($pdo)) {
        $book_copies_cols = 'copy_id, book_id, format, quantity, physical_location, file_path'
            . (bookcopies_has_file_size($pdo) ? ', file_size' : ', 0 AS file_size')
            . (bookcopies_has_sha256($pdo) ? ', sha256' : ', NULL AS sha256')
            . ', notes, created_at, updated_at';
        $book_copies = $pdo->query("SELECT {$book_copies_cols} FROM BookCopies ORDER BY book_id, copy_id")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $book_copies = [];
    }
    $books_authors_cols = books_authors_has_author_alias($pdo)
        ? 'book_id, author_id, author_ord, author_alias'
        : 'book_id, author_id, author_ord, NULL AS author_alias';
    $books_authors = $pdo->query("SELECT {$books_authors_cols} FROM Books_Authors ORDER BY book_id, author_ord")->fetchAll(PDO::FETCH_ASSOC);
    $books_subjects = $pdo->query("SELECT book_id, subject_id FROM Books_Subjects ORDER BY book_id, subject_id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    json_fail('Query failed: ' . $e->getMessage(), 500);
}

// ---------- write temp JSON/CSV ----------
$book_copies_map = [];
foreach ($book_copies as $copy) {
    $book_copies_map[(int)$copy['book_id']][] = $copy;
}

$csv_books_header     = ['id','title','subtitle','series','record_status','language','copy_count','year_published','isbn','lccn','notes','publisher','loaned_to','loaned_date','bookcase_no','shelf_no','cover_image','cover_file','copies_json']; // cover_file last
$csv_books_rows       = array_map(function($b) use ($book_copies_map) {
    // cover_file is filename (last segment) or empty
    $cover_file = '';
    if (!empty($b['cover_image'])) {
        $parts = explode('/', $b['cover_image']);
        $cover_file = end($parts);
    }
    $copies = $book_copies_map[(int)$b['id']] ?? [];
    return [
        'id'             => $b['id'],
        'title'          => $b['title'],
        'subtitle'       => $b['subtitle'],
        'series'         => $b['series'],
        'record_status'  => normalize_book_record_status($b['record_status'] ?? 'active'),
        'language'       => normalize_book_language($b['language'] ?? 'unknown'),
        'copy_count'     => total_book_copy_quantity($copies, (int)($b['copy_count'] ?? 1)),
        'year_published' => $b['year_published'],
        'isbn'           => $b['isbn'],
        'lccn'           => $b['lccn'],
        'notes'          => $b['notes'],
        'publisher'      => $b['publisher'],
        'loaned_to'      => $b['loaned_to'],
        'loaned_date'    => $b['loaned_date'],
        'bookcase_no'    => $b['bookcase_no'],
        'shelf_no'       => $b['shelf_no'],
        'cover_image'    => $b['cover_image'],
        'cover_file'     => $cover_file,
        'copies_json'    => json_encode($copies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}, $books);
$csv_books_path       = write_csv($csv_books_rows, $csv_books_header);

$csv_authors_path     = write_csv($authors,      ['author_id','first_name','last_name','sort_name']);
$csv_publishers_path  = write_csv($publishers,   ['publisher_id','name']);
$csv_subjects_path    = write_csv($subjects,     ['subject_id','name']);
$csv_bookcopies_path  = write_csv($book_copies,  ['copy_id','book_id','format','quantity','physical_location','file_path','file_size','sha256','notes','created_at','updated_at']);
$csv_ba_path          = write_csv($books_authors, ['book_id','author_id','author_ord','author_alias']);
$csv_bs_path          = write_csv($books_subjects,['book_id','subject_id']);

// ---------- zip build ----------
$zip = new ZipArchive();
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
$app_version_raw = current_app_version();
$app_version = $app_version_raw ? 'v' . $app_version_raw : '';

$timestamp = date('Ymd_His');
$suffix_parts = array_filter([$os_label, $app_version]);
$suffix = $suffix_parts ? '_' . implode('_', $suffix_parts) : '';
$filename = "bookcatalog_backup_{$timestamp}{$suffix}.zip";
$zip_path = $server_side
    ? rtrim($backup_dir, "/\\") . '/' . $filename
    : sys_get_temp_dir() . "/{$filename}";
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    json_fail('Zip open failed', 500);
}

// meta + readme
$generated_at = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
$meta = [
    'generated_at' => $generated_at,
    'versions' => [
        'app_version' => $app_version_raw,
        'schema_version' => SCHEMA_VERSION,
    ],
    'counts'  => [
            'books'      => count($books),
            'book_copies'=> count($book_copies),
            'authors'    => count($authors),
        'publishers' => count($publishers),
        'subjects'   => count($subjects),
        'links'      => [
            'books_authors'  => count($books_authors),
            'books_subjects' => count($books_subjects),
        ],
    ],
    'schema' => [
        'books' => array_keys($csv_books_rows[0] ?? [])
    ],
];
$zip->addFromString('meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$readme = <<<TXT
BookCatalog – Full Backup
Generated: {$generated_at}

Includes:
- books.csv  (flat export; last column is cover_file)
- BookCopies.csv
- authors.csv, publishers.csv, subjects.csv
- Books_Authors.csv (with author_ord and author_alias), Books_Subjects.csv
- uploads/default-cover.jpg (if present)
- uploads/<id>/cover.* and cover-thumb.* files referenced by exported rows

Restore notes:
- CSVs can be staged and merged using your existing SQL pipeline.
- Cover images mirror the web-relative layout under /uploads.
TXT;
$zip->addFromString('README.txt', $readme);

// data files
$zip->addFile($csv_books_path,      'data/books.csv');
$zip->addFile($csv_bookcopies_path, 'data/BookCopies.csv');
$zip->addFile($csv_authors_path,    'data/authors.csv');
$zip->addFile($csv_publishers_path, 'data/publishers.csv');
$zip->addFile($csv_subjects_path,   'data/subjects.csv');
$zip->addFile($csv_ba_path,         'data/Books_Authors.csv');
$zip->addFile($csv_bs_path,         'data/Books_Subjects.csv');

// cover images (only existing ones)
$uploads_dir = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
$cover_paths = [];
$cover_paths['uploads/.htaccess'] = true;
$cover_paths['uploads/default-cover.jpg'] = true;
$cover_paths['uploads/default_cover.jpg'] = true;
foreach ($books as $r) {
    $id = (int)$r['id'];
    if ($id > 0) {
        foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
            $cover_paths["uploads/{$id}/cover.{$ext}"] = true;
            $cover_paths["uploads/{$id}/cover-thumb.{$ext}"] = true;
        }
    }

    $ci = trim((string)($r['cover_image'] ?? ''));
    $ct = trim((string)($r['cover_thumb'] ?? ''));
    if ($ci !== '' && strpos($ci, 'uploads/') === 0) $cover_paths[$ci] = true;
    if ($ct !== '' && strpos($ct, 'uploads/') === 0) $cover_paths[$ct] = true;
}
foreach (array_keys($cover_paths) as $rel) {
    $rel_clean = ltrim(str_replace('\\', '/', $rel), '/');
    if (strpos($rel_clean, 'uploads/') !== 0) continue;
    if (archive_should_skip_path($rel_clean)) continue;
    $abs = realpath(__DIR__ . '/' . $rel_clean);
    if ($abs === false || strpos($abs, $uploads_dir . '/') !== 0) continue;
    add_if_exists($zip, $abs, $rel_clean);
}

// --- Build sha256sums.txt for everything we package ---
$checksums = [];

/** helper: hash a file if readable */
$sha = function (string $abs_path, string $zip_path) use (&$checksums) {
    if (is_file($abs_path) && is_readable($abs_path)) {
        $h = hash_file('sha256', $abs_path);
        // Common format: "<sha256>  <path inside zip>"
        $checksums[] = $h . "  " . $zip_path;
    }
};

// hash the data files we added
$sha($csv_books_path,      'data/books.csv');
$sha($csv_bookcopies_path, 'data/BookCopies.csv');
$sha($csv_authors_path,    'data/authors.csv');
$sha($csv_publishers_path, 'data/publishers.csv');
$sha($csv_subjects_path,   'data/subjects.csv');
$sha($csv_ba_path,         'data/Books_Authors.csv');
$sha($csv_bs_path,         'data/Books_Subjects.csv');

// and the images we added
foreach (array_keys($cover_paths) as $rel) {
    $rel_clean = ltrim(str_replace('\\', '/', $rel), '/');
    if (strpos($rel_clean, 'uploads/') !== 0) continue;
    if (archive_should_skip_path($rel_clean)) continue;
    $abs = realpath(__DIR__ . '/' . $rel_clean);
    if ($abs === false || strpos($abs, $uploads_dir . '/') !== 0) continue;
    $sha($abs, $rel_clean);
}

// finally add sha256s.txt to ZIP
$zip->addFromString('sha256sums.txt', implode("\n", $checksums) . "\n");

$zip->close();

// ---------- stream zip download ----------
clearstatcache(true, $zip_path);
$size_bytes = is_file($zip_path) ? (int)filesize($zip_path) : 0;

@unlink($csv_books_path);
@unlink($csv_bookcopies_path);
@unlink($csv_authors_path);
@unlink($csv_publishers_path);
@unlink($csv_subjects_path);
@unlink($csv_ba_path);
@unlink($csv_bs_path);

if ($server_side) {
    $job_status_path = getenv('BOOKCATALOG_FULL_BACKUP_STATUS');
    if ($full_backup_cli_job && $job_status_path !== false && trim((string)$job_status_path) !== '') {
        full_backup_write_job((string)$job_status_path, [
            'ok' => true,
            'status' => 'complete',
            'completed_at' => date(DateTimeInterface::ATOM),
            'mode' => 'server',
            'dir' => $backup_dir,
            'filename' => $filename,
            'path' => $zip_path,
            'size_bytes' => $size_bytes,
        ]);
    }
    error_log(sprintf(
        'BookCatalog backup completed: type=%s mode=%s file=%s size=%d bytes',
        'full',
        'server',
        $filename ?? '-',
        $size_bytes
    ));
    json_out([
        'ok' => true,
        'mode' => 'server',
        'dir' => $backup_dir,
        'filename' => $filename,
        'path' => $zip_path,
    ]);
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($zip_path));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
readfile($zip_path);

error_log(sprintf(
    'BookCatalog backup completed: type=%s mode=%s file=%s size=%d bytes',
    'full',
    'download',
    $filename ?? '-',
    $size_bytes
));

@unlink($zip_path);
