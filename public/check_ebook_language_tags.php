<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function language_tag_supported_formats(): array {
    return ['epub' => true, 'pdf' => true, 'mobi' => true, 'azw3' => true, 'djvu' => true, 'lit' => true, 'prc' => true, 'rtf' => true, 'odt' => true];
}

function language_tag_report_csv(array $rows): string {
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) return '';
    fputcsv($fh, ['status', 'expected_language', 'actual_language', 'stored_path', 'absolute_path', 'filename'], ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($fh, [
            $row['status'] ?? '',
            $row['expected_language'] ?? '',
            $row['actual_language'] ?? '',
            $row['stored_path'] ?? '',
            $row['absolute_path'] ?? '',
            $row['filename'] ?? '',
        ], ',', '"', '\\');
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv === false ? '' : $csv;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_fail('Method Not Allowed', 405);
    }

    $health = requireEbookRepositoryAvailable(false);
    $books_root = rtrim((string)($health['books_path'] ?? ''), '/');
    if ($books_root === '' || !is_dir($books_root)) {
        json_fail('Ebook repository Books directory is unavailable.', 400, ['repository_health' => $health]);
    }

    $formats = language_tag_supported_formats();
    $checked = 0;
    $ok = 0;
    $missing = 0;
    $wrong = 0;
    $ignored = 0;
    $rows = [];

    foreach (@scandir($books_root) ?: [] as $top) {
        if ($top === '.' || $top === '..') continue;
        $top_path = $books_root . '/' . $top;
        if (!is_dir($top_path)) continue;
        if (!preg_match('/^\d+_([A-Z]{2,3})$/i', $top, $m)) {
            $ignored++;
            continue;
        }
        $expected = strtolower((string)$m[1]);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($top_path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $info) {
            if (!$info instanceof SplFileInfo || !$info->isFile()) continue;
            $ext = strtolower((string)$info->getExtension());
            if (!isset($formats[$ext])) continue;
            $checked++;
            $absolute = filesystemPathString($info->getPathname()) ?? $info->getPathname();
            try {
                $stored = absoluteToRelativeEbookPath($absolute);
            } catch (Throwable $e) {
                $stored = $absolute;
            }
            $filename = $info->getFilename();
            $base = preg_replace('/\.[^.]+$/u', '', $filename) ?? $filename;
            $actual = null;
            if (preg_match('/\[([a-z]{2,3})\]\s*$/iu', $base, $lm)) {
                $actual = strtolower((string)$lm[1]);
            }
            if ($actual === null) {
                $missing++;
                $rows[] = [
                    'status' => 'missing_language_tag',
                    'expected_language' => $expected,
                    'actual_language' => '',
                    'stored_path' => $stored,
                    'absolute_path' => $absolute,
                    'filename' => $filename,
                ];
            } elseif ($actual !== $expected) {
                $wrong++;
                $rows[] = [
                    'status' => 'wrong_language_tag',
                    'expected_language' => $expected,
                    'actual_language' => $actual,
                    'stored_path' => $stored,
                    'absolute_path' => $absolute,
                    'filename' => $filename,
                ];
            } else {
                $ok++;
            }
        }
    }

    json_out(['ok' => true, 'data' => [
        'checked' => $checked,
        'ok' => $ok,
        'missing_language_tag' => $missing,
        'wrong_language_tag' => $wrong,
        'ignored_top_level_dirs' => $ignored,
        'rows' => array_slice($rows, 0, 300),
        'csv' => language_tag_report_csv($rows),
        'filename' => 'ebook_language_tag_audit_' . date('Ymd_His') . '.csv',
        'repository_health' => $health,
    ]]);
} catch (Throwable $e) {
    json_fail($e->getMessage(), 500);
}
