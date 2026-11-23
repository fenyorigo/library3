<?php
declare(strict_types=1);
require_once __DIR__.'/functions.php';require __DIR__.'/auth.php';require_admin();
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') json_fail('Method Not Allowed',405);
    $input=json_in();$id=(int)($input['id']??$_POST['id']??$_GET['id']??0);
    if ($id<1) json_fail('Invalid book ID.',400);
    $db=pdo();$db->beginTransaction();
    catalog_retire_book($db,$id); $result=['book_id'=>$id,'record_status'=>'deleted','book_removed'=>false];
    $db->commit();json_out(['ok'=>true,'data'=>$result]);
} catch(Throwable $e) {if(isset($db)&&$db->inTransaction())$db->rollBack();$code=(int)$e->getCode();json_fail($code>=400&&$code<500?$e->getMessage():'Book state change failed.',$code>=400&&$code<500?$code:500);}
