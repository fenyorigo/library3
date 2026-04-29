<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

$me = require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

/**
 * Remove a path recursively and track removed files/dirs with file classes.
 *
 * @return array{files:int,covers:int,thumbs:int,other_files:int,dirs:int}
 */
function remove_path_recursive(string $path): array {
    $files = 0;
    $covers = 0;
    $thumbs = 0;
    $other_files = 0;
    $dirs = 0;

    if (is_link($path) || is_file($path)) {
        if (@unlink($path)) {
            $files++;
            $base = strtolower(basename($path));
            if ((bool)preg_match('/^cover-thumb\.[a-z0-9]+$/', $base)) {
                $thumbs++;
            } elseif ((bool)preg_match('/^cover\.[a-z0-9]+$/', $base) || (bool)preg_match('/^default[-_]cover\.[a-z0-9]+$/', $base)) {
                $covers++;
            } else {
                $other_files++;
            }
        }
        return ['files' => $files, 'covers' => $covers, 'thumbs' => $thumbs, 'other_files' => $other_files, 'dirs' => $dirs];
    }

    if (!is_dir($path)) {
        return ['files' => $files, 'covers' => $covers, 'thumbs' => $thumbs, 'other_files' => $other_files, 'dirs' => $dirs];
    }

    $items = @scandir($path);
    if ($items !== false) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $child = $path . DIRECTORY_SEPARATOR . $item;
            $removed = remove_path_recursive($child);
            $files += $removed['files'];
            $covers += $removed['covers'];
            $thumbs += $removed['thumbs'];
            $other_files += $removed['other_files'];
            $dirs += $removed['dirs'];
        }
    }

    if (@rmdir($path)) {
        $dirs++;
    }

    return ['files' => $files, 'covers' => $covers, 'thumbs' => $thumbs, 'other_files' => $other_files, 'dirs' => $dirs];
}

/**
 * Remove all cover/thumbnail files under public/uploads.
 *
 * @return array{files:int,covers:int,thumbs:int,other_files:int,dirs:int}
 */
function wipe_uploads_content(string $uploads_root): array {
    $removed_files = 0;
    $removed_covers = 0;
    $removed_thumbs = 0;
    $removed_other_files = 0;
    $removed_dirs = 0;

    $items = @scandir($uploads_root);
    if ($items === false) {
        throw new RuntimeException('Unable to scan uploads directory');
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        // Keep default placeholder covers during purge.
        if ($item === 'default-cover.jpg' || $item === 'default_cover.jpg') continue;
        $path = $uploads_root . DIRECTORY_SEPARATOR . $item;
        $removed = remove_path_recursive($path);
        $removed_files += $removed['files'];
        $removed_covers += $removed['covers'];
        $removed_thumbs += $removed['thumbs'];
        $removed_other_files += $removed['other_files'];
        $removed_dirs += $removed['dirs'];
    }

    return [
        'files' => $removed_files,
        'covers' => $removed_covers,
        'thumbs' => $removed_thumbs,
        'other_files' => $removed_other_files,
        'dirs' => $removed_dirs,
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_fail('Method Not Allowed', 405);
    }

    $payload = json_in();
    $confirm = trim((string)($payload['confirm'] ?? ''));
    if ($confirm !== 'DELETE') {
        json_fail('Confirmation text mismatch', 400);
    }

    $pdo = pdo();
    $tables = [
        'Books_Authors',
        'Books_Subjects',
        'duplicate_review',
        'BookCopies',
        'Books',
        'Authors',
        'Subjects',
        'Publishers',
        'Placement',
    ];

    $deleted_rows = [];

    $pdo->beginTransaction();
    foreach ($tables as $table) {
        $affected = $pdo->exec("DELETE FROM `{$table}`");
        if ($affected === false) {
            throw new RuntimeException("Failed to clear table {$table}");
        }
        $deleted_rows[$table] = (int)$affected;
    }
    $pdo->commit();

    $warnings = [];
    foreach (['Books', 'Authors', 'Subjects', 'Publishers', 'Placement'] as $table) {
        try {
            $affected = $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
            if ($affected === false) {
                $error = $pdo->errorInfo();
                $warnings[] = "Could not reset AUTO_INCREMENT for {$table}" . (!empty($error[2]) ? ": {$error[2]}" : '');
            }
        } catch (Throwable $e) {
            $warnings[] = "Could not reset AUTO_INCREMENT for {$table}: " . $e->getMessage();
        }
    }

    $uploads_root = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
    if (!is_dir($uploads_root)) {
        throw new RuntimeException('Uploads directory not found');
    }
    $removed_uploads = wipe_uploads_content($uploads_root);

    log_auth_event('catalog_purge', (int)$me['uid'], (string)$me['username'], [
        'actor_username' => (string)$me['username'],
        'deleted_rows' => $deleted_rows,
        'warnings' => $warnings,
        'deleted_upload_files' => $removed_uploads['files'],
        'deleted_upload_cover_files' => $removed_uploads['covers'],
        'deleted_upload_thumb_files' => $removed_uploads['thumbs'],
        'deleted_upload_other_files' => $removed_uploads['other_files'],
        'deleted_upload_dirs' => $removed_uploads['dirs'],
    ]);

    json_out([
        'ok' => true,
        'data' => [
            'deleted_rows' => $deleted_rows,
            'warnings' => $warnings,
            'deleted_upload_files' => $removed_uploads['files'],
            'deleted_upload_cover_files' => $removed_uploads['covers'],
            'deleted_upload_thumb_files' => $removed_uploads['thumbs'],
            'deleted_upload_other_files' => $removed_uploads['other_files'],
            'deleted_upload_dirs' => $removed_uploads['dirs'],
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_auth_event('catalog_purge_failed', (int)$me['uid'], (string)$me['username'], [
        'actor_username' => (string)$me['username'],
        'error' => $e->getMessage(),
    ]);
    json_fail($e->getMessage(), 500);
}
