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

const INTEGRITY_SESSION_TTL = 86400;

function integrity_session_dir(): string {
    $dir = sys_get_temp_dir() . '/bookcatalog_integrity';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function integrity_session_path(string $token): string {
    if (!preg_match('/^[a-f0-9]{24,64}$/', $token)) {
        throw new InvalidArgumentException('Invalid integrity token');
    }
    return integrity_session_dir() . '/integrity_' . $token . '.json';
}

function integrity_load(string $token): array {
    $path = integrity_session_path($token);
    if (!is_file($path)) throw new InvalidArgumentException('Integrity session not found');
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) throw new RuntimeException('Invalid integrity session');
    return $data;
}

function integrity_save(array $session): void {
    file_put_contents(integrity_session_path((string)$session['token']), json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function integrity_cleanup_old(): void {
    foreach (glob(integrity_session_dir() . '/integrity_*.json') ?: [] as $path) {
        if (is_file($path) && filemtime($path) !== false && filemtime($path) < time() - INTEGRITY_SESSION_TTL) @unlink($path);
    }
}

function integrity_where(PDO $pdo): string {
    return "bc.file_path IS NOT NULL
        AND TRIM(bc.file_path) <> ''
        AND bc.format <> 'print'" . (books_table_has_record_status($pdo) ? " AND b.record_status = 'active'" : '');
}

function integrity_counters(): array {
    return [
        'ok' => 0,
        'sha_missing' => 0,
        'missing_on_disk' => 0,
        'sha_mismatch' => 0,
        'ok_sha_size_mismatch' => 0,
        'errors' => 0,
    ];
}

function integrity_store_result(array &$session, string $status, array $item): void {
    if (!isset($session['results'][$status])) $session['results'][$status] = [];
    if ($status !== 'ok') {
        $session['results'][$status][] = $item;
        if (count($session['results'][$status]) > 1000) {
            $session['results'][$status] = array_slice($session['results'][$status], -1000);
        }
    }
    $session['counters'][$status] = (int)($session['counters'][$status] ?? 0) + 1;
}

function integrity_copy_rows(PDO $pdo, int $after_copy_id, int $limit): array {
    $where = integrity_where($pdo);
    $st = $pdo->prepare("\n        SELECT bc.copy_id, bc.book_id, bc.format, bc.file_path, bc.file_size, bc.sha256, bc.notes, b.title\n        FROM BookCopies bc\n        JOIN Books b ON b.book_id = bc.book_id\n        WHERE {$where}\n          AND bc.copy_id > :after_copy_id\n        ORDER BY bc.copy_id ASC\n        LIMIT :limit\n    ");
    $st->bindValue(':after_copy_id', $after_copy_id, PDO::PARAM_INT);
    $st->bindValue(':limit', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function integrity_item_base(array $row, ?array $diag = null): array {
    $stored_path = normalize_book_copy_file_path($row['file_path'] ?? null);
    $diag = $diag ?? ($stored_path !== null ? diagnoseFilesystemPath($stored_path) : []);
    $intended = $diag['intended_absolute_path'] ?? ($stored_path !== null ? relativeToAbsoluteEbookPath($stored_path) : null);
    $resolved = $diag['resolved_absolute_path'] ?? null;
    return [
        'copy_id' => (int)$row['copy_id'],
        'book_id' => (int)$row['book_id'],
        'title' => $row['title'] ?? null,
        'file_path' => $stored_path,
        'expected_absolute_path' => $intended,
        'resolved_absolute_path' => $resolved,
        'path_resolved_by_canonical_match' => $resolved !== null && $intended !== null && $resolved !== $intended,
        'stored_sha256' => normalize_book_copy_sha256($row['sha256'] ?? null),
        'stored_file_size' => max(0, (int)($row['file_size'] ?? 0)),
    ];
}

try {
    $pdo = pdo();
    if (!bookcopies_table_exists($pdo) || !bookcopies_has_sha256($pdo)) {
        json_fail('BookCopies.sha256 is required. Run the SHA256 schema migration first.', 500);
    }
    $warnings = unicode_path_runtime_warnings();
    if ($warnings) json_fail($warnings[0], 500);
    $repo_health = requireEbookRepositoryAvailable(false);
    $root = (string)$repo_health['mount_point'];
    $books_root = (string)$repo_health['books_path'];

    $where = integrity_where($pdo);
    $count_sql = "SELECT COUNT(*) FROM BookCopies bc JOIN Books b ON b.book_id = bc.book_id WHERE {$where}";

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_out(['ok' => true, 'data' => [
            'ebook_library_root' => $root,
            'scan_root' => $books_root,
            'repository_health' => $repo_health,
            'total_copies' => (int)$pdo->query($count_sql)->fetchColumn(),
        ]]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_fail('Method Not Allowed', 405);

    $in = json_in();
    $action = (string)($in['action'] ?? '');

    if ($action === 'start') {
        integrity_cleanup_old();
        $token = bin2hex(random_bytes(16));
        $session = [
            'token' => $token,
            'created_at' => time(),
            'ebook_library_root' => $root,
            'scan_root' => $books_root,
            'last_copy_id' => 0,
            'checked' => 0,
            'total_copies' => (int)$pdo->query($count_sql)->fetchColumn(),
            'counters' => integrity_counters(),
            'results' => [],
        ];
        integrity_save($session);
        json_out(['ok' => true, 'data' => [
            'token' => $token,
            'ebook_library_root' => $root,
            'scan_root' => $books_root,
            'repository_health' => $repo_health,
            'total_copies' => $session['total_copies'],
            'counters' => $session['counters'],
        ]]);
    }

    $token = (string)($in['token'] ?? '');
    $session = integrity_load($token);

    if ($action === 'next') {
        $limit = max(1, min(50, (int)($in['limit'] ?? 25)));
        $rows = integrity_copy_rows($pdo, (int)$session['last_copy_id'], $limit);
        $batch_results = [];
        $processed = 0;

        foreach ($rows as $row) {
            $processed++;
            $copy_id = (int)$row['copy_id'];
            $session['last_copy_id'] = max((int)$session['last_copy_id'], $copy_id);
            try {
                $diag = diagnoseFilesystemPath((string)($row['file_path'] ?? ''));
                $base = integrity_item_base($row, $diag);
                $actual_path = $base['resolved_absolute_path'];
                if ($actual_path === null) {
                    $item = $base + ['status' => 'missing_on_disk'];
                    integrity_store_result($session, 'missing_on_disk', $item);
                    $batch_results[] = $item;
                    continue;
                }
                if (!is_readable($actual_path)) {
                    $item = $base + ['status' => 'errors', 'error' => 'File is not readable'];
                    integrity_store_result($session, 'errors', $item);
                    $batch_results[] = $item;
                    continue;
                }
                $actual_sha = calculateFileSha256($actual_path);
                $actual_size_raw = @filesize($actual_path);
                $actual_size = $actual_size_raw === false ? 0 : max(0, (int)$actual_size_raw);
                if ($actual_sha === null) {
                    $item = $base + ['status' => 'errors', 'actual_file_size' => $actual_size, 'error' => 'SHA256 calculation failed'];
                    integrity_store_result($session, 'errors', $item);
                    $batch_results[] = $item;
                    continue;
                }
                $stored_sha = $base['stored_sha256'];
                $item = $base + ['actual_sha256' => $actual_sha, 'actual_file_size' => $actual_size];
                if ($stored_sha === null) {
                    $item['status'] = 'sha_missing';
                    integrity_store_result($session, 'sha_missing', $item);
                    $batch_results[] = $item;
                } elseif ($stored_sha === $actual_sha) {
                    if ((int)$base['stored_file_size'] !== $actual_size) {
                        $item['status'] = 'ok_sha_size_mismatch';
                        integrity_store_result($session, 'ok_sha_size_mismatch', $item);
                        $batch_results[] = $item;
                    } else {
                        $item['status'] = 'ok';
                        integrity_store_result($session, 'ok', $item);
                    }
                } else {
                    $item['status'] = 'sha_mismatch';
                    integrity_store_result($session, 'sha_mismatch', $item);
                    $batch_results[] = $item;
                }
            } catch (Throwable $e) {
                $item = [
                    'status' => 'errors',
                    'copy_id' => $copy_id,
                    'book_id' => (int)($row['book_id'] ?? 0),
                    'title' => $row['title'] ?? null,
                    'file_path' => $row['file_path'] ?? null,
                    'error' => $e->getMessage(),
                ];
                integrity_store_result($session, 'errors', $item);
                $batch_results[] = $item;
            }
        }

        $session['checked'] = (int)$session['checked'] + $processed;
        $done = $processed === 0 || (int)$session['checked'] >= (int)$session['total_copies'];
        integrity_save($session);
        json_out(['ok' => true, 'data' => [
            'token' => $token,
            'processed' => $processed,
            'checked' => $session['checked'],
            'total_copies' => $session['total_copies'],
            'done' => $done,
            'counters' => $session['counters'],
            'results' => $batch_results,
        ]]);
    }

    if ($action === 'populate_missing_sha') {
        $items = is_array($in['items'] ?? null) ? $in['items'] : ($session['results']['sha_missing'] ?? []);
        $updated = 0;
        $st = $pdo->prepare('UPDATE BookCopies SET sha256 = ?, file_size = ?, updated_at = CURRENT_TIMESTAMP WHERE copy_id = ? AND sha256 IS NULL');
        foreach ($items as $item) {
            $copy_id = (int)($item['copy_id'] ?? 0);
            $sha = normalize_book_copy_sha256($item['actual_sha256'] ?? null);
            if ($copy_id <= 0 || $sha === null) continue;
            $st->execute([$sha, max(0, (int)($item['actual_file_size'] ?? 0)), $copy_id]);
            $updated += $st->rowCount();
        }
        json_out(['ok' => true, 'data' => ['updated' => $updated]]);
    }

    if ($action === 'refresh_file_size') {
        $items = is_array($in['items'] ?? null) ? $in['items'] : ($session['results']['ok_sha_size_mismatch'] ?? []);
        $updated = 0;
        $st = $pdo->prepare('UPDATE BookCopies SET file_size = ?, updated_at = CURRENT_TIMESTAMP WHERE copy_id = ? AND sha256 = ?');
        foreach ($items as $item) {
            $copy_id = (int)($item['copy_id'] ?? 0);
            $sha = normalize_book_copy_sha256($item['stored_sha256'] ?? null);
            if ($copy_id <= 0 || $sha === null) continue;
            $st->execute([max(0, (int)($item['actual_file_size'] ?? 0)), $copy_id, $sha]);
            $updated += $st->rowCount();
        }
        json_out(['ok' => true, 'data' => ['updated' => $updated]]);
    }

    if ($action === 'update_mismatched_sha') {
        $items = is_array($in['items'] ?? null) ? $in['items'] : ($session['results']['sha_mismatch'] ?? []);
        $updated = 0;
        $st = $pdo->prepare('UPDATE BookCopies SET sha256 = ?, file_size = ?, updated_at = CURRENT_TIMESTAMP WHERE copy_id = ? AND file_path = ? AND sha256 = ?');
        foreach ($items as $item) {
            $copy_id = (int)($item['copy_id'] ?? 0);
            $path = normalize_book_copy_file_path($item['file_path'] ?? null);
            $old_sha = normalize_book_copy_sha256($item['stored_sha256'] ?? null);
            $new_sha = normalize_book_copy_sha256($item['actual_sha256'] ?? null);
            if ($copy_id <= 0 || $path === null || $old_sha === null || $new_sha === null) continue;
            $st->execute([$new_sha, max(0, (int)($item['actual_file_size'] ?? 0)), $copy_id, $path, $old_sha]);
            $updated += $st->rowCount();
        }
        json_out(['ok' => true, 'data' => ['updated' => $updated]]);
    }

    if ($action === 'mark_missing_unavailable') {
        $items = is_array($in['items'] ?? null) ? $in['items'] : ($session['results']['missing_on_disk'] ?? []);
        $updated = 0;
        $stamp = gmdate('Y-m-d');
        $st = $pdo->prepare("\n            UPDATE BookCopies\n            SET notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END, ?)),\n                updated_at = CURRENT_TIMESTAMP\n            WHERE copy_id = ?\n        ");
        foreach ($items as $item) {
            $copy_id = (int)($item['copy_id'] ?? 0);
            if ($copy_id <= 0) continue;
            $st->execute(['Missing on disk during integrity check: ' . $stamp, $copy_id]);
            $updated += $st->rowCount();
        }
        json_out(['ok' => true, 'data' => ['updated' => $updated]]);
    }

    if ($action === 'export_csv') {
        $rows = [];
        foreach (($session['results'] ?? []) as $status => $items) {
            foreach ($items as $item) {
                $rows[] = [
                    'status' => $status,
                    'copy_id' => $item['copy_id'] ?? '',
                    'book_id' => $item['book_id'] ?? '',
                    'title' => $item['title'] ?? '',
                    'file_path' => $item['file_path'] ?? '',
                    'expected_absolute_path' => $item['expected_absolute_path'] ?? '',
                    'resolved_absolute_path' => $item['resolved_absolute_path'] ?? '',
                    'path_resolved_by_canonical_match' => !empty($item['path_resolved_by_canonical_match']) ? '1' : '0',
                    'stored_sha256' => $item['stored_sha256'] ?? '',
                    'actual_sha256' => $item['actual_sha256'] ?? '',
                    'stored_file_size' => $item['stored_file_size'] ?? '',
                    'actual_file_size' => $item['actual_file_size'] ?? '',
                    'error' => $item['error'] ?? '',
                ];
            }
        }
        $fh = fopen('php://temp', 'w+');
        $header = ['status','copy_id','book_id','title','file_path','expected_absolute_path','resolved_absolute_path','path_resolved_by_canonical_match','stored_sha256','actual_sha256','stored_file_size','actual_file_size','error'];
        fputcsv($fh, $header, ',', '"', '\\');
        foreach ($rows as $row) fputcsv($fh, $row, ',', '"', '\\');
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        json_out(['ok' => true, 'data' => ['filename' => 'ebook_integrity_report_' . date('Ymd_His') . '.csv', 'csv' => $csv]]);
    }

    json_fail('Unknown integrity action', 400);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException ? 400 : 500;
    json_fail($e->getMessage(), $code);
}
