<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function php_runtime_diagnostics(): array {
    return [
        'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
        'post_max_size' => (string)ini_get('post_max_size'),
        'memory_limit' => (string)ini_get('memory_limit'),
        'max_execution_time' => (string)ini_get('max_execution_time'),
        'max_input_time' => (string)ini_get('max_input_time'),
    ];
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        json_out([
            'ok' => true,
            'data' => [
                'settings' => [
                    'ebook_library_root' => getEbookLibraryRoot(),
                ],
                'php_runtime' => php_runtime_diagnostics(),
                'ebook_repository_health' => checkEbookRepositoryHealth(false),
            ],
        ]);
    }

    if ($method === 'POST') {
        $in = json_in();
        $root = normalizeEbookLibraryRoot((string)($in['ebook_library_root'] ?? ''));
        setSetting('ebook_library_root', $root);

        json_out([
            'ok' => true,
            'data' => [
                'settings' => [
                    'ebook_library_root' => $root,
                ],
                'php_runtime' => php_runtime_diagnostics(),
                'ebook_repository_health' => checkEbookRepositoryHealth(false),
            ],
        ]);
    }

    json_fail('Method Not Allowed', 405);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException ? 400 : 500;
    json_fail($e->getMessage(), $code);
}
