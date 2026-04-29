<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_fail('Method Not Allowed', 405);
    }

    $pdo = pdo();
    $in = json_in();
    $book_id = (int)($in['book_id'] ?? 0);
    if ($book_id <= 0) {
        json_fail('Missing or invalid book_id', 400);
    }

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
    if (normalize_book_record_status((string)$status) === 'deleted') {
        json_fail('Cannot add a copy to a deleted record. Restore it first.', 409);
    }

    $copy = normalize_book_copy_input($in);
    $pdo->beginTransaction();

    $ins = $pdo->prepare("
        INSERT INTO BookCopies
            (book_id, format, quantity, physical_location, file_path, notes, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $ins->execute([
        $book_id,
        $copy['format'],
        $copy['quantity'],
        $copy['physical_location'],
        $copy['file_path'],
        $copy['notes'],
    ]);

    $sync = sync_book_copy_derived_fields($pdo, $book_id);
    $pdo->commit();

    json_out([
        'ok' => true,
        'data' => [
            'book_id' => $book_id,
            'copies' => $sync['copies'] ?? [],
            'copy_count' => (int)($sync['copy_count'] ?? 0),
        ],
    ], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_fail($e->getMessage(), 500);
}
