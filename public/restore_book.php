<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_fail('Method Not Allowed', 405);
    }

    $json = json_in();
    $id = null;
    if (isset($json['id'])) {
        $id = (int)$json['id'];
    } elseif (isset($_POST['id'])) {
        $id = (int)$_POST['id'];
    } elseif (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
    }
    if (!$id || $id <= 0) {
        json_fail('Invalid or missing id', 400);
    }

    $pdo = pdo();
    $pdo->beginTransaction();

    $sel = $pdo->prepare(
        books_table_has_record_status($pdo)
            ? 'SELECT book_id, record_status FROM Books WHERE book_id = ? LIMIT 1 FOR UPDATE'
            : 'SELECT book_id, \'active\' AS record_status FROM Books WHERE book_id = ? LIMIT 1 FOR UPDATE'
    );
    $sel->execute([$id]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_fail('Book not found', 404);
    }

    $copies = fetch_book_copies($pdo, $id);
    if (!$copies) {
        json_fail('Cannot restore a record without any remaining item instances.', 409);
    }
    if (!can_restore_book_from_copies($copies)) {
        json_fail('Cannot restore ebook-only record because no stored ebook path is currently valid.', 409);
    }

    $before = normalize_book_record_status($row['record_status'] ?? 'active');
    if (books_table_has_record_status($pdo)) {
        $pdo->prepare("UPDATE Books SET record_status = 'active' WHERE book_id = ?")->execute([$id]);
    }
    $sync = sync_book_copy_derived_fields($pdo, $id, $copies);
    $pdo->commit();

    json_out([
        'ok' => true,
        'data' => [
            'id' => $id,
            'record_status_before' => $before,
            'record_status_after' => 'active',
            'copy_count' => (int)($sync['copy_count'] ?? 0),
            'has_print' => (bool)($sync['has_print'] ?? false),
        ],
        'message' => $before === 'active'
            ? 'Book is already active.'
            : 'Book restored.',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $code = (int)$e->getCode();
    if ($code < 400 || $code > 599) $code = 500;
    json_fail($e->getMessage(), $code);
}
