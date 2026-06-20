<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$ebook_orphan_cli_job = PHP_SAPI === 'cli' && getenv('BOOKCATALOG_EBOOK_ORPHAN_JOB') === '1';
if (!$ebook_orphan_cli_job) {
    require __DIR__ . '/auth.php';
    require_admin();
}

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: application/json; charset=utf-8');

function ebook_orphan_active_condition(PDO $pdo): string {
    return books_table_has_record_status($pdo) ? "AND b.record_status = 'active'" : '';
}

function ebook_orphan_rows(PDO $pdo): array {
    $rows = $pdo->query("\n        SELECT bc.copy_id, bc.book_id, bc.format, bc.file_path, bc.file_size, bc.sha256,\n               b.title, b.subtitle, b.series, b.language\n        FROM BookCopies bc\n        JOIN Books b ON b.book_id = bc.book_id\n        WHERE bc.file_path IS NOT NULL\n          AND TRIM(bc.file_path) <> ''\n          AND bc.format <> 'print'\n          AND bc.sha256 IS NOT NULL\n          AND TRIM(bc.sha256) <> ''\n          " . ebook_orphan_active_condition($pdo) . "\n        ORDER BY bc.sha256 ASC, bc.copy_id ASC\n    ")->fetchAll(PDO::FETCH_ASSOC);

    $author_map = fetch_book_authors_metadata_map($pdo, array_map(static fn($row): int => (int)($row['book_id'] ?? 0), $rows));
    foreach ($rows as &$row) {
        $row['copy_id'] = (int)$row['copy_id'];
        $row['book_id'] = (int)$row['book_id'];
        $row['file_size'] = isset($row['file_size']) ? (int)$row['file_size'] : null;
        $row['file_path'] = normalize_book_copy_file_path($row['file_path'] ?? null);
        $row['sha256'] = normalize_book_copy_sha256($row['sha256'] ?? null);
        $authors = $author_map[$row['book_id']] ?? [];
        $row['authors'] = implode('; ', array_map(static fn(array $author): string => normalize_author_metadata_name($author['name'] ?? ''), $authors));
    }
    unset($row);
    return array_values(array_filter($rows, static fn(array $row): bool => !empty($row['file_path']) && !empty($row['sha256'])));
}

function ebook_orphan_expected_absolute(?string $file_path): ?string {
    if ($file_path === null || trim($file_path) === '') return null;
    return relativeToAbsoluteEbookPath($file_path);
}

function ebook_orphan_probe_path(?string $file_path): array {
    if ($file_path === null || trim($file_path) === '') {
        return ['exists' => false, 'absolute_path' => null, 'resolved_path' => null];
    }
    $absolute = ebook_orphan_expected_absolute($file_path);
    $resolved = resolveFilesystemPath($file_path);
    return [
        'exists' => $resolved !== null,
        'absolute_path' => $absolute,
        'resolved_path' => $resolved,
    ];
}

function ebook_orphan_rank_keep(array $row): array {
    $exists = !empty($row['exists']);
    $title = trim((string)($row['title'] ?? ''));
    $path = trim((string)($row['file_path'] ?? ''));
    return [
        $exists ? 1 : 0,
        $title !== '' && mb_strtolower($title, 'UTF-8') !== 'beck wissen' ? 1 : 0,
        str_starts_with(basename($path), '_NoAuthor') ? 0 : 1,
        (int)($row['copy_id'] ?? 0),
    ];
}

function ebook_orphan_compare_keep(array $a, array $b): int {
    $ra = ebook_orphan_rank_keep($a);
    $rb = ebook_orphan_rank_keep($b);
    for ($i = 0; $i < count($ra); $i++) {
        if ($ra[$i] === $rb[$i]) continue;
        return $ra[$i] <=> $rb[$i];
    }
    return 0;
}

function ebook_orphan_pick_keep(array $rows): array {
    usort($rows, static fn(array $a, array $b): int => ebook_orphan_compare_keep($b, $a));
    return $rows[0];
}

function ebook_orphan_public_row(array $row): array {
    return [
        'copy_id' => $row['copy_id'] ?? null,
        'book_id' => $row['book_id'] ?? null,
        'file_path' => $row['file_path'] ?? null,
        'absolute_path' => $row['absolute_path'] ?? null,
        'resolved_path' => $row['resolved_path'] ?? null,
        'exists' => !empty($row['exists']),
        'sha256' => $row['sha256'] ?? null,
        'file_size' => $row['file_size'] ?? null,
        'title' => $row['title'] ?? null,
        'subtitle' => $row['subtitle'] ?? null,
        'series' => $row['series'] ?? null,
        'language' => $row['language'] ?? null,
        'authors' => $row['authors'] ?? null,
    ];
}

function ebook_orphan_analyze(PDO $pdo): array {
    $groups = [];
    foreach (ebook_orphan_rows($pdo) as $row) {
        $probe = ebook_orphan_probe_path($row['file_path']);
        $row += $probe;
        $groups[(string)$row['sha256']][] = $row;
    }

    $candidates = [];
    $missing_only = [];
    foreach ($groups as $sha => $rows) {
        $existing = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['exists'])));
        $missing = array_values(array_filter($rows, static fn(array $row): bool => empty($row['exists'])));
        $paths = [];
        foreach ($existing as $row) {
            $paths[(string)$row['file_path']][] = $row;
        }

        $delete = [];
        $reason = null;
        if (count($rows) > 1 && count($existing) > 0) {
            $keep = ebook_orphan_pick_keep($existing);
            foreach ($rows as $row) {
                if ((int)$row['copy_id'] === (int)$keep['copy_id']) continue;
                if (empty($row['exists']) || (string)$row['file_path'] === (string)$keep['file_path']) {
                    $delete[] = $row;
                }
            }
            if ($delete) {
                $reason = count($missing) > 0 ? 'missing_path_replaced_by_existing_sha' : 'duplicate_catalog_rows_same_file';
            }
        }

        if ($delete) {
            $candidates[] = [
                'sha256' => $sha,
                'reason' => $reason,
                'keep' => ebook_orphan_public_row($keep),
                'delete' => array_map('ebook_orphan_public_row', $delete),
                'active_rows' => count($rows),
                'existing_rows' => count($existing),
                'missing_rows' => count($missing),
            ];
        } elseif (count($missing) > 0 && count($existing) === 0) {
            foreach ($missing as $row) $missing_only[] = ebook_orphan_public_row($row);
        }
    }

    usort($candidates, static fn(array $a, array $b): int => strcmp((string)($a['keep']['file_path'] ?? ''), (string)($b['keep']['file_path'] ?? '')));
    return [
        'ebook_mount_point' => getEbookLibraryRoot(),
        'candidates' => $candidates,
        'missing_only' => array_slice($missing_only, 0, 500),
        'summary' => [
            'candidate_groups' => count($candidates),
            'delete_rows' => array_sum(array_map(static fn(array $c): int => count($c['delete'] ?? []), $candidates)),
            'missing_only' => count($missing_only),
        ],
    ];
}

function ebook_orphan_csv(array $analysis): string {
    $headers = ['status', 'reason', 'sha256', 'copy_id', 'book_id', 'file_path', 'title', 'subtitle', 'series', 'authors', 'keep_copy_id', 'keep_book_id', 'keep_file_path'];
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) return '';
    fputcsv($fh, $headers);
    foreach (($analysis['candidates'] ?? []) as $candidate) {
        foreach (($candidate['delete'] ?? []) as $row) {
            fputcsv($fh, [
                'soft_delete_candidate',
                $candidate['reason'] ?? '',
                $candidate['sha256'] ?? '',
                $row['copy_id'] ?? '',
                $row['book_id'] ?? '',
                $row['file_path'] ?? '',
                $row['title'] ?? '',
                $row['subtitle'] ?? '',
                $row['series'] ?? '',
                $row['authors'] ?? '',
                $candidate['keep']['copy_id'] ?? '',
                $candidate['keep']['book_id'] ?? '',
                $candidate['keep']['file_path'] ?? '',
            ]);
        }
    }
    foreach (($analysis['missing_only'] ?? []) as $row) {
        fputcsv($fh, [
            'missing_no_replacement', '', $row['sha256'] ?? '', $row['copy_id'] ?? '', $row['book_id'] ?? '', $row['file_path'] ?? '',
            $row['title'] ?? '', $row['subtitle'] ?? '', $row['series'] ?? '', $row['authors'] ?? '', '', '', '',
        ]);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv === false ? '' : $csv;
}

function ebook_orphan_soft_delete(PDO $pdo, array $items): array {
    if (!books_table_has_record_status($pdo)) {
        return ['updated' => 0, 'warnings' => [['error' => 'Books.record_status is not available']]];
    }
    $updated = 0;
    $copy_rows_deleted = 0;
    $warnings = [];
    $st = $pdo->prepare("UPDATE Books SET record_status = 'deleted' WHERE book_id = ? AND record_status = 'active'");
    $del_copy = $pdo->prepare('DELETE FROM BookCopies WHERE copy_id = ? AND book_id = ?');
    foreach ($items as $group_index => $candidate) {
        if (!is_array($candidate)) continue;
        $keep_book_id = (int)($candidate['keep']['book_id'] ?? 0);
        $keep_copy_id = (int)($candidate['keep']['copy_id'] ?? 0);
        foreach (($candidate['delete'] ?? []) as $row_index => $row) {
            if (!is_array($row)) continue;
            $book_id = (int)($row['book_id'] ?? 0);
            $copy_id = (int)($row['copy_id'] ?? 0);
            if ($book_id <= 0) {
                $warnings[] = ['group' => $group_index, 'row' => $row_index, 'error' => 'Invalid soft-delete target'];
                continue;
            }
            if ($keep_book_id > 0 && $book_id === $keep_book_id) {
                if ($copy_id <= 0 || ($keep_copy_id > 0 && $copy_id === $keep_copy_id)) {
                    $warnings[] = ['group' => $group_index, 'row' => $row_index, 'error' => 'Invalid duplicate copy target'];
                    continue;
                }
                $del_copy->execute([$copy_id, $book_id]);
                $copy_rows_deleted += $del_copy->rowCount();
                continue;
            }
            $st->execute([$book_id]);
            $updated += $st->rowCount();
        }
    }
    return ['updated' => $updated, 'copy_rows_deleted' => $copy_rows_deleted, 'warnings' => $warnings];
}

function ebook_orphan_soft_delete_rows(PDO $pdo, array $rows): array {
    if (!books_table_has_record_status($pdo)) {
        return ['updated' => 0, 'warnings' => [['error' => 'Books.record_status is not available']]];
    }
    $updated = 0;
    $warnings = [];
    $st = $pdo->prepare("UPDATE Books SET record_status = 'deleted' WHERE book_id = ? AND record_status = 'active'");
    foreach ($rows as $index => $row) {
        if (!is_array($row)) continue;
        $book_id = (int)($row['book_id'] ?? 0);
        if ($book_id <= 0) {
            $warnings[] = ['row' => $index, 'error' => 'Invalid soft-delete target'];
            continue;
        }
        $st->execute([$book_id]);
        $updated += $st->rowCount();
    }
    return ['updated' => $updated, 'warnings' => $warnings];
}

function ebook_orphan_job_dir(): string {
    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/bookcatalog_ebook_orphan_jobs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function ebook_orphan_job_token(): string {
    return bin2hex(random_bytes(16));
}

function ebook_orphan_job_path(string $token): string {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new InvalidArgumentException('Invalid ebook orphan job token');
    }
    return ebook_orphan_job_dir() . '/ebook_orphan_' . $token . '.json';
}

function ebook_orphan_job_write(string $path, array $data): void {
    $data['updated_at'] = date(DateTimeInterface::ATOM);
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function ebook_orphan_job_read(string $path): array {
    if (!is_file($path)) throw new RuntimeException('Ebook orphan job not found');
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) throw new RuntimeException('Invalid ebook orphan job status');
    return $data;
}

function ebook_orphan_cli_binary(): string {
    $candidates = [];
    $bindir = defined('PHP_BINDIR') ? (string)PHP_BINDIR : '';
    if ($bindir !== '') $candidates[] = rtrim($bindir, '/\\') . '/php';
    $candidates[] = '/opt/homebrew/bin/php';
    $candidates[] = '/usr/local/bin/php';
    $candidates[] = '/usr/bin/php';
    foreach (array_values(array_unique($candidates)) as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) return $candidate;
    }
    return 'php';
}

function ebook_orphan_log_error(?string $log_path): ?string {
    if (!$log_path || !is_file($log_path)) return null;
    $log = trim((string)file_get_contents($log_path));
    if ($log === '') return null;
    if (stripos($log, 'Usage: php-fpm') !== false) {
        return 'Background job tried to run php-fpm instead of PHP CLI. Please retry with the updated ebook orphan runner.';
    }
    if (stripos($log, 'Fatal error') !== false || stripos($log, 'Parse error') !== false || stripos($log, 'Uncaught') !== false) {
        return mb_substr($log, 0, 1000, 'UTF-8');
    }
    return null;
}

$pdo = pdo();

try {
    $repo_health = requireEbookRepositoryAvailable(false);

    if ($ebook_orphan_cli_job) {
        $job_path = getenv('BOOKCATALOG_EBOOK_ORPHAN_STATUS');
        if ($job_path === false || trim((string)$job_path) === '') {
            throw new RuntimeException('Missing ebook orphan job status path');
        }
        $analysis = ebook_orphan_analyze($pdo);
        $analysis['repository_health'] = $repo_health;
        ebook_orphan_job_write((string)$job_path, [
            'ok' => true,
            'status' => 'complete',
            'completed_at' => date(DateTimeInterface::ATOM),
            'data' => $analysis,
        ]);
        exit;
    }

    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = $method === 'GET' ? (string)($_GET['action'] ?? '') : '';
    if ($method === 'GET' && $action === 'status') {
        $token = (string)($_GET['token'] ?? '');
        $job_path = ebook_orphan_job_path($token);
        $job = ebook_orphan_job_read($job_path);
        if (($job['status'] ?? '') === 'running') {
            $log_error = ebook_orphan_log_error($job['log_path'] ?? null);
            if ($log_error !== null) {
                $job['ok'] = false;
                $job['status'] = 'error';
                $job['error'] = $log_error;
                ebook_orphan_job_write($job_path, $job);
            }
        }
        json_out(['ok' => true, 'job' => $job]);
    }

    if ($method === 'GET') {
        json_out(['ok' => true, 'data' => [
            'mode' => 'async',
            'repository_health' => $repo_health,
            'message' => 'Use action=start_async to run ebook orphan maintenance analysis.',
        ]]);
    }

    if ($method !== 'POST') {
        json_fail('Method Not Allowed', 405);
    }

    $d = json_in();
    $action = (string)($d['action'] ?? '');
    if ($action === 'start_async') {
        $token = ebook_orphan_job_token();
        $job_path = ebook_orphan_job_path($token);
        $log_path = ebook_orphan_job_dir() . '/ebook_orphan_' . $token . '.log';
        ebook_orphan_job_write($job_path, [
            'ok' => true,
            'status' => 'running',
            'token' => $token,
            'started_at' => date(DateTimeInterface::ATOM),
            'log_path' => $log_path,
            'message' => 'Ebook orphan maintenance analysis is running on the server.',
        ]);

        $env_parts = [
            'BOOKCATALOG_EBOOK_ORPHAN_JOB=1',
            'BOOKCATALOG_EBOOK_ORPHAN_STATUS=' . escapeshellarg($job_path),
        ];
        $config = getenv('BOOKCATALOG_CONFIG');
        if ($config !== false && trim((string)$config) !== '') {
            $env_parts[] = 'BOOKCATALOG_CONFIG=' . escapeshellarg((string)$config);
        }
        $catalog_backup_dir = getenv('CATALOG_BACKUP_DIR');
        if ($catalog_backup_dir !== false && trim((string)$catalog_backup_dir) !== '') {
            $env_parts[] = 'CATALOG_BACKUP_DIR=' . escapeshellarg((string)$catalog_backup_dir);
        }
        $cmd = implode(' ', $env_parts)
            . ' ' . escapeshellarg(ebook_orphan_cli_binary())
            . ' ' . escapeshellarg(__FILE__)
            . ' > ' . escapeshellarg($log_path)
            . ' 2>&1 &';
        exec($cmd, $out, $code);
        if ($code !== 0) {
            ebook_orphan_job_write($job_path, [
                'ok' => false,
                'status' => 'error',
                'token' => $token,
                'started_at' => date(DateTimeInterface::ATOM),
                'error' => 'Failed to start ebook orphan maintenance background job.',
            ]);
            json_fail('Failed to start ebook orphan maintenance background job', 500);
        }
        json_out(['ok' => true, 'token' => $token, 'status' => 'running']);
    }

    if ($action === 'export_csv') {
        if (!is_array($d['analysis'] ?? null)) {
            json_fail('CSV export requires an already loaded ebook orphan analysis.', 400);
        }
        $analysis = $d['analysis'];
        json_out(['ok' => true, 'data' => [
            'filename' => 'ebook_orphan_maintenance_' . date('Ymd_His') . '.csv',
            'csv' => ebook_orphan_csv($analysis),
        ]]);
    }
    if ($action === 'soft_delete') {
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        json_out(['ok' => true, 'data' => ebook_orphan_soft_delete($pdo, $items)]);
    }
    if ($action === 'soft_delete_missing') {
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        json_out(['ok' => true, 'data' => ebook_orphan_soft_delete_rows($pdo, $items)]);
    }
    json_fail('Unknown action', 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($ebook_orphan_cli_job) {
        $job_path = getenv('BOOKCATALOG_EBOOK_ORPHAN_STATUS');
        if ($job_path !== false && trim((string)$job_path) !== '') {
            ebook_orphan_job_write((string)$job_path, [
                'ok' => false,
                'status' => 'error',
                'completed_at' => date(DateTimeInterface::ATOM),
                'error' => $e->getMessage(),
            ]);
            exit;
        }
    }
    json_fail($e->getMessage(), 500);
}
