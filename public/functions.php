<?php
/**
 * Central PDO factory (lazy singleton) + shared helpers.
 * Uses ../config.php for DB creds.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

const SCHEMA_VERSION = '3.0.0';

/* --------------------------- Error helpers --------------------------- */

function json_error(string $msg, int $code = 500): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_fail(string $msg, int $code = 500, array $extra = []): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $payload = array_merge(['ok' => false, 'error' => $msg], $extra);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_in(): array {
    $raw = file_get_contents('php://input');
    $d = $raw ? json_decode($raw, true) : null;
    return is_array($d) ? $d : [];
}

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $params = session_get_cookie_params();
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    $cookie_params = [
        'lifetime' => 0,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($cookie_params);
    } else {
        $path = ($cookie_params['path'] ?? '/') . '; samesite=Lax';
        session_set_cookie_params(
            $cookie_params['lifetime'],
            $path,
            $cookie_params['domain'],
            $cookie_params['secure'],
            $cookie_params['httponly']
        );
    }

    session_start();
}

function auth_failure_delay(): void {
    usleep(200000);
}

function password_policy_errors(string $password, string $username = ''): array {
    $errors = [];
    if (strlen($password) < 12) $errors[] = 'Password must be at least 12 characters.';
    if (!preg_match('/[a-z]/', $password)) $errors[] = 'Password must include a lowercase letter.';
    if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must include an uppercase letter.';
    if (!preg_match('/[0-9]/', $password)) $errors[] = 'Password must include a digit.';
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) $errors[] = 'Password must include a special character.';
    if ($username !== '' && stripos($password, $username) !== false) {
        $errors[] = 'Password cannot contain the username.';
    }
    return $errors;
}

function users_table_has_column(PDO $pdo, string $column): bool {
    static $cache = [];
    $key = strtolower($column);
    if (array_key_exists($key, $cache)) return $cache[$key];

    $st = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'Users'
          AND COLUMN_NAME = ?
    ");
    $st->execute([$column]);
    $exists = (int)$st->fetchColumn() > 0;
    $cache[$key] = $exists;
    return $exists;
}

function auth_events_table_exists(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;

    $st = $pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'AuthEvents'
    ");
    $exists = (int)$st->fetchColumn() > 0;
    return $exists;
}

function system_info_table_exists(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;

    $st = $pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'SystemInfo'
    ");
    $exists = (int)$st->fetchColumn() > 0;
    return $exists;
}

function count_active_admins(PDO $pdo): int {
    $st = $pdo->query("SELECT COUNT(*) FROM Users WHERE role = 'admin' AND is_active = 1");
    return (int)$st->fetchColumn();
}

function count_admins(PDO $pdo): int {
    $st = $pdo->query("SELECT COUNT(*) FROM Users WHERE role = 'admin'");
    return (int)$st->fetchColumn();
}

function strip_invisible_format_chars(string $v): string {
    $clean = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{2060}]/u', '', $v);
    return $clean === null ? $v : $clean;
}

function normalize_unicode_nfc(string $v): string {
    if (class_exists('Normalizer')) {
        $norm = Normalizer::normalize($v, Normalizer::FORM_C);
        if (is_string($norm)) return $norm;
    }
    return $v;
}

function sanitize_csv_value($v) {
    if (is_string($v)) {
        return normalize_unicode_nfc(strip_invisible_format_chars($v));
    }
    return $v;
}

function N($v): ?string {
    if (!isset($v)) return null;
    if (is_string($v)) {
        $v = normalize_unicode_nfc(strip_invisible_format_chars($v));
        $v = trim($v);
        return $v === '' ? null : $v;
    }
    if (is_scalar($v)) {
        $v = normalize_unicode_nfc(strip_invisible_format_chars((string)$v));
        $v = trim($v);
        return $v === '' ? null : $v;
    }
    return null;
}

/* ------------------------------ PDO ------------------------------- */

function pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    // Prefer vhost SetEnv values for DB switchover (3306/3307); fallback to existing config loading.
    $env_host = getenv('BOOKS_DB_HOST');
    $env_name = getenv('BOOKS_DB_NAME');
    $env_user = getenv('BOOKS_DB_USER');
    $env_pass = getenv('BOOKS_DB_PASS');

    if ($env_host !== false && $env_name !== false && $env_user !== false && $env_pass !== false) {
        $host = (string)$env_host;
        $port = (int)(getenv('BOOKS_DB_PORT') ?: '3306');
        $dbname = (string)$env_name;
        $user = (string)$env_user;
        $pass = (string)$env_pass;
    } else {
        $env_path = getenv('BOOKCATALOG_CONFIG') ?: '';
        $home = getenv('HOME') ?: '';
        $default_path = $home !== '' ? $home . '/.config/config.php' : '';
        $local_path = __DIR__ . '/../config.php';

        $candidates = [];
        if ($env_path !== '') $candidates[] = $env_path;
        $candidates[] = $local_path;
        if ($default_path !== '') $candidates[] = $default_path;

        $config_path = null;
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_readable($candidate)) {
                $config_path = $candidate;
                break;
            }
        }
        if ($config_path === null) {
            throw new RuntimeException("Missing or unreadable config.php (set BOOKCATALOG_CONFIG, use ./config.php, or ~/.config/config.php)");
        }

        $cfg = require $config_path;
        if (!is_array($cfg) || !isset($cfg['db']) || !is_array($cfg['db'])) {
            throw new RuntimeException("config.php does not return ['db'=>...] array");
        }

        $db = $cfg['db'];
        $host = $db['host'] ?? null;
        $port = $db['port'] ?? 3306;
        $dbname = $db['dbname'] ?? null;
        $user = $db['user'] ?? null;
        $pass = $db['pass'] ?? null;

        if (!$host || $user === null || $pass === null) {
            throw new RuntimeException("config.php missing db.host, db.user, or db.pass");
        }
        if (empty($db['dbname'])) {
            throw new RuntimeException('Database name (dbname) is not configured');
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        (int)$port,
        $dbname
    );

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/* -------------------------- Misc helpers --------------------------- */

/**
 * Ensure a string is valid UTF-8.
 * Does NOT convert encodings — only strips invalid sequences.
 */
function utf8_clean(string $s): string {
    $out = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    return ($out !== false) ? $out : $s;
}

function current_app_version(): ?string {
    $pkg_path = dirname(__DIR__) . '/frontend/package.json';
    $pkg_raw = @file_get_contents($pkg_path);
    if ($pkg_raw === false) return null;

    $pkg = json_decode($pkg_raw, true);
    if (!is_array($pkg) || empty($pkg['version'])) return null;

    return trim((string)$pkg['version']);
}

function sync_systeminfo_app_version(PDO $pdo): void {
    try {
        if (!system_info_table_exists($pdo)) return;

        $app_version = current_app_version();
        if ($app_version === null || $app_version === '') return;

        $st = $pdo->prepare("SELECT value FROM SystemInfo WHERE key_name = 'app_version' LIMIT 1");
        $st->execute();
        $current = $st->fetchColumn();
        if ($current === $app_version) return;

        $up = $pdo->prepare("
            INSERT INTO SystemInfo (key_name, value)
            VALUES ('app_version', ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ");
        $up->execute([$app_version]);
    } catch (Throwable $e) {
        // Ignore version sync errors to avoid blocking login.
    }
}

function sync_systeminfo_schema_version(PDO $pdo): void {
    try {
        if (!system_info_table_exists($pdo)) return;

        $schema_version = trim((string)SCHEMA_VERSION);
        if ($schema_version === '') return;

        $st = $pdo->prepare("SELECT value FROM SystemInfo WHERE key_name = 'schema_version' LIMIT 1");
        $st->execute();
        $current = $st->fetchColumn();
        if ($current === $schema_version) return;

        $up = $pdo->prepare("
            INSERT INTO SystemInfo (key_name, value)
            VALUES ('schema_version', ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ");
        $up->execute([$schema_version]);
    } catch (Throwable $e) {
        // Ignore version sync errors to avoid blocking login.
    }
}

function catalog_backup_dir_status(): array {
    $raw = getenv('CATALOG_BACKUP_DIR');
    $dir = $raw !== false ? trim((string)$raw) : '';
    if ($dir === '') {
        return ['enabled' => false, 'status' => 'disabled', 'dir' => ''];
    }
    $dir = rtrim($dir, "/\\");
    if (!file_exists($dir)) {
        return ['enabled' => true, 'status' => 'missing', 'dir' => $dir];
    }
    if (!is_dir($dir)) {
        return ['enabled' => true, 'status' => 'not_dir', 'dir' => $dir];
    }
    if (!is_writable($dir)) {
        return ['enabled' => true, 'status' => 'not_writable', 'dir' => $dir];
    }
    return ['enabled' => true, 'status' => 'ready', 'dir' => $dir];
}

function catalog_backup_dir_error(array $status): string {
    $dir = $status['dir'] ?? '';
    $base = "CATALOG_BACKUP_DIR is set to '{$dir}'";
    switch ($status['status'] ?? '') {
        case 'missing':
            return $base . ", but it does not exist. Fix it or remove the env var for streaming mode.";
        case 'not_dir':
            return $base . ", but it is not a directory. Fix it or remove the env var for streaming mode.";
        case 'not_writable':
            return $base . ", but it is not writable by the web server. Fix it or remove the env var for streaming mode.";
        default:
            return "CATALOG_BACKUP_DIR is not usable. Fix it or remove the env var for streaming mode.";
    }
}

function admin_tools_warnings(): array {
    $warnings = [];

    if (!class_exists('ZipArchive')) {
        $warnings[] = 'ZipArchive is not available in PHP runtime. ZIP backup/export features will fail.';
    }

    $has_imagick = class_exists('Imagick');
    $has_gd = extension_loaded('gd');
    if (!$has_imagick && !$has_gd) {
        $warnings[] = 'Neither Imagick nor GD is available in PHP runtime. Cover thumbnail generation will fail.';
    }

    $backup_status = catalog_backup_dir_status();
    if (($backup_status['enabled'] ?? false) && ($backup_status['status'] ?? '') !== 'ready') {
        $warnings[] = catalog_backup_dir_error($backup_status);
    }

    return $warnings;
}

function admin_tools_warning_message(): ?string {
    $warnings = admin_tools_warnings();
    if (!$warnings) return null;
    return implode("\n", $warnings);
}

function auth_event_request_meta(): array {
    $cf_ip = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    $remote_ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $ip_address = $cf_ip !== '' ? $cf_ip : ($remote_ip !== '' ? $remote_ip : '');
    $user_agent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $user_agent = $user_agent !== '' ? utf8_clean($user_agent) : null;

    $details = [];
    if ($cf_ip !== '') $details['ip_cf'] = $cf_ip;
    if ($remote_ip !== '') $details['ip_remote'] = $remote_ip;

    return [
        'ip_address' => $ip_address,
        'user_agent' => $user_agent,
        'details' => $details,
    ];
}

function log_auth_event(string $event_type, ?int $user_id, ?string $username_snapshot, array $details = []): void {
    try {
        $pdo = pdo();
        if (!auth_events_table_exists($pdo)) return;

        $meta = auth_event_request_meta();
        $details = array_merge($meta['details'], $details);
        $details_json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $username_snapshot = utf8_clean((string)($username_snapshot ?? ''));
        if (strlen($username_snapshot) > 190) {
            $username_snapshot = substr($username_snapshot, 0, 190);
        }

        $user_agent = $meta['user_agent'];
        if ($user_agent !== null && strlen($user_agent) > 512) {
            $user_agent = substr($user_agent, 0, 512);
        }

        $event_type = substr($event_type, 0, 32);

        $ins = $pdo->prepare("
            INSERT INTO AuthEvents
                (user_id, username_snapshot, event_type, ip_address, user_agent, details, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
        ");
        $ins->execute([
            $user_id,
            $username_snapshot,
            $event_type,
            (string)$meta['ip_address'],
            $user_agent,
            $details_json,
        ]);
    } catch (Throwable $e) {
        // Fail closed: auth flows should not break on logging errors.
    }
}

/* ---------------------- User preferences helpers ---------------------- */

function normalize_user_preferences(array $row): array {
    $logo = isset($row['logo_path']) ? trim((string)$row['logo_path']) : '';
    if ($logo !== '') {
        $logo = ltrim($logo, '/');
    }
    $per = isset($row['per_page']) ? (int)$row['per_page'] : 25;
    if ($per < 1) $per = 25;
    $bool = function ($value, bool $default): bool {
        if ($value === null) return $default;
        return (bool)$value;
    };
    return [
        'logo_url' => $logo !== '' ? $logo : null,
        'bg_color' => $row['bg_color'] ?? null,
        'fg_color' => $row['fg_color'] ?? null,
        'text_size' => $row['text_size'] ?? 'medium',
        'per_page' => $per,
        'show_cover' => $bool($row['show_cover'] ?? null, true),
        'show_subtitle' => $bool($row['show_subtitle'] ?? null, true),
        'show_series' => $bool($row['show_series'] ?? null, true),
        'show_is_hungarian' => $bool($row['show_is_hungarian'] ?? null, true),
        'show_publisher' => $bool($row['show_publisher'] ?? null, true),
        'show_language' => $bool($row['show_language'] ?? null, false),
        'show_format' => $bool($row['show_format'] ?? null, false),
        'show_year' => $bool($row['show_year'] ?? null, true),
        'show_copy_count' => $bool($row['show_copy_count'] ?? null, false),
        'show_status' => $bool($row['show_status'] ?? null, true),
        'show_placement' => $bool($row['show_placement'] ?? null, true),
        'show_isbn' => $bool($row['show_isbn'] ?? null, false),
        'show_loaned_to' => $bool($row['show_loaned_to'] ?? null, false),
        'show_loaned_date' => $bool($row['show_loaned_date'] ?? null, false),
        'show_subjects' => $bool($row['show_subjects'] ?? null, false),
        'show_notes' => $bool($row['show_notes'] ?? null, false),
    ];
}

function fetch_user_preferences(PDO $pdo, int $user_id): array {
    static $has_show_copy_count = null;
    static $has_show_language = null;
    static $has_show_format = null;
    if ($has_show_copy_count === null) {
        try {
            $col = $pdo->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'UserPreferences'
                  AND COLUMN_NAME = 'show_copy_count'
            ");
            $col->execute();
            $has_show_copy_count = ((int)$col->fetchColumn() > 0);
        } catch (Throwable $e) {
            $has_show_copy_count = false;
        }
    }
    if ($has_show_language === null) {
        try {
            $col = $pdo->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'UserPreferences'
                  AND COLUMN_NAME = 'show_language'
            ");
            $col->execute();
            $has_show_language = ((int)$col->fetchColumn() > 0);
        } catch (Throwable $e) {
            $has_show_language = false;
        }
    }
    if ($has_show_format === null) {
        try {
            $col = $pdo->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'UserPreferences'
                  AND COLUMN_NAME = 'show_format'
            ");
            $col->execute();
            $has_show_format = ((int)$col->fetchColumn() > 0);
        } catch (Throwable $e) {
            $has_show_format = false;
        }
    }

    $select_cols = "logo_path, bg_color, fg_color, text_size, per_page,
                    show_cover, show_subtitle, show_series, show_is_hungarian,
                    show_publisher, show_year, show_status, show_placement,
                    show_isbn, show_loaned_to, show_loaned_date, show_subjects, show_notes";
    if ($has_show_language) {
        $select_cols .= ", show_language";
    }
    if ($has_show_format) {
        $select_cols .= ", show_format";
    }
    if ($has_show_copy_count) {
        $select_cols .= ", show_copy_count";
    }

    $st = $pdo->prepare("SELECT {$select_cols}
                         FROM UserPreferences
                         WHERE user_id = ? LIMIT 1");
    $st->execute([$user_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'logo_url' => null,
            'bg_color' => null,
            'fg_color' => null,
            'text_size' => 'medium',
            'per_page' => 25,
            'show_cover' => true,
            'show_subtitle' => true,
            'show_series' => true,
            'show_is_hungarian' => true,
            'show_publisher' => true,
            'show_language' => false,
            'show_format' => false,
            'show_year' => true,
            'show_copy_count' => false,
            'show_status' => true,
            'show_placement' => true,
            'show_isbn' => false,
            'show_loaned_to' => false,
            'show_loaned_date' => false,
            'show_subjects' => false,
            'show_notes' => false,
        ];
    }
    return normalize_user_preferences($row);
}

/* -------------------------- BookCopies helpers -------------------------- */

function bookcopies_table_exists(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;

    $st = $pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'BookCopies'
    ");
    $exists = (int)$st->fetchColumn() > 0;
    return $exists;
}

function books_table_has_language(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;

    $st = $pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'Books'
          AND COLUMN_NAME = 'language'
    ");
    $exists = (int)$st->fetchColumn() > 0;
    return $exists;
}

function books_table_has_record_status(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;

    $st = $pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'Books'
          AND COLUMN_NAME = 'record_status'
    ");
    $exists = (int)$st->fetchColumn() > 0;
    return $exists;
}

function normalize_book_record_status(?string $status): string {
    $status = strtolower(trim((string)$status));
    $allowed = ['active', 'deleted'];
    return in_array($status, $allowed, true) ? $status : 'active';
}

function normalize_book_language(?string $language): string {
    $language = strtolower(trim((string)$language));
    $allowed = ['unknown', 'hu', 'en', 'de', 'fr'];
    return in_array($language, $allowed, true) ? $language : 'unknown';
}

function split_authors_csv(?string $authors_csv): array {
    $s = trim((string)$authors_csv);
    if ($s === '') return [];

    // Prefer semicolons to separate multiple authors.
    // If no semicolons, handle commas carefully to preserve "Last, First".
    $parts = [];
    if (strpos($s, ';') !== false) {
        $parts = explode(';', $s);
    } else {
        $comma_count = substr_count($s, ',');
        if ($comma_count === 0) {
            $parts = [$s];
        } else {
            $pieces = array_map('trim', explode(',', $s));
            $pieces = array_values(array_filter($pieces, function ($p) {
                return $p !== '' && $p !== ',' && $p !== ';';
            }));

            if ($comma_count === 1 && count($pieces) >= 2) {
                $parts = [$pieces[0] . ', ' . $pieces[1]];
            } elseif (count($pieces) >= 2 && count($pieces) % 2 === 0) {
                for ($i = 0; $i < count($pieces); $i += 2) {
                    $parts[] = $pieces[$i] . ', ' . $pieces[$i + 1];
                }
            } else {
                $parts = $pieces;
            }
        }
    }

    $names = [];
    foreach ($parts as $p) {
        $name = preg_replace('/\s+/', ' ', trim((string)$p));
        if ($name === '' || $name === ',' || $name === ';') continue;
        $names[] = $name;
    }
    return $names;
}

function infer_import_language_from_metadata(
    ?string $title,
    ?string $subtitle,
    ?string $authors_csv,
    ?int $force_authors_is_hungarian = null,
    bool $allow_author_hungarian_fallback = true
): string {
    $parts = array_values(array_filter([
        trim((string)$title),
        trim((string)$subtitle),
    ], static fn (string $part): bool => $part !== ''));

    if (!$parts) {
        return 'unknown';
    }

    $joined = implode(' ', $parts);
    $joined = str_replace(["\xE2\x80\x99", "\xE2\x80\x98"], "'", $joined);
    $needle = mb_strtolower($joined, 'UTF-8');
    $haystack = ' ' . $needle . ' ';

    foreach ($parts as $part) {
        if (
            strpos($part, 'è') !== false || strpos($part, 'È') !== false ||
            strpos($part, 'ç') !== false || strpos($part, 'Ç') !== false
        ) return 'fr';
        if (
            strpos($part, 'ä') !== false || strpos($part, 'Ä') !== false ||
            strpos($part, 'ß') !== false
        ) return 'de';
    }

    if (
        strpos($haystack, ' am ') !== false ||
        strpos($haystack, ' der ') !== false ||
        strpos($haystack, ' die ') !== false ||
        strpos($haystack, ' das ') !== false ||
        strpos($haystack, ' ein ') !== false ||
        strpos($haystack, ' über ') !== false ||
        strpos($haystack, ' um ') !== false ||
        strpos($haystack, ' von ') !== false ||
        strpos($haystack, ' und ') !== false ||
        strpos($haystack, ' im ') !== false
    ) return 'de';
    if (
        str_starts_with($needle, 'le ') ||
        str_starts_with($needle, 'une ') ||
        str_starts_with($needle, "l'") ||
        str_starts_with($needle, "d'") ||
        strpos($haystack, ' le ') !== false ||
        strpos($haystack, ' la ') !== false ||
        strpos($haystack, ' les ') !== false ||
        strpos($haystack, ' une ') !== false ||
        strpos($haystack, ' de ') !== false ||
        strpos($haystack, ' du ') !== false ||
        strpos($haystack, ' et ') !== false ||
        strpos($haystack, ' en ') !== false ||
        strpos($haystack, " l'") !== false ||
        strpos($haystack, " d'") !== false
    ) return 'fr';
    if (
        strpos($haystack, ' the ') !== false ||
        strpos($haystack, ' of ') !== false ||
        strpos($haystack, ' for ') !== false ||
        strpos($haystack, ' and ') !== false ||
        str_starts_with($needle, 'war ') ||
        strpos($haystack, ' war ') !== false ||
        str_starts_with($needle, 'what ') ||
        strpos($haystack, ' what ') !== false ||
        str_starts_with($needle, 'who ') ||
        strpos($haystack, ' who ') !== false ||
        str_starts_with($needle, 'was ') ||
        strpos($haystack, ' was ') !== false ||
        strpos($haystack, ' at ') !== false ||
        strpos($haystack, ' by ') !== false ||
        strpos($haystack, ' to ') !== false ||
        str_starts_with($needle, 'why ') ||
        strpos($haystack, ' why ') !== false ||
        strpos($haystack, ' but ') !== false ||
        strpos($haystack, ' on ') !== false ||
        strpos($haystack, "'s ") !== false ||
        strpos($haystack, ' we ') !== false ||
        strpos($haystack, ' my ') !== false
    ) return 'en';
    if (
        strpos($haystack, ' az ') !== false ||
        strpos($haystack, ' és ') !== false ||
        str_starts_with($needle, 'ö') ||
        str_starts_with($needle, 'szent ') ||
        strpos($haystack, ' ami ') !== false ||
        strpos($haystack, ' aki ') !== false
    ) return 'hu';

    foreach ($parts as $part) {
        if (preg_match('/[áÁéÉíÍóÓőŐúÚűŰ]/u', $part)) return 'hu';
    }

    if ($allow_author_hungarian_fallback) {
        $names = split_authors_csv($authors_csv);
        if ($names) {
            $all_hungarian = true;
            foreach ($names as $name) {
                if ($force_authors_is_hungarian !== null) {
                    $is_hungarian = (bool)$force_authors_is_hungarian;
                } else {
                    $is_hungarian = strpos($name, ',') === false;
                }
                if (!$is_hungarian) {
                    $all_hungarian = false;
                    break;
                }
            }
            if ($all_hungarian) {
                return 'hu';
            }
        }
    }

    return 'unknown';
}

function normalize_book_copy_format(?string $format): string {
    $format = strtolower(trim((string)$format));
    $allowed = ['print', 'epub', 'mobi', 'azw3', 'pdf', 'djvu', 'lit', 'prc', 'rtf', 'odt'];
    return in_array($format, $allowed, true) ? $format : 'print';
}

function normalize_book_copy_file_path(?string $file_path): ?string {
    $path = trim((string)$file_path);
    if ($path === '') return null;

    if (str_starts_with($path, 'file://')) {
        $url_path = parse_url($path, PHP_URL_PATH);
        if (is_string($url_path) && trim($url_path) !== '') {
            $path = trim($url_path);
        }
    }

    if (str_starts_with($path, '~/')) {
        $home = getenv('HOME') ?: '';
        if ($home !== '') {
            return rtrim($home, '/\\') . '/' . substr($path, 2);
        }
        return $path;
    }

    $is_windows_drive = (bool)preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    if (!$is_windows_drive && strpos($path, ':') !== false && strpos($path, '/') === false && strpos($path, '\\') === false) {
        $parts = array_values(array_filter(array_map('trim', explode(':', $path)), static fn (string $part): bool => $part !== ''));
        if ($parts) {
            return '/Volumes/' . implode('/', $parts);
        }
    }

    return $path;
}

function format_physical_location_from_placement(?int $bookcase_no, ?int $shelf_no): ?string {
    if ($bookcase_no === null || $shelf_no === null || $bookcase_no <= 0 || $shelf_no <= 0) {
        return null;
    }
    return '#' . $bookcase_no . '/' . $shelf_no;
}

function parse_placement_from_physical_location(?string $physical_location): ?array {
    $physical_location = trim((string)$physical_location);
    if ($physical_location === '') return null;

    if (preg_match('/^#?\s*(\d+)\s*\/\s*(\d+)$/', $physical_location, $m)) {
        $bookcase_no = (int)$m[1];
        $shelf_no = (int)$m[2];
        if ($bookcase_no > 0 && $shelf_no > 0) {
            return [
                'bookcase_no' => $bookcase_no,
                'shelf_no' => $shelf_no,
            ];
        }
    }
    return null;
}

function normalize_book_copy_input(array $copy, int $index = 0): array {
    $format = normalize_book_copy_format($copy['format'] ?? null);
    $quantity = (int)($copy['quantity'] ?? 1);
    if ($quantity < 1) $quantity = 1;

    return [
        'copy_id' => isset($copy['copy_id']) && (int)$copy['copy_id'] > 0 ? (int)$copy['copy_id'] : null,
        'format' => $format,
        'quantity' => $quantity,
        'physical_location' => N($copy['physical_location'] ?? null),
        'file_path' => $format === 'print' ? null : normalize_book_copy_file_path($copy['file_path'] ?? null),
        'notes' => N($copy['notes'] ?? null),
        '_index' => $index,
    ];
}

function fetch_book_copies_map(PDO $pdo, array $book_ids): array {
    $book_ids = array_values(array_filter(array_map('intval', $book_ids), static fn (int $id): bool => $id > 0));
    if (!$book_ids || !bookcopies_table_exists($pdo)) return [];

    $placeholders = implode(',', array_fill(0, count($book_ids), '?'));
    $st = $pdo->prepare("
        SELECT copy_id, book_id, format, quantity, physical_location, file_path, notes, created_at, updated_at
        FROM BookCopies
        WHERE book_id IN ($placeholders)
        ORDER BY book_id ASC, FIELD(format, 'print', 'epub', 'mobi', 'azw3', 'pdf', 'djvu', 'lit', 'prc', 'rtf', 'odt'), copy_id ASC
    ");
    $st->execute($book_ids);

    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $book_id = (int)$row['book_id'];
        $row['copy_id'] = (int)$row['copy_id'];
        $row['book_id'] = $book_id;
        $row['quantity'] = max(1, (int)$row['quantity']);
        $map[$book_id][] = $row;
    }
    return $map;
}

function fetch_book_copies(PDO $pdo, int $book_id): array {
    $map = fetch_book_copies_map($pdo, [$book_id]);
    return $map[$book_id] ?? [];
}

function summarize_book_formats(array $copies): string {
    if (!$copies) return '';
    $parts = [];
    foreach ($copies as $copy) {
        $format = normalize_book_copy_format($copy['format'] ?? null);
        $quantity = max(1, (int)($copy['quantity'] ?? 1));
        $parts[] = $format === 'print' ? "print x{$quantity}" : ($quantity > 1 ? "{$format} x{$quantity}" : $format);
    }
    return implode(', ', $parts);
}

function total_book_copy_quantity(array $copies, int $fallback = 1): int {
    if (!$copies) return max(1, $fallback);
    $total = 0;
    foreach ($copies as $copy) {
        $total += max(1, (int)($copy['quantity'] ?? 1));
    }
    return max(1, $total);
}

function find_first_print_copy(array $copies): ?array {
    foreach ($copies as $copy) {
        if (normalize_book_copy_format($copy['format'] ?? null) === 'print') {
            return $copy;
        }
    }
    return null;
}

function book_copy_file_path_exists(?string $file_path): bool {
    $path = trim((string)$file_path);
    if ($path === '') return false;

    $candidates = [$path];

    if (str_starts_with($path, 'file://')) {
        $url_path = parse_url($path, PHP_URL_PATH);
        if (is_string($url_path) && $url_path !== '') {
            $candidates[] = $url_path;
        }
    }

    if (str_starts_with($path, '~/')) {
        $home = getenv('HOME') ?: '';
        if ($home !== '') {
            $candidates[] = rtrim($home, '/\\') . '/' . substr($path, 2);
        }
    }

    if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
        $candidates[] = $path;
    } elseif (strpos($path, ':') !== false && strpos($path, '/') === false && strpos($path, '\\') === false) {
        $parts = array_values(array_filter(array_map('trim', explode(':', $path)), static fn (string $part): bool => $part !== ''));
        if ($parts) {
            $joined = implode('/', $parts);
            $candidates[] = '/' . $joined;
            if (count($parts) >= 2) {
                $candidates[] = '/Volumes/' . $joined;
            }
        }
    } else {
        $rel = ltrim(str_replace('\\', '/', $path), '/');
        $candidates[] = dirname(__DIR__) . '/' . $rel;
        $candidates[] = __DIR__ . '/' . $rel;
    }

    $seen = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '' || isset($seen[$candidate])) continue;
        $seen[$candidate] = true;
        if (is_file($candidate)) return true;
    }
    return false;
}

function has_restorable_ebook_copy(array $copies): bool {
    foreach ($copies as $copy) {
        $format = normalize_book_copy_format($copy['format'] ?? null);
        if ($format === 'print') continue;
        if (normalize_book_copy_file_path($copy['file_path'] ?? null) !== null) {
            return true;
        }
    }
    return false;
}

function can_restore_book_from_copies(array $copies): bool {
    return find_first_print_copy($copies) !== null || has_restorable_ebook_copy($copies);
}

function sync_book_copy_derived_fields(PDO $pdo, int $book_id, ?array $copies = null): array {
    if ($copies === null) {
        $copies = fetch_book_copies($pdo, $book_id);
    }

    $print_copy = find_first_print_copy($copies);
    $placement_id = null;
    if ($print_copy !== null) {
        $parsed = parse_placement_from_physical_location($print_copy['physical_location'] ?? null);
        if ($parsed) {
            $placement_id = getOrCreatePlacementId($pdo, $parsed);
        }
    }

    $copy_count = $copies ? total_book_copy_quantity($copies, 1) : 0;
    $upd = $pdo->prepare('UPDATE Books SET copy_count = ?, placement_id = ? WHERE book_id = ?');
    $upd->execute([$copy_count, $placement_id, $book_id]);

    return [
        'copies' => $copies,
        'copy_count' => $copy_count,
        'placement_id' => $placement_id,
        'has_print' => $print_copy !== null,
    ];
}

function upsert_default_print_copy(PDO $pdo, int $book_id, int $quantity, ?string $physical_location = null): void {
    if (!bookcopies_table_exists($pdo)) return;

    $quantity = max(1, $quantity);
    $sel = $pdo->prepare("
        SELECT copy_id
        FROM BookCopies
        WHERE book_id = ? AND format = 'print'
        ORDER BY copy_id ASC
        LIMIT 1
    ");
    $sel->execute([$book_id]);
    $copy_id = $sel->fetchColumn();

    if ($copy_id) {
        $upd = $pdo->prepare("
            UPDATE BookCopies
            SET quantity = ?, physical_location = ?, file_path = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE copy_id = ?
        ");
        $upd->execute([$quantity, $physical_location, (int)$copy_id]);
        return;
    }

    $ins = $pdo->prepare("
        INSERT INTO BookCopies (book_id, format, quantity, physical_location, file_path, notes, created_at, updated_at)
        VALUES (?, 'print', ?, ?, NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $ins->execute([$book_id, $quantity, $physical_location]);
}

function replace_book_copies(PDO $pdo, int $book_id, array $copies): array {
    if (!bookcopies_table_exists($pdo)) return [];

    $norm = [];
    foreach ($copies as $index => $copy) {
        if (!is_array($copy)) continue;
        $norm[] = normalize_book_copy_input($copy, $index);
    }
    if (!$norm) {
        $norm[] = normalize_book_copy_input([
            'format' => 'print',
            'quantity' => 1,
        ]);
    }

    $del = $pdo->prepare('DELETE FROM BookCopies WHERE book_id = ?');
    $del->execute([$book_id]);

    $ins = $pdo->prepare("
        INSERT INTO BookCopies
            (book_id, format, quantity, physical_location, file_path, notes, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");

    foreach ($norm as $copy) {
        $ins->execute([
            $book_id,
            $copy['format'],
            $copy['quantity'],
            $copy['physical_location'],
            $copy['file_path'],
            $copy['notes'],
        ]);
    }

    return fetch_book_copies($pdo, $book_id);
}

function delete_book_copy_record(PDO $pdo, int $copy_id): array {
    $copies = [];
    $book_id = 0;
    $quantity_before = 0;
    $decremented = false;
    $deleted = false;

    if (!bookcopies_table_exists($pdo)) {
        return [
            'ok' => false,
            'book_id' => 0,
            'copy_id' => $copy_id,
            'decremented' => false,
            'deleted' => false,
            'quantity_before' => 0,
            'quantity_after' => 0,
            'copies' => [],
        ];
    }

    $sel = $pdo->prepare("
        SELECT copy_id, book_id, quantity
        FROM BookCopies
        WHERE copy_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $sel->execute([$copy_id]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'ok' => true,
            'book_id' => 0,
            'copy_id' => $copy_id,
            'decremented' => false,
            'deleted' => false,
            'quantity_before' => 0,
            'quantity_after' => 0,
            'copies' => [],
        ];
    }

    $book_id = (int)$row['book_id'];
    $quantity_before = max(1, (int)$row['quantity']);
    if ($quantity_before > 1) {
        $upd = $pdo->prepare("
            UPDATE BookCopies
            SET quantity = quantity - 1, updated_at = CURRENT_TIMESTAMP
            WHERE copy_id = ? AND quantity > 1
        ");
        $upd->execute([$copy_id]);
        $decremented = $upd->rowCount() > 0;
    } else {
        $del = $pdo->prepare('DELETE FROM BookCopies WHERE copy_id = ?');
        $del->execute([$copy_id]);
        $deleted = $del->rowCount() > 0;
    }

    $copies = fetch_book_copies($pdo, $book_id);
    return [
        'ok' => true,
        'book_id' => $book_id,
        'copy_id' => $copy_id,
        'decremented' => $decremented,
        'deleted' => $deleted,
        'quantity_before' => $quantity_before,
        'quantity_after' => $decremented ? ($quantity_before - 1) : 0,
        'copies' => $copies,
    ];
}

/* ---------------------- Placement get-or-create --------------------- */

function getOrCreatePlacementId(PDO $pdo, ?array $placement): ?int {
    if (!$placement || !is_array($placement)) return null;

    $bookcase = isset($placement['bookcase_no']) ? (int)$placement['bookcase_no'] : null;
    $shelf    = isset($placement['shelf_no'])    ? (int)$placement['shelf_no']    : null;

    if ($bookcase === null || $shelf === null) return null;

    $sel = $pdo->prepare("SELECT placement_id FROM Placement WHERE bookcase_no = ? AND shelf_no = ? LIMIT 1");
    $sel->execute([$bookcase, $shelf]);
    $id = $sel->fetchColumn();
    if ($id) return (int)$id;

    try {
        $ins = $pdo->prepare("INSERT INTO Placement (bookcase_no, shelf_no) VALUES (?, ?)");
        $ins->execute([$bookcase, $shelf]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        // race → reselect
        $sel->execute([$bookcase, $shelf]);
        $id = $sel->fetchColumn();
        if ($id) return (int)$id;
        throw $e;
    }
}

/* ----------------- Generic name get-or-create (1 col) --------------- */

function getOrCreateIdByName(PDO $pdo, string $table, string $id_col, string $name_col, ?string $name_value): ?int {
    if ($name_value === null || $name_value === '') return null;

    $name_value = preg_replace('/\s+/', ' ', trim($name_value));

    $sel = $pdo->prepare("SELECT {$id_col} FROM {$table} WHERE {$name_col} = ? LIMIT 1");
    $sel->execute([$name_value]);
    $id = $sel->fetchColumn();
    if ($id) return (int)$id;

    try {
        $ins = $pdo->prepare("INSERT INTO {$table} ({$name_col}) VALUES (?)");
        $ins->execute([$name_value]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        $sel->execute([$name_value]);
        $id = $sel->fetchColumn();
        if ($id) return (int)$id;
        throw $e;
    }
}

/* ---------------- Publisher helpers (unchanged) -------------------- */

function getPublisherId(PDO $pdo, ?string $publisher_name): ?int {
    return getOrCreateIdByName($pdo, 'Publishers', 'publisher_id', 'name', $publisher_name);
}

/* --------------------- Author helpers (NEW) ------------------------ */

/**
 * Parse free-text like "Bach, Johann Sebastian" or "Johann Sebastian Bach"
 * into [first_name, last_name, sort_name("Last First")].
 * If $is_hungarian is true and there's no comma, treat the first token as last name.
 */
function parse_author_free_text(string $s, bool $is_hungarian = false): array {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if ($s === '') return ['', '', ''];

    if (strpos($s, ',') !== false) {
        // "Last, First …"
        [$last, $first] = array_map('trim', explode(',', $s, 2));
    } else {
        // "First … Last" (last token as last name)
        $parts = explode(' ', $s);
        if (count($parts) === 1) {
            $first = '';
            $last  = $parts[0];
        } elseif ($is_hungarian) {
            $looks_short = static function (string $token): bool {
                $token = trim($token);
                if ($token === '') return false;
                return mb_strlen($token, 'UTF-8') <= 2;
            };

            if (count($parts) >= 3 && $looks_short($parts[0])) {
                $last = $parts[0] . ' ' . $parts[1];
                $first = implode(' ', array_slice($parts, 2));
            } elseif (count($parts) >= 3 && $looks_short($parts[count($parts) - 2])) {
                $last = implode(' ', array_slice($parts, 0, -1));
                $first = $parts[count($parts) - 1];
            } else {
                $last  = array_shift($parts);
                $first = implode(' ', $parts);
            }
        } else {
            $last  = array_pop($parts);
            $first = implode(' ', $parts);
        }
    }

    $first = trim($first);
    $last  = trim($last);
    $sort  = trim($last . ' ' . $first); // legacy sort (no comma)

    return [$first, $last, $sort];
}

function format_author_display(?string $first, ?string $last, int $is_hungarian): string {
    $first = trim((string)$first);
    $last  = trim((string)$last);
    if ($first === '' && $last === '') return '';
    if ($is_hungarian) return trim($last . ' ' . $first);
    return trim($first . ' ' . $last);
}

function format_author_sort(?string $first, ?string $last): string {
    $first = trim((string)$first);
    $last  = trim((string)$last);
    if ($first === '' && $last === '') return '';
    if ($first === '') return $last;
    if ($last === '') return $first;
    return trim($last . ', ' . $first);
}

/**
 * Find or create an author row using Authors(first_name,last_name,sort_name).
 * Match priority:
 *   1) exact match on sort_name
 *   2) exact match on TRIM(CONCAT(first_name,' ',last_name))
 * Otherwise INSERT (first_name,last_name,sort_name).
 */
function getOrCreateAuthorIdFromFree(PDO $pdo, string $free_text, ?int $force_is_hungarian = null): ?int {
    $free_text = preg_replace('/\s+/', ' ', trim($free_text));
    if ($free_text === '') return null;

    $has_comma = (strpos($free_text, ',') !== false);
    $is_hungarian = ($force_is_hungarian !== null) ? (int)!!$force_is_hungarian : ($has_comma ? 0 : 1);

    [$first, $last, $sort] = parse_author_free_text($free_text, (bool)$is_hungarian);
    $display = format_author_display($first, $last, $is_hungarian);
    $sort_new = format_author_sort($first, $last);
    $sort_legacy = trim($last . ' ' . $first);
    $name_alt = trim($last . ', ' . $first);

    // Alternate parse to tolerate flipped HU/standard order in stored data.
    $is_hungarian_alt = (int)!$is_hungarian;
    [$first_alt, $last_alt] = parse_author_free_text($free_text, (bool)$is_hungarian_alt);
    $display_alt = format_author_display($first_alt, $last_alt, $is_hungarian_alt);
    $sort_alt = format_author_sort($first_alt, $last_alt);
    $sort_legacy_alt = trim($last_alt . ' ' . $first_alt);
    $name_alt_alt = trim($last_alt . ', ' . $first_alt);
    $first_last = trim($first . ' ' . $last);
    $first_last_alt = trim($first_alt . ' ' . $last_alt);
    $last_first = trim($last . ' ' . $first);
    $last_first_alt = trim($last_alt . ' ' . $first_alt);

    // Try by sort_name
    $sel = $pdo->prepare("SELECT author_id FROM Authors WHERE sort_name IN (?, ?) LIMIT 1");
    $sel->execute([$sort_new, $sort_legacy]);
    $id = $sel->fetchColumn();
    if ($id) return (int)$id;
    if ($sort_alt && ($sort_alt !== $sort_new || $sort_legacy_alt !== $sort_legacy)) {
        $sel->execute([$sort_alt, $sort_legacy_alt]);
        $id = $sel->fetchColumn();
        if ($id) return (int)$id;
    }

    // Try by name (legacy column)
    $sel_name = $pdo->prepare("SELECT author_id FROM Authors WHERE name IN (?, ?) LIMIT 1");
    $sel_name->execute([$display, $name_alt]);
    $id = $sel_name->fetchColumn();
    if ($id) return (int)$id;
    if ($display_alt && ($display_alt !== $display || $name_alt_alt !== $name_alt)) {
        $sel_name->execute([$display_alt, $name_alt_alt]);
        $id = $sel_name->fetchColumn();
        if ($id) return (int)$id;
    }

    // Fallback by display "First Last"
    $sel2 = $pdo->prepare("SELECT author_id
                           FROM Authors
                          WHERE TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) IN (?, ?)
                          LIMIT 1");
    $sel2->execute([$first_last, $first_last_alt ?: $first_last]);
    $id = $sel2->fetchColumn();
    if ($id) return (int)$id;

    // Fallback by display "Last First"
    $sel3 = $pdo->prepare("SELECT author_id
                           FROM Authors
                          WHERE TRIM(CONCAT(COALESCE(last_name,''),' ',COALESCE(first_name,''))) IN (?, ?)
                          LIMIT 1");
    $sel3->execute([$last_first, $last_first_alt ?: $last_first]);
    $id = $sel3->fetchColumn();
    if ($id) return (int)$id;

    // Insert
    try {
        $ins = $pdo->prepare("INSERT INTO Authors (name, first_name, last_name, sort_name, is_hungarian)
                          VALUES (?, ?, ?, ?, ?)");
        $ins->execute([
            $display ?: null,
            $first ?: null,
            $last ?: null,
            $sort_new ?: null,
            $is_hungarian,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        // Race → reselect by sort_name
        $sel->execute([$sort_new, $sort_legacy]);
        $id = $sel->fetchColumn();
        if ($id) return (int)$id;
        if ($sort_alt && ($sort_alt !== $sort_new || $sort_legacy_alt !== $sort_legacy)) {
            $sel->execute([$sort_alt, $sort_legacy_alt]);
            $id = $sel->fetchColumn();
            if ($id) return (int)$id;
        }
        $sel_name->execute([$display, $name_alt]);
        $id = $sel_name->fetchColumn();
        if ($id) return (int)$id;
        if ($display_alt && ($display_alt !== $display || $name_alt_alt !== $name_alt)) {
            $sel_name->execute([$display_alt, $name_alt_alt]);
            $id = $sel_name->fetchColumn();
            if ($id) return (int)$id;
        }
        $sel2->execute([$first_last, $first_last_alt ?: $first_last]);
        $id = $sel2->fetchColumn();
        if ($id) return (int)$id;
        $sel3->execute([$last_first, $last_first_alt ?: $last_first]);
        $id = $sel3->fetchColumn();
        if ($id) return (int)$id;
        throw $e;
    }
}

/** Public adapter (keeps old signature). */
function getAuthorId(PDO $pdo, ?string $author_name, ?int $force_is_hungarian = null): ?int {
    $author_name = trim((string)$author_name);

    if ($author_name === '' || $author_name === ',' || $author_name === ';')
        return null;    // prevent invalid inserts

    return getOrCreateAuthorIdFromFree($pdo, $author_name, $force_is_hungarian);
}
/* --------------- Book↔Authors linking (uses new helper) ------------- */

function attachAuthorsToBook(PDO $pdo, int $book_id, ?string $authors_csv, ?int $force_is_hungarian = null): void {
    if (!$book_id || !$authors_csv) return;

    $names = split_authors_csv($authors_csv);
    if (!$names) return;

    $own_tx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $own_tx = true;
    }

    try {
        foreach ($names as $name) {
            // Guard again
            if ($name === '') continue;

            $author_id = getAuthorId($pdo, $name, $force_is_hungarian);
            if (!$author_id) continue; // getAuthorId returns null on empty; be safe

            // Link if not already linked
            $link = $pdo->prepare("SELECT 1 FROM Books_Authors WHERE book_id = ? AND author_id = ? LIMIT 1");
            $link->execute([$book_id, $author_id]);
            if (!$link->fetchColumn()) {
                $ins = $pdo->prepare("INSERT INTO Books_Authors (book_id, author_id) VALUES (?, ?)");
                $ins->execute([$book_id, $author_id]);
            }
        }
        if ($own_tx) $pdo->commit();
    } catch (Throwable $e) {
        if ($own_tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* ---------------------- Convenience shims --------------------------- */

function publisherId(?string $publisher_name): ?int { return getPublisherId(pdo(), $publisher_name); }
function authorId(?string $author_name): ?int      { return getAuthorId(pdo(), $author_name); }
function attachAuthors(int $book_id, ?string $authors_csv, ?int $force_is_hungarian = null): void {
    attachAuthorsToBook(pdo(), $book_id, $authors_csv, $force_is_hungarian);
}

/* ------------------------- Cover uploads --------------------------- */

function make_thumb(string $src, string $dst, int $max_w = 200): bool {
    $max_w = max(1, (int)$max_w);

    // Try Imagick first
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick();
            $img->readImage($src);

            // Validate geometry early
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            if ($w < 1 || $h < 1) {
                $img->clear(); $img->destroy();
                throw new RuntimeException("Invalid image geometry (w={$w}, h={$h})");
            }

            // Normalize colorspace / alpha
            $img->setImageColorspace(Imagick::COLORSPACE_RGB);
            $img->setBackgroundColor(new ImagickPixel('white'));
            if (method_exists($img, 'setImageAlphaChannel')) {
                $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            }

            // Do not upscale small images
            if ($w <= $max_w) {
                $ok = $img->writeImage($dst);
                $img->clear(); $img->destroy();
                return (bool)$ok;
            }

            // Best-fit resize (keeps aspect)
            $img->thumbnailImage($max_w, 0, true);
            $ok = $img->writeImage($dst);
            $img->clear(); $img->destroy();
            return (bool)$ok;

        } catch (Throwable $e) {
            // Imagick failed → fall back to GD below
        }
    }

    // GD fallback
    $info = @getimagesize($src);
    if (!$info) return false;

    [$w, $h] = $info;
    if ($w < 1 || $h < 1) return false;

    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg': $im = imagecreatefromjpeg($src); break;
        case 'image/png':  $im = imagecreatefrompng($src);  break;
        case 'image/webp': $im = function_exists('imagecreatefromwebp') ? imagecreatefromwebp($src) : null; break;
        default: $im = null;
    }
    if (!$im) return false;

    if ($w <= $max_w) {
        return @copy($src, $dst);
    }

    $new_w = min($max_w, $w);
    $new_h = (int) round($h * $new_w / $w);

    $dst_im = imagecreatetruecolor($new_w, $new_h);
    imagealphablending($dst_im, false);
    imagesavealpha($dst_im, true);
    imagecopyresampled($dst_im, $im, 0, 0, 0, 0, $new_w, $new_h, $w, $h);

    $ok = false;
    switch ($mime) {
        case 'image/jpeg': $ok = imagejpeg($dst_im, $dst, 85); break;
        case 'image/png':  $ok = imagepng($dst_im,  $dst, 6);  break;
        case 'image/webp': $ok = function_exists('imagewebp') ? imagewebp($dst_im, $dst, 85) : false; break;
    }
    return $ok;
}

function process_cover_upload(PDO $pdo, int $book_id, array $file, int $thumb_max_w = 200): array {
    if ($book_id <= 0) {
        throw new RuntimeException('Invalid book_id', 400);
    }
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No file uploaded or upload error', 400);
    }

    $tmp_path = (string)($file['tmp_name'] ?? '');
    if ($tmp_path === '' || !is_uploaded_file($tmp_path)) {
        throw new RuntimeException('Invalid upload', 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp_path);
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Unsupported file type', 415);
    }
    if ((int)($file['size'] ?? 0) > 10*1024*1024) {
        throw new RuntimeException('File too large', 413);
    }

    $base_dir = __DIR__ . '/uploads/' . $book_id;
    if (!is_dir($base_dir) && !mkdir($base_dir, 0775, true)) {
        throw new RuntimeException('Unable to create upload directory');
    }

    foreach (glob($base_dir . "/cover*.*") ?: [] as $old) { @unlink($old); }
    foreach (glob($base_dir . "/cover-thumb*.*") ?: [] as $old) { @unlink($old); }

    $ext       = $allowed[$mime];
    $cover_fs  = $base_dir . "/cover.$ext";
    $thumb_fs  = $base_dir . "/cover-thumb.$ext";

    if (!move_uploaded_file($tmp_path, $cover_fs)) {
        throw new RuntimeException('Failed to move uploaded file');
    }

    $thumb_ok = make_thumb($cover_fs, $thumb_fs, $thumb_max_w);

    $rel_img = 'uploads/' . $book_id . '/cover.' . $ext;
    $rel_thm = $thumb_ok ? ('uploads/' . $book_id . '/cover-thumb.' . $ext) : null;

    $upd = $pdo->prepare("UPDATE Books SET cover_image = ?, cover_thumb = ? WHERE book_id = ?");
    $upd->execute([$rel_img, $rel_thm, $book_id]);

    return [
        'path' => $rel_img,
        'thumb' => $rel_thm,
        'affected_rows' => $upd->rowCount(),
    ];
}
