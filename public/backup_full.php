<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

ini_set('memory_limit', '512M');      // or '1G' if you like
set_time_limit(600);                  // give the backup time
ignore_user_abort(true);              // keep running if client disconnects

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
    // Books (include only cover_image; also compute cover_file for CSV last column)
    $books_sql = "
    SELECT
      b.book_id AS id,
      b.title, b.subtitle, b.series,
      b.copy_count,
      b.year_published, b.isbn, b.lccn, b.notes,
      b.loaned_to, b.loaned_date,
      b.cover_image,
      b.cover_image AS cover_thumb,
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
    $books_authors = $pdo->query("SELECT book_id, author_id, author_ord FROM Books_Authors ORDER BY book_id, author_ord")->fetchAll(PDO::FETCH_ASSOC);
    $books_subjects = $pdo->query("SELECT book_id, subject_id FROM Books_Subjects ORDER BY book_id, subject_id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    json_fail('Query failed: ' . $e->getMessage(), 500);
}

// ---------- write temp JSON/CSV ----------
$csv_books_header     = ['id','title','subtitle','series','copy_count','year_published','isbn','lccn','notes','publisher','loaned_to','loaned_date','bookcase_no','shelf_no','cover_image','cover_file']; // cover_file last
$csv_books_rows       = array_map(function($b){
    // cover_file is filename (last segment) or empty
    $cover_file = '';
    if (!empty($b['cover_image'])) {
        $parts = explode('/', $b['cover_image']);
        $cover_file = end($parts);
    }
    return [
        'id'             => $b['id'],
        'title'          => $b['title'],
        'subtitle'       => $b['subtitle'],
        'series'         => $b['series'],
        'copy_count'     => $b['copy_count'] ?? 1,
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
    ];
}, $books);
$csv_books_path       = write_csv($csv_books_rows, $csv_books_header);

$csv_authors_path     = write_csv($authors,      ['author_id','first_name','last_name','sort_name']);
$csv_publishers_path  = write_csv($publishers,   ['publisher_id','name']);
$csv_subjects_path    = write_csv($subjects,     ['subject_id','name']);
$csv_ba_path          = write_csv($books_authors, ['book_id','author_id','author_ord']);
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
- authors.csv, publishers.csv, subjects.csv
- Books_Authors.csv (with author_ord), Books_Subjects.csv
- uploads/default-cover.jpg (if present)
- uploads/<id>/cover.jpg for any book that has a cover

Restore notes:
- CSVs can be staged and merged using your existing SQL pipeline.
- Cover images mirror the web-relative layout under /uploads.
TXT;
$zip->addFromString('README.txt', $readme);

// data files
$zip->addFile($csv_books_path,      'data/books.csv');
$zip->addFile($csv_authors_path,    'data/authors.csv');
$zip->addFile($csv_publishers_path, 'data/publishers.csv');
$zip->addFile($csv_subjects_path,   'data/subjects.csv');
$zip->addFile($csv_ba_path,         'data/Books_Authors.csv');
$zip->addFile($csv_bs_path,         'data/Books_Subjects.csv');

// cover images (only existing ones)
$uploads_dir = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
add_if_exists($zip, $uploads_dir . '/default-cover.jpg', 'uploads/default-cover.jpg');

foreach ($books as $r) {
    $id = (int)$r['id'];
    $abs = $uploads_dir . '/' . $id . '/cover.jpg';
    if (is_file($abs)) {
        $zip->addFile($abs, "uploads/{$id}/cover.jpg");
    }
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
$sha($csv_authors_path,    'data/authors.csv');
$sha($csv_publishers_path, 'data/publishers.csv');
$sha($csv_subjects_path,   'data/subjects.csv');
$sha($csv_ba_path,         'data/Books_Authors.csv');
$sha($csv_bs_path,         'data/Books_Subjects.csv');

// and the images we added (same loop you already use to add files)
$uploads_dir = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
$default_cover = $uploads_dir . '/default-cover.jpg';
if (is_file($default_cover)) {
    $sha($default_cover, 'uploads/default-cover.jpg');
}
foreach ($books as $r) {
    $id = (int)$r['id'];
    if (!empty($r['cover_image'])) {
        $abs = $uploads_dir . '/' . $id . '/cover.jpg';
        if (is_file($abs)) {
            $sha($abs, "uploads/{$id}/cover.jpg");
        }
    }
}

// finally add sha256s.txt to ZIP
$zip->addFromString('sha256sums.txt', implode("\n", $checksums) . "\n");

$zip->close();

// ---------- stream zip download ----------
clearstatcache(true, $zip_path);
$size_bytes = is_file($zip_path) ? (int)filesize($zip_path) : 0;

@unlink($csv_books_path);
@unlink($csv_authors_path);
@unlink($csv_publishers_path);
@unlink($csv_subjects_path);
@unlink($csv_ba_path);
@unlink($csv_bs_path);

if ($server_side) {
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
