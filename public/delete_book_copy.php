<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

function rrmdir_copy_delete(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $it;
        if (is_dir($path)) {
            rrmdir_copy_delete($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

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
    if ($book_id > 0) {
        $remaining = $result['copies'] ?? [];
        if ($remaining) {
            $sync = sync_book_copy_derived_fields($pdo, $book_id, $remaining);
            $copy_count = (int)($sync['copy_count'] ?? 0);
        } else {
            $pdo->prepare('DELETE FROM Books_Authors WHERE book_id = ?')->execute([$book_id]);
            $pdo->prepare('DELETE FROM Books_Subjects WHERE book_id = ?')->execute([$book_id]);
            $pdo->prepare('DELETE FROM Books WHERE book_id = ?')->execute([$book_id]);
            $copy_count = 0;
            $book_removed = true;
        }
    } else {
        $copy_count = 0;
    }
    $pdo->commit();

    if ($book_removed && $book_id > 0) {
        $uploads_base = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
        $book_dir = $uploads_base . DIRECTORY_SEPARATOR . $book_id;
        if (strpos(realpath($book_dir) ?: $book_dir, $uploads_base) === 0) {
            rrmdir_copy_delete($book_dir);
        }
    }

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
