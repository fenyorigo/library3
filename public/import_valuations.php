<?php
declare(strict_types=1);
require_once __DIR__.'/functions.php';
require __DIR__.'/auth.php';
$me=require_admin();
ini_set('display_errors','0');

try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') json_fail('Method Not Allowed',405);
    $db=pdo();
    if (!table_column_exists($db,'BookAssessments','assessment_id')) json_fail('Install schema migration 06 before importing valuations.',409);
    $action=$_POST['action']??'preview';
    if ($action==='preview') {
        $upload=$_FILES['file']??[];
        if (($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']??'')) json_fail('Choose a valuation CSV file (maximum 20 MB).',400);
        $rows=valuation_read_csv($upload['tmp_name']);
        $conflicts=valuation_match_rows($db,$rows);
        $summary=valuation_import_summary($rows,$conflicts);
        $sha=hash_file('sha256',$upload['tmp_name']);
        $s=$db->prepare('SELECT import_id FROM ValuationImports WHERE content_sha256=?'); $s->execute([$sha]);
        $summary['already_imported']=(bool)$s->fetchColumn();
        $dir=sys_get_temp_dir().'/library3-valuation-imports';
        if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Cannot create private staging directory.');
        $old=$_SESSION['valuation_import']??null;
        if ($old && isset($old['path']) && is_file($old['path'])) unlink($old['path']);
        // Staged uploads expire independently of a PHP session being retained.
        foreach (glob($dir.'/*.csv')?:[] as $old_path) if (filemtime($old_path)<time()-3600) @unlink($old_path);
        $token=bin2hex(random_bytes(24)); $path=$dir.'/'.$token.'.csv';
        if (!move_uploaded_file($upload['tmp_name'],$path)) throw new RuntimeException('Cannot stage valuation CSV.');
        chmod($path,0600);
        $_SESSION['valuation_import']=['token'=>$token,'path'=>$path,'sha'=>$sha,'name'=>mb_substr(basename($upload['name']??'valuations.csv'),0,255),'expires'=>time()+1800];
        json_out(['ok'=>true,'data'=>['token'=>$token,'summary'=>$summary]]);
    }
    if ($action!=='apply') json_fail('Unknown valuation import action.',400);
    $staged=$_SESSION['valuation_import']??null;
    if (!$staged || !hash_equals($staged['token'],(string)($_POST['token']??'')) || $staged['expires']<time() || !is_file($staged['path'])) json_fail('Preview expired; choose the CSV again.',409);
    if (!hash_equals($staged['sha'],hash_file('sha256',$staged['path']))) json_fail('Staged file changed; preview again.',409);
    $rows=valuation_read_csv($staged['path']);
    $result=valuation_apply_import($db,$rows,$staged['sha'],$staged['name'],(int)$me['uid']);
    unlink($staged['path']); unset($_SESSION['valuation_import']);
    json_out(['ok'=>true,'data'=>$result]);
} catch (Throwable $e) {
    if ($e instanceof InvalidArgumentException || $e instanceof JsonException) json_fail($e->getMessage(),400);
    if ($e instanceof PDOException) json_fail('Valuation import failed and was rolled back. Check schema and retry the preview.',409);
    json_fail($e->getCode()===409?$e->getMessage():'Unable to process valuation import.', $e->getCode()===409?409:500);
}
