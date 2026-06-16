<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        json_out([
            'ok' => true,
            'data' => [
                'settings' => [
                    'ebook_library_root' => getEbookLibraryRoot(),
                ],
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
            ],
        ]);
    }

    json_fail('Method Not Allowed', 405);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException ? 400 : 500;
    json_fail($e->getMessage(), $code);
}
