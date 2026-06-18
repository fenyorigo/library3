<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
set_time_limit(120);
ignore_user_abort(true);
header('Content-Type: application/json; charset=utf-8');

function sha256_build_where(PDO $pdo): string {
    $status_clause = books_table_has_record_status($pdo) ? " AND b.record_status = 'active'" : '';
    return "bc.file_path IS NOT NULL
        AND TRIM(bc.file_path) <> ''
        AND bc.format <> 'print'
        AND bc.sha256 IS NULL" . $status_clause;
}

try {
    $pdo = pdo();
    if (!bookcopies_table_exists($pdo)) {
        json_fail('BookCopies table is missing.', 500);
    }
    if (!bookcopies_has_sha256($pdo)) {
        json_fail('BookCopies.sha256 column is missing. Run the schema migration first.', 500);
    }

    $repo_health = requireEbookRepositoryAvailable(false);
    $root = (string)$repo_health['mount_point'];

    $unicode_warnings = unicode_path_runtime_warnings();
    if ($unicode_warnings) {
        json_fail($unicode_warnings[0], 500);
    }

    $where = sha256_build_where($pdo);
    $count_sql = "
        SELECT COUNT(*)
        FROM BookCopies bc
        JOIN Books b ON b.book_id = bc.book_id
        WHERE {$where}
    ";

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['check'] ?? '') === '1') {
        json_out([
            'ok' => true,
            'data' => [
                'ebook_library_root' => $root,
                'repository_health' => $repo_health,
                'missing_sha256' => (int)$pdo->query($count_sql)->fetchColumn(),
                'unicode_warnings' => $unicode_warnings,
            ],
        ]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['report'] ?? '') === '1') {
        $limit = max(1, min(5000, (int)($_GET['limit'] ?? 500)));
        $st = $pdo->prepare("
            SELECT bc.copy_id, bc.book_id, bc.format, bc.file_path
            FROM BookCopies bc
            JOIN Books b ON b.book_id = bc.book_id
            WHERE {$where}
            ORDER BY bc.copy_id ASC
            LIMIT :limit
        ");
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();
        $report = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stored_path = (string)($row['file_path'] ?? '');
            $diag = diagnoseFilesystemPath($stored_path);
            $resolved = $diag['resolved_absolute_path'];
            $status = $diag['status'];
            if ($resolved !== null && !is_readable($resolved)) {
                $status = 'unreadable';
            } elseif ($resolved !== null) {
                $status = $diag['status'] === 'canonical_match' ? 'canonical_match' : 'exact_match';
            }
            $report[] = [
                'copy_id' => (int)$row['copy_id'],
                'book_id' => (int)$row['book_id'],
                'file_path' => $stored_path,
                'absolute_path' => $diag['intended_absolute_path'],
                'resolved_absolute_path' => $resolved,
                'status' => $status,
                'error' => null,
            ];
        }
        json_out([
            'ok' => true,
            'data' => [
                'ebook_library_root' => $root,
                'repository_health' => $repo_health,
                'remaining' => (int)$pdo->query($count_sql)->fetchColumn(),
                'report' => $report,
            ],
        ]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_fail('Method Not Allowed', 405);
    }

    $in = json_in();
    $limit = max(1, min(100, (int)($in['limit'] ?? ($_GET['limit'] ?? 25))));
    $after_copy_id = max(0, (int)($in['after_copy_id'] ?? ($_GET['after_copy_id'] ?? 0)));

    $batch = $pdo->prepare("
        SELECT bc.copy_id, bc.book_id, bc.format, bc.file_path
        FROM BookCopies bc
        JOIN Books b ON b.book_id = bc.book_id
        WHERE {$where}
          AND bc.copy_id > :after_copy_id
        ORDER BY bc.copy_id ASC
        LIMIT :limit
    ");
    $batch->bindValue(':after_copy_id', $after_copy_id, PDO::PARAM_INT);
    $batch->bindValue(':limit', $limit, PDO::PARAM_INT);
    $batch->execute();
    $rows = $batch->fetchAll(PDO::FETCH_ASSOC);

    $has_file_size = bookcopies_has_file_size($pdo);
    $update_sql = $has_file_size
        ? 'UPDATE BookCopies SET sha256 = ?, file_size = ?, updated_at = CURRENT_TIMESTAMP WHERE copy_id = ? AND sha256 IS NULL'
        : 'UPDATE BookCopies SET sha256 = ?, updated_at = CURRENT_TIMESTAMP WHERE copy_id = ? AND sha256 IS NULL';
    $update = $pdo->prepare($update_sql);

    $processed = 0;
    $updated = 0;
    $missing = 0;
    $unreadable = 0;
    $errors = 0;
    $report = [];
    $last_copy_id = $after_copy_id;

    foreach ($rows as $row) {
        $processed++;
        $copy_id = (int)$row['copy_id'];
        $last_copy_id = max($last_copy_id, $copy_id);
        $stored_path = (string)($row['file_path'] ?? '');
        $diag = diagnoseFilesystemPath($stored_path);
        $absolute_path = $diag['resolved_absolute_path'];
        $entry = [
            'copy_id' => $copy_id,
            'book_id' => (int)$row['book_id'],
            'file_path' => $stored_path,
            'absolute_path' => $diag['intended_absolute_path'],
            'resolved_absolute_path' => $absolute_path,
            'status' => 'error',
            'error' => null,
        ];

        try {
            if ($absolute_path === null) {
                $missing++;
                $entry['status'] = 'missing';
            } elseif (!is_readable($absolute_path)) {
                $unreadable++;
                $entry['status'] = 'unreadable';
            } else {
                $sha256 = calculateFileSha256($absolute_path);
                if ($sha256 === null) {
                    $errors++;
                    $entry['status'] = 'error';
                    $entry['error'] = 'SHA256 calculation failed';
                } else {
                    if ($has_file_size) {
                        $size = @filesize($absolute_path);
                        $update->execute([$sha256, $size === false ? 0 : max(0, (int)$size), $copy_id]);
                    } else {
                        $update->execute([$sha256, $copy_id]);
                    }
                    if ($update->rowCount() > 0) {
                        $updated++;
                        $entry['status'] = 'updated';
                    } else {
                        $entry['status'] = 'skipped';
                        $entry['error'] = 'Checksum already exists';
                    }
                }
            }
        } catch (Throwable $e) {
            $errors++;
            $entry['status'] = 'error';
            $entry['error'] = $e->getMessage();
        }

        $report[] = $entry;
    }

    $remaining = (int)$pdo->query($count_sql)->fetchColumn();
    $more = 0;
    if ($processed > 0) {
        $more_st = $pdo->prepare("
            SELECT COUNT(*)
            FROM BookCopies bc
            JOIN Books b ON b.book_id = bc.book_id
            WHERE {$where}
              AND bc.copy_id > ?
        ");
        $more_st->execute([$last_copy_id]);
        $more = (int)$more_st->fetchColumn();
    }

    json_out([
        'ok' => true,
        'data' => [
            'ebook_library_root' => $root,
            'repository_health' => $repo_health,
            'limit' => $limit,
            'after_copy_id' => $after_copy_id,
            'next_after_copy_id' => $last_copy_id,
            'processed' => $processed,
            'updated' => $updated,
            'missing' => $missing,
            'unreadable' => $unreadable,
            'errors' => $errors,
            'remaining' => $remaining,
            'done' => $processed === 0 || $more === 0,
            'report' => $report,
        ],
    ]);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException ? 400 : 500;
    json_fail($e->getMessage(), $code);
}
