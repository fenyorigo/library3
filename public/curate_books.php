<?php
declare(strict_types=1);
require_once __DIR__.'/functions.php';require __DIR__.'/auth.php';require_once __DIR__.'/curator_helpers.php';
$me=require_admin();
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')json_fail('Method Not Allowed',405);
    json_out(['ok'=>true,'data'=>curator_apply(pdo(),json_in(),$me)]);
}catch(Throwable $e){$code=$e instanceof InvalidArgumentException?400:((int)$e->getCode()===409?409:500);json_fail($code===500?'A kurátori mentés nem sikerült; nem történt módosítás.':$e->getMessage(),$code);}
