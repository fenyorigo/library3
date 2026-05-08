<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

start_secure_session();

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Method Not Allowed', 405);
  }

  // Accept JSON or form-encoded
  $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
  if (str_contains($ct, 'application/json')) {
    $d = json_in();
  } else {
    $d = $_POST ?? [];
  }

  $username = trim((string)($d['username'] ?? ''));
  $password = (string)($d['password'] ?? '');

  if ($username === '' || $password === '') {
    json_fail('Missing credentials', 400);
  }

  $pdo = pdo();
  login_rate_limit_check($pdo, request_ip());
  $st = $pdo->prepare("SELECT user_id, username, password_hash, role, is_active
                       FROM Users WHERE username = ? LIMIT 1");
  $st->execute([$username]);
  $u = $st->fetch(PDO::FETCH_ASSOC);

  $reason = null;
  if (!$u) {
    $reason = 'user_not_found';
  } elseif (!(int)$u['is_active']) {
    $reason = 'inactive';
  } elseif (!password_verify($password, $u['password_hash'])) {
    $reason = 'bad_password';
  }

  if ($reason !== null) {
    auth_failure_delay();
    log_auth_event('login_failed', $u ? (int)$u['user_id'] : null, $username, [
      'reason' => $reason,
    ]);
    json_fail('Invalid username or password', 401);
  }

  session_regenerate_id(true);
  $_SESSION['uid']      = (int)$u['user_id'];
  $_SESSION['username'] = (string)$u['username'];
  $_SESSION['role']     = (string)$u['role'];

  $pdo->prepare("UPDATE Users SET last_login = NOW() WHERE user_id = ?")
      ->execute([$_SESSION['uid']]);

  sync_systeminfo_app_version($pdo);
  sync_systeminfo_schema_version($pdo);

  log_auth_event('login_success', (int)$_SESSION['uid'], (string)$_SESSION['username'], [
    'role' => (string)$_SESSION['role'],
  ]);

  $admin_tools_warning = null;
  if ((string)$_SESSION['role'] === 'admin') {
    $admin_tools_warning = admin_tools_warning_message();
  }

  json_out([
    'ok' => true,
    'data' => [
      'user' => ['username' => $_SESSION['username'], 'role' => $_SESSION['role']],
      'admin_tools_warning' => $admin_tools_warning,
      'csrf_token' => csrf_token_ensure(),
    ],
  ]);
} catch (Throwable $e) {
  json_fail($e->getMessage(), 500);
}
