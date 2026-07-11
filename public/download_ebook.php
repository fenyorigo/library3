<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
$me = require_login();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
set_time_limit(0);
ignore_user_abort(true);

function ebook_download_mime(string $format, string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: $format);
    return match ($ext) {
        'epub' => 'application/epub+zip',
        'pdf' => 'application/pdf',
        'mobi' => 'application/x-mobipocket-ebook',
        'azw3' => 'application/vnd.amazon.ebook',
        'djvu' => 'image/vnd.djvu',
        'rtf' => 'application/rtf',
        'odt' => 'application/vnd.oasis.opendocument.text',
        default => 'application/octet-stream',
    };
}

function ebook_download_ascii_filename(string $filename): string {
    $fallback = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename) ?: 'ebook';
    $fallback = trim($fallback, " .\t\n\r\0\x0B");
    return $fallback !== '' ? $fallback : 'ebook';
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_fail('Method Not Allowed', 405);
    }

    $copy_id = (int)($_GET['copy_id'] ?? 0);
    if ($copy_id <= 0) {
        json_fail('Missing or invalid copy id', 400);
    }

    requireEbookRepositoryAvailable(false);
    $pdo = pdo();
    if (!bookcopies_table_exists($pdo)) {
        json_fail('BookCopies table is not available', 500);
    }

    $st = $pdo->prepare("\n        SELECT bc.copy_id, bc.book_id, bc.format, bc.file_path,\n               b.title, " . (books_table_has_record_status($pdo) ? "b.record_status" : "'active' AS record_status") . "\n        FROM BookCopies bc\n        JOIN Books b ON b.book_id = bc.book_id\n        WHERE bc.copy_id = ?\n        LIMIT 1\n    ");
    $st->execute([$copy_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        json_fail('Ebook copy not found', 404);
    }

    $record_status = normalize_book_record_status($row['record_status'] ?? 'active');
    if ($record_status === 'deleted' && (($me['role'] ?? '') !== 'admin')) {
        json_fail('Ebook copy not found', 404);
    }

    $format = normalize_book_copy_format($row['format'] ?? null);
    $stored_path = normalize_book_copy_file_path($row['file_path'] ?? null);
    if ($format === 'print' || $stored_path === null) {
        json_fail('Selected copy is not a downloadable ebook file', 400);
    }

    $resolved = resolveFilesystemPath($stored_path);
    if ($resolved === null || !is_file($resolved)) {
        json_fail('Ebook file is not available on disk', 404, ['file_path' => $stored_path]);
    }
    if (!is_readable($resolved)) {
        json_fail('Ebook file is not readable', 403, ['file_path' => $stored_path]);
    }

    $filename = basename($stored_path) ?: ('ebook-' . $copy_id . '.' . $format);
    $size = filesize($resolved);
    if ($size === false) $size = 0;

    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: ' . ebook_download_mime($format, $filename));
    header('Content-Length: ' . (string)$size);
    header('Content-Disposition: attachment; filename="' . addcslashes(ebook_download_ascii_filename($filename), "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($resolved);
    exit;
} catch (Throwable $e) {
    json_fail($e->getMessage(), $e instanceof InvalidArgumentException ? 400 : 500);
}
