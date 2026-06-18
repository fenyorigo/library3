<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

set_time_limit(600);
ignore_user_abort(true);

$backup_status = catalog_backup_dir_status();
$check_mode = isset($_GET['check']) && $_GET['check'] === '1';

if ($check_mode) {
    if (!$backup_status['enabled']) {
        json_out(['ok' => true, 'mode' => 'stream']);
    }
    if ($backup_status['status'] !== 'ready') {
        json_fail(catalog_backup_dir_error($backup_status), 500);
    }
    json_out(['ok' => true, 'mode' => 'server', 'dir' => $backup_status['dir']]);
}

if ($backup_status['enabled'] && $backup_status['status'] !== 'ready') {
    json_fail(catalog_backup_dir_error($backup_status), 500);
}

$server_side = $backup_status['enabled'] && $backup_status['status'] === 'ready';
$backup_dir = $backup_status['dir'] ?? '';

if (PHP_OS_FAMILY === 'Darwin') {
    $os_label = 'macos';
} elseif (PHP_OS_FAMILY === 'Linux') {
    $os_label = 'linux';
    $os_release = @file_get_contents('/etc/os-release');
    if ($os_release !== false) {
        if (preg_match('/^ID=([a-z0-9._-]+)$/mi', $os_release, $m)) {
            if (strtolower($m[1]) === 'fedora') {
                $os_label = 'fedora';
            }
        }
    }
} else {
    $os_label = strtolower(PHP_OS_FAMILY);
}

// Default to frontend package.json version when available.
$app_version = '';
$pkg_path = dirname(__DIR__) . '/frontend/package.json';
$pkg_raw = @file_get_contents($pkg_path);
if ($pkg_raw !== false) {
    $pkg = json_decode($pkg_raw, true);
    if (is_array($pkg) && !empty($pkg['version'])) {
        $app_version = 'v' . $pkg['version'];
    }
}

$uploads_dir = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
$file_count = 0;
$cover_count = 0;
$errors = [];
$timestamp = date('Ymd_His');

if (!class_exists('ZipArchive')) {
    json_fail('ZipArchive not available in PHP runtime', 500);
}

$tmp_candidates = [sys_get_temp_dir(), '/tmp', $uploads_dir, __DIR__];
if ($server_side && $backup_dir !== '') {
    array_unshift($tmp_candidates, $backup_dir);
}
$tmp_root = '';
foreach ($tmp_candidates as $dir) {
    if (is_dir($dir) && is_writable($dir)) {
        $tmp_root = $dir;
        break;
    }
}
if ($tmp_root === '') {
    json_fail('No writable temp directory available for zip', 500);
}

$zip_path = rtrim($tmp_root, '/\\') . '/bookcatalog_covers_' . $timestamp . '.zip';
$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    error_log('export_covers_zip.php: Zip open failed for ' . $zip_path);
    json_fail('Zip open failed', 500);
}

function is_thumb_rel(string $rel): bool {
    $base = basename(str_replace('\\', '/', $rel));
    return (bool)preg_match('/(?:^|-)thumb\.[a-z0-9]+$/i', $base);
}

if (is_dir($uploads_dir) && is_readable($uploads_dir)) {
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploads_dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $fileinfo) {
            if (!$fileinfo->isFile() || !$fileinfo->isReadable()) {
                continue;
            }
            $abs = $fileinfo->getPathname();
            $rel = substr($abs, strlen($uploads_dir));
            $rel = ltrim(str_replace('\\', '/', $rel), '/');
            if (!$zip->addFile($abs, 'uploads/' . $rel)) {
                $errors[] = 'zip add failed: ' . $rel;
            }
            $file_count++;
            if (!is_thumb_rel($rel)) {
                $cover_count++;
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'uploads scan failed: ' . $e->getMessage();
    }
} else {
    $errors[] = 'uploads directory is missing or not readable';
}

$generated_at = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
$readme = <<<TXT
BookCatalog – Covers Backup
Generated: {$generated_at}

Includes:
- uploads/ (all files, preserving directory structure)
Notes:
- skipped files may indicate permission issues
TXT;
$zip->addFromString('README.txt', $readme);

if ($errors) {
    $zip->addFromString('errors.txt', implode("\n", $errors) . "\n");
}

$zip->close();

$suffix_parts = array_filter([$os_label, $app_version]);
$suffix = $suffix_parts ? '_' . implode('_', $suffix_parts) : '';
$export_name = "export_{$cover_count}_covers_{$timestamp}{$suffix}.zip";
$export_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $export_name);

if ($server_side) {
    $final_path = rtrim($backup_dir, "/\\") . '/' . $export_name;
    if (!@rename($zip_path, $final_path)) {
        @unlink($zip_path);
        json_fail('Failed to move zip into backup directory', 500);
    }
    clearstatcache(true, $final_path);
    $size_bytes = is_file($final_path) ? (int)filesize($final_path) : 0;
    error_log(sprintf(
        'BookCatalog backup completed: type=%s mode=%s file=%s size=%d bytes',
        'covers',
        'server',
        $export_name ?? '-',
        $size_bytes
    ));
    json_out([
        'ok' => true,
        'mode' => 'server',
        'dir' => $backup_dir,
        'filename' => $export_name,
        'path' => $final_path,
    ]);
}

clearstatcache(true, $zip_path);
if (!is_file($zip_path) || !is_readable($zip_path)) {
    json_fail('Zip missing or unreadable', 500);
}
$size_bytes = (int)filesize($zip_path);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $export_name . '"');
header('Content-Length: ' . filesize($zip_path));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
while (ob_get_level() > 0) {
    ob_end_clean();
}
$fh = fopen($zip_path, 'rb');
if ($fh === false) {
    json_fail('Failed to open zip for download', 500);
}
while (!feof($fh)) {
    $chunk = fread($fh, 1048576);
    if ($chunk === false) {
        break;
    }
    echo $chunk;
}
fclose($fh);

error_log(sprintf(
    'BookCatalog backup completed: type=%s mode=%s file=%s size=%d bytes',
    'covers',
    'download',
    $export_name ?? '-',
    $size_bytes
));

@unlink($zip_path);
