<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function books_has_copy_count(PDO $pdo): bool {
    static $has_column = null;
    if ($has_column !== null) return $has_column;
    try {
        $st = $pdo->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'Books'
              AND COLUMN_NAME = 'copy_count'
        ");
        $has_column = ((int)$st->fetchColumn() > 0);
    } catch (Throwable $e) {
        $has_column = false;
    }
    return $has_column;
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $it;
        if (is_dir($path)) rrmdir($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_fail('Method Not Allowed', 405);
    }

    // accept id from JSON, POST, or GET
    $json = json_in();
    $id = null;

    if (isset($json['id']))           $id = (int)$json['id'];
    elseif (isset($_POST['id']))      $id = (int)$_POST['id'];
    elseif (isset($_GET['id']))       $id = (int)$_GET['id'];

    if (!$id || $id <= 0) {
        json_fail('Invalid or missing id', 400);
    }

    $pdo = pdo();
    $pdo->beginTransaction();

    $has_copy_count = books_has_copy_count($pdo);
    $select_sql = $has_copy_count
        ? 'SELECT book_id, cover_image, cover_thumb, copy_count FROM Books WHERE book_id = ?'
        : 'SELECT book_id, cover_image, cover_thumb FROM Books WHERE book_id = ?';
    $chk = $pdo->prepare($select_sql);
    $chk->execute([$id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        // treat as idempotent delete
        $pdo->commit();
        json_out([
            'ok' => true,
            'data' => [
                'id' => $id,
                'affected_rows' => 0,
            ],
            'message' => 'Not found (already deleted)',
        ]);
    }

    $copy_count = $has_copy_count ? max(1, (int)($row['copy_count'] ?? 1)) : 1;
    $decremented = false;
    $deleted = 0;

    if ($has_copy_count && $copy_count > 1) {
        // When a book represents multiple physical copies, a delete request removes one copy.
        $upd = $pdo->prepare('UPDATE Books SET copy_count = copy_count - 1 WHERE book_id = ? AND copy_count > 1');
        $upd->execute([$id]);
        $decremented = $upd->rowCount() > 0;
    } else {
        // Last copy: remove relations and delete the record.
        $pdo->prepare('DELETE FROM Books_Authors  WHERE book_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM Books_Subjects WHERE book_id=?')->execute([$id]);
        $del = $pdo->prepare('DELETE FROM Books WHERE book_id=?');
        $del->execute([$id]);
        $deleted = $del->rowCount();
    }

    $pdo->commit();

    if (!$decremented) {
        // Physical files are removed only when the DB row is deleted.
        $uploads_base = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
        $book_dir = $uploads_base . DIRECTORY_SEPARATOR . $id;
        if (strpos(realpath($book_dir) ?: $book_dir, $uploads_base) === 0) {
            rrmdir($book_dir);
        }
    }

    json_out([
        'ok' => true,
        'data' => [
            'id' => $id,
            'affected_rows' => $deleted,
            'decremented' => $decremented,
            'copy_count_before' => $copy_count,
            'copy_count_after' => $decremented ? ($copy_count - 1) : 0,
        ],
        'message' => $decremented ? 'Copy count decremented by one.' : 'Book deleted.',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    json_fail($e->getMessage(), 500);
}
