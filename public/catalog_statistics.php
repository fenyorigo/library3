<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$pdo = pdo();

$timestamp = date('Ymd_His');
$filename = 'bookcatalog_stats_' . $timestamp . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$format_in = strtolower(trim((string)($_GET['format'] ?? '')));
$language_in = strtolower(trim((string)($_GET['language'] ?? '')));
$record_status_in = strtolower(trim((string)($_GET['record_status'] ?? 'active')));
$record_status_filter = in_array($record_status_in, ['active', 'deleted', 'all'], true) ? $record_status_in : 'active';

$supported_formats = ['print', 'ebooks', 'epub', 'mobi', 'azw3', 'pdf', 'djvu', 'lit', 'prc', 'rtf', 'odt'];
$format_filter = in_array($format_in, $supported_formats, true) ? $format_in : '';
$language_filter = $language_in !== '' ? normalize_book_language($language_in) : '';

$where_chunks = [];
$params = [];

if ($q !== '') {
    $tokens = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($tokens as $i => $tok) {
        $like = '%' . $tok . '%';
        $where_chunks[] = "("
            . "b.title LIKE :t{$i}_title OR "
            . "b.subtitle LIKE :t{$i}_subtitle OR "
            . "b.series LIKE :t{$i}_series OR "
            . "b.isbn LIKE :t{$i}_isbn OR "
            . "b.lccn LIKE :t{$i}_lccn OR "
            . "b.notes LIKE :t{$i}_notes OR "
            . "p.name LIKE :t{$i}_pub OR "
            . "EXISTS (SELECT 1 FROM BookCopies bcq WHERE bcq.book_id = b.book_id AND bcq.format LIKE :t{$i}_format) OR "
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
        foreach ([
            "t{$i}_title", "t{$i}_subtitle", "t{$i}_series", "t{$i}_isbn", "t{$i}_lccn",
            "t{$i}_notes", "t{$i}_pub", "t{$i}_format", "t{$i}_an", "t{$i}_afn", "t{$i}_aln", "t{$i}_asn",
        ] as $key) {
            $params[$key] = $like;
        }
    }
}

if ($format_filter === 'ebooks') {
    $where_chunks[] = "EXISTS (SELECT 1 FROM BookCopies bcf WHERE bcf.book_id = b.book_id AND bcf.format <> 'print')";
} elseif ($format_filter !== '') {
    $where_chunks[] = "EXISTS (SELECT 1 FROM BookCopies bcf WHERE bcf.book_id = b.book_id AND bcf.format = :format_filter)";
    $params['format_filter'] = $format_filter;
}

if ($language_filter !== '') {
    $where_chunks[] = "b.language = :language_filter";
    $params['language_filter'] = $language_filter;
}

if (books_table_has_record_status($pdo)) {
    if ($record_status_filter === 'deleted') {
        $where_chunks[] = "b.record_status = 'deleted'";
    } elseif ($record_status_filter === 'active') {
        $where_chunks[] = "b.record_status = 'active'";
    }
}

$where_sql = $where_chunks ? ('WHERE ' . implode(' AND ', $where_chunks)) : '';
$book_from = "FROM Books b LEFT JOIN Publishers p ON p.publisher_id = b.publisher_id {$where_sql}";
$copy_from = "FROM BookCopies bc JOIN Books b ON b.book_id = bc.book_id LEFT JOIN Publishers p ON p.publisher_id = b.publisher_id {$where_sql}";

$bind = static function (PDOStatement $st) use ($params): void {
    foreach ($params as $key => $value) {
        $st->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
};

$scalar = static function (string $sql) use ($pdo, $bind): int {
    $st = $pdo->prepare($sql);
    $bind($st);
    $st->execute();
    return (int)$st->fetchColumn();
};

$rows = [];
$add = static function (string $section, string $key, $value, string $notes = '') use (&$rows): void {
    $rows[] = [$section, $key, (string)$value, $notes];
};

$bytes_human = static function (int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return ($unit === 0 ? (string)$bytes : number_format($value, 2, '.', '')) . ' ' . $units[$unit];
};

$add('meta', 'generated_at', date('c'), 'Audit/support export; not an import file.');
$add('filter', 'q', $q);
$add('filter', 'format', $format_filter !== '' ? $format_filter : 'all');
$add('filter', 'language', $language_filter !== '' ? $language_filter : 'all');
$add('filter', 'record_status', $record_status_filter);

$active_records = $scalar("SELECT COUNT(*) {$book_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'active'");
$deleted_records = $scalar("SELECT COUNT(*) {$book_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'deleted'");
$selected_records = $scalar("SELECT COUNT(*) {$book_from}");

$active_copy_units = $scalar("SELECT COALESCE(SUM(CASE WHEN bc.format = 'print' THEN GREATEST(bc.quantity, 1) ELSE 1 END), 0) {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'active'");
$deleted_copy_units = $scalar("SELECT COALESCE(SUM(CASE WHEN bc.format = 'print' THEN GREATEST(bc.quantity, 1) ELSE 1 END), 0) {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'deleted'");
$print_active = $scalar("SELECT COALESCE(SUM(GREATEST(bc.quantity, 1)), 0) {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'active' AND bc.format = 'print'");
$print_deleted = $scalar("SELECT COALESCE(SUM(GREATEST(bc.quantity, 1)), 0) {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'deleted' AND bc.format = 'print'");
$ebook_active = $scalar("SELECT COUNT(*) {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'active' AND bc.format <> 'print'");
$ebook_deleted = $scalar("SELECT COUNT(*) {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'deleted' AND bc.format <> 'print'");

$add('summary', 'bibliographic_records_selected', $selected_records, 'Current filters applied.');
$add('summary', 'bibliographic_records_active', $active_records, 'Current filters applied.');
$add('summary', 'books_active', $active_records, 'Synonym for active bibliographic records.');
$add('summary', 'book_copies_active', $active_copy_units, 'Print uses quantity; ebook files count as one copy row.');
$add('summary', 'print_copies_active', $print_active, 'Print quantity sum.');
$add('summary', 'ebook_copies_active', $ebook_active, 'Active ebook file rows.');
$add('summary', 'deleted_records', $deleted_records, 'Current filters applied.');
$add('summary', 'deleted_copies', $deleted_copy_units, 'Copies attached to deleted bibliographic records.');

$cover_rows = [];
$st = $pdo->prepare("SELECT b.book_id, b.cover_image {$book_from}");
$bind($st);
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $book_id = (int)($row['book_id'] ?? 0);
    if ($book_id <= 0) continue;
    $candidates = [];
    $cover_image = trim((string)($row['cover_image'] ?? ''));
    if ($cover_image !== '' && strpos($cover_image, 'uploads/') === 0) {
        $candidates[] = $cover_image;
    }
    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
        $candidates[] = 'uploads/' . $book_id . '/cover.' . $ext;
    }
    foreach (array_values(array_unique($candidates)) as $rel) {
        $rel_clean = ltrim(str_replace('\\', '/', $rel), '/');
        if (!preg_match('#^uploads/\d+/cover\.(jpg|jpeg|png|webp|gif)$#i', $rel_clean)) continue;
        if (is_file(__DIR__ . '/' . $rel_clean)) {
            $cover_rows[$book_id] = strtolower($rel_clean);
            break;
        }
    }
}
$cover_files = count($cover_rows);
$cover_jpg_files = 0;
foreach ($cover_rows as $rel) {
    if (preg_match('#/cover\.jpe?g$#i', $rel)) $cover_jpg_files++;
}
$add('covers', 'cover_files', $cover_files, 'Existing uploads/<book_id>/cover.* files for current filters; cover-thumb.* is not counted.');
$add('covers', 'cover_jpg_files', $cover_jpg_files, 'Existing uploads/<book_id>/cover.jpg/jpeg files for current filters.');
$add('covers', 'cover_missing', max(0, $selected_records - $cover_files), 'Bibliographic records without an existing real cover file.');

$add('prints', 'total_print_copies', $print_active, 'Active print quantity sum.');
$add('prints', 'deleted_print_copies', $print_deleted, 'Print quantity attached to deleted records.');

$ebook_total = $ebook_active;
$ebook_path_present = $scalar("SELECT COUNT(*) {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'active' AND bc.format <> 'print' AND bc.file_path IS NOT NULL AND TRIM(bc.file_path) <> ''");
$ebook_path_missing = $scalar("SELECT COUNT(*) {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'active' AND bc.format <> 'print' AND (bc.file_path IS NULL OR TRIM(bc.file_path) = '')");
$ebook_sha_present = $scalar("SELECT COUNT(*) {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'active' AND bc.format <> 'print' AND bc.sha256 IS NOT NULL AND TRIM(bc.sha256) <> ''");
$ebook_sha_missing = $scalar("SELECT COUNT(*) {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'active' AND bc.format <> 'print' AND (bc.sha256 IS NULL OR TRIM(bc.sha256) = '')");
$ebook_bytes = $scalar("SELECT COALESCE(SUM(bc.file_size), 0) {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'active' AND bc.format <> 'print'");

$add('ebooks', 'total_files', $ebook_total, 'Active ebook file rows.');
$add('ebooks', 'deleted_ebook_copies', $ebook_deleted, 'Ebook rows attached to deleted records.');
$add('ebooks', 'sha256_present', $ebook_sha_present);
$add('ebooks', 'sha256_missing', $ebook_sha_missing);
$add('ebooks', 'total_file_size_bytes', $ebook_bytes);
$add('ebooks', 'total_file_size_mb', number_format($ebook_bytes / 1048576, 2, '.', ''));
$add('ebooks', 'total_file_size_human', $bytes_human($ebook_bytes));

$format_rows = [];
$st = $pdo->prepare("SELECT bc.format, COUNT(*) AS c {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'active' AND bc.format <> 'print' GROUP BY bc.format");
$bind($st);
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $format_rows[strtolower((string)$row['format'])] = (int)$row['c'];
}
foreach (['epub', 'pdf', 'mobi', 'azw3', 'djvu'] as $fmt) {
    $add('ebooks', 'format_' . $fmt, $format_rows[$fmt] ?? 0);
}
$known_format_count = 0;
foreach (['epub', 'pdf', 'mobi', 'azw3', 'djvu'] as $fmt) $known_format_count += $format_rows[$fmt] ?? 0;
$add('ebooks', 'format_other', max(0, $ebook_total - $known_format_count), 'Active ebook formats other than epub/pdf/mobi/azw3/djvu.');

$add('source_path_health', 'ebook_file_path_present', $ebook_path_present);
$add('source_path_health', 'ebook_file_path_missing', $ebook_path_missing);
$add('source_path_health', 'ebook_sha256_present', $ebook_sha_present);
$add('source_path_health', 'ebook_sha256_missing', $ebook_sha_missing);

$language_rows = [];
$st = $pdo->prepare("SELECT b.language, COUNT(*) AS c {$book_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'active' GROUP BY b.language");
$bind($st);
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $language_rows[normalize_book_language($row['language'] ?? 'unknown')] = (int)$row['c'];
}
foreach (['hu', 'en', 'de', 'fr', 'unknown'] as $lang) {
    $add('languages', $lang, $language_rows[$lang] ?? 0, 'Active bibliographic records.');
}

foreach ([
    'prints' => "bc.format = 'print'",
    'ebooks' => "bc.format <> 'print'",
] as $section => $copy_condition) {
    $counts = [];
    $st = $pdo->prepare("SELECT b.language, COALESCE(SUM(CASE WHEN bc.format = 'print' THEN GREATEST(bc.quantity, 1) ELSE 1 END), 0) AS c {$copy_from} " . ($where_sql ? "AND" : "WHERE") . " b.record_status = 'active' AND {$copy_condition} GROUP BY b.language");
    $bind($st);
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[normalize_book_language($row['language'] ?? 'unknown')] = (int)$row['c'];
    }
    foreach (['hu', 'en', 'de', 'fr', 'unknown'] as $lang) {
        $add($section, 'language_' . $lang, $counts[$lang] ?? 0, 'Active copies.');
    }
}

$add('integrity', 'last_full_check', 'not_available', 'Full integrity check results are not persisted yet.');
$add('integrity', 'last_full_check_timestamp', 'not_available');
$add('integrity', 'last_full_check_checked', 'not_available');
$add('integrity', 'last_full_check_ok', 'not_available');
$add('integrity', 'last_full_check_missing', 'not_available');
$add('integrity', 'last_full_check_sha_missing', 'not_available');
$add('integrity', 'last_full_check_sha_mismatch', 'not_available');
$add('integrity', 'last_full_check_size_mismatch', 'not_available');
$add('integrity', 'last_full_check_errors', 'not_available');

$add('duplicate_orphan', 'duplicate_sha_in_database', 'not_available', 'Incremental rescan/orphan snapshots are not persisted yet.');
$add('duplicate_orphan', 'duplicate_file_on_disk', 'not_available');
$add('duplicate_orphan', 'orphan_ebook_records', 'not_available');
$add('duplicate_orphan', 'orphan_print_records', 'not_available');

$out = fopen('php://output', 'w');
fputcsv($out, ['section', 'key', 'value', 'notes'], ',', '"', '\\');
foreach ($rows as $row) {
    fputcsv($out, $row, ',', '"', '\\');
}
