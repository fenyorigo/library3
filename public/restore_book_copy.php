<?php
declare(strict_types=1);
require_once __DIR__.'/functions.php';require __DIR__.'/auth.php';require_admin();
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') json_fail('Method Not Allowed',405);
    $input=json_in();$id=(int)($input['copy_id']??0);if($id<1)json_fail('Invalid copy ID.',400);
    $db=pdo();$db->beginTransaction();$result=catalog_restore_copy($db,$id);$db->commit();json_out(['ok'=>true,'data'=>$result]);
} catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();$code=(int)$e->getCode();json_fail($code>=400&&$code<500?$e->getMessage():'Copy restoration failed.',$code>=400&&$code<500?$code:500);}
