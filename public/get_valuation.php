<?php
declare(strict_types=1);
require_once __DIR__.'/functions.php';
require __DIR__.'/auth.php';
$me=require_login();
try {
    $id=(int)($_GET['book_id']??0);
    if ($id<1) json_fail('Invalid book ID.',400);
    $db=pdo();
    $s=$db->prepare('SELECT record_status FROM Books WHERE book_id=?'); $s->execute([$id]); $status=$s->fetchColumn();
    if ($status===false || ($status==='deleted' && $me['role']!=='admin')) json_fail('Book not found.',404);
    json_out(['ok'=>true,'data'=>['assessment'=>valuation_get_latest($db,$id)]]);
} catch (Throwable $e) { json_fail('Unable to load book assessment.',500); }
