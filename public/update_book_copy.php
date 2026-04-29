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
    $copy_id = (int)($in['copy_id'] ?? 0);
    if ($copy_id <= 0) {
        json_fail('Missing or invalid copy_id', 400);
    }

    $copy = normalize_book_copy_input($in);
    $sel = $pdo->prepare(
        books_table_has_record_status($pdo)
            ? 'SELECT bc.book_id, b.record_status FROM BookCopies bc JOIN Books b ON b.book_id = bc.book_id WHERE bc.copy_id = ? LIMIT 1'
            : 'SELECT bc.book_id, \'active\' AS record_status FROM BookCopies bc JOIN Books b ON b.book_id = bc.book_id WHERE bc.copy_id = ? LIMIT 1'
    );
    $sel->execute([$copy_id]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    $book_id = (int)($row['book_id'] ?? 0);
    if ($book_id <= 0) {
        json_fail('Copy not found', 404);
    }
    if (normalize_book_record_status($row['record_status'] ?? 'active') === 'deleted') {
        json_fail('Cannot update a copy on a deleted record. Restore it first.', 409);
    }

    $pdo->beginTransaction();
    $upd = $pdo->prepare("
        UPDATE BookCopies
        SET format = ?, quantity = ?, physical_location = ?, file_path = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
        WHERE copy_id = ?
    ");
    $upd->execute([
        $copy['format'],
        $copy['quantity'],
        $copy['physical_location'],
        $copy['file_path'],
        $copy['notes'],
        $copy_id,
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
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_fail($e->getMessage(), 500);
}
