<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This installer must be run from the CLI.\n");
    exit(1);
}

$root = __DIR__;
$PROMPT_AUTO_DEFAULTS = false;

function fail(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function is_tty_input(): bool {
    if (function_exists('stream_isatty')) {
        return @stream_isatty(STDIN);
    }
    if (function_exists('posix_isatty')) {
        return @posix_isatty(STDIN);
    }
    return false;
}

function prompt(string $label, ?string $default = null): string {
    if (($GLOBALS['PROMPT_AUTO_DEFAULTS'] ?? false) && $default !== null) {
        fwrite(STDOUT, $label . " [{$default}]: {$default}\n");
        return $default;
    }
    $suffix = $default !== null ? " [{$default}]" : '';
    fwrite(STDOUT, $label . $suffix . ': ');
    $line = fgets(STDIN);
    if ($line === false) {
        return $default ?? '';
    }
    $line = trim($line);
    if ($line === '' && $default !== null) {
        return $default;
    }
    return $line;
}

function prompt_hidden(string $label): string {
    if (!is_tty_input() || stripos(PHP_OS, 'WIN') === 0) {
        return prompt($label);
    }

    fwrite(STDOUT, $label . ': ');
    $stty = shell_exec('stty -g 2>/dev/null');
    shell_exec('stty -echo 2>/dev/null');
    $line = fgets(STDIN);
    if ($stty !== null) {
        shell_exec('stty ' . trim($stty) . ' 2>/dev/null');
    }
    fwrite(STDOUT, "\n");

    if ($line === false) {
        return '';
    }
    return trim($line);
}

function prompt_yes_no(string $label, bool $default = false): bool {
    $def = $default ? 'y' : 'n';
    $raw = strtolower(trim(prompt($label . ' (y/N)', $def)));
    return in_array($raw, ['y', 'yes'], true);
}

function prompt_choice(string $label, array $choices, string $default): string {
    $allowed = array_values($choices);
    while (true) {
        $raw = strtolower(trim(prompt($label . ' [' . implode('/', $allowed) . ']', $default)));
        if (in_array($raw, $allowed, true)) return $raw;
        fwrite(STDOUT, "Invalid choice. Allowed: " . implode(', ', $allowed) . "\n");
    }
}

function prompt_until_valid(string $label, callable $validator, ?string $default = null, bool $hidden = false): string {
    while (true) {
        $value = $hidden ? prompt_hidden($label) : prompt($label, $default);
        $err = $validator($value);
        if ($err === null) {
            return $value;
        }
        fwrite(STDOUT, "Invalid value: {$err}\n");
    }
}

function command_exists(string $command): bool {
    $out = [];
    $code = 1;
    exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $out, $code);
    return $code === 0 && !empty($out);
}

function run_cmd(string $command): array {
    $out = [];
    $code = 1;
    exec($command . ' 2>&1', $out, $code);
    return ['code' => $code, 'output' => trim(implode("\n", $out))];
}

function check_writable_or_creatable_dir(string $dir): array {
    $dir = rtrim($dir, "/\\");
    if ($dir === '') {
        return ['ok' => false, 'message' => 'Invalid empty directory path'];
    }

    if (is_dir($dir)) {
        if (!is_writable($dir)) {
            return ['ok' => false, 'message' => "{$dir} exists but is not writable"];
        }
        return ['ok' => true, 'message' => "{$dir} is writable"];
    }

    // Allow missing intermediate directories: find nearest existing ancestor.
    $anchor = $dir;
    while (!is_dir($anchor)) {
        $next = dirname($anchor);
        if ($next === $anchor || $next === '.' || $next === '') {
            return ['ok' => false, 'message' => "No existing ancestor directory found for: {$dir}"];
        }
        $anchor = $next;
    }
    if (!is_writable($anchor)) {
        return ['ok' => false, 'message' => "Nearest existing ancestor is not writable: {$anchor}"];
    }

    return ['ok' => true, 'message' => "{$dir} can be created (nearest writable ancestor: {$anchor})"];
}

function ini_size_to_bytes(string $value): int {
    $v = trim($value);
    if ($v === '') return 0;
    $unit = strtolower(substr($v, -1));
    $num = (float)$v;
    switch ($unit) {
        case 'g': return (int)($num * 1024 * 1024 * 1024);
        case 'm': return (int)($num * 1024 * 1024);
        case 'k': return (int)($num * 1024);
        default: return (int)$num;
    }
}

function precheck_print(string $status, string $label, string $message): void {
    fwrite(STDOUT, sprintf("[%s] %s: %s\n", $status, $label, $message));
}

function password_policy_rules(string $username = ''): array {
    return [
        [
            'message' => 'Password must be at least 12 characters.',
            'check' => static fn (string $password): bool => strlen($password) >= 12,
        ],
        [
            'message' => 'Password must include a lowercase letter.',
            'check' => static fn (string $password): bool => (bool)preg_match('/[a-z]/', $password),
        ],
        [
            'message' => 'Password must include an uppercase letter.',
            'check' => static fn (string $password): bool => (bool)preg_match('/[A-Z]/', $password),
        ],
        [
            'message' => 'Password must include a digit.',
            'check' => static fn (string $password): bool => (bool)preg_match('/[0-9]/', $password),
        ],
        [
            'message' => 'Password must include a special character.',
            'check' => static fn (string $password): bool => (bool)preg_match('/[^a-zA-Z0-9]/', $password),
        ],
        [
            'message' => 'Password cannot contain the username.',
            'check' => static fn (string $password): bool => $username === '' || stripos($password, $username) === false,
        ],
    ];
}

function password_policy_errors(string $password, string $username = ''): array {
    $errors = [];
    foreach (password_policy_rules($username) as $rule) {
        if (!($rule['check'])($password)) {
            $errors[] = $rule['message'];
        }
    }
    return $errors;
}

function password_policy_messages(string $username = ''): array {
    return array_map(
        static fn (array $rule): string => $rule['message'],
        password_policy_rules($username)
    );
}

function generate_strong_password(int $length = 24): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()-_=+[]{}';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function detect_default_platform(): string {
    if (PHP_OS_FAMILY === 'Darwin') return 'mac';
    return 'fedora';
}

function installer_platform_profile(string $platform): array {
    if ($platform === 'mac') {
        $home = getenv('HOME');
        $backup_default = (is_string($home) && trim($home) !== '')
            ? (rtrim($home, "/\\") . '/Backups/library')
            : '/Users/Shared/Backups/library';
        return [
            'name' => 'mac',
            'config_dir' => '/opt/homebrew/etc/bookcatalog',
            'backup_default' => $backup_default,
        ];
    }

    return [
        'name' => 'fedora',
        'config_dir' => '/etc/bookcatalog',
        'backup_default' => '/var/backups/library',
    ];
}

function is_absolute_path(string $path): bool {
    return $path !== '' && str_starts_with($path, '/');
}

function validate_port(string $raw): ?string {
    if (!preg_match('/^\d+$/', $raw)) return 'must be numeric';
    $port = (int)$raw;
    if ($port < 1 || $port > 65535) return 'must be between 1 and 65535';
    return null;
}

function validate_db_identifier(string $raw, string $field): ?string {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $raw)) {
        return "{$field} must match ^[A-Za-z_][A-Za-z0-9_]*$";
    }
    return null;
}

function parse_bool_option(string $raw, string $name): bool {
    $v = strtolower(trim($raw));
    if (in_array($v, ['1', 'true', 'yes', 'y', 'on'], true)) return true;
    if (in_array($v, ['0', 'false', 'no', 'n', 'off'], true)) return false;
    fail("Invalid {$name} value '{$raw}'. Allowed: true|false|yes|no|1|0");
}

function parse_params_file_args(string $path): array {
    $resolved = $path;
    if (!is_absolute_path($resolved)) {
        $cwd = getcwd();
        if (!is_string($cwd) || $cwd === '') {
            fail("Cannot resolve relative --params-file path: {$path}");
        }
        $resolved = rtrim($cwd, "/\\") . '/' . $path;
    }
    if (!is_file($resolved) || !is_readable($resolved)) {
        fail("Params file is missing or not readable: {$resolved}");
    }

    $lines = @file($resolved, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fail("Failed to read params file: {$resolved}");
    }

    $args = [];
    foreach ($lines as $line_no => $line) {
        $raw = trim((string)$line);
        if ($raw === '' || str_starts_with($raw, '#')) {
            continue;
        }
        if (!str_starts_with($raw, '--')) {
            fail("Invalid params file line " . ($line_no + 1) . " in {$resolved}: expected '--key=value'");
        }
        if (str_starts_with($raw, '--params-file=')) {
            fail("Nested --params-file is not supported (line " . ($line_no + 1) . " in {$resolved})");
        }
        $args[] = $raw;
    }

    return $args;
}

function parse_installer_options(array $args, string $root): array {
    $opts = [
        'precheck_only' => false,
        'install_mode' => false,
        'platform' => detect_default_platform(),
        'mysql_host' => '127.0.0.1',
        'mysql_port' => 3306,
        'apache_port' => 8443,
        'target_dir' => $root,
        'backup_dir' => null,
        'source_mode' => null,
        'application_archive' => null,
        'mysql_admin_user' => null,
        'db_name' => null,
        'app_db_user' => null,
        'generate_app_db_password' => null,
        'catalog_admin_user' => null,
        'config_path' => null,
        'server_name' => null,
        'ssl_cert_path' => null,
        'ssl_key_path' => null,
        'vhost_output_path' => null,
        'install_sample_data' => null,
        'sample_data_archive' => null,
        'fedora_listen_conf_path' => null,
        'fedora_firewall_cidr' => null,
        'fedora_firewall_interface' => null,
        'auto_defaults' => false,
    ];

    $file_args = [];
    $cli_args = [];
    $has_params_file = false;
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--params-file=')) {
            $value = trim(substr($arg, strlen('--params-file=')));
            if ($value === '') {
                fail('Invalid --params-file value: empty');
            }
            $file_args = array_merge($file_args, parse_params_file_args($value));
            $has_params_file = true;
            continue;
        }
        $cli_args[] = $arg;
    }
    $all_args = array_merge($file_args, $cli_args);

    foreach ($all_args as $arg) {
        if ($arg === '--precheck') {
            $opts['precheck_only'] = true;
            continue;
        }
        if ($arg === '--install') {
            $opts['install_mode'] = true;
            continue;
        }
        if (str_starts_with($arg, '--platform=')) {
            $value = strtolower(trim(substr($arg, strlen('--platform='))));
            if (!in_array($value, ['mac', 'fedora'], true)) {
                fail("Invalid --platform value '{$value}'. Allowed: mac, fedora");
            }
            $opts['platform'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--mysql-host=')) {
            $value = trim(substr($arg, strlen('--mysql-host=')));
            if ($value === '') {
                fail('Invalid --mysql-host value: empty');
            }
            $opts['mysql_host'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--mysql-port=')) {
            $value = trim(substr($arg, strlen('--mysql-port=')));
            $err = validate_port($value);
            if ($err !== null) {
                fail("Invalid --mysql-port value '{$value}': {$err}");
            }
            $opts['mysql_port'] = (int)$value;
            continue;
        }
        if (str_starts_with($arg, '--apache-port=')) {
            $value = trim(substr($arg, strlen('--apache-port=')));
            $err = validate_port($value);
            if ($err !== null) {
                fail("Invalid --apache-port value '{$value}': {$err}");
            }
            $opts['apache_port'] = (int)$value;
            continue;
        }
        if (str_starts_with($arg, '--target-dir=')) {
            $value = trim(substr($arg, strlen('--target-dir=')));
            if (!is_absolute_path($value)) {
                fail('Invalid --target-dir value: must be absolute path');
            }
            $opts['target_dir'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--backup-dir=')) {
            $value = trim(substr($arg, strlen('--backup-dir=')));
            if (!is_absolute_path($value)) {
                fail('Invalid --backup-dir value: must be absolute path');
            }
            $opts['backup_dir'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--source-mode=')) {
            $value = strtolower(trim(substr($arg, strlen('--source-mode='))));
            if (!in_array($value, ['extracted', 'archive'], true)) {
                fail("Invalid --source-mode value '{$value}'. Allowed: extracted, archive");
            }
            $opts['source_mode'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--application-archive=')) {
            $value = trim(substr($arg, strlen('--application-archive=')));
            if (!is_absolute_path($value)) {
                fail('Invalid --application-archive value: must be absolute path');
            }
            $opts['application_archive'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--mysql-admin-user=')) {
            $value = trim(substr($arg, strlen('--mysql-admin-user=')));
            if ($value === '') fail('Invalid --mysql-admin-user value: empty');
            $opts['mysql_admin_user'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--db-name=')) {
            $value = trim(substr($arg, strlen('--db-name=')));
            $err = validate_db_identifier($value, 'DB name');
            if ($err !== null) fail("Invalid --db-name value '{$value}': {$err}");
            $opts['db_name'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--app-db-user=')) {
            $value = trim(substr($arg, strlen('--app-db-user=')));
            $err = validate_db_identifier($value, 'DB user');
            if ($err !== null) fail("Invalid --app-db-user value '{$value}': {$err}");
            $opts['app_db_user'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--generate-app-db-password=')) {
            $value = trim(substr($arg, strlen('--generate-app-db-password=')));
            $opts['generate_app_db_password'] = parse_bool_option($value, '--generate-app-db-password');
            continue;
        }
        if (str_starts_with($arg, '--catalog-admin-user=')) {
            $value = trim(substr($arg, strlen('--catalog-admin-user=')));
            if ($value === '') fail('Invalid --catalog-admin-user value: empty');
            $opts['catalog_admin_user'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--config-path=')) {
            $value = trim(substr($arg, strlen('--config-path=')));
            if (!is_absolute_path($value)) fail('Invalid --config-path value: must be absolute path');
            $opts['config_path'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--server-name=')) {
            $value = trim(substr($arg, strlen('--server-name=')));
            if ($value === '') fail('Invalid --server-name value: empty');
            $opts['server_name'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--ssl-cert-path=')) {
            $value = trim(substr($arg, strlen('--ssl-cert-path=')));
            if (!is_absolute_path($value)) fail('Invalid --ssl-cert-path value: must be absolute path');
            $opts['ssl_cert_path'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--ssl-key-path=')) {
            $value = trim(substr($arg, strlen('--ssl-key-path=')));
            if (!is_absolute_path($value)) fail('Invalid --ssl-key-path value: must be absolute path');
            $opts['ssl_key_path'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--vhost-output-path=')) {
            $value = trim(substr($arg, strlen('--vhost-output-path=')));
            if (!is_absolute_path($value)) fail('Invalid --vhost-output-path value: must be absolute path');
            $opts['vhost_output_path'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--install-sample-data=')) {
            $value = trim(substr($arg, strlen('--install-sample-data=')));
            $opts['install_sample_data'] = parse_bool_option($value, '--install-sample-data');
            continue;
        }
        if (str_starts_with($arg, '--sample-data-archive=')) {
            $value = trim(substr($arg, strlen('--sample-data-archive=')));
            if (!is_absolute_path($value)) fail('Invalid --sample-data-archive value: must be absolute path');
            $opts['sample_data_archive'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--fedora-listen-conf-path=')) {
            $value = trim(substr($arg, strlen('--fedora-listen-conf-path=')));
            if (!is_absolute_path($value)) fail('Invalid --fedora-listen-conf-path value: must be absolute path');
            $opts['fedora_listen_conf_path'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--fedora-firewall-cidr=')) {
            $value = trim(substr($arg, strlen('--fedora-firewall-cidr=')));
            if (!preg_match('/^\d+\.\d+\.\d+\.\d+\/\d+$/', $value)) {
                fail("Invalid --fedora-firewall-cidr value '{$value}'. Expected CIDR like 192.168.0.0/24");
            }
            $opts['fedora_firewall_cidr'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--fedora-firewall-interface=')) {
            $value = trim(substr($arg, strlen('--fedora-firewall-interface=')));
            if ($value === '') fail('Invalid --fedora-firewall-interface value: empty');
            $opts['fedora_firewall_interface'] = $value;
            continue;
        }
        fail('Unknown argument. Supported: --params-file=<path>, --precheck, --install, --platform=mac|fedora, --mysql-host=<host>, --mysql-port=<port>, --apache-port=<port>, --target-dir=<path>, --backup-dir=<path>, --source-mode=extracted|archive, --application-archive=<path>, --mysql-admin-user=<user>, --db-name=<name>, --app-db-user=<user>, --generate-app-db-password=true|false, --catalog-admin-user=<user>, --config-path=<path>, --server-name=<name>, --ssl-cert-path=<path>, --ssl-key-path=<path>, --vhost-output-path=<path>, --install-sample-data=true|false, --sample-data-archive=<path>, --fedora-listen-conf-path=<path>, --fedora-firewall-cidr=<cidr>, --fedora-firewall-interface=<iface>');
    }

    if ($opts['precheck_only'] && $opts['install_mode']) {
        fail('Use either --precheck or --install, not both.');
    }
    if ($has_params_file) {
        $opts['auto_defaults'] = true;
    }

    return $opts;
}

function installer_precheck(string $project_root, array $platform_profile, array $opts): array {
    $results = ['ok' => 0, 'warn' => 0, 'fail' => 0];
    $emit = static function (string $status, string $label, string $message) use (&$results): void {
        precheck_print($status, $label, $message);
        $key = strtolower($status);
        if (isset($results[$key])) {
            $results[$key]++;
        }
    };

    $emit('OK', 'PHP CLI', sprintf('PHP %s (%s)', PHP_VERSION, PHP_BINARY));
    if (!is_executable(PHP_BINARY)) {
        $emit('WARN', 'PHP CLI binary', 'PHP_BINARY is not executable in this environment');
    }

    $required_ext = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'fileinfo', 'session', 'zip'];
    foreach ($required_ext as $ext) {
        if (extension_loaded($ext)) {
            $emit('OK', "PHP extension {$ext}", 'loaded');
        } else {
            $emit('FAIL', "PHP extension {$ext}", 'missing');
        }
    }

    $has_imagick = extension_loaded('imagick') || class_exists('Imagick');
    $has_gd = extension_loaded('gd');
    if ($has_imagick) {
        $emit('OK', 'Image backend', 'Imagick available (preferred)');
    } elseif ($has_gd) {
        $emit('WARN', 'Image backend', 'GD available (Imagick missing; GD fallback will be used)');
    } else {
        $emit('FAIL', 'Image backend', 'Neither Imagick nor GD is available');
    }

    if (command_exists('mysql')) {
        $emit('OK', 'MySQL client', 'mysql client found');
    } else {
        $emit('FAIL', 'MySQL client', 'mysql client not found in PATH');
    }

    $mysql_host = (string)($opts['mysql_host'] ?? '127.0.0.1');
    $mysql_port = (int)($opts['mysql_port'] ?? 3306);
    $db_detected = false;
    if (command_exists('mysqladmin')) {
        $ping = run_cmd('mysqladmin --host=' . escapeshellarg($mysql_host) . ' --port=' . $mysql_port . ' --connect-timeout=2 ping');
        $po = strtolower($ping['output']);
        if ($ping['code'] === 0 && str_contains($po, 'alive')) {
            $emit('OK', 'Database service', "MySQL reachable on {$mysql_host}:{$mysql_port}: " . ($ping['output'] !== '' ? $ping['output'] : 'mysqld is alive'));
            $db_detected = true;
        } elseif (str_contains($po, 'access denied')) {
            $emit('OK', 'Database service', "MySQL reachable on {$mysql_host}:{$mysql_port} (access denied without credentials)");
            $db_detected = true;
        }
    }
    if (!$db_detected) {
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($mysql_host, $mysql_port, $errno, $errstr, 1.0);
        if (is_resource($sock)) {
            fclose($sock);
            $emit('WARN', 'Database service', "TCP {$mysql_host}:{$mysql_port} is open, but mysqladmin ping could not confirm readiness");
        } else {
            $emit('FAIL', 'Database service', "MySQL is not reachable on {$mysql_host}:{$mysql_port}");
        }
    }

    $apache_cmd = null;
    if (command_exists('apachectl')) {
        $apache_cmd = 'apachectl';
    } elseif (command_exists('httpd')) {
        $apache_cmd = 'httpd';
    }
    if ($apache_cmd === null) {
        $emit('FAIL', 'Apache binary', 'Neither apachectl nor httpd found in PATH');
    } else {
        $emit('OK', 'Apache binary', "{$apache_cmd} found");
    }

    if (command_exists('pgrep')) {
        $run = run_cmd('pgrep -fl "httpd|apache2"');
        if ($run['code'] === 0 && $run['output'] !== '') {
            $emit('OK', 'Apache service', 'running process detected');
        } else {
            $emit('WARN', 'Apache service', 'not detected as running');
        }
    } else {
        $emit('WARN', 'Apache service', 'pgrep not available, runtime status not detectable');
    }

    if ($apache_cmd !== null) {
        $mods_cmd = $apache_cmd === 'apachectl' ? 'apachectl -M' : 'httpd -M';
        $mods = run_cmd($mods_cmd);
        if ($mods['code'] === 0) {
            $mout = strtolower($mods['output']);
            $emit(str_contains($mout, 'ssl_module') ? 'OK' : 'FAIL', 'Apache module mod_ssl', str_contains($mout, 'ssl_module') ? 'loaded' : 'not loaded');
            $emit(str_contains($mout, 'headers_module') ? 'OK' : 'FAIL', 'Apache module mod_headers', str_contains($mout, 'headers_module') ? 'loaded' : 'not loaded');
        } else {
            $emit('WARN', 'Apache modules', 'could not detect loaded modules automatically');
        }

        $test_cmd = $apache_cmd === 'apachectl' ? 'apachectl -t' : 'httpd -t';
        $cfg = run_cmd($test_cmd);
        if ($cfg['code'] === 0) {
            $emit('OK', 'Apache configtest', $cfg['output'] !== '' ? $cfg['output'] : 'Syntax OK');
        } else {
            $msg = $cfg['output'] !== '' ? $cfg['output'] : 'configtest failed';
            $emit('WARN', 'Apache configtest', $msg);
        }
    }

    $apache_port = (int)($opts['apache_port'] ?? 8443);
    $errno = 0;
    $errstr = '';
    $sock = @fsockopen('127.0.0.1', $apache_port, $errno, $errstr, 1.0);
    if (is_resource($sock)) {
        fclose($sock);
        $emit('FAIL', 'Apache target port', "Port {$apache_port} is already in use");
    } else {
        $emit('OK', 'Apache target port', "Port {$apache_port} is free");
    }

    $target_dir = rtrim((string)($opts['target_dir'] ?? $project_root), "/\\");
    $expect_extracted_app = (bool)($opts['expect_extracted_app'] ?? true);
    $dist_shipped = is_file($target_dir . '/public/dist/index.html');
    $has_node = command_exists('node');
    $has_npm = command_exists('npm');
    if ($has_node) {
        $node_v = run_cmd('node --version');
        $emit('OK', 'Node.js', $node_v['output'] !== '' ? $node_v['output'] : 'available');
    } else {
        $emit($dist_shipped ? 'WARN' : 'FAIL', 'Node.js', $dist_shipped ? 'missing, but public/dist is already shipped' : 'missing and frontend build is required');
    }
    if ($has_npm) {
        $npm_v = run_cmd('npm --version');
        $emit('OK', 'npm', $npm_v['output'] !== '' ? $npm_v['output'] : 'available');
    } else {
        $emit($dist_shipped ? 'WARN' : 'FAIL', 'npm', $dist_shipped ? 'missing, but public/dist is already shipped' : 'missing and frontend build is required');
    }

    $target_check = check_writable_or_creatable_dir($target_dir);
    if ($target_check['ok']) {
        $emit('OK', 'Filesystem target parent', $target_check['message']);
    } else {
        $emit('FAIL', 'Filesystem target parent', $target_check['message']);
    }

    $public_dir = $target_dir . '/public';
    $public_check = check_writable_or_creatable_dir($public_dir);
    if ($public_check['ok']) {
        $emit('OK', 'Filesystem app public dir', $public_check['message']);
    } else {
        $emit('FAIL', 'Filesystem app public dir', $public_check['message']);
    }

    $schema_path = $target_dir . '/00-basedata/sql/schema.sql';
    if (is_file($schema_path) && is_readable($schema_path)) {
        $emit('OK', 'Schema file', "Readable schema found at {$schema_path}");
    } else {
        if ($expect_extracted_app) {
            $emit('FAIL', 'Schema file', "Missing or unreadable {$schema_path}");
        } else {
            $emit('WARN', 'Schema file', "Missing or unreadable {$schema_path} (will be validated after extracting application archive)");
        }
    }

    $user_ini = $public_dir . '/.user.ini';
    if (!is_file($user_ini) || !is_readable($user_ini)) {
        if ($expect_extracted_app) {
            $emit('FAIL', 'PHP runtime file', "Missing or unreadable {$user_ini}");
        } else {
            $emit('WARN', 'PHP runtime file', "Missing or unreadable {$user_ini} (will be validated after extracting application archive)");
        }
    } else {
        $ini = parse_ini_file($user_ini, false, INI_SCANNER_RAW);
        if (!is_array($ini)) {
            $emit('FAIL', 'PHP runtime file', "Unable to parse {$user_ini}");
        } else {
            $upload_raw = (string)($ini['upload_max_filesize'] ?? '');
            $post_raw = (string)($ini['post_max_size'] ?? '');
            $memory_raw = (string)($ini['memory_limit'] ?? '');
            $max_exec = (int)($ini['max_execution_time'] ?? 0);
            $max_input = (int)($ini['max_input_time'] ?? 0);

            $upload_ok = ini_size_to_bytes($upload_raw) >= 512 * 1024 * 1024;
            $post_ok = ini_size_to_bytes($post_raw) >= 540 * 1024 * 1024;
            $memory_ok = (trim($memory_raw) === '-1') || (ini_size_to_bytes($memory_raw) >= 512 * 1024 * 1024);
            $exec_ok = $max_exec >= 600;
            $input_ok = $max_input >= 600;

            if ($upload_ok && $post_ok && $memory_ok && $exec_ok && $input_ok) {
                $emit('OK', 'PHP runtime file', '.user.ini limits are suitable for large imports');
            } else {
                $emit('FAIL', 'PHP runtime file', ".user.ini limits are too low (upload={$upload_raw}, post={$post_raw}, memory={$memory_raw}, max_execution_time={$max_exec}, max_input_time={$max_input})");
            }
        }
    }

    $config_dir = (string)$platform_profile['config_dir'];
    $config_dir_check = check_writable_or_creatable_dir($config_dir);
    if ($config_dir_check['ok']) {
        $emit('OK', 'Filesystem config dir', $config_dir_check['message'] . " [{$config_dir}]");
    } else {
        $emit('FAIL', 'Filesystem config dir', $config_dir_check['message'] . " [{$config_dir}]");
    }

    $backup_dir_opt = (string)($opts['backup_dir'] ?? '');
    if ($backup_dir_opt !== '') {
        $backup_dir = $backup_dir_opt;
    } else {
        $backup_dir_env = getenv('CATALOG_BACKUP_DIR');
        $backup_dir = (is_string($backup_dir_env) && trim($backup_dir_env) !== '')
            ? trim($backup_dir_env)
            : (string)$platform_profile['backup_default'];
    }
    $backup_check = check_writable_or_creatable_dir($backup_dir);
    if ($backup_check['ok']) {
        $emit('OK', 'Filesystem backup dir', $backup_check['message'] . " [{$backup_dir}]");
    } else {
        $emit('FAIL', 'Filesystem backup dir', $backup_check['message'] . " [{$backup_dir}]");
    }

    if (PHP_OS_FAMILY === 'Linux') {
        if (command_exists('firewall-cmd')) {
            $state = run_cmd('firewall-cmd --state');
            if ($state['code'] === 0 && trim($state['output']) === 'running') {
                $emit('OK', 'firewalld', 'running');
            } else {
                $msg = $state['output'] !== '' ? $state['output'] : 'not running';
                $emit('WARN', 'firewalld', $msg);
            }
        } else {
            $emit('WARN', 'firewalld', 'firewall-cmd not found');
        }

        $emit(command_exists('semanage') ? 'OK' : 'WARN', 'SELinux semanage', command_exists('semanage') ? 'available' : 'not found');
        $emit(command_exists('restorecon') ? 'OK' : 'WARN', 'SELinux restorecon', command_exists('restorecon') ? 'available' : 'not found');

        if (command_exists('getenforce')) {
            $se = run_cmd('getenforce');
            $emit('OK', 'SELinux mode', $se['output'] !== '' ? $se['output'] : 'unknown');
        } else {
            $emit('WARN', 'SELinux mode', 'getenforce not found');
        }
    }

    fwrite(STDOUT, "\n");
    fwrite(STDOUT, sprintf("Precheck summary: OK=%d WARN=%d FAIL=%d\n", $results['ok'], $results['warn'], $results['fail']));
    return $results;
}

function redact_install_plan(array $plan): array {
    $copy = $plan;
    $copy['db']['admin_password'] = '***';
    $copy['db']['app_password'] = '***';
    $copy['catalog_admin']['password'] = '***';
    return $copy;
}

function ensure_dir_exists(string $dir): void {
    if (is_dir($dir)) return;
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail("Failed to create directory: {$dir}");
    }
}

function is_dir_empty(string $dir): bool {
    if (!is_dir($dir)) return true;
    $items = scandir($dir);
    if ($items === false) return false;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        return false;
    }
    return true;
}

function split_sql_statements(string $sql): array {
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) continue;
        $buffer .= $line . "\n";
        if (preg_match('/;\s*$/', $line)) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') $statements[] = trim($buffer);
    return $statements;
}

function resolve_app_root(string $target_dir): string {
    $target_dir = rtrim($target_dir, '/\\');
    if (is_file($target_dir . '/install.php') && is_dir($target_dir . '/public')) {
        return $target_dir;
    }
    $items = scandir($target_dir);
    if ($items === false) return $target_dir;
    $candidates = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $target_dir . '/' . $item;
        if (!is_dir($path)) continue;
        if (is_file($path . '/install.php') && is_dir($path . '/public')) {
            $candidates[] = $path;
        }
    }
    if (count($candidates) === 1) return $candidates[0];
    return $target_dir;
}

function extract_archive_to_target(string $archive_path, string $target_dir): string {
    ensure_dir_exists($target_dir);
    if (!is_dir_empty($target_dir)) {
        fail("Target directory is not empty: {$target_dir}. Use extracted mode or choose an empty target directory.");
    }
    $cmd = 'tar -xzf ' . escapeshellarg($archive_path) . ' -C ' . escapeshellarg($target_dir);
    $res = run_cmd($cmd);
    if (($res['code'] ?? 1) !== 0) {
        fail('Failed to extract application archive: ' . ($res['output'] ?: 'tar command failed'));
    }
    return resolve_app_root($target_dir);
}

function resolve_app_version_label(string $app_root): string {
    $pkg_path = rtrim($app_root, '/\\') . '/frontend/package.json';
    $pkg_raw = @file_get_contents($pkg_path);
    if ($pkg_raw === false) return 'installer build';
    $pkg = json_decode($pkg_raw, true);
    if (!is_array($pkg) || empty($pkg['version'])) return 'installer build';
    return trim((string)$pkg['version']) . ' (installer build)';
}

function create_database_and_schema(array $plan, string $app_root): void {
    $db = $plan['db'];
    $host = (string)$db['host'];
    $port = (int)$db['port'];
    $admin_user = (string)$db['admin_user'];
    $admin_pass = (string)$db['admin_password'];
    $dbname = (string)$db['dbname'];

    $admin_dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
    $admin_pdo = new PDO($admin_dsn, $admin_user, $admin_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $admin_pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $db_dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbname);
    $db_pdo = new PDO($db_dsn, $admin_user, $admin_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $schema_path = rtrim($app_root, '/\\') . '/00-basedata/sql/schema.sql';
    $schema_sql = @file_get_contents($schema_path);
    if ($schema_sql === false) {
        fail("Failed to read schema file: {$schema_path}");
    }
    foreach (split_sql_statements($schema_sql) as $stmt) {
        if ($stmt !== '') $db_pdo->exec($stmt);
    }

    $app_version = resolve_app_version_label($app_root);
    $schema_version = '3.0.0';
    $install_date = gmdate('c');
    $st = $db_pdo->prepare(
        "INSERT INTO SystemInfo (key_name, value) VALUES (?, ?)\n"
        . "ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $st->execute(['app_version', $app_version]);
    $st->execute(['schema_version', $schema_version]);
    $st->execute(['install_date', $install_date]);
}

function create_or_update_app_db_user(array $plan): void {
    $db = $plan['db'];
    $host = (string)$db['host'];
    $port = (int)$db['port'];
    $admin_user = (string)$db['admin_user'];
    $admin_pass = (string)$db['admin_password'];
    $dbname = (string)$db['dbname'];
    $app_user = (string)$db['app_user'];
    $app_pass = (string)$db['app_password'];

    $admin_dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
    $pdo = new PDO($admin_dsn, $admin_user, $admin_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $user_q = $pdo->quote($app_user);
    $pass_q = $pdo->quote($app_pass);
    $hosts = array_unique([$host, '127.0.0.1', 'localhost']);
    foreach ($hosts as $h) {
        $h_q = $pdo->quote($h);
        $pdo->exec("CREATE USER IF NOT EXISTS {$user_q}@{$h_q} IDENTIFIED BY {$pass_q}");
        // Ensure password is updated even when user already existed.
        $pdo->exec("ALTER USER {$user_q}@{$h_q} IDENTIFIED BY {$pass_q}");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$dbname}`.* TO {$user_q}@{$h_q}");
    }
    $pdo->exec('FLUSH PRIVILEGES');
}

function create_or_update_catalog_admin(array $plan): void {
    $db = $plan['db'];
    $host = (string)$db['host'];
    $port = (int)$db['port'];
    $dbname = (string)$db['dbname'];
    $user = (string)$db['app_user'];
    $pass = (string)$db['app_password'];
    $admin_user = (string)$plan['catalog_admin']['username'];
    $admin_pass = (string)$plan['catalog_admin']['password'];

    $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
    if ($hash === false) fail('Failed to hash catalog admin password.');

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbname);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $sel = $pdo->prepare('SELECT user_id FROM Users WHERE username = ? LIMIT 1');
    $sel->execute([$admin_user]);
    $uid = (int)($sel->fetchColumn() ?: 0);

    if ($uid > 0) {
        $up = $pdo->prepare('UPDATE Users SET password_hash=?, role=\'admin\', is_active=1, force_password_change=0 WHERE user_id=?');
        $up->execute([$hash, $uid]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO Users (username, password_hash, role, is_active, force_password_change, created_at) '
            . 'VALUES (?, ?, \'admin\', 1, 0, NOW())'
        );
        $ins->execute([$admin_user, $hash]);
        $uid = (int)$pdo->lastInsertId();
    }

    $prefs = $pdo->prepare(
        'INSERT INTO UserPreferences '
        . '(user_id, logo_path, bg_color, fg_color, text_size, per_page, '
        . 'show_cover, show_subtitle, show_series, show_is_hungarian, show_publisher, '
        . 'show_year, show_status, show_placement, show_isbn, show_loaned_to, '
        . 'show_loaned_date, show_subjects, updated_at) '
        . 'VALUES (?, NULL, NULL, NULL, \'medium\', 25, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, NOW()) '
        . 'ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)'
    );
    $prefs->execute([$uid]);
}

function write_config_file(array $plan, string $app_root): void {
    $template_path = rtrim($app_root, '/\\') . '/config.sample.php';
    $template = @file_get_contents($template_path);
    if ($template === false) {
        fail("Missing or unreadable template: {$template_path}");
    }

    $cfg_path = (string)$plan['config_path'];
    ensure_dir_exists(dirname($cfg_path));
    $replacements = [
        'DB_HOST' => (string)$plan['db']['host'],
        'DB_PORT' => (string)$plan['db']['port'],
        'DB_NAME' => (string)$plan['db']['dbname'],
        'DB_USER' => (string)$plan['db']['app_user'],
        'DB_PASS' => (string)$plan['db']['app_password'],
    ];
    $body = str_replace(array_keys($replacements), array_values($replacements), $template);
    if (@file_put_contents($cfg_path, $body) === false) {
        fail("Failed to write config file: {$cfg_path}");
    }
    @chmod($cfg_path, 0640);
}

function harden_config_for_platform(array $plan): void {
    $cfg_path = (string)$plan['config_path'];
    if (($plan['platform'] ?? '') !== 'fedora') return;

    if (!command_exists('chown') || !command_exists('chmod')) {
        fail('Missing chown/chmod tools for Fedora config hardening.');
    }

    $chown = run_cmd('chown root:apache ' . escapeshellarg($cfg_path));
    if (($chown['code'] ?? 1) !== 0) {
        fail('Failed to set config ownership (root:apache): ' . ($chown['output'] ?? 'unknown error'));
    }
    $chmod = run_cmd('chmod 640 ' . escapeshellarg($cfg_path));
    if (($chmod['code'] ?? 1) !== 0) {
        fail('Failed to set config permissions (640): ' . ($chmod['output'] ?? 'unknown error'));
    }
}

function write_vhost_snippet(array $plan, string $app_root): string {
    $docroot = rtrim($app_root, '/\\') . '/public';
    $server_name = (string)$plan['vhost']['server_name'];
    $https_port = (int)$plan['vhost']['https_port'];
    $crt = (string)$plan['vhost']['ssl_cert_path'];
    $key = (string)$plan['vhost']['ssl_key_path'];
    $cfg = (string)$plan['config_path'];
    $backup = (string)$plan['backup_dir'];

    $content = <<<CONF
<VirtualHost *:{$https_port}>
  ServerName {$server_name}
  DocumentRoot "{$docroot}"

  SSLEngine on
  SSLCertificateFile "{$crt}"
  SSLCertificateKeyFile "{$key}"

  SetEnv BOOKCATALOG_CONFIG "{$cfg}"
  SetEnv CATALOG_BACKUP_DIR "{$backup}"

  DirectoryIndex index.php index.html

  <Directory "{$docroot}">
    AllowOverride All
    Require all granted
  </Directory>
</VirtualHost>
CONF;

    $out = (string)($plan['vhost']['output_path'] ?? '');
    if ($out === '') {
        $out_dir = rtrim($app_root, '/\\') . '/install-output';
        ensure_dir_exists($out_dir);
        $out = $out_dir . '/httpd-bookcatalog-' . $https_port . '.conf';
    } else {
        ensure_dir_exists(dirname($out));
    }
    if (@file_put_contents($out, $content . "\n") === false) {
        fail("Failed to write vhost snippet: {$out}");
    }
    return $out;
}

function add_listen_port(string $listen_conf_path, int $port): void {
    if (!is_file($listen_conf_path) || !is_readable($listen_conf_path)) {
        fail("Listen config not found/readable: {$listen_conf_path}");
    }
    $raw = (string)file_get_contents($listen_conf_path);
    if (preg_match('/^\s*Listen\s+' . preg_quote((string)$port, '/') . '\s*$/mi', $raw)) {
        return;
    }
    $append = rtrim($raw, "\r\n") . "\nListen {$port}\n";
    if (@file_put_contents($listen_conf_path, $append) === false) {
        fail("Failed to update listen config: {$listen_conf_path}");
    }
}

function detect_interface_for_cidr(string $cidr): string {
    if (!command_exists('ip')) return '';
    $parts = explode('/', $cidr, 2);
    $ip_probe = trim($parts[0] ?? '');
    if ($ip_probe === '') return '';
    $route = run_cmd('ip route get ' . escapeshellarg($ip_probe));
    if (($route['code'] ?? 1) !== 0) return '';
    $out = (string)($route['output'] ?? '');
    if (preg_match('/\bdev\s+([a-zA-Z0-9._:-]+)/', $out, $m)) {
        return trim((string)$m[1]);
    }
    return '';
}

function semanage_set_context(string $regex_path, string $type): void {
    $add = run_cmd('semanage fcontext -a -t ' . escapeshellarg($type) . ' ' . escapeshellarg($regex_path));
    if (($add['code'] ?? 1) === 0) return;
    $mod = run_cmd('semanage fcontext -m -t ' . escapeshellarg($type) . ' ' . escapeshellarg($regex_path));
    if (($mod['code'] ?? 1) !== 0) {
        fail('semanage failed for ' . $regex_path . ': ' . (($mod['output'] ?? '') ?: ($add['output'] ?? 'unknown error')));
    }
}

function configure_fedora_security(array $plan, string $app_root): void {
    if (($plan['platform'] ?? '') !== 'fedora') return;

    $uploads = rtrim($app_root, '/\\') . '/public/uploads';
    $assets = rtrim($app_root, '/\\') . '/public/user-assets';
    $backup = (string)$plan['backup_dir'];
    $cfg_path = (string)$plan['config_path'];
    $cfg_dir = dirname($cfg_path);
    $port = (int)$plan['vhost']['https_port'];
    $cidr = (string)($plan['fedora']['firewall_cidr'] ?? '192.168.0.0/24');
    $iface = (string)($plan['fedora']['firewall_interface'] ?? '');

    if (command_exists('semanage') && command_exists('restorecon')) {
        semanage_set_context(rtrim($app_root, '/\\') . '(/.*)?', 'httpd_sys_content_t');
        semanage_set_context($uploads . '(/.*)?', 'httpd_sys_rw_content_t');
        semanage_set_context($assets . '(/.*)?', 'httpd_sys_rw_content_t');
        semanage_set_context(rtrim($backup, '/\\') . '(/.*)?', 'httpd_sys_rw_content_t');
        semanage_set_context(rtrim($cfg_dir, '/\\') . '(/.*)?', 'httpd_sys_content_t');

        $restore = run_cmd(
            'restorecon -Rv '
            . escapeshellarg(rtrim($app_root, '/\\'))
            . ' ' . escapeshellarg($uploads)
            . ' ' . escapeshellarg($assets)
            . ' ' . escapeshellarg(rtrim($backup, '/\\'))
            . ' ' . escapeshellarg($cfg_dir)
            . ' ' . escapeshellarg($cfg_path)
        );
        if (($restore['code'] ?? 1) !== 0) {
            fail('restorecon failed: ' . ($restore['output'] ?? 'unknown error'));
        }
    } else {
        fwrite(STDOUT, "WARN: semanage/restorecon not available; SELinux context steps skipped.\n");
    }

    if (!command_exists('firewall-cmd')) {
        fwrite(STDOUT, "WARN: firewall-cmd not available; firewall rule steps skipped.\n");
        return;
    }

    if ($iface === '') {
        $iface = detect_interface_for_cidr($cidr);
    }
    if ($iface === '') {
        fail('Could not detect network interface for firewall rule. Provide fedora firewall interface in installer prompt.');
    }

    $zone_res = run_cmd('firewall-cmd --get-zone-of-interface=' . escapeshellarg($iface));
    if (($zone_res['code'] ?? 1) !== 0 || trim((string)$zone_res['output']) === '') {
        fail('Failed to resolve firewalld zone for interface ' . $iface . ': ' . ($zone_res['output'] ?? 'unknown error'));
    }
    $zone = trim((string)$zone_res['output']);
    $rule = 'rule family="ipv4" source address="' . $cidr . '" port port="' . $port . '" protocol="tcp" accept';

    $query = run_cmd(
        'firewall-cmd --permanent --zone=' . escapeshellarg($zone)
        . ' --query-rich-rule=' . escapeshellarg($rule)
    );
    if (($query['code'] ?? 1) !== 0) {
        $add = run_cmd(
            'firewall-cmd --permanent --zone=' . escapeshellarg($zone)
            . ' --add-rich-rule=' . escapeshellarg($rule)
        );
        if (($add['code'] ?? 1) !== 0) {
            fail('Failed to add firewalld rich-rule: ' . ($add['output'] ?? 'unknown error'));
        }
    }

    $reload = run_cmd('firewall-cmd --reload');
    if (($reload['code'] ?? 1) !== 0) {
        fail('Failed to reload firewalld: ' . ($reload['output'] ?? 'unknown error'));
    }
}

function configure_fedora_permissions(array $plan, string $app_root): void {
    if (($plan['platform'] ?? '') !== 'fedora') return;

    $backup = (string)$plan['backup_dir'];
    $cfg_dir = dirname((string)$plan['config_path']);
    $uploads = rtrim($app_root, '/\\') . '/public/uploads';
    $assets = rtrim($app_root, '/\\') . '/public/user-assets';

    $ops = [
        'chown -R apache:apache ' . escapeshellarg(rtrim($app_root, '/\\')),
        'chmod 755 ' . escapeshellarg(rtrim($app_root, '/\\')),
        'chown -R apache:apache ' . escapeshellarg(rtrim($backup, '/\\')),
        'chmod 750 ' . escapeshellarg(rtrim($backup, '/\\')),
        'chown root:root ' . escapeshellarg(rtrim($cfg_dir, '/\\')),
        'chmod 755 ' . escapeshellarg(rtrim($cfg_dir, '/\\')),
        'chown -R apache:apache ' . escapeshellarg($uploads),
        'chmod 775 ' . escapeshellarg($uploads),
        'chown -R apache:apache ' . escapeshellarg($assets),
        'chmod 775 ' . escapeshellarg($assets),
    ];

    foreach ($ops as $cmd) {
        $res = run_cmd($cmd);
        if (($res['code'] ?? 1) !== 0) {
            fail('Fedora permission step failed: ' . $cmd . ' :: ' . ($res['output'] ?? 'unknown error'));
        }
    }
}

function verify_fedora_postinstall(array $plan, string $app_root): void {
    if (($plan['platform'] ?? '') !== 'fedora') return;

    $checks = [
        'app_root_owner' => 'stat -c %U:%G ' . escapeshellarg(rtrim($app_root, '/\\')),
        'uploads_owner' => 'stat -c %U:%G ' . escapeshellarg(rtrim($app_root, '/\\') . '/public/uploads'),
        'uploads_mode' => 'stat -c %a ' . escapeshellarg(rtrim($app_root, '/\\') . '/public/uploads'),
        'app_root_context' => 'ls -Zd ' . escapeshellarg(rtrim($app_root, '/\\')),
        'uploads_context' => 'ls -Zd ' . escapeshellarg(rtrim($app_root, '/\\') . '/public/uploads'),
    ];

    foreach ($checks as $name => $cmd) {
        $res = run_cmd($cmd);
        if (($res['code'] ?? 1) !== 0) {
            fail('Fedora verification failed (' . $name . '): ' . ($res['output'] ?? 'unknown error'));
        }
    }

    $write_test = run_cmd('runuser -u apache -- test -w ' . escapeshellarg(rtrim($app_root, '/\\') . '/public/uploads'));
    if (($write_test['code'] ?? 1) !== 0) {
        fail('Fedora verification failed: apache user cannot write uploads directory.');
    }
}

function install_sample_data_if_requested(array $plan): void {
    $sample = $plan['sample_data'] ?? [];
    if (!($sample['install'] ?? false)) return;
    $archive = (string)($sample['archive_path'] ?? '');
    if ($archive === '' || !is_file($archive)) {
        fail('Sample data installation requested but archive is missing.');
    }
    fwrite(STDOUT, "Sample data install requested: {$archive}\n");
    fwrite(STDOUT, "Sample data execution is not yet automated in this step; run your sample import workflow after base install.\n");
}

function collect_install_plan(array $opts, array $platform_profile): array {
    fwrite(STDOUT, "\n=== BookCatalog Interactive Install Plan ===\n\n");

    $target_dir = prompt_until_valid('Target install directory', static function (string $v): ?string {
        if (!is_absolute_path($v)) return 'must be an absolute path';
        return null;
    }, (string)$opts['target_dir']);

    $default_source_mode = is_file(rtrim($target_dir, '/\\') . '/install.php') ? 'extracted' : 'archive';
    if (is_string($opts['source_mode'] ?? null) && in_array($opts['source_mode'], ['extracted', 'archive'], true)) {
        $default_source_mode = (string)$opts['source_mode'];
    }
    $source_mode = prompt_choice('Application source mode', ['extracted', 'archive'], $default_source_mode);

    $tar_path = null;
    if ($source_mode === 'archive') {
        $home = getenv('HOME') ?: '';
        $default_tar = '';
        $candidate_local = rtrim($target_dir, '/\\') . '/bookcatalog.tar.gz';
        $candidate_dl = $home !== '' ? (rtrim($home, '/\\') . '/Downloads/bookcatalog.tar.gz') : '';
        if (is_file($candidate_local)) {
            $default_tar = $candidate_local;
        } elseif ($candidate_dl !== '' && is_file($candidate_dl)) {
            $default_tar = $candidate_dl;
        }

        $tar_default = is_string($opts['application_archive'] ?? null) && trim((string)$opts['application_archive']) !== ''
            ? (string)$opts['application_archive']
            : ($default_tar !== '' ? $default_tar : null);
        $tar_path = prompt_until_valid('Application tar.gz path', static function (string $v): ?string {
            if ($v === '') return 'path is required';
            if (!is_absolute_path($v)) return 'path must be absolute';
            if (!is_file($v) || !is_readable($v)) return 'file is missing or not readable';
            if (!preg_match('/\.(tar\.gz|tgz)$/i', $v)) return 'file should end with .tar.gz or .tgz';
            return null;
        }, $tar_default);
    }

    $mysql_host = prompt_until_valid('DB host', static fn(string $v): ?string => trim($v) === '' ? 'host is required' : null, (string)$opts['mysql_host']);
    $mysql_port_s = prompt_until_valid('DB port', static fn(string $v): ?string => validate_port($v), (string)$opts['mysql_port']);
    $mysql_port = (int)$mysql_port_s;

    $mysql_admin_user = prompt_until_valid(
        'MySQL admin/root username',
        static fn(string $v): ?string => trim($v) === '' ? 'username is required' : null,
        is_string($opts['mysql_admin_user'] ?? null) ? (string)$opts['mysql_admin_user'] : null
    );
    $mysql_admin_password = prompt_until_valid('MySQL admin/root password', static fn(string $v): ?string => $v === '' ? 'password is required' : null, null, true);

    $db_name = prompt_until_valid(
        'Catalog DB name',
        static fn(string $v): ?string => validate_db_identifier($v, 'DB name'),
        is_string($opts['db_name'] ?? null) ? (string)$opts['db_name'] : 'books'
    );
    $app_db_user = prompt_until_valid(
        'App DB user',
        static fn(string $v): ?string => validate_db_identifier($v, 'DB user'),
        is_string($opts['app_db_user'] ?? null) ? (string)$opts['app_db_user'] : 'bookcatalog_app'
    );

    $generate_app_db_password_default = is_bool($opts['generate_app_db_password'] ?? null)
        ? (bool)$opts['generate_app_db_password']
        : true;
    $generate_app_db_password = prompt_yes_no('Generate random strong app DB password?', $generate_app_db_password_default);
    if ($generate_app_db_password) {
        $app_db_password = generate_strong_password(24);
        fwrite(STDOUT, "App DB password generated (hidden).\n");
    } else {
        $app_db_password = '';
        while ($app_db_password === '') {
            $p1 = prompt_until_valid('App DB password', static fn(string $v): ?string => $v === '' ? 'password is required' : null, null, true);
            $p2 = prompt_until_valid('Confirm app DB password', static fn(string $v): ?string => $v === '' ? 'password is required' : null, null, true);
            if ($p1 !== $p2) {
                fwrite(STDOUT, "Passwords do not match.\n");
                continue;
            }
            $app_db_password = $p1;
        }
    }

    $catalog_admin_user = prompt_until_valid(
        'Catalog admin username',
        static fn(string $v): ?string => trim($v) === '' ? 'username is required' : null,
        is_string($opts['catalog_admin_user'] ?? null) ? (string)$opts['catalog_admin_user'] : 'admin'
    );
    fwrite(STDOUT, "Catalog admin password policy:\n");
    foreach (password_policy_messages($catalog_admin_user) as $msg) {
        fwrite(STDOUT, "- {$msg}\n");
    }
    $catalog_admin_password = '';
    while ($catalog_admin_password === '') {
        $p1 = prompt_until_valid('Catalog admin password', static fn(string $v): ?string => $v === '' ? 'password is required' : null, null, true);
        $p2 = prompt_until_valid('Confirm catalog admin password', static fn(string $v): ?string => $v === '' ? 'password is required' : null, null, true);
        if ($p1 !== $p2) {
            fwrite(STDOUT, "Passwords do not match.\n");
            continue;
        }
        $errs = password_policy_errors($p1, $catalog_admin_user);
        if ($errs) {
            fwrite(STDOUT, "Password policy errors:\n");
            foreach ($errs as $err) fwrite(STDOUT, "- {$err}\n");
            continue;
        }
        $catalog_admin_password = $p1;
    }

    $backup_default = $opts['backup_dir'] ?? $platform_profile['backup_default'];
    $backup_dir = prompt_until_valid('Backup directory', static function (string $v): ?string {
        if (!is_absolute_path($v)) return 'must be an absolute path';
        return null;
    }, (string)$backup_default);

    $target_name = basename(rtrim($target_dir, '/\\'));
    if (($platform_profile['name'] ?? '') === 'fedora') {
        $config_default = rtrim((string)$platform_profile['config_dir'], '/\\') . '/' . $target_name . '.conf';
    } else {
        $config_default = rtrim((string)$platform_profile['config_dir'], '/\\') . '/library-config.php';
    }
    $config_default = is_string($opts['config_path'] ?? null) && trim((string)$opts['config_path']) !== ''
        ? (string)$opts['config_path']
        : $config_default;
    $config_path = prompt_until_valid('Config path', static function (string $v): ?string {
        if (!is_absolute_path($v)) return 'must be an absolute path';
        return null;
    }, $config_default);

    $server_name = prompt_until_valid(
        'ServerName',
        static fn(string $v): ?string => trim($v) === '' ? 'ServerName is required' : null,
        is_string($opts['server_name'] ?? null) ? (string)$opts['server_name'] : 'localhost'
    );
    $https_port_s = prompt_until_valid('Apache HTTPS port', static fn(string $v): ?string => validate_port($v), (string)$opts['apache_port']);
    $https_port = (int)$https_port_s;

    $default_ssl_cert = (($platform_profile['name'] ?? '') === 'fedora')
        ? '/etc/pki/tls/certs/localhost.crt'
        : '/opt/homebrew/etc/httpd/certs/bookcatalogv2.crt';
    $default_ssl_key = (($platform_profile['name'] ?? '') === 'fedora')
        ? '/etc/pki/tls/private/localhost.key'
        : '/opt/homebrew/etc/httpd/certs/bookcatalogv2.key';

    $ssl_cert_path = prompt_until_valid('SSL certificate path', static function (string $v): ?string {
        if (!is_absolute_path($v)) return 'must be absolute path';
        if (!is_file($v) || !is_readable($v)) return 'file is missing or not readable';
        return null;
    }, is_string($opts['ssl_cert_path'] ?? null) ? (string)$opts['ssl_cert_path'] : $default_ssl_cert);

    $ssl_key_path = prompt_until_valid('SSL key path', static function (string $v): ?string {
        if (!is_absolute_path($v)) return 'must be absolute path';
        if (!is_file($v) || !is_readable($v)) return 'file is missing or not readable';
        return null;
    }, is_string($opts['ssl_key_path'] ?? null) ? (string)$opts['ssl_key_path'] : $default_ssl_key);

    $vhost_output_default = rtrim($target_dir, '/\\') . '/install-output/httpd-bookcatalog-' . $https_port . '.conf';
    $fedora_cfg = null;
    if (($platform_profile['name'] ?? '') === 'fedora') {
        // Fedora naming convention: <port>-<app>.conf (app = target dir basename)
        $vhost_output_default = '/etc/httpd/conf.d/' . $https_port . '-' . $target_name . '.conf';
        if (is_string($opts['vhost_output_path'] ?? null) && trim((string)$opts['vhost_output_path']) !== '') {
            fwrite(STDOUT, "Note: --vhost-output-path is ignored on fedora; using {$vhost_output_default}\n");
        }
        $listen_conf_path = prompt_until_valid('Fedora listen.conf path', static function (string $v): ?string {
            if (!is_absolute_path($v)) return 'must be absolute path';
            return null;
        }, is_string($opts['fedora_listen_conf_path'] ?? null) ? (string)$opts['fedora_listen_conf_path'] : '/etc/httpd/conf.d/listen.conf');
        $firewall_cidr = prompt_until_valid('Fedora allowed LAN CIDR', static function (string $v): ?string {
            if (!preg_match('/^\\d+\\.\\d+\\.\\d+\\.\\d+\\/\\d+$/', $v)) return 'must look like 192.168.0.0/24';
            return null;
        }, is_string($opts['fedora_firewall_cidr'] ?? null) ? (string)$opts['fedora_firewall_cidr'] : '192.168.0.0/24');
        $detected_iface = detect_interface_for_cidr($firewall_cidr);
        $firewall_iface_default = is_string($opts['fedora_firewall_interface'] ?? null) && trim((string)$opts['fedora_firewall_interface']) !== ''
            ? (string)$opts['fedora_firewall_interface']
            : ($detected_iface !== '' ? $detected_iface : 'enp0s31f6');
        $firewall_iface = prompt_until_valid('Fedora network interface', static fn(string $v): ?string => trim($v) === '' ? 'interface is required' : null, $firewall_iface_default);
        $fedora_cfg = [
            'listen_conf_path' => $listen_conf_path,
            'firewall_cidr' => $firewall_cidr,
            'firewall_interface' => $firewall_iface,
        ];
    }

    if (($platform_profile['name'] ?? '') === 'fedora') {
        $vhost_output_path = $vhost_output_default;
        fwrite(STDOUT, "Vhost output path: {$vhost_output_path}\n");
    } else {
        $vhost_output_default = is_string($opts['vhost_output_path'] ?? null) && trim((string)$opts['vhost_output_path']) !== ''
            ? (string)$opts['vhost_output_path']
            : $vhost_output_default;
        $vhost_output_path = prompt_until_valid('Vhost output path', static function (string $v): ?string {
            if (!is_absolute_path($v)) return 'must be absolute path';
            return null;
        }, $vhost_output_default);
    }

    $install_sample_default = is_bool($opts['install_sample_data'] ?? null)
        ? (bool)$opts['install_sample_data']
        : false;
    $install_sample = prompt_yes_no('Install sample data archive?', $install_sample_default);
    $sample_path = null;
    if ($install_sample) {
        $sample_path = prompt_until_valid('Sample data archive path', static function (string $v): ?string {
            if (!is_absolute_path($v)) return 'must be absolute path';
            if (!is_file($v) || !is_readable($v)) return 'file is missing or not readable';
            return null;
        }, is_string($opts['sample_data_archive'] ?? null) ? (string)$opts['sample_data_archive'] : null);
    }

    return [
        'mode' => 'install',
        'platform' => $platform_profile['name'],
        'application_source_mode' => $source_mode,
        'application_archive' => $tar_path,
        'target_dir' => $target_dir,
        'backup_dir' => $backup_dir,
        'config_path' => $config_path,
        'db' => [
            'host' => $mysql_host,
            'port' => $mysql_port,
            'admin_user' => $mysql_admin_user,
            'admin_password' => $mysql_admin_password,
            'dbname' => $db_name,
            'app_user' => $app_db_user,
            'app_password' => $app_db_password,
            'app_password_generated' => $generate_app_db_password,
        ],
        'catalog_admin' => [
            'username' => $catalog_admin_user,
            'password' => $catalog_admin_password,
        ],
        'vhost' => [
            'server_name' => $server_name,
            'https_port' => $https_port,
            'ssl_cert_path' => $ssl_cert_path,
            'ssl_key_path' => $ssl_key_path,
            'output_path' => $vhost_output_path,
        ],
        'sample_data' => [
            'install' => $install_sample,
            'archive_path' => $sample_path,
        ],
        'fedora' => $fedora_cfg,
    ];
}

$argv = $_SERVER['argv'] ?? [];
$args = array_slice($argv, 1);
$opts = parse_installer_options($args, $root);
$PROMPT_AUTO_DEFAULTS = (bool)($opts['auto_defaults'] ?? false);
$platform = (string)$opts['platform'];
$platform_profile = installer_platform_profile($platform);

if ($opts['precheck_only']) {
    fwrite(STDOUT, "Installer platform profile: {$platform}\n");
    fwrite(STDOUT, "Precheck ports: mysql={$opts['mysql_port']}, apache={$opts['apache_port']}\n");
    fwrite(STDOUT, 'Precheck target dir: ' . ($opts['target_dir'] ?: $root) . "\n");
    fwrite(STDOUT, 'Precheck backup dir: ' . (($opts['backup_dir'] ?? '') !== '' ? $opts['backup_dir'] : '[env/default]') . "\n");
    $opts['expect_extracted_app'] = true;
    $precheck = installer_precheck($root, $platform_profile, $opts);
    exit(($precheck['fail'] ?? 0) > 0 ? 1 : 0);
}

$plan = collect_install_plan($opts, $platform_profile);

$precheck_opts = [
    'mysql_host' => $plan['db']['host'],
    'mysql_port' => $plan['db']['port'],
    'apache_port' => $plan['vhost']['https_port'],
    'target_dir' => $plan['target_dir'],
    'backup_dir' => $plan['backup_dir'],
    'expect_extracted_app' => (($plan['application_source_mode'] ?? 'archive') === 'extracted'),
];

fwrite(STDOUT, "\nRunning precheck with collected inputs...\n");
$precheck = installer_precheck($root, $platform_profile, $precheck_opts);
if (($precheck['fail'] ?? 0) > 0) {
    fail('Precheck failed for the collected install inputs. Fix FAIL items and rerun.');
}

$redacted = redact_install_plan($plan);
fwrite(STDOUT, "\n=== Installation Summary (passwords hidden) ===\n");
fwrite(STDOUT, json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$auto_defaults_prev = (bool)($GLOBALS['PROMPT_AUTO_DEFAULTS'] ?? false);
$GLOBALS['PROMPT_AUTO_DEFAULTS'] = false;
$proceed = prompt_yes_no('Proceed with installation?', false);
$GLOBALS['PROMPT_AUTO_DEFAULTS'] = $auto_defaults_prev;
if (!$proceed) {
    fwrite(STDOUT, "Installation cancelled. No changes were made.\n");
    exit(0);
}

fwrite(STDOUT, "Installation plan confirmed.\n");

$effective_app_root = rtrim((string)$plan['target_dir'], '/\\');
if (($plan['application_source_mode'] ?? 'archive') === 'archive') {
    fwrite(STDOUT, "Extracting application archive...\n");
    $effective_app_root = extract_archive_to_target((string)$plan['application_archive'], (string)$plan['target_dir']);
    fwrite(STDOUT, "Archive extracted to: {$effective_app_root}\n");
} else {
    $effective_app_root = resolve_app_root((string)$plan['target_dir']);
    fwrite(STDOUT, "Using pre-extracted application at: {$effective_app_root}\n");
}

fwrite(STDOUT, "Running post-extract precheck...\n");
$post_precheck_opts = [
    'mysql_host' => $plan['db']['host'],
    'mysql_port' => $plan['db']['port'],
    'apache_port' => $plan['vhost']['https_port'],
    'target_dir' => $effective_app_root,
    'backup_dir' => $plan['backup_dir'],
    'expect_extracted_app' => true,
];
$post_precheck = installer_precheck($root, $platform_profile, $post_precheck_opts);
if (($post_precheck['fail'] ?? 0) > 0) {
    fail('Post-extract precheck failed. Fix FAIL items and rerun.');
}

fwrite(STDOUT, "Ensuring filesystem directories...\n");
ensure_dir_exists((string)$plan['backup_dir']);
ensure_dir_exists(dirname((string)$plan['config_path']));
ensure_dir_exists(rtrim($effective_app_root, '/\\') . '/public/uploads');
ensure_dir_exists(rtrim($effective_app_root, '/\\') . '/public/user-assets');
configure_fedora_permissions($plan, $effective_app_root);

fwrite(STDOUT, "Creating database and applying schema...\n");
create_database_and_schema($plan, $effective_app_root);

fwrite(STDOUT, "Creating/updating application DB user...\n");
create_or_update_app_db_user($plan);

fwrite(STDOUT, "Creating/updating catalog admin user...\n");
create_or_update_catalog_admin($plan);

fwrite(STDOUT, "Writing application config...\n");
write_config_file($plan, $effective_app_root);
harden_config_for_platform($plan);

fwrite(STDOUT, "Writing Apache vhost snippet...\n");
$vhost_path = write_vhost_snippet($plan, $effective_app_root);

if (($plan['platform'] ?? '') === 'fedora') {
    $listen_conf = (string)($plan['fedora']['listen_conf_path'] ?? '/etc/httpd/conf.d/listen.conf');
    fwrite(STDOUT, "Updating listen config ({$listen_conf})...\n");
    add_listen_port($listen_conf, (int)$plan['vhost']['https_port']);

    fwrite(STDOUT, "Applying Fedora SELinux and firewalld settings...\n");
    configure_fedora_security($plan, $effective_app_root);
    verify_fedora_postinstall($plan, $effective_app_root);
}

install_sample_data_if_requested($plan);

fwrite(STDOUT, "\nInstallation completed.\n");
fwrite(STDOUT, "Application root: {$effective_app_root}\n");
fwrite(STDOUT, "Config file: {$plan['config_path']}\n");
fwrite(STDOUT, "Vhost snippet: {$vhost_path}\n");
fwrite(STDOUT, "Next: copy/include the vhost snippet, reload Apache, then login with admin '{$plan['catalog_admin']['username']}'.\n");
exit(0);
