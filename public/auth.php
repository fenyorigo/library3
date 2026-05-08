<?php
declare(strict_types=1);

// Must be included AFTER functions.php (pdo/json_out available)
start_secure_session();

function require_login(): array {
  require_csrf();
  if (empty($_SESSION['uid'])) {
    json_fail('Not authenticated', 401);
  }
  return [
    'uid' => (int)$_SESSION['uid'],
    'role' => (string)($_SESSION['role'] ?? 'reader'),
    'username' => (string)($_SESSION['username'] ?? ''),
  ];
}

function require_admin(): array {
  $me = require_login();
  if (($me['role'] ?? '') !== 'admin') {
    json_fail('Admin required', 403);
  }
  return $me;
}
