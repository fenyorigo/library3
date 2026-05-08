<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
$me = require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_fail('Method Not Allowed', 405);
    }

    $d = json_in();
    $user_id = (int)($d['user_id'] ?? $d['id'] ?? 0);
    $new = (string)($d['newPassword'] ?? '');

    if ($user_id <= 0) {
        json_fail('user_id is required', 400);
    }
    if ($new === '') {
        json_fail('New password is required', 400);
    }

    $pdo = pdo();
    $sel = $pdo->prepare('SELECT username FROM Users WHERE user_id = ? LIMIT 1');
    $sel->execute([$user_id]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_fail('User not found', 404);
    }

    $errors = password_policy_errors($new, (string)$row['username']);
    if ($errors) {
        json_fail('Password does not meet policy', 400, ['details' => $errors]);
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    if ($hash === false) {
        json_fail('Failed to hash password', 500);
    }

    $upd = $pdo->prepare('UPDATE Users SET password_hash = ?, force_password_change = 1 WHERE user_id = ?');
    $upd->execute([$hash, $user_id]);

    session_regenerate_id(true);

    log_auth_event('admin_reset_password', $user_id, (string)$row['username'], [
        'actor_user_id' => (int)$me['uid'],
        'actor_username' => (string)$me['username'],
    ]);

    json_out(['ok' => true, 'data' => null]);
} catch (Throwable $e) {
    json_fail($e->getMessage(), 500);
}
