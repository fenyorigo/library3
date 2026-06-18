<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
    $write_test = isset($_GET['write_test']) && $_GET['write_test'] === '1';
    json_out([
        'ok' => true,
        'data' => checkEbookRepositoryHealth($write_test),
    ]);
} catch (Throwable $e) {
    json_fail($e->getMessage(), 500);
}
