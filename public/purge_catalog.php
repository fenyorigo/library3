<?php
declare(strict_types=1);
require_once __DIR__.'/functions.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/catalog_purge_helpers.php';
$me=require_admin();
header('Content-Type: application/json; charset=utf-8');
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') json_fail('Method Not Allowed',405);
    if (trim((string)(json_in()['confirm']??''))!=='DELETE') json_fail('Confirmation text mismatch',400);
    $result=catalog_purge(pdo());
    log_auth_event('catalog_purge',(int)$me['uid'],(string)$me['username'],['actor_username'=>$me['username']]+$result);
    json_out(['ok'=>true,'data'=>$result]);
} catch (Throwable $e) {
    log_auth_event('catalog_purge_failed',(int)$me['uid'],(string)$me['username'],['actor_username'=>$me['username'],'error'=>$e->getMessage()]);
    json_fail($e->getMessage(),500);
}
