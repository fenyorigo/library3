<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';

$me = require_login();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');

$pdo = pdo();
$prefs = fetch_user_preferences($pdo, (int)$me['uid']);
$st = $pdo->prepare('SELECT force_password_change FROM Users WHERE user_id = ? LIMIT 1');
$st->execute([(int)$me['uid']]);
$force = (int)($st->fetchColumn() ?? 0);
json_out([
  'ok' => true,
  'data' => [
    'user' => [
      'username' => $me['username'],
      'role' => $me['role'],
      'force_password_change' => $force,
    ],
    'preferences' => $prefs,
    'csrf_token' => csrf_token_ensure(),
  ],
]);
