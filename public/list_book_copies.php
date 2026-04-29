<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
$me = require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    $book_id = (int)($_GET['book_id'] ?? 0);
    if ($book_id <= 0) {
        json_fail('Missing or invalid book_id', 400);
    }

    $pdo = pdo();
    $sel = $pdo->prepare(
        books_table_has_record_status($pdo)
            ? 'SELECT record_status FROM Books WHERE book_id = ? LIMIT 1'
            : 'SELECT \'active\' AS record_status FROM Books WHERE book_id = ? LIMIT 1'
    );
    $sel->execute([$book_id]);
    $status = $sel->fetchColumn();
    if ($status === false) {
        json_fail('Book not found', 404);
    }
    if (normalize_book_record_status((string)$status) === 'deleted' && (($me['role'] ?? '') !== 'admin')) {
        json_fail('Book not found', 404);
    }

    json_out([
        'ok' => true,
        'data' => [
            'book_id' => $book_id,
            'copies' => fetch_book_copies($pdo, $book_id),
        ],
    ]);
} catch (Throwable $e) {
    json_fail($e->getMessage(), 500);
}
