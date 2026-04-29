<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function rrmdir_book_delete(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $it;
        if (is_dir($path)) {
            rrmdir_book_delete($path);
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
    $select_sql = books_table_has_record_status($pdo)
        ? 'SELECT book_id, record_status, copy_count FROM Books WHERE book_id = ?'
        : 'SELECT book_id, copy_count FROM Books WHERE book_id = ?';
    $chk = $pdo->prepare($select_sql);
    $chk->execute([$id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->rollBack();
        json_out([
            'ok' => true,
            'data' => [
                'id' => $id,
                'affected_rows' => 0,
                'record_status' => 'deleted',
                'book_removed' => false,
            ],
            'message' => 'Not found',
        ]);
    }

    $current_status = normalize_book_record_status($row['record_status'] ?? 'active');
    $copies = fetch_book_copies($pdo, $id);
    $copy_count = $copies ? total_book_copy_quantity($copies, (int)($row['copy_count'] ?? 1)) : 0;
    $book_removed = false;

    if (!$copies) {
        $pdo->prepare('DELETE FROM Books_Authors WHERE book_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM Books_Subjects WHERE book_id = ?')->execute([$id]);
        $del = $pdo->prepare('DELETE FROM Books WHERE book_id = ?');
        $del->execute([$id]);
        $affected_rows = $del->rowCount();
        $book_removed = $affected_rows > 0;
        $pdo->commit();

        if ($book_removed) {
            $uploads_base = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
            $book_dir = $uploads_base . DIRECTORY_SEPARATOR . $id;
            if (strpos(realpath($book_dir) ?: $book_dir, $uploads_base) === 0) {
                rrmdir_book_delete($book_dir);
            }
        }

        json_out([
            'ok' => true,
            'data' => [
                'id' => $id,
                'affected_rows' => $affected_rows,
                'copy_count_before' => 0,
                'copy_count_after' => 0,
                'record_status_before' => $current_status,
                'record_status_after' => 'deleted',
                'book_removed' => $book_removed,
            ],
            'message' => 'Book removed permanently because no item instances remained.',
        ]);
    }

    if (books_table_has_record_status($pdo)) {
        $upd = $pdo->prepare("UPDATE Books SET record_status = 'deleted' WHERE book_id = ?");
        $upd->execute([$id]);
        $affected_rows = $upd->rowCount();
    } else {
        $affected_rows = 0;
    }
    $pdo->commit();

    json_out([
        'ok' => true,
        'data' => [
            'id' => $id,
            'affected_rows' => $affected_rows,
            'copy_count_before' => $copy_count,
            'copy_count_after' => $copy_count,
            'record_status_before' => $current_status,
            'record_status_after' => 'deleted',
            'book_removed' => $book_removed,
        ],
        'message' => $current_status === 'deleted'
            ? 'Book is already marked deleted.'
            : 'Book marked deleted.',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_fail($e->getMessage(), 500);
}
