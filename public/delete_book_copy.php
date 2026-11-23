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

    $in = json_in();
    $copy_id = (int)($in['copy_id'] ?? ($_GET['copy_id'] ?? 0));
    if ($copy_id <= 0) {
        json_fail('Missing or invalid copy_id', 400);
    }

    $pdo = pdo();
    $pdo->beginTransaction();
    $result = delete_book_copy_record($pdo, $copy_id);
    $book_id = (int)($result['book_id'] ?? 0);
    $book_removed = false;
    $copy_count = 0;
    if ($book_id > 0) {
        $remaining = $result['copies'] ?? [];
        if ($remaining) {
            $sync = sync_book_copy_derived_fields($pdo, $book_id, $remaining);
            $copy_count = (int)($sync['copy_count'] ?? 0);
        } else {
            $pdo->prepare("UPDATE Books SET record_status='deleted' WHERE book_id=?")->execute([$book_id]);
            catalog_evidence_sync($pdo);
        }
    } else {
        $copy_count = 0;
    }
    $pdo->commit();

    json_out([
        'ok' => true,
        'data' => [
            'book_id' => $book_id,
            'copy_id' => $copy_id,
            'copies' => $result['copies'] ?? [],
            'decremented' => (bool)($result['decremented'] ?? false),
            'deleted' => (bool)($result['deleted'] ?? false),
            'quantity_before' => (int)($result['quantity_before'] ?? 0),
            'quantity_after' => (int)($result['quantity_after'] ?? 0),
            'copy_count' => $copy_count,
            'book_removed' => $book_removed,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_fail($e->getMessage(), 500);
}
