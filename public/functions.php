<?php
/**
 * Central PDO factory (lazy singleton) + shared helpers.
 * Uses ../config.php for DB creds.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

const SCHEMA_VERSION = '3.6.0';

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

function table_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    try {
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $safe_table = str_replace("'", "''", $table);
            $rows = $pdo->query("PRAGMA table_info('{$safe_table}')")->fetchAll(PDO::FETCH_ASSOC);
            $cache[$key] = false;
            foreach ($rows as $row) {
                if (($row['name'] ?? '') === $column) {
                    $cache[$key] = true;
                    break;
                }
            }
            return $cache[$key];
        }

        $st = $pdo->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $st->execute([$table, $column]);
        $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function books_authors_has_author_alias(PDO $pdo): bool {
    return table_column_exists($pdo, 'Books_Authors', 'author_alias');
}

function next_book_id(PDO $pdo): int {
    $next_id = (int)$pdo->query('SELECT COALESCE(MAX(book_id), 0) + 1 FROM Books')->fetchColumn();
    return max(1, $next_id);
}

function reset_books_auto_increment(PDO $pdo): int {
    $next_auto_increment = next_book_id($pdo);
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $pdo->prepare("DELETE FROM sqlite_sequence WHERE name = 'Books'")->execute();
        return $next_auto_increment;
    }
    $pdo->exec('ALTER TABLE Books AUTO_INCREMENT = ' . $next_auto_increment);
    return $next_auto_increment;
}

function archive_should_skip_path(string $path): bool {
    $parts = preg_split('#[\\\\/]+#', str_replace('\\', '/', $path)) ?: [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') continue;
        if ($part === '.DS_Store' || $part === '.AppleDouble' || $part === '.LSOverride') return true;
        if (strncmp($part, '._', 2) === 0) return true;
        if (preg_match('/^Icon\r?$/', $part)) return true;
    }
    return false;
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
    csrf_token_ensure();
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; object-src 'none'");
}

function csrf_token_ensure(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function require_csrf(): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $session_token = (string)($_SESSION['csrf_token'] ?? '');
    if ($session_token === '' || !hash_equals($session_token, $token)) {
        json_fail('CSRF token mismatch', 403);
    }
}

function auth_failure_delay(): void {
    usleep(200000);
}

function request_ip(): string {
    $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '') return $cf;
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function login_rate_limit_check(PDO $pdo, string $ip): void {
    if (!auth_events_table_exists($pdo)) return;
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM AuthEvents
        WHERE ip_address = ?
          AND event_type = 'login_failed'
          AND created_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE
    ");
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 10) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Too many failed login attempts. Try again later.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
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

function strip_control_chars(string $s): string {
    $out = preg_replace('/[\r\n\x00-\x1f\x7f]/', '', $s);
    return $out !== null ? $out : $s;
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
    $user_agent = $user_agent !== '' ? strip_control_chars(utf8_clean($user_agent)) : null;

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

        $username_snapshot = strip_control_chars(utf8_clean((string)($username_snapshot ?? '')));
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
        'show_file_size' => $bool($row['show_file_size'] ?? null, false),
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
    static $has_show_file_size = null;
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
    if ($has_show_file_size === null) {
        try {
            $col = $pdo->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'UserPreferences'
                  AND COLUMN_NAME = 'show_file_size'
            ");
            $col->execute();
            $has_show_file_size = ((int)$col->fetchColumn() > 0);
        } catch (Throwable $e) {
            $has_show_file_size = false;
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
    if ($has_show_file_size) {
        $select_cols .= ", show_file_size";
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
            'show_file_size' => false,
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

function bookcopies_has_file_size(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;

    $st = $pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'BookCopies'
          AND COLUMN_NAME = 'file_size'
    ");
    $exists = (int)$st->fetchColumn() > 0;
    return $exists;
}

function bookcopies_has_sha256(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;

    $st = $pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'BookCopies'
          AND COLUMN_NAME = 'sha256'
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

function normalize_author_metadata_name(?string $name): string {
    $name = normalize_unicode_nfc(strip_invisible_format_chars(trim((string)$name)));
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim((string)$name);
    if (preg_match('/^@(.+)@$/u', $name, $m)) {
        $name = trim((string)$m[1]);
    }
    return $name;
}

function normalize_author_alias(?string $alias): ?string {
    $alias = normalize_unicode_nfc(strip_invisible_format_chars(trim((string)$alias)));
    $alias = preg_replace('/\s+/', ' ', $alias);
    $alias = trim((string)$alias);
    return $alias !== '' ? $alias : null;
}

function normalize_author_metadata_flag($value): ?int {
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (is_int($value)) {
        return $value ? 1 : 0;
    }
    if (is_float($value)) {
        return ((int)$value) ? 1 : 0;
    }
    if (is_string($value)) {
        $v = strtolower(trim($value));
        if ($v === '') return null;
        if (in_array($v, ['1', 'true', 'yes', 'y'], true)) return 1;
        if (in_array($v, ['0', 'false', 'no', 'n'], true)) return 0;
    }
    return null;
}

function parse_authors_metadata_json(?string $authors_metadata_json): array {
    $json = trim((string)$authors_metadata_json);
    if ($json === '') return [];

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return [];

    $entries = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) continue;

        $name = normalize_author_metadata_name($entry['name'] ?? null);
        if ($name === '') continue;

        $flag = normalize_author_metadata_flag($entry['is_hungarian'] ?? null);
        $alias = normalize_author_alias($entry['author_alias'] ?? ($entry['alias'] ?? null));
        $entries[] = [
            'name' => $name,
            'is_hungarian' => $flag,
            'author_alias' => $alias,
        ];
    }

    return $entries;
}

function build_authors_metadata_json(array $authors): string {
    $payload = [];
    foreach ($authors as $author) {
        if (!is_array($author)) continue;

        $name = normalize_author_metadata_name($author['name'] ?? null);
        if ($name === '') continue;

        $entry = [
            'name' => $name,
            'is_hungarian' => (bool)normalize_author_metadata_flag($author['is_hungarian'] ?? null),
        ];
        $alias = normalize_author_alias($author['author_alias'] ?? ($author['alias'] ?? null));
        if ($alias !== null) {
            $entry['author_alias'] = $alias;
        }
        $payload[] = $entry;
    }

    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
}

function resolve_import_author_entries(?string $authors_csv, ?string $authors_metadata_json = null): array {
    $names = split_authors_csv($authors_csv);
    $metadata = parse_authors_metadata_json($authors_metadata_json);

    if (!$names) {
        return $metadata;
    }
    if (!$metadata) {
        return array_map(static function (string $name): array {
            return ['name' => normalize_author_metadata_name($name), 'is_hungarian' => null, 'author_alias' => null];
        }, $names);
    }

    $entries = [];
    $used = [];
    $metadata_by_name = [];
    foreach ($metadata as $index => $entry) {
        $key = mb_strtolower(normalize_author_metadata_name($entry['name'] ?? ''), 'UTF-8');
        if ($key === '') continue;
        $metadata_by_name[$key][] = $index;
    }

    foreach ($names as $index => $name) {
        $flag = null;
        $matched = null;
        $name_key = mb_strtolower(normalize_author_metadata_name($name), 'UTF-8');

        if (isset($metadata[$index])) {
            $meta_name = normalize_author_metadata_name($metadata[$index]['name'] ?? '');
            if ($meta_name !== '' && mb_strtolower($meta_name, 'UTF-8') === $name_key) {
                $matched = $index;
            }
        }

        if ($matched === null && isset($metadata_by_name[$name_key])) {
            foreach ($metadata_by_name[$name_key] as $candidate) {
                if (!isset($used[$candidate])) {
                    $matched = $candidate;
                    break;
                }
            }
        }

        $alias = null;
        if ($matched !== null) {
            $used[$matched] = true;
            $flag = $metadata[$matched]['is_hungarian'] ?? null;
            $alias = normalize_author_alias($metadata[$matched]['author_alias'] ?? ($metadata[$matched]['alias'] ?? null));
        }

        $entries[] = [
            'name' => normalize_author_metadata_name($name),
            'is_hungarian' => $flag,
            'author_alias' => $alias,
        ];
    }

    return $entries;
}

function fetch_book_authors_metadata_map(PDO $pdo, array $book_ids): array {
    $book_ids = array_values(array_filter(array_map('intval', $book_ids), static fn (int $id): bool => $id > 0));
    if (!$book_ids) return [];

    $placeholders = implode(',', array_fill(0, count($book_ids), '?'));
    $alias_select = books_authors_has_author_alias($pdo) ? 'ba.author_alias' : 'NULL';
    $st = $pdo->prepare("
        SELECT
            ba.book_id,
            ba.author_ord,
            a.author_id,
            TRIM(
                COALESCE(
                    a.name,
                    CASE
                        WHEN a.is_hungarian = 1
                            THEN CONCAT(COALESCE(a.last_name,''),' ',COALESCE(a.first_name,''))
                        ELSE CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,''))
                    END
                )
            ) AS name,
            a.is_hungarian,
            {$alias_select} AS author_alias
        FROM Books_Authors ba
        JOIN Authors a ON a.author_id = ba.author_id
        WHERE ba.book_id IN ($placeholders)
        ORDER BY ba.book_id ASC, ba.author_ord ASC, a.author_id ASC
    ");
    $st->execute($book_ids);

    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $book_id = (int)$row['book_id'];
        $name = normalize_author_metadata_name($row['name'] ?? null);
        if ($name === '') continue;

        $entry = [
            'name' => $name,
            'is_hungarian' => (int)($row['is_hungarian'] ?? 0),
        ];
        $alias = normalize_author_alias($row['author_alias'] ?? null);
        if ($alias !== null) {
            $entry['author_alias'] = $alias;
        }
        $map[$book_id][] = $entry;
    }

    return $map;
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

function getSetting(string $key, $default = null) {
    $key = trim($key);
    if ($key === '') return $default;

    try {
        $st = pdo()->prepare('SELECT setting_value FROM Settings WHERE setting_key = ? LIMIT 1');
        $st->execute([$key]);
        $value = $st->fetchColumn();
        return $value === false ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function setSetting(string $key, $value): void {
    $key = trim($key);
    if ($key === '') {
        throw new InvalidArgumentException('Missing setting key');
    }

    $st = pdo()->prepare("
        INSERT INTO Settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
          setting_value = VALUES(setting_value),
          updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([$key, $value === null ? null : (string)$value]);
}

function unicode_path_normalization_available(): bool {
    return class_exists('Normalizer');
}

function unicode_path_runtime_warnings(): array {
    return unicode_path_normalization_available()
        ? []
        : ['PHP intl extension / Normalizer is missing; Unicode path normalization is not reliable.'];
}

function filesystemPathString(?string $path): ?string {
    $path = (string)($path ?? '');
    if (trim($path) === '') return null;

    if (str_starts_with(trim($path), 'file://')) {
        $url_path = parse_url(trim($path), PHP_URL_PATH);
        if (is_string($url_path) && trim($url_path) !== '') {
            $path = $url_path;
        }
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    return $path;
}

function canonicalPathString(?string $path): ?string {
    $path = filesystemPathString($path);
    if ($path === null) return null;

    $path = str_replace("\u{00A0}", ' ', $path);
    $path = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}", "\u{2060}"], '', $path);
    $path = preg_replace('/ {2,}/u', ' ', $path) ?? $path;
    $path = trim($path);

    if (unicode_path_normalization_available()) {
        $normalized = Normalizer::normalize($path, Normalizer::FORM_C);
        if (is_string($normalized)) {
            $path = $normalized;
        }
    }

    return $path === '' ? null : $path;
}

function normalize_posix_path_value(?string $path): ?string {
    return canonicalPathString($path);
}

function normalizeEbookLibraryRoot(string $path): string {
    $normalized = normalize_posix_path_value($path) ?? '';
    $normalized = rtrim($normalized, '/');
    if ($normalized === '') {
        throw new InvalidArgumentException('Ebook library root cannot be empty');
    }
    if ($normalized[0] !== '/') {
        throw new InvalidArgumentException('Ebook library root must be an absolute mount path');
    }
    return $normalized;
}

function getEbookLibraryRoot(): string {
    return normalizeEbookLibraryRoot((string)getSetting('ebook_library_root', '/Volumes/SanDisk 2T'));
}

function checkEbookRepositoryHealth(bool $write_test = false): array {
    $mount_point = '';
    $books_path = '';
    $readable = false;
    $writable = false;
    $tmp_write_test = 'skipped';
    $message = '';

    try {
        $mount_point = getEbookLibraryRoot();
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'mount_point' => '',
            'books_path' => '',
            'readable' => false,
            'writable' => false,
            'tmp_write_test' => 'skipped',
            'message' => $e->getMessage(),
        ];
    }

    $books_path = rtrim($mount_point, '/') . '/Books';
    if ($mount_point === '') {
        $message = 'Ebook mount point is not configured.';
    } elseif (!is_dir($mount_point)) {
        $message = 'Configured ebook mount point does not exist on this system: ' . $mount_point;
    } elseif (!is_dir($books_path)) {
        $message = 'Ebook repository Books directory does not exist: ' . $books_path;
    } else {
        $readable = is_readable($books_path);
        $writable = is_writable($books_path);
        if (!$readable) {
            $message = 'Ebook repository Books directory is not readable: ' . $books_path;
        } else {
            $message = 'Ebook repository OK: ' . $mount_point;
        }
    }

    if ($write_test && $readable && is_dir($books_path)) {
        if (!$writable) {
            $tmp_write_test = 'failed';
            if ($message === 'Ebook repository OK: ' . $mount_point) {
                $message = 'Ebook repository is readable but not writable: ' . $books_path;
            }
        } else {
            $tmp = rtrim($books_path, '/') . '/.bookcatalog_write_test_' . bin2hex(random_bytes(6)) . '.tmp';
            $content = 'bookcatalog repository write test ' . date(DateTimeInterface::ATOM);
            $ok = false;
            try {
                if (@file_put_contents($tmp, $content) !== false) {
                    $read_back = @file_get_contents($tmp);
                    $ok = ($read_back === $content);
                }
            } finally {
                if (is_file($tmp)) @unlink($tmp);
            }
            $tmp_write_test = $ok ? 'ok' : 'failed';
            if (!$ok && $message === 'Ebook repository OK: ' . $mount_point) {
                $message = 'Ebook repository is readable but write test failed: ' . $books_path;
            }
        }
    }

    return [
        'ok' => $mount_point !== '' && is_dir($mount_point) && is_dir($books_path) && $readable,
        'mount_point' => $mount_point,
        'books_path' => $books_path,
        'readable' => $readable,
        'writable' => $writable,
        'tmp_write_test' => $tmp_write_test,
        'message' => $message,
    ];
}

function requireEbookRepositoryAvailable(bool $write_test = false): array {
    $health = checkEbookRepositoryHealth($write_test);
    if (empty($health['ok'])) {
        json_fail((string)($health['message'] ?? 'Ebook repository is unavailable.'), 400, ['repository_health' => $health]);
    }
    return $health;
}

function is_absolute_file_path(string $path): bool {
    return $path !== '' && ($path[0] === '/' || (bool)preg_match('/^[A-Za-z]:[\\\\\/]/', $path));
}

function normalize_relative_books_path(string $path): string {
    $path = normalize_posix_path_value($path) ?? '';
    if ($path === '') {
        throw new InvalidArgumentException('Ebook file path cannot be empty');
    }
    if (str_starts_with($path, 'Books/')) {
        $path = '/' . $path;
    }
    if ($path !== '/Books' && !str_starts_with($path, '/Books/')) {
        throw new InvalidArgumentException('Ebook file path must be under /Books');
    }
    return $path;
}

function absoluteToRelativeEbookPath(?string $absolutePath): ?string {
    $path = normalize_posix_path_value($absolutePath);
    if ($path === null) return null;

    if ($path === '/Books' || str_starts_with($path, 'Books/') || str_starts_with($path, '/Books/')) {
        return normalize_relative_books_path($path);
    }

    if (!is_absolute_file_path($path)) {
        return normalize_relative_books_path($path);
    }

    $root = getEbookLibraryRoot();
    if ($path === $root) {
        throw new InvalidArgumentException('Ebook file path points to the mount root, not a file under /Books');
    }
    if (!str_starts_with($path, $root . '/')) {
        $root_real = realpath($root);
        $path_real = realpath($path);
        if (is_string($root_real) && is_string($path_real)) {
            $root_real = normalize_posix_path_value($root_real) ?? $root_real;
            $path_real = normalize_posix_path_value($path_real) ?? $path_real;
            if ($path_real === $root_real) {
                throw new InvalidArgumentException('Ebook file path points to the mount root, not a file under /Books');
            }
            if (str_starts_with($path_real, rtrim($root_real, '/') . '/')) {
                return normalize_relative_books_path(substr($path_real, strlen(rtrim($root_real, '/'))));
            }
        }
        throw new InvalidArgumentException('Ebook file path is outside the configured ebook library root');
    }

    return normalize_relative_books_path(substr($path, strlen($root)));
}

function relativeToAbsoluteEbookPath(?string $relativePath): ?string {
    $path = normalize_posix_path_value($relativePath);
    if ($path === null) return null;

    $root = getEbookLibraryRoot();
    if (str_starts_with($path, $root . '/')) {
        return $path;
    }
    if (str_starts_with($path, 'Books/')) {
        $path = '/' . $path;
    }
    if ($path === '/Books' || str_starts_with($path, '/Books/')) {
        return rtrim($root, '/') . $path;
    }

    return $path;
}

function normalize_book_copy_file_path(?string $file_path): ?string {
    return absoluteToRelativeEbookPath($file_path);
}

function resolveFilesystemPath(string $storedPath): ?string {
    $stored = normalize_book_copy_file_path($storedPath);
    if ($stored === null) return null;
    $intended = relativeToAbsoluteEbookPath($stored);
    if ($intended === null) return null;
    if (is_file($intended)) return $intended;

    $dir = dirname($intended);
    if (!is_dir($dir) || !is_readable($dir)) return null;

    $target = canonicalPathString(basename($stored));
    if ($target === null) return null;
    foreach (@scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (canonicalPathString($entry) === $target) {
            $actual = rtrim($dir, '/') . '/' . $entry;
            return is_file($actual) ? $actual : null;
        }
    }
    return null;
}

function diagnoseFilesystemPath(string $storedPath): array {
    $stored = normalize_book_copy_file_path($storedPath);
    $intended = $stored !== null ? relativeToAbsoluteEbookPath($stored) : null;
    $resolved = $stored !== null ? resolveFilesystemPath($stored) : null;
    $status = 'missing';
    if ($resolved !== null && $intended !== null && $resolved === $intended) {
        $status = 'exact_match';
    } elseif ($resolved !== null) {
        $status = 'canonical_match';
    }
    return [
        'stored_path' => $stored,
        'intended_absolute_path' => $intended,
        'resolved_absolute_path' => $resolved,
        'status' => $status,
    ];
}

function normalize_book_copy_sha256($sha256): ?string {
    $sha256 = trim((string)($sha256 ?? ''));
    if ($sha256 === '') return null;
    if (!preg_match('/^[A-Fa-f0-9]{64}$/', $sha256)) {
        throw new InvalidArgumentException('Book copy sha256 must be exactly 64 hex characters');
    }
    return strtolower($sha256);
}

function calculateFileSha256(string $absolutePath): ?string {
    $path = filesystemPathString($absolutePath);
    if ($path === null || !is_file($path) || !is_readable($path)) {
        return null;
    }
    $hash = @hash_file('sha256', $path);
    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
        return null;
    }
    return strtolower($hash);
}

function calculateBookCopySha256(array|int $copy): ?string {
    if (is_int($copy)) {
        if ($copy <= 0) return null;
        $pdo = pdo();
        if (!bookcopies_table_exists($pdo)) return null;
        $st = $pdo->prepare('SELECT format, file_path FROM BookCopies WHERE copy_id = ? LIMIT 1');
        $st->execute([$copy]);
        $copy = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    if (!$copy || normalize_book_copy_format($copy['format'] ?? null) === 'print') {
        return null;
    }
    try {
        $relative = normalize_book_copy_file_path($copy['file_path'] ?? null);
    } catch (InvalidArgumentException $e) {
        return null;
    }
    if ($relative === null) return null;

    $actual = resolveFilesystemPath($relative);
    return $actual === null ? null : calculateFileSha256($actual);
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
        'file_size' => $format === 'print' ? 0 : max(0, (int)($copy['file_size'] ?? 0)),
        'sha256' => $format === 'print' ? null : normalize_book_copy_sha256($copy['sha256'] ?? null),
        'notes' => N($copy['notes'] ?? null),
        '_index' => $index,
    ];
}

function fetch_book_copies_map(PDO $pdo, array $book_ids, bool $include_deleted = false): array {
    $book_ids = array_values(array_filter(array_map('intval', $book_ids), static fn (int $id): bool => $id > 0));
    if (!$book_ids || !bookcopies_table_exists($pdo)) return [];

    $placeholders = implode(',', array_fill(0, count($book_ids), '?'));
    $has_status = table_column_exists($pdo, 'BookCopies', 'record_status');
    $status_cols = $has_status ? ', record_status, deleted_with_book' : ", 'active' AS record_status, 0 AS deleted_with_book";
    $active_sql = $has_status && !$include_deleted ? " AND record_status='active'" : '';
    $fs_col = bookcopies_has_file_size($pdo) ? ', file_size' : ', 0 AS file_size';
    $sha_col = bookcopies_has_sha256($pdo) ? ', sha256' : ', NULL AS sha256';
    $st = $pdo->prepare("
        SELECT copy_id, book_id, format, quantity, physical_location, file_path{$fs_col}{$sha_col}{$status_cols}, notes, created_at, updated_at
        FROM BookCopies
        WHERE book_id IN ($placeholders){$active_sql}
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

function fetch_book_copies(PDO $pdo, int $book_id, bool $include_deleted = false): array {
    $map = fetch_book_copies_map($pdo, [$book_id], $include_deleted);
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
    if ($file_path === null) return false;
    return resolveFilesystemPath((string)$file_path) !== null;
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

    catalog_evidence_sync($pdo);
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
        WHERE book_id = ? AND format = 'print' AND record_status='active'
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

function copy_documentation_root(): string {
    return rtrim(getenv('BOOKCATALOG_ATTACHMENTS_DIR') ?: dirname(__DIR__) . '/private-attachments', '/\\');
}

function copy_documentation_file(string $relative): ?string {
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/D', $relative)) return null;
    $root = realpath(copy_documentation_root());
    $file = $root ? realpath($root . '/' . $relative) : false;
    return $file && dirname($file) === $root && is_file($file) && is_readable($file) ? $file : null;
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

    $has_fs = bookcopies_has_file_size($pdo);
    $has_sha = bookcopies_has_sha256($pdo);
    $columns = ['book_id', 'format', 'quantity', 'physical_location', 'file_path'];
    if ($has_fs) $columns[] = 'file_size';
    if ($has_sha) $columns[] = 'sha256';
    $columns[] = 'notes';
    $columns[] = 'created_at';
    $columns[] = 'updated_at';
    $placeholders = implode(', ', array_fill(0, count($columns) - 2, '?')) . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP';
    $ins = $pdo->prepare('INSERT INTO BookCopies (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');

    $owns_transaction = !$pdo->inTransaction();
    if ($owns_transaction) $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT book_id FROM Books WHERE book_id = ? FOR UPDATE');
        $lock->execute([$book_id]);
        if (!$lock->fetchColumn()) throw new InvalidArgumentException('Book not found');
        $existing_stmt = $pdo->prepare('SELECT copy_id,format FROM BookCopies WHERE book_id = ? AND record_status=\'active\' FOR UPDATE');
        $existing_stmt->execute([$book_id]);
        $existing = [];
        foreach ($existing_stmt->fetchAll(PDO::FETCH_ASSOC) as $existing_copy) $existing[(int)$existing_copy['copy_id']]=$existing_copy['format'];
        $seen = []; $input_seen = [];
        $has_documentation = $pdo->query("SHOW TABLES LIKE 'BookCopyPhotos'")->fetchColumn() !== false;
        foreach ($norm as &$copy) {
            $id = $copy['copy_id'];
            if ($id !== null && (!isset($existing[$id]) || isset($input_seen[$id]))) {
                throw new InvalidArgumentException('Invalid or repeated copy_id for this book');
            }
            if ($id !== null) $input_seen[$id]=true;
            if ($id !== null && $existing[$id] === 'print' && $copy['format'] !== 'print') {
                $copy['copy_id'] = null; $id = null;
            }
            if ($id !== null) {
                $seen[$id] = true;
                if ($has_documentation && ($copy['format'] !== 'print' || $copy['quantity'] !== 1)) {
                    $attached = $pdo->prepare('SELECT (SELECT COUNT(*) FROM BookCopyPhotos WHERE copy_id=?) + (SELECT COUNT(*) FROM BookCopyAcquisitionDocuments WHERE copy_id=?)');
                    $attached->execute([$id, $id]);
                    if ((int)$attached->fetchColumn() > 0) throw new RuntimeException('A documented physical copy must remain an individual print copy.', 409);
                }
            }
        }
        unset($copy);
        $update_columns = array_values(array_filter($columns, fn($c) => !in_array($c, ['book_id', 'created_at', 'updated_at'], true)));
        $upd = $pdo->prepare('UPDATE BookCopies SET ' . implode(', ', array_map(fn($c) => "$c = ?", $update_columns)) . ', updated_at = CURRENT_TIMESTAMP WHERE copy_id = ? AND book_id = ?');
        foreach ($norm as $copy) {
            $params = [
                $book_id,
                $copy['format'],
                $copy['quantity'],
                $copy['physical_location'],
                $copy['file_path'],
            ];
            if ($has_fs) $params[] = $copy['file_size'];
            if ($has_sha) $params[] = $copy['sha256'];
            $params[] = $copy['notes'];
            if ($copy['copy_id'] !== null) {
                array_shift($params);
                $params[] = $copy['copy_id'];
                $params[] = $book_id;
                $upd->execute($params);
            } else {
                $ins->execute($params);
            }
        }
        // RESTRICT foreign keys protect copies with attached evidence from deletion.
        $del = $pdo->prepare('DELETE FROM BookCopies WHERE copy_id = ? AND book_id = ?');
        foreach ($existing as $id => $_) {
            if (!isset($seen[$id])) {
                if ($existing[$id] === 'print') $pdo->prepare("UPDATE BookCopies SET record_status='deleted',deleted_with_book=0 WHERE copy_id=?")->execute([$id]);
                else $del->execute([$id, $book_id]);
            }
        }
        catalog_evidence_sync($pdo);
        if ($owns_transaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($owns_transaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
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
        SELECT copy_id, book_id, quantity, format
        FROM BookCopies
        WHERE copy_id = ? AND record_status='active'
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
        $del = $row['format']==='print' ? $pdo->prepare("UPDATE BookCopies SET record_status='deleted',deleted_with_book=0 WHERE copy_id=?") : $pdo->prepare('DELETE FROM BookCopies WHERE copy_id = ?');
        $del->execute([$copy_id]);
        $deleted = $del->rowCount() > 0;
    }

    $copies = fetch_book_copies($pdo, $book_id);
    if (!$copies) $pdo->prepare("UPDATE Books SET record_status='deleted' WHERE book_id=?")->execute([$book_id]);
    catalog_evidence_sync($pdo);
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
    if (preg_match('/^@(.+)@$/u', $s, $m)) {
        $inner = trim((string)$m[1]);
        return ['', $inner, $inner];
    }
    if (strpos($s, '|') !== false) {
        [$last, $first] = array_map('trim', explode('|', $s, 2));
        $sort = trim($last . ' ' . $first);
        return [$first, $last, $sort];
    }

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

function canonicalize_author_row(PDO $pdo, int $author_id, ?string $first, ?string $last, int $is_hungarian): void {
    $first = trim((string)$first);
    $last = trim((string)$last);
    $display = format_author_display($first, $last, $is_hungarian);
    $sort = format_author_sort($first, $last);

    $upd = $pdo->prepare("\n        UPDATE Authors\n           SET name = ?,\n               first_name = ?,\n               last_name = ?,\n               sort_name = ?,\n               is_hungarian = ?\n         WHERE author_id = ?\n           AND (\n                COALESCE(name, '') <> ?\n             OR COALESCE(first_name, '') <> ?\n             OR COALESCE(last_name, '') <> ?\n             OR COALESCE(sort_name, '') <> ?\n             OR is_hungarian <> ?\n           )\n    ");
    $upd->execute([
        $display ?: null,
        $first !== '' ? $first : null,
        $last !== '' ? $last : null,
        $sort ?: null,
        $is_hungarian,
        $author_id,
        $display,
        $first,
        $last,
        $sort,
        $is_hungarian,
    ]);
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
    $has_pipe = (strpos($free_text, '|') !== false);
    $is_structured = $has_comma || $has_pipe;
    $is_hungarian = ($force_is_hungarian !== null) ? (int)!!$force_is_hungarian : ($has_comma ? 0 : 1);

    [$first, $last, $sort] = parse_author_free_text($free_text, (bool)$is_hungarian);
    $display = format_author_display($first, $last, $is_hungarian);
    $sort_new = format_author_sort($first, $last);
    $sort_legacy = trim($last . ' ' . $first);
    $name_alt = trim($last . ', ' . $first);

    if ($is_structured) {
        $sel_exact = $pdo->prepare("\n            SELECT author_id\n              FROM Authors\n             WHERE COALESCE(first_name, '') = ?\n               AND COALESCE(last_name, '') = ?\n               AND is_hungarian = ?\n             ORDER BY author_id ASC\n             LIMIT 1\n        ");
        $sel_exact->execute([trim($first), trim($last), $is_hungarian]);
        $id = $sel_exact->fetchColumn();
        if ($id) {
            canonicalize_author_row($pdo, (int)$id, $first, $last, $is_hungarian);
            return (int)$id;
        }

        $sel_exact_sort = $pdo->prepare("\n            SELECT author_id\n              FROM Authors\n             WHERE COALESCE(sort_name, '') = ?\n               AND is_hungarian = ?\n             ORDER BY author_id ASC\n             LIMIT 1\n        ");
        $sel_exact_sort->execute([$sort_new, $is_hungarian]);
        $id = $sel_exact_sort->fetchColumn();
        if ($id) {
            canonicalize_author_row($pdo, (int)$id, $first, $last, $is_hungarian);
            return (int)$id;
        }

        try {
            $ins = $pdo->prepare("INSERT INTO Authors (name, first_name, last_name, sort_name, is_hungarian)\n                              VALUES (?, ?, ?, ?, ?)");
            $ins->execute([
                $display ?: null,
                $first ?: null,
                $last ?: null,
                $sort_new ?: null,
                $is_hungarian,
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            $sel_exact->execute([trim($first), trim($last), $is_hungarian]);
            $id = $sel_exact->fetchColumn();
            if ($id) {
                canonicalize_author_row($pdo, (int)$id, $first, $last, $is_hungarian);
                return (int)$id;
            }
            $sel_exact_sort->execute([$sort_new, $is_hungarian]);
            $id = $sel_exact_sort->fetchColumn();
            if ($id) {
                canonicalize_author_row($pdo, (int)$id, $first, $last, $is_hungarian);
                return (int)$id;
            }
            throw $e;
        }
    }

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

function attachAuthorEntriesToBook(PDO $pdo, int $book_id, array $entries, ?int $force_is_hungarian = null): void {
    if (!$book_id || !$entries) return;

    $own_tx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $own_tx = true;
    }

    try {
        $has_alias = books_authors_has_author_alias($pdo);
        foreach ($entries as $index => $entry) {
            $name = normalize_author_metadata_name($entry['name'] ?? null);
            if ($name === '') continue;

            $entry_flag = normalize_author_metadata_flag($entry['is_hungarian'] ?? null);
            $effective_flag = $force_is_hungarian !== null ? (int)!!$force_is_hungarian : $entry_flag;
            $author_id = getAuthorId($pdo, $name, $effective_flag);
            if (!$author_id) continue;

            if ($entry_flag !== null) {
                $pdo->prepare("UPDATE Authors SET is_hungarian = ? WHERE author_id = ?")
                    ->execute([$entry_flag, $author_id]);
            }

            $alias = normalize_author_alias($entry['author_alias'] ?? ($entry['alias'] ?? null));
            $link = $pdo->prepare("SELECT 1 FROM Books_Authors WHERE book_id = ? AND author_id = ? LIMIT 1");
            $link->execute([$book_id, $author_id]);
            if (!$link->fetchColumn()) {
                if ($has_alias) {
                    $ins = $pdo->prepare("INSERT INTO Books_Authors (book_id, author_id, author_ord, author_alias) VALUES (?, ?, ?, ?)");
                    $ins->execute([$book_id, $author_id, $index + 1, $alias]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO Books_Authors (book_id, author_id, author_ord) VALUES (?, ?, ?)");
                    $ins->execute([$book_id, $author_id, $index + 1]);
                }
            } else {
                if ($has_alias) {
                    $upd = $pdo->prepare("UPDATE Books_Authors SET author_ord = ?, author_alias = ? WHERE book_id = ? AND author_id = ?");
                    $upd->execute([$index + 1, $alias, $book_id, $author_id]);
                } else {
                    $upd = $pdo->prepare("UPDATE Books_Authors SET author_ord = ? WHERE book_id = ? AND author_id = ?");
                    $upd->execute([$index + 1, $book_id, $author_id]);
                }
            }
        }
        if ($own_tx) $pdo->commit();
    } catch (Throwable $e) {
        if ($own_tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function attachAuthorsToBook(PDO $pdo, int $book_id, ?string $authors_csv, ?int $force_is_hungarian = null): void {
    $entries = resolve_import_author_entries($authors_csv, null);
    attachAuthorEntriesToBook($pdo, $book_id, $entries, $force_is_hungarian);
}

function attachAuthorsMetadataToBook(PDO $pdo, int $book_id, ?string $authors_csv, ?string $authors_metadata_json = null): void {
    $entries = resolve_import_author_entries($authors_csv, $authors_metadata_json);
    attachAuthorEntriesToBook($pdo, $book_id, $entries, null);
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

function validate_image_magic_bytes(string $path, string $mime): bool {
    $fh = @fopen($path, 'rb');
    if (!$fh) return false;
    $header = fread($fh, 12);
    fclose($fh);
    if ($header === false) return false;

    switch ($mime) {
        case 'image/jpeg':
            return strncmp($header, "\xFF\xD8\xFF", 3) === 0;
        case 'image/png':
            return strncmp($header, "\x89PNG", 4) === 0;
        case 'image/webp':
            return strncmp($header, 'RIFF', 4) === 0 && substr($header, 8, 4) === 'WEBP';
    }
    return false;
}

function ensure_uploads_htaccess(string $uploads_dir): void {
    $htaccess = rtrim($uploads_dir, '/\\') . '/.htaccess';
    if (is_file($htaccess)) return;
    $content = "Options -ExecCGI -Indexes\n"
        . "AddType text/plain .php .php3 .php4 .php5 .php7 .phtml .phar\n"
        . "<FilesMatch \"\\.(php[0-9s]?|phtml|phar|cgi|pl|py|sh)$\">\n"
        . "    Require all denied\n"
        . "</FilesMatch>\n";
    @file_put_contents($htaccess, $content);
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
    if (!validate_image_magic_bytes($tmp_path, $mime)) {
        throw new RuntimeException('File content does not match declared type', 415);
    }
    if ((int)($file['size'] ?? 0) > 10*1024*1024) {
        throw new RuntimeException('File too large', 413);
    }

    $base_dir = __DIR__ . '/uploads/' . $book_id;
    if (!is_dir($base_dir) && !mkdir($base_dir, 0775, true)) {
        throw new RuntimeException('Unable to create upload directory');
    }
    ensure_uploads_htaccess(__DIR__ . '/uploads');

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

/* Book valuations: imported evidence and estimates remain separate from catalog metadata. */
function valuation_normalize(string $value): string {
    if (class_exists('Normalizer')) $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 'UTF-8');
}

function valuation_amount($value): ?string {
    if ($value === null || trim((string)$value) === '') return null;
    $value = str_replace(',', '.', trim((string)$value));
    if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $value)) throw new InvalidArgumentException('Invalid amount: use a nonnegative number with at most two decimals; leave unknown prices empty.');
    $parts = explode('.', $value);
    return ltrim($parts[0], '0') . (ltrim($parts[0], '0') === '' ? '0' : '') . '.' . str_pad($parts[1] ?? '', 2, '0');
}

function valuation_date($value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || !checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4))) throw new InvalidArgumentException('Invalid observation date; use YYYY-MM-DD or leave empty.');
    return $value;
}

function valuation_columns(): array {
    return [
        'used_amount'=>'Használtpiaci irányérték (Ft; becslés)', 'replacement_amount'=>'Pótlási könyvár (Ft; referencia)',
        'used_confidence'=>'Használt becslés megbízhatósága', 'replacement_confidence'=>'Pótlási ár megbízhatósága',
        'used_method'=>'Becslés módszere', 'replacement_method'=>'Pótlás módja', 'priority'=>'Kiemelés',
        'reason'=>'Kiemelés indoka / eltérés', 'heirs_action'=>'Örökösök teendője', 'group_key'=>'Együtt vizsgálandó csoport',
        'research_status'=>'Kutatás állapota', 'owner_condition'=>'Példányállapot (kitöltendő)',
        'provenance'=>'Beszerzési előzmény (tulajdonosi közlés)', 'completeness'=>'Kiadás teljessége / eladói állítás',
        'checked_on'=>'Forrás megtekintése', 'preservation_label'=>'Megőrzési értékkategória',
    ];
}

function valuation_read_csv(string $path): array {
    if (!is_file($path) || filesize($path) > 20*1024*1024) throw new InvalidArgumentException('Valuation CSV limit: 20 MB.');
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException('Unable to read valuation CSV.');
    try {
        $line = fgets($fh);
        if ($line === false) throw new InvalidArgumentException('Empty CSV.');
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        $delimiter = count(str_getcsv($line, ';', '"', '')) > count(str_getcsv($line, ',', '"', '')) ? ';' : ',';
        rewind($fh); $header = fgetcsv($fh, 0, $delimiter, '"', '');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        if (count(array_unique($header)) !== count($header)) throw new InvalidArgumentException('Duplicate CSV column.');
        foreach (['ID','Title','Authors','Publisher','Year','Használtpiaci irányérték (Ft; becslés)','Pótlási könyvár (Ft; referencia)','Megőrzési értékkategória'] as $required) {
            if (!in_array($required, $header, true)) throw new InvalidArgumentException('Missing valuation column: '.$required);
        }
        $rows = []; $seen = []; $source_versions = [];
        while (($fields = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            if (count($fields) === 1 && trim((string)$fields[0]) === '') continue;
            if (count($fields) !== count($header)) throw new InvalidArgumentException('CSV field count differs at row '.(count($rows)+2));
            $input = array_combine($header, $fields);
            $id = trim($input['ID']);
            if (!ctype_digit($id) || (int)$id<1 || (int)$id>4294967295 || isset($seen[(int)$id])) throw new InvalidArgumentException('Invalid or duplicate book ID: '.$id);
            $seen[(int)$id] = true;
            // A whole-catalog export may contain ebook-only rows with empty enrichment.
            $copies_text=trim($input['Copies JSON']??$input['copies_json']??'');
            if ($copies_text!=='') {
                $copies=json_decode($copies_text,true,64,JSON_THROW_ON_ERROR);
                if (!is_array($copies) || !array_is_list($copies)) throw new InvalidArgumentException('Invalid Copies JSON.');
                $has_print=false;
                foreach ($copies as $copy) if (is_array($copy) && strtolower((string)($copy['format']??''))==='print') $has_print=true;
                if (!$has_print) continue;
            }
            $row = ['book_id'=>(int)$id, 'is_high_value'=>0, 'currency'=>'HUF'];
            foreach (valuation_columns() as $key=>$column) $row[$key] = trim($input[$column] ?? '') ?: null;
            foreach (['used_amount','replacement_amount'] as $key) $row[$key] = valuation_amount($row[$key]);
            $row['checked_on'] = valuation_date($row['checked_on']);
            $row['is_high_value'] = $row['preservation_label'] === 'Nagy értékű – külön kezelendő' ? 1 : 0;
            if ($row['preservation_label'] !== null && !$row['is_high_value']) throw new InvalidArgumentException('Unrecognized preservation category for book '.$id);
            foreach (['preservation_label'=>190,'priority'=>100,'group_key'=>190] as $key=>$length) {
                if (mb_strlen((string)$row[$key])>$length) throw new InvalidArgumentException('Field too long: '.$key);
            }
            $source_text = trim($input['Forrásrészletek (JSON)'] ?? '');
            $sources = $source_text === '' ? [] : json_decode($source_text, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($sources) || !array_is_list($sources) || count($sources)>500) throw new InvalidArgumentException('Invalid source details for book '.$id);
            $source_map = [];
            foreach ($sources as $source) {
                if (!is_array($source)) throw new InvalidArgumentException('Invalid source entry.');
                $key = (string)($source['source_id'] ?? '');
                if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $key) || isset($source_map[$key])) throw new InvalidArgumentException('Invalid or repeated source key: '.$key);
                $source['price'] = valuation_amount($source['price'] ?? null);
                $source['shipping'] = valuation_amount($source['shipping'] ?? null);
                $source['checked'] = valuation_date($source['checked'] ?? null);
                $currency = strtoupper(trim((string)($source['currency'] ?? '')));
                if ($currency!=='' && !preg_match('/^[A-Z]{3}$/D', $currency)) throw new InvalidArgumentException('Invalid source currency.');
                if ($source['price']!==null && $currency==='') throw new InvalidArgumentException('Source amount requires a currency.');
                $source['currency'] = $currency ?: null;
                if (trim((string)($source['kind'] ?? '')) === '' || trim((string)($source['title'] ?? '')) === '') throw new InvalidArgumentException('Source title and observation kind are required.');
                ksort($source); $encoded=json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                if (isset($source_versions[$key]) && $source_versions[$key]!==$encoded) throw new InvalidArgumentException('Conflicting data for source '.$key);
                $source_versions[$key]=$encoded; $source_map[$key]=$source;
            }
            $row['sources']=$source_map;
            $row['roles']=[];
            foreach (['used'=>'Használt irányérték forrása','replacement'=>'Pótlási referencia forrása'] as $role=>$column) {
                $keys=preg_split('/[;,\s]+/',trim($input[$column]??''),-1,PREG_SPLIT_NO_EMPTY) ?: [];
                foreach ($keys as $key) if (!isset($source_map[$key])) throw new InvalidArgumentException('Missing source details for '.$key.' on book '.$id);
                if ($row[$role.'_amount']!==null && !$keys) throw new InvalidArgumentException('Estimate requires a source: book '.$id);
                $row['roles'][$role]=$keys;
            }
            $row['bibliography']=array_intersect_key($input,array_flip(['Title','Authors','Publisher','Year','ISBN']));
            $row['raw_json']=json_encode(['bibliography'=>$row['bibliography'],'assessment'=>array_diff_key($input,array_flip(['Forrásrészletek (JSON)','Copies JSON','Loaned To','Loaned Date']))],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $rows[]=$row;
            if (count($rows)>20000) throw new InvalidArgumentException('CSV row limit: 20000.');
        }
        if (!$rows) throw new InvalidArgumentException('No valuation records.');
        return $rows;
    } finally { fclose($fh); }
}

function valuation_match_rows(PDO $db, array $rows, bool $lock=false): array {
    $ids=array_column($rows,'book_id'); $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $s=$db->prepare('SELECT b.book_id,b.title,b.year_published,b.isbn,p.name publisher FROM Books b LEFT JOIN Publishers p ON p.publisher_id=b.publisher_id WHERE b.book_id IN ('.$placeholders.') ORDER BY b.book_id'.($lock?' FOR UPDATE':''));
    $s->execute($ids); $books=[];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $book) $books[(int)$book['book_id']]=$book;
    $conflicts=[];
    foreach ($rows as $row) {
        $book=$books[$row['book_id']]??null; $bib=$row['bibliography']; $errors=[];
        if (!$book) $errors[]='Book ID does not exist';
        else {
            if (valuation_normalize($bib['Title'])!==valuation_normalize($book['title'])) $errors[]='Title differs';
            if (!empty($bib['Publisher']) && valuation_normalize($bib['Publisher'])!==valuation_normalize((string)$book['publisher'])) $errors[]='Publisher differs';
            if (!empty($bib['Year']) && (int)$bib['Year']!== (int)$book['year_published']) $errors[]='Year differs';
            $isbn=fn($v)=>preg_replace('/[\s-]+/u','',mb_strtoupper((string)$v));
            if (!empty($bib['ISBN']) && $isbn($bib['ISBN'])!==$isbn($book['isbn'])) $errors[]='ISBN differs';
        }
        if ($errors) $conflicts[]=['book_id'=>$row['book_id'],'title'=>$bib['Title'],'reasons'=>$errors];
    }
    return $conflicts;
}

function valuation_import_summary(array $rows, array $conflicts): array {
    $sources=[]; foreach ($rows as $row) foreach ($row['sources'] as $key=>$_) $sources[$key]=true;
    return ['records'=>count($rows),'matched'=>count($rows)-count($conflicts),'conflicts'=>$conflicts,'high_value'=>array_sum(array_column($rows,'is_high_value')),
        'used_estimates'=>count(array_filter($rows,fn($r)=>$r['used_amount']!==null)), 'replacement_references'=>count(array_filter($rows,fn($r)=>$r['replacement_amount']!==null)), 'sources'=>count($sources)];
}

function valuation_apply_import(PDO $db, array $rows, string $sha, string $filename, ?int $actor=null): array {
    $db->beginTransaction();
    try {
        $existing=$db->prepare('SELECT import_id FROM ValuationImports WHERE content_sha256=?'); $existing->execute([$sha]);
        if ($id=$existing->fetchColumn()) { $db->rollBack(); return ['import_id'=>(int)$id,'already_imported'=>true,'applied'=>0]; }
        $conflicts=valuation_match_rows($db,$rows,true);
        if ($conflicts) throw new RuntimeException('Catalog changed or bibliographic conflicts remain. Preview the CSV again.',409);
        $s=$db->prepare('INSERT INTO ValuationImports (content_sha256,original_filename,record_count,imported_by) VALUES (?,?,?,?)');
        $s->execute([$sha,mb_substr(basename($filename),0,255),count($rows),$actor]); $import_id=(int)$db->lastInsertId();
        $source_ids=[];
        $source_insert=$db->prepare('INSERT INTO PriceObservations (import_id,source_key,title,amount,currency,shipping_amount,observation_kind,edition_match,source_url,seller,notes,event_date_text,checked_on,record_ids,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $columns=['book_id','import_id','used_amount','replacement_amount','currency','used_confidence','replacement_confidence','used_method','replacement_method','is_high_value','preservation_label','priority','reason','heirs_action','research_status','group_key','owner_condition','provenance','completeness','checked_on','raw_json'];
        $insert=$db->prepare('INSERT INTO BookAssessments ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
        $link=$db->prepare('INSERT INTO BookAssessmentSources (assessment_id,observation_id,source_role) VALUES (?,?,?)');
        foreach ($rows as $row) {
            $row['import_id']=$import_id; $insert->execute(array_map(fn($key)=>$row[$key],$columns)); $assessment_id=(int)$db->lastInsertId();
            foreach ($row['sources'] as $key=>$source) {
                if (!isset($source_ids[$key])) {
                    $scope=$source['record_ids']??implode(', ',$source['ids']??[]);
                    $source_insert->execute([$import_id,$key,$source['title'],$source['price'],$source['currency'],$source['shipping'],$source['kind'],$source['match']??null,$source['url']??null,$source['seller']??null,$source['note']??null,$source['event']??null,$source['checked'],$scope,json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                    $source_ids[$key]=(int)$db->lastInsertId();
                }
                $link->execute([$assessment_id,$source_ids[$key],'reference']);
                foreach (['used','replacement'] as $role) if (in_array($key,$row['roles'][$role],true)) $link->execute([$assessment_id,$source_ids[$key],$role]);
            }
        }
        catalog_evidence_sync($db);
        $db->commit(); return ['import_id'=>$import_id,'already_imported'=>false,'applied'=>count($rows)];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function valuation_add_filter(PDO $db, array &$where, array &$params): void {
    $filter=trim((string)($_GET['value_filter']??''));
    if ($filter==='') return;
    if (!in_array($filter,['high_value','priority','estimated'],true)) throw new InvalidArgumentException('Invalid value filter.');
    if (!table_column_exists($db,'BookAssessments','assessment_id')) { $where[]='1=0'; return; }
    $condition=match($filter) { 'high_value'=>'va.is_high_value=1', 'priority'=>"va.priority='Elsőként ellenőrizendő'", 'estimated'=>'(va.used_amount IS NOT NULL OR va.replacement_amount IS NOT NULL)' };
    $where[]='EXISTS (SELECT 1 FROM BookAssessments va WHERE va.book_id=b.book_id AND va.assessment_id=(SELECT MAX(vb.assessment_id) FROM BookAssessments vb WHERE vb.book_id=b.book_id) AND va.record_status=\'active\' AND '.$condition.')';
}

function valuation_get_latest(PDO $db, int $book_id, bool $include_deleted=false): ?array {
    if (!table_column_exists($db,'BookAssessments','assessment_id')) return null;
    $s=$db->prepare('SELECT a.*, i.created_at imported_at, i.original_filename FROM BookAssessments a JOIN ValuationImports i ON i.import_id=a.import_id WHERE a.book_id=? ORDER BY a.assessment_id DESC LIMIT 1');
    $s->execute([$book_id]); $row=$s->fetch(PDO::FETCH_ASSOC);
    if (!$row || (!$include_deleted && ($row['record_status']??'active')==='deleted')) return null;
    $s=$db->prepare('SELECT p.*, l.source_role FROM PriceObservations p JOIN BookAssessmentSources l ON l.observation_id=p.observation_id WHERE l.assessment_id=? ORDER BY p.source_key');
    $s->execute([$row['assessment_id']]); $sources=[];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $source) {
        $key=$source['source_key']; $role=$source['source_role']; unset($source['source_role']);
        if (!isset($sources[$key])) { $source['roles']=[]; $sources[$key]=$source; }
        $sources[$key]['roles'][]=$role;
    }
    $row['sources']=array_values($sources);
    $s=$db->prepare('SELECT assessment_id,import_id,created_at,used_amount,replacement_amount,is_high_value FROM BookAssessments WHERE book_id=? ORDER BY assessment_id DESC'); $s->execute([$book_id]);
    $row['history']=$s->fetchAll(PDO::FETCH_ASSOC);
    return $row;
}

function valuation_list_marks(PDO $db, array $book_ids): array {
    if (!$book_ids || !table_column_exists($db,'BookAssessments','assessment_id')) return [];
    $ids=array_map('intval',$book_ids);
    $s=$db->prepare('SELECT a.book_id,IF(a.record_status=\'active\',a.is_high_value,0) is_high_value FROM BookAssessments a WHERE a.book_id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND a.assessment_id=(SELECT MAX(v.assessment_id) FROM BookAssessments v WHERE v.book_id=a.book_id)');
    $s->execute($ids); $result=[];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(int)$row['book_id']]=$row;
    return $result;
}

function print_enrichment_csv_headers(): array {
    return array_merge(array_values(valuation_columns()), [
        'Árforrások azonosítói','Használt irányérték forrása','Pótlási referencia forrása',
        'Forrásrészletek (JSON)','Értékelési előzmények (JSON)','Példánydokumentáció (JSON)',
    ]);
}

/** Additional CSV values only for records with an explicitly stored print copy. */
function print_enrichment_csv_map(PDO $db, array $copy_map): array {
    $book_ids=[]; $copy_books=[];
    $deleted_books=array_fill_keys($db->query("SELECT book_id FROM Books WHERE record_status='deleted'")->fetchAll(PDO::FETCH_COLUMN),true);
    foreach ($copy_map as $book_id=>$copies) foreach ($copies as $copy) {
        if (strtolower((string)($copy['format']??''))!=='print' || (($copy['record_status']??'active')==='deleted' && !isset($deleted_books[$book_id]))) continue;
        $book_ids[(int)$book_id]=(int)$book_id;
        if (!empty($copy['copy_id'])) $copy_books[(int)$copy['copy_id']]=(int)$book_id;
    }
    if (!$book_ids) return [];
    $headers=print_enrichment_csv_headers(); $result=[];
    foreach ($book_ids as $book_id) $result[$book_id]=array_fill_keys($headers,'');
    $in=implode(',',array_fill(0,count($book_ids),'?'));$ids=array_values($book_ids);
    if (table_column_exists($db,'BookAssessments','assessment_id')) {
        $s=$db->prepare('SELECT a.*,i.content_sha256,i.original_filename,i.created_at imported_at FROM BookAssessments a JOIN ValuationImports i ON i.import_id=a.import_id WHERE a.book_id IN ('.$in.') ORDER BY a.assessment_id');
        $s->execute($ids);$assessments=[];$by_book=[];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['original_data']=json_decode($row['raw_json'],true,64,JSON_THROW_ON_ERROR);unset($row['raw_json']);
            $row['sources']=[];$assessments[(int)$row['assessment_id']]=$row;$by_book[(int)$row['book_id']][]=(int)$row['assessment_id'];
        }
        $s=$db->prepare('SELECT l.assessment_id,l.source_role,p.observation_id,p.source_key,p.raw_json FROM BookAssessmentSources l JOIN PriceObservations p ON p.observation_id=l.observation_id JOIN BookAssessments a ON a.assessment_id=l.assessment_id WHERE a.book_id IN ('.$in.') ORDER BY p.source_key,l.source_role');
        $s->execute($ids);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $source) {
            $aid=(int)$source['assessment_id'];$oid=(int)$source['observation_id'];
            if (!isset($assessments[$aid]['sources'][$oid])) {
                $assessments[$aid]['sources'][$oid]=['observation'=>json_decode($source['raw_json'],true,64,JSON_THROW_ON_ERROR),'roles'=>[]];
            }
            $assessments[$aid]['sources'][$oid]['roles'][]=$source['source_role'];
        }
        foreach ($by_book as $book_id=>$aids) {
            $current=$assessments[end($aids)];
            foreach (valuation_columns() as $key=>$header) $result[$book_id][$header]=$current[$key]??'';
            $all=[];$used=[];$replacement=[];$details=[];
            foreach ($current['sources'] as $source) {
                $key=$source['observation']['source_id'];$all[]=$key;$details[]=$source['observation'];
                if (in_array('used',$source['roles'],true)) $used[]=$key;
                if (in_array('replacement',$source['roles'],true)) $replacement[]=$key;
            }
            $result[$book_id]['Árforrások azonosítói']=implode('; ',$all);
            $result[$book_id]['Használt irányérték forrása']=implode('; ',$used);
            $result[$book_id]['Pótlási referencia forrása']=implode('; ',$replacement);
            $result[$book_id]['Forrásrészletek (JSON)']=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $history=[];
            foreach ($aids as $aid) { $assessment=$assessments[$aid];$assessment['sources']=array_values($assessment['sources']);$history[]=$assessment; }
            $result[$book_id]['Értékelési előzmények (JSON)']=json_encode($history,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        }
    }
    if ($copy_books && table_column_exists($db,'BookCopyPhotos','photo_id')) {
        $copy_ids=array_keys($copy_books);$cin=implode(',',array_fill(0,count($copy_ids),'?'));$copies=[];
        foreach ($copy_books as $copy_id=>$book_id) $copies[$copy_id]=['copy_id'=>$copy_id,'book_id'=>$book_id,'photos'=>[],'documents'=>[]];
        $s=$db->prepare('SELECT * FROM BookCopyPhotos WHERE copy_id IN ('.$cin.') ORDER BY copy_id,slot_number');$s->execute($copy_ids);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $photo) $copies[(int)$photo['copy_id']]['photos'][]=$photo;
        $s=$db->prepare('SELECT d.*,l.copy_id,l.item_reference,l.notes link_notes FROM AcquisitionDocuments d JOIN BookCopyAcquisitionDocuments l ON l.document_id=d.document_id WHERE l.copy_id IN ('.$cin.') ORDER BY l.copy_id,d.document_id');$s->execute($copy_ids);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $document) $copies[(int)$document['copy_id']]['documents'][]=$document;
        $documents=[];
        foreach ($copies as $copy) $documents[$copy['book_id']][]=$copy;
        foreach ($documents as $book_id=>$entries) $result[$book_id]['Példánydokumentáció (JSON)']=json_encode(['copies'=>$entries],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    return $result;
}

function catalog_evidence_sync(PDO $db): void {
    if (!table_column_exists($db,'BookCopies','deleted_with_book')) return;
    // Flags derive from actual ownership. Shared evidence stays active if any owner is active.
    $db->exec("UPDATE BookCopyPhotos p JOIN BookCopies c ON c.copy_id=p.copy_id JOIN Books b ON b.book_id=c.book_id SET p.record_status=IF(c.record_status='active' AND b.record_status='active','active','deleted')");
    $db->exec("UPDATE BookCopyAcquisitionDocuments l JOIN BookCopies c ON c.copy_id=l.copy_id JOIN Books b ON b.book_id=c.book_id SET l.record_status=IF(c.record_status='active' AND b.record_status='active','active','deleted')");
    $db->exec("UPDATE AcquisitionDocuments d SET d.record_status=IF(EXISTS(SELECT 1 FROM BookCopyAcquisitionDocuments l WHERE l.document_id=d.document_id AND l.record_status='active'),'active','deleted')");
    $db->exec("UPDATE BookAssessments a JOIN Books b ON b.book_id=a.book_id SET a.record_status=IF(b.record_status='active' AND EXISTS(SELECT 1 FROM BookCopies c WHERE c.book_id=b.book_id AND c.format='print' AND c.record_status='active'),'active','deleted')");
    $db->exec("UPDATE BookAssessmentSources l JOIN BookAssessments a ON a.assessment_id=l.assessment_id SET l.record_status=a.record_status");
    $db->exec("UPDATE PriceObservations p SET p.record_status=IF(EXISTS(SELECT 1 FROM BookAssessmentSources l WHERE l.observation_id=p.observation_id AND l.record_status='active'),'active','deleted')");
}

function catalog_retire_book(PDO $db,int $book_id): void {
    $owns_transaction=!$db->inTransaction();
    if ($owns_transaction) $db->beginTransaction();
    try {
        $s=$db->prepare('SELECT book_id FROM Books WHERE book_id=? FOR UPDATE');$s->execute([$book_id]);
        if (!$s->fetchColumn()) throw new RuntimeException('Book not found.',404);
        $db->prepare("UPDATE BookCopies SET record_status='deleted',deleted_with_book=1 WHERE book_id=? AND record_status='active'")->execute([$book_id]);
        $db->prepare("UPDATE Books SET record_status='deleted' WHERE book_id=?")->execute([$book_id]);
        catalog_evidence_sync($db);
        if ($owns_transaction) $db->commit();
    } catch (Throwable $e) {
        if ($owns_transaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function catalog_restore_book(PDO $db,int $book_id): array {
    $s=$db->prepare('SELECT record_status FROM Books WHERE book_id=? FOR UPDATE');$s->execute([$book_id]);$status=$s->fetchColumn();
    if ($status===false) throw new RuntimeException('Book not found.',404);
    $copies=fetch_book_copies($db,$book_id,true);
    $eligible=array_values(array_filter($copies,fn($c)=>$c['record_status']==='active' || (int)$c['deleted_with_book']===1));
    if (!$eligible) throw new RuntimeException('Restore an archived copy first; no copies were retired with this book.',409);
    if (!can_restore_book_from_copies($eligible)) throw new RuntimeException('No readable ebook path is available.',409);
    $db->prepare("UPDATE BookCopies SET record_status='active',deleted_with_book=0 WHERE book_id=? AND deleted_with_book=1")->execute([$book_id]);
    $db->prepare("UPDATE Books SET record_status='active' WHERE book_id=?")->execute([$book_id]);
    $result=sync_book_copy_derived_fields($db,$book_id);catalog_evidence_sync($db);return $result;
}

function catalog_restore_copy(PDO $db,int $copy_id): array {
    $s=$db->prepare('SELECT * FROM BookCopies WHERE copy_id=? FOR UPDATE');$s->execute([$copy_id]);$copy=$s->fetch(PDO::FETCH_ASSOC);
    if (!$copy) throw new RuntimeException('Copy not found.',404);
    if ($copy['format']!=='print' && !book_copy_file_path_exists($copy['file_path'])) throw new RuntimeException('Ebook file is not available.',409);
    $book_id=(int)$copy['book_id'];
    $db->prepare("UPDATE BookCopies SET record_status='active',deleted_with_book=0 WHERE copy_id=?")->execute([$copy_id]);
    $db->prepare("UPDATE Books SET record_status='active' WHERE book_id=?")->execute([$book_id]);
    $result=sync_book_copy_derived_fields($db,$book_id);catalog_evidence_sync($db);$result['book_id']=$book_id;return $result;
}

function portable_tables(): array {
    return ['Publishers'=>['publisher_id'],'Placement'=>['placement_id'],'Authors'=>['author_id'],'Subjects'=>['subject_id'],
        'Books'=>['book_id'],'Books_Authors'=>['book_id','author_id'],'Books_Subjects'=>['book_id','subject_id'],
        'BookCopies'=>['copy_id'],'AcquisitionDocuments'=>['document_id'],'BookCopyPhotos'=>['photo_id'],
        'BookCopyAcquisitionDocuments'=>['copy_id','document_id'],'ValuationImports'=>['import_id'],
        'BookAssessments'=>['assessment_id'],'PriceObservations'=>['observation_id'],'BookAssessmentSources'=>['assessment_id','observation_id','source_role']];
}
function portable_rows(PDO $db,string $table,string $column,array $ids): array {
    if (!$ids) return [];
    if (!isset(portable_tables()[$table]) || !preg_match('/^[a-z_]+$/D',$column)) throw new LogicException('Invalid package table.');
    $ids=array_values(array_unique(array_map('intval',$ids)));$s=$db->prepare('SELECT * FROM `'.$table.'` WHERE `'.$column.'` IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY '.implode(',',portable_tables()[$table]));$s->execute($ids);return $s->fetchAll(PDO::FETCH_ASSOC);
}
function portable_path(string $path): bool {
    return (bool)preg_match('~^(?:copy-attachments/[a-f0-9]{32}\.(?:jpg|png|webp|pdf)|uploads/(?:[0-9]+/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp|gif)|default[-_]cover\.jpg))$~D',$path);
}
function portable_target(string $path): string {
    if (!portable_path($path)) throw new InvalidArgumentException('Unsafe archive path.');
    $base=str_starts_with($path,'copy-attachments/')?copy_documentation_root():__DIR__.'/uploads';
    $relative=str_starts_with($path,'copy-attachments/')?substr($path,17):substr($path,8);
    $target=rtrim($base,'/\\').'/'.$relative;
    for ($parent=dirname($target);$parent!==dirname($parent);$parent=dirname($parent)) {
        if (is_link($parent)) throw new InvalidArgumentException('Symbolic-link destination is not allowed.');
        if ($parent===rtrim($base,'/\\')) break;
    }
    if (is_link($target)) throw new InvalidArgumentException('Symbolic-link destination is not allowed.');
    return $target;
}
function portable_manifest(PDO $db,array $book_ids): array {
    $t=[];$t['Books']=portable_rows($db,'Books','book_id',$book_ids);$ids=array_column($t['Books'],'book_id');
    foreach (['BookCopies','Books_Authors','Books_Subjects','BookAssessments'] as $table) $t[$table]=portable_rows($db,$table,'book_id',$ids);
    $t['Authors']=portable_rows($db,'Authors','author_id',array_column($t['Books_Authors'],'author_id'));
    $t['Subjects']=portable_rows($db,'Subjects','subject_id',array_column($t['Books_Subjects'],'subject_id'));
    $t['Publishers']=portable_rows($db,'Publishers','publisher_id',array_filter(array_column($t['Books'],'publisher_id')));
    $t['Placement']=portable_rows($db,'Placement','placement_id',array_filter(array_column($t['Books'],'placement_id')));
    $cids=array_column($t['BookCopies'],'copy_id');
    $t['BookCopyPhotos']=portable_rows($db,'BookCopyPhotos','copy_id',$cids);
    $t['BookCopyAcquisitionDocuments']=portable_rows($db,'BookCopyAcquisitionDocuments','copy_id',$cids);
    $t['AcquisitionDocuments']=portable_rows($db,'AcquisitionDocuments','document_id',array_column($t['BookCopyAcquisitionDocuments'],'document_id'));
    $t['BookAssessmentSources']=portable_rows($db,'BookAssessmentSources','assessment_id',array_column($t['BookAssessments'],'assessment_id'));
    $t['PriceObservations']=portable_rows($db,'PriceObservations','observation_id',array_column($t['BookAssessmentSources'],'observation_id'));
    $t['ValuationImports']=portable_rows($db,'ValuationImports','import_id',array_merge(array_column($t['BookAssessments'],'import_id'),array_column($t['PriceObservations'],'import_id')));
    // A selected archive carries only selected owners, including their archived links.
    $active_docs=[];foreach($t['BookCopyAcquisitionDocuments'] as $link)if($link['record_status']==='active')$active_docs[$link['document_id']]=true;
    foreach($t['AcquisitionDocuments'] as &$doc)$doc['record_status']=isset($active_docs[$doc['document_id']])?'active':'deleted';unset($doc);
    $active_prices=[];foreach($t['BookAssessmentSources'] as $link)if($link['record_status']==='active')$active_prices[$link['observation_id']]=true;
    foreach($t['PriceObservations'] as &$price)$price['record_status']=isset($active_prices[$price['observation_id']])?'active':'deleted';unset($price);
    $paths=[];
    foreach($t['Books'] as $book)foreach(['cover_image','cover_thumb'] as $key){$path=$book[$key]??'';if($path!=='' && str_starts_with($path,'uploads/'))$paths[$path]=true;}
    foreach(['BookCopyPhotos','AcquisitionDocuments'] as $table)foreach($t[$table] as $record)if(!empty($record['file_path']))$paths['copy-attachments/'.$record['file_path']]=true;
    $files=[];foreach(array_keys($paths) as $path){$file=portable_target($path);if(!is_file($file)||!is_readable($file))throw new RuntimeException('Archive incomplete: missing file '.$path);$files[]=['path'=>$path,'size'=>filesize($file),'sha256'=>hash_file('sha256',$file)];}
    $ordered=[];foreach(portable_tables() as $table=>$_)$ordered[$table]=$t[$table];
    return ['format'=>'library3-portable','format_version'=>1,'schema_version'=>SCHEMA_VERSION,'created_at'=>gmdate('c'),'tables'=>$ordered,'files'=>$files];
}
function portable_add_to_zip(ZipArchive $zip,PDO $db,array $book_ids): void {
    $manifest=portable_manifest($db,$book_ids);$sums=[];
    foreach($manifest['files'] as $file){if($zip->locateName($file['path'])===false && !$zip->addFile(portable_target($file['path']),$file['path']))throw new RuntimeException('Could not package attachment.');$sums[]=$file['sha256'].'  '.$file['path'];}
    $json=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    $zip->addFromString('library3-package.json',$json);$sums[]=hash('sha256',$json).'  library3-package.json';
    $zip->addFromString('portable-sha256sums.txt',implode("\n",$sums)."\n");
    $zip->addFromString('PORTABLE-RESTORE.txt',"Import this ZIP through Import books, preserving IDs.\nIt contains book records, copy/document/valuation history and all referenced cover, condition-photo and invoice files, including archived items.\nA dry run validates metadata and file hashes. Different existing rows or files with the same IDs/paths are rejected; nothing is overwritten.\nOrdinary ebook files are external repository references and are not included.\n");
}
function portable_has_manifest(string $path): bool {
    $zip=new ZipArchive();if($zip->open($path)!==true)return false;$has=$zip->locateName('library3-package.json')!==false;$zip->close();return $has;
}
function portable_restore(PDO $db,string $path,bool $dry_run=false): array {
    $zip=new ZipArchive();if($zip->open($path)!==true)throw new InvalidArgumentException('Invalid ZIP archive.');
    $stage=null;$created=[];
    try {
        $stat=$zip->statName('library3-package.json');if(!$stat||$stat['size']>64*1024*1024)throw new InvalidArgumentException('Missing or oversized archive manifest.');
        $m=json_decode($zip->getFromName('library3-package.json'),true,64,JSON_THROW_ON_ERROR);
        if(($m['format']??'')!=='library3-portable'||($m['format_version']??0)!==1||($m['schema_version']??'')!==SCHEMA_VERSION)throw new InvalidArgumentException('Unsupported archive format/schema; update the application first.');
        if(!is_array($m['tables']??null)||array_keys($m['tables'])!==array_keys(portable_tables())||!is_array($m['files']??null))throw new InvalidArgumentException('Invalid archive tables.');
        if($zip->numFiles>100000)throw new InvalidArgumentException('Archive has too many entries.');
        foreach ($m['tables'] as $rows) { if (!is_array($rows) || !array_is_list($rows)) throw new InvalidArgumentException('Invalid archive row list.'); foreach ($rows as $row) if (!is_array($row) || (isset($row['record_status']) && !in_array($row['record_status'],['active','deleted'],true))) throw new InvalidArgumentException('Invalid archive status.'); }
        $seen=[];$total=0;
        for($i=0;$i<$zip->numFiles;$i++){$item=$zip->statIndex($i);$name=$item['name'];if(isset($seen[$name])||str_contains($name,'\\')||preg_match('~(?:^|/)\.\.(?:/|$)~',$name)||str_starts_with($name,'/'))throw new InvalidArgumentException('Unsafe or duplicate ZIP entry.');$seen[$name]=true;$total+=$item['size'];$opsys=0;$attrs=0;$zip->getExternalAttributesIndex($i,$opsys,$attrs);if((($attrs>>16)&0170000)===0120000)throw new InvalidArgumentException('ZIP symbolic links are not allowed.');}
        if($total>4*1024*1024*1024)throw new InvalidArgumentException('Uncompressed archive exceeds 4 GB.');
        $declared=[];foreach($m['files'] as $file){$name=$file['path']??'';if(!portable_path($name)||isset($declared[$name])||!preg_match('/^[a-f0-9]{64}$/D',$file['sha256']??''))throw new InvalidArgumentException('Invalid archive file declaration.');$declared[$name]=$file;}
        $referenced=[];foreach($m['tables']['Books'] as $book)foreach(['cover_image','cover_thumb'] as $key){$name=$book[$key]??'';if(str_starts_with((string)$name,'uploads/'))$referenced[$name]=true;}
        foreach(['BookCopyPhotos','AcquisitionDocuments'] as $table)foreach($m['tables'][$table] as $row)if(!empty($row['file_path']))$referenced['copy-attachments/'.$row['file_path']]=true;
        if(array_diff_key($referenced,$declared)||array_diff_key($declared,$referenced))throw new InvalidArgumentException('Manifest file references do not match its payload.');
        portable_validate_metadata($m['tables'],$declared);
        $stage=sys_get_temp_dir().'/library3-portable-'.bin2hex(random_bytes(12));if(!mkdir($stage,0700))throw new RuntimeException('Cannot stage archive.');
        $staged=[];$warnings=[];
        foreach($declared as $name=>$file){$entry=$zip->statName($name);if(!$entry||$entry['size']!==(int)$file['size']||$entry['size']>64*1024*1024)throw new InvalidArgumentException('Missing or invalid file: '.$name);$input=$zip->getStream($name);if(!$input)throw new InvalidArgumentException('Unreadable ZIP entry.');$tmp=$stage.'/'.count($staged);$out=fopen($tmp,'xb');stream_copy_to_stream($input,$out);fclose($input);fclose($out);if(hash_file('sha256',$tmp)!==$file['sha256'])throw new InvalidArgumentException('Checksum mismatch: '.$name);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif','application/pdf'],true)){if(str_starts_with($name,'uploads/')&&$mime==='application/octet-stream')$warnings[]=['line'=>0,'warning'=>'Legacy cover is not a recognized image; original bytes preserved: '.$name];else throw new InvalidArgumentException('Unsupported attachment content: '.$name);}$target=portable_target($name);if(is_file($target)&&hash_file('sha256',$target)!==$file['sha256'])throw new RuntimeException('Existing file differs: '.$name,409);$staged[$name]=['tmp'=>$tmp,'target'=>$target,'sha256'=>$file['sha256'],'size'=>(int)$file['size']];}
        $db->beginTransaction();$inserted=0;$reused=0;$rows_seen=[];
        foreach(portable_tables() as $table=>$keys){
            $columns=$db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
            foreach($m['tables'][$table] as $row){
                if(!is_array($row)||array_diff(array_keys($row),$columns)||array_diff($columns,array_keys($row)))throw new InvalidArgumentException('Archive columns do not match '.$table);
                foreach($row as $value)if(!is_scalar($value)&&$value!==null)throw new InvalidArgumentException('Invalid archive scalar.');
                $identity=json_encode(array_map(fn($key)=>$row[$key],$keys));if(isset($rows_seen[$table][$identity]))throw new InvalidArgumentException('Repeated archive record.');$rows_seen[$table][$identity]=true;
                $where=implode(' AND ',array_map(fn($key)=>'`'.$key.'`=?',$keys));$values=array_map(fn($key)=>$row[$key],$keys);
                $s=$db->prepare('SELECT * FROM `'.$table.'` WHERE '.$where.' FOR UPDATE');$s->execute($values);$existing=$s->fetch(PDO::FETCH_ASSOC);
                if($existing){foreach($columns as $column)if(($existing[$column]===null)!==($row[$column]===null)||($row[$column]!==null&&(string)$existing[$column]!== (string)$row[$column]))throw new RuntimeException('Existing '.$table.' ID differs; restore into an empty catalog or resolve ID conflicts first.',409);$reused++;continue;}
                $s=$db->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');$s->execute(array_map(fn($column)=>$row[$column],$columns));$inserted++;
            }
        }
        // Verify projected shared states before committing or copying anything into place.
        catalog_evidence_sync($db);
        if($dry_run){$db->rollBack();return ['dry_run'=>true,'source_kind'=>'portable_zip','total'=>count($m['tables']['Books']),'inserted'=>$inserted,'reused'=>$reused,'files'=>count($staged),'errors'=>[],'warnings'=>$warnings,'note'=>'Portable archive validated; no data or files changed.'];}
        foreach ($staged as $item) {
            $target=$item['target'];
            if (is_file($target)) {
                if (hash_file('sha256',$target)!==$item['sha256']) throw new RuntimeException('Destination changed during import.',409);
                continue;
            }
            $dir=dirname($target);
            if (!is_dir($dir) && !mkdir($dir,str_starts_with($target,rtrim(copy_documentation_root(),'/').'/')?0700:0755,true)) throw new RuntimeException('Cannot create attachment directory.');
            $tmp=tempnam($dir,'.portable-');
            if ($tmp===false) throw new RuntimeException('Cannot stage destination file.');
            try {
                if (!copy($item['tmp'],$tmp) || filesize($tmp)!==$item['size'] || hash_file('sha256',$tmp)!==$item['sha256']) throw new RuntimeException('Incomplete destination write.');
                chmod($tmp,str_contains($target,'/uploads/')?0644:0600);
                if (!@link($tmp,$target)) {
                    if (!is_file($target) || is_link($target) || hash_file('sha256',$target)!==$item['sha256']) throw new RuntimeException('Destination appeared during import.',409);
                } else $created[]=$target;
            } finally { @unlink($tmp); }
        }
        $db->prepare('INSERT INTO PortablePackageImports (package_sha256,source_created_at) VALUES (?,?) ON DUPLICATE KEY UPDATE package_sha256=VALUES(package_sha256)')->execute([hash_file('sha256',$path),$m['created_at']??null]);
        $db->commit();$created=[];
        return ['dry_run'=>false,'source_kind'=>'portable_zip','total'=>count($m['tables']['Books']),'inserted'=>$inserted,'reused'=>$reused,'files'=>count($staged),'errors'=>[],'warnings'=>$warnings,'note'=>'Portable archive restored with document files, history and deletion flags.'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();foreach($created as $file)@unlink($file);throw $e;}
    finally{$zip->close();if($stage&&is_dir($stage)){foreach(glob($stage.'/*')?:[] as $file)@unlink($file);@rmdir($stage);}}
}

function portable_validate_metadata(array $tables,array $files): void {
    $maps=[];
    foreach (portable_tables() as $table=>$keys) {
        foreach ($tables[$table] as $row) {
            foreach ($keys as $key) if (!isset($row[$key]) || ($key!=='source_role' && (!ctype_digit((string)$row[$key]) || (int)$row[$key]<1))) throw new InvalidArgumentException('Invalid archive identity.');
            if (count($keys)===1) $maps[$table][(string)$row[$keys[0]]]=$row;
        }
    }
    $parent=function(string $table,$id)use($maps):array {if(!isset($maps[$table][(string)$id]))throw new InvalidArgumentException('Archive omits a referenced '.$table.' record.');return $maps[$table][(string)$id];};
    $status=function(array $row,bool $active):void{if(($row['record_status']??null)!==($active?'active':'deleted'))throw new InvalidArgumentException('Inconsistent archive deletion flags.');};
    $print=[];$docs=[];$prices=[];
    foreach ($tables['Books'] as $row) {
        foreach (['publisher_id'=>'Publishers','placement_id'=>'Placement'] as $key=>$table) if ($row[$key]!==null) $parent($table,$row[$key]);
        foreach (['cover_image','cover_thumb'] as $key) if (!empty($row[$key]) && !portable_path($row[$key])) throw new InvalidArgumentException('Unsupported cover path in portable archive.');
    }
    foreach (['Books_Authors'=>['author_id','Authors'],'Books_Subjects'=>['subject_id','Subjects']] as $table=>[$key,$target]) foreach($tables[$table] as $row){$parent('Books',$row['book_id']);$parent($target,$row[$key]);}
    foreach($tables['BookCopies'] as $row){$book=$parent('Books',$row['book_id']);if($book['record_status']==='deleted' && $row['record_status']==='active')throw new InvalidArgumentException('Active copy belongs to deleted book.');if(!in_array((string)$row['deleted_with_book'],['0','1'],true)||($row['record_status']==='active'&&(int)$row['deleted_with_book']!==0))throw new InvalidArgumentException('Invalid copy archive marker.');if($row['format']==='print'&&$row['record_status']==='active')$print[$row['book_id']]=true;}
    foreach(['BookCopyPhotos','BookCopyAcquisitionDocuments'] as $table)foreach($tables[$table] as $row){$copy=$parent('BookCopies',$row['copy_id']);$book=$parent('Books',$copy['book_id']);$active=$copy['record_status']==='active'&&$book['record_status']==='active';$status($row,$active);if($copy['format']!=='print')throw new InvalidArgumentException('Physical documentation belongs to an ebook.');if($table==='BookCopyAcquisitionDocuments'){$parent('AcquisitionDocuments',$row['document_id']);if($active)$docs[$row['document_id']]=true;}}
    foreach($tables['AcquisitionDocuments'] as $row)$status($row,isset($docs[$row['document_id']]));
    foreach($tables['BookAssessments'] as $row){$book=$parent('Books',$row['book_id']);$parent('ValuationImports',$row['import_id']);$status($row,$book['record_status']==='active'&&isset($print[$row['book_id']]));}
    foreach($tables['BookAssessmentSources'] as $row){$a=$parent('BookAssessments',$row['assessment_id']);$parent('PriceObservations',$row['observation_id']);$status($row,$a['record_status']==='active');if($row['record_status']==='active')$prices[$row['observation_id']]=true;}
    foreach($tables['PriceObservations'] as $row){$parent('ValuationImports',$row['import_id']);$status($row,isset($prices[$row['observation_id']]));}
    foreach(['BookCopyPhotos','AcquisitionDocuments'] as $table)foreach($tables[$table] as $row)if(!empty($row['file_path'])){$file=$files['copy-attachments/'.$row['file_path']]??null;if(!$file||(!empty($row['sha256'])&&$row['sha256']!==$file['sha256'])||($row['file_size']!==null&&(int)$row['file_size']!==(int)$file['size']))throw new InvalidArgumentException('Attachment metadata differs from file declaration.');}
}

/** Count catalog-specific cover references, excluding all default-cover variants. */
function catalog_real_cover_sql(string $column='b.cover_image'): string {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/D',$column)) throw new InvalidArgumentException('Invalid cover column.');
    $name="LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM($column),'?',1),'#',1),'/',-1))";
    return "(TRIM(COALESCE($column,''))<>'' AND $name<>'' AND $name NOT REGEXP '^default[-_]cover[.][a-z0-9]+$')";
}
function catalog_has_real_cover(?string $path): bool {
    $path=trim($path??'');
    $name=strtolower(basename(explode('#',explode('?',$path,2)[0],2)[0]));
    return $path!=='' && $name!=='' && !preg_match('/^default[-_]cover\.[a-z0-9]+$/D',$name);
}

/** A manual category change is an immutable revision, retaining amounts and source roles. */
function valuation_set_high_value(PDO $db,int $book_id,bool $flag,?int $actor): bool {
    if (!$db->inTransaction()) throw new LogicException('Classification must share the book save transaction.');
    $s=$db->prepare('SELECT record_status FROM Books WHERE book_id=? FOR UPDATE');$s->execute([$book_id]);$status=$s->fetchColumn();
    if ($status===false) throw new InvalidArgumentException('Book not found.');
    $s=$db->prepare("SELECT copy_id FROM BookCopies WHERE book_id=? AND format='print' AND record_status='active' LIMIT 1");$s->execute([$book_id]);
    if ($status!=='active' || !$s->fetchColumn()) throw new InvalidArgumentException('High-value/rare classification requires an active printed copy.');
    $s=$db->prepare('SELECT * FROM BookAssessments WHERE book_id=? ORDER BY assessment_id DESC LIMIT 1 FOR UPDATE');$s->execute([$book_id]);$previous=$s->fetch(PDO::FETCH_ASSOC);
    if ((bool)($previous['is_high_value']??false)===$flag) return false;
    $raw=$previous?json_decode($previous['raw_json'],true,64,JSON_THROW_ON_ERROR):[];
    $label=$flag?'Nagy értékű – külön kezelendő':null;
    $raw['assessment']['Megőrzési értékkategória']=$label??'';
    $raw['manual_classification']=['is_high_value'=>$flag,'actor_id'=>$actor,'changed_at'=>gmdate('c'),'previous_assessment_id'=>$previous['assessment_id']??null];
    $encoded=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $db->prepare('INSERT INTO ValuationImports(content_sha256,original_filename,record_count,imported_by) VALUES(?,?,1,?)')->execute([hash('sha256',random_bytes(32).$encoded),'Manual high-value/rare classification',$actor]);
    $import_id=(int)$db->lastInsertId();
    if ($previous) {
        $columns=array_values(array_diff(array_keys($previous),['assessment_id','created_at']));
        $values=$previous;$values['import_id']=$import_id;$values['is_high_value']=$flag?1:0;$values['preservation_label']=$label;$values['raw_json']=$encoded;$values['record_status']='active';
        $db->prepare('INSERT INTO BookAssessments (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_map(fn($column)=>$values[$column],$columns));
    } else {
        $db->prepare('INSERT INTO BookAssessments(book_id,import_id,is_high_value,preservation_label,raw_json) VALUES(?,?,?,?,?)')->execute([$book_id,$import_id,$flag?1:0,$label,$encoded]);
    }
    $assessment_id=(int)$db->lastInsertId();
    if ($previous) $db->prepare('INSERT INTO BookAssessmentSources(assessment_id,observation_id,source_role,record_status) SELECT ?,observation_id,source_role,record_status FROM BookAssessmentSources WHERE assessment_id=?')->execute([$assessment_id,$previous['assessment_id']]);
    catalog_evidence_sync($db);
    return true;
}
