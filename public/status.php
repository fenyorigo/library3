<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = pdo();

    $systeminfo = [];
    if (system_info_table_exists($pdo)) {
        $st = $pdo->query("
            SELECT key_name, value
            FROM SystemInfo
            WHERE key_name IN ('app_version', 'schema_version', 'install_date')
        ");
        $systeminfo = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }

    $app_version = current_app_version();
    $schema_version = SCHEMA_VERSION;
    $version_match = ($app_version !== null && $schema_version !== '')
        && ($systeminfo['app_version'] ?? null) === $app_version
        && ($systeminfo['schema_version'] ?? null) === $schema_version;

    json_out([
        'ok' => true,
        'runtime' => [
            'app_version' => $app_version,
            'schema_version' => $schema_version,
            'systeminfo' => $systeminfo,
            'version_match' => $version_match,
            'unicode_path_warnings' => unicode_path_runtime_warnings(),
        ],
    ]);
} catch (Throwable $e) {
    json_fail($e->getMessage(), 500);
}
