<?php
declare(strict_types=1);
require_once __DIR__.'/../public/functions.php';
$dsn=getenv('LIBRARY3_SCHEMA_TEST_DSN');
if (!$dsn) { fwrite(STDERR,"Use a disposable LIBRARY3_SCHEMA_TEST_DSN named library3_schema_test*.\n"); exit(2); }
$db=new PDO($dsn,getenv('LIBRARY3_SCHEMA_TEST_USER')?:'root',getenv('LIBRARY3_SCHEMA_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if (!preg_match('/^library3_schema_test[a-zA-Z0-9_]*$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('Refusing to reset a non-test database.');
function vcheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function vscript(PDO $db,string $path): void {
    $sql=preg_replace('~/\*(?![!])[^*]*\*+(?:[^/*][^*]*\*+)*/~s','',file_get_contents($path)); $buffer='';
    foreach (preg_split('/\r?\n/',$sql) as $line) {
        if (trim($line)==='' || str_starts_with(trim($line),'--')) continue;
        $buffer.=$line."\n";
        if (preg_match('/;\s*$/',$line)) { if (trim($buffer," \r\n\t;")!=='') $db->exec($buffer); $buffer=''; }
    }
    if (trim($buffer)!=='') $db->exec($buffer);
}
function vstruct(PDO $db): array {
    $result=[];foreach (['ValuationImports','BookAssessments','PriceObservations','BookAssessmentSources'] as $table) $result[$table]=$db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];return $result;
}
$root=dirname(__DIR__);
vscript($db,$root.'/00-basedata/sql/schema.sql'); $fresh=vstruct($db);
vscript($db,$root.'/00-basedata/sql/schema.sql'); vcheck(vstruct($db)===$fresh,'Fresh schema reset differs.');
$db->exec('DROP TABLE BookAssessmentSources,PriceObservations,BookAssessments,ValuationImports');
$db->exec("INSERT INTO Books (book_id,title) VALUES (10000,'TEST Verne'),(10001,'TEST set I'),(10002,'TEST set II')");
$db->exec("INSERT INTO BookCopies (copy_id,book_id,format,quantity,notes) VALUES (10000,10000,'print',1,'Keep original copy')");
$db->exec("INSERT INTO BookCopyPhotos (copy_id,slot_number,file_path,mime_type) VALUES (10000,1,'00000000000000000000000000000000.jpg','image/jpeg')");
$db->exec("INSERT INTO AcquisitionDocuments (document_id,title,original_location) VALUES (1,'TEST existing invoice','In the book')");
$db->exec('INSERT INTO BookCopyAcquisitionDocuments VALUES (10000,1,NULL,NULL)');
$before=$db->query('SELECT * FROM BookCopies')->fetchAll(PDO::FETCH_ASSOC);
vscript($db,$root.'/00-basedata/sql/migrations/06_v3_add_book_valuations.sql');
vcheck(vstruct($db)===$fresh,'Migration and fresh schema differ.');
vscript($db,$root.'/00-basedata/sql/migrations/06_v3_add_book_valuations.sql');
vcheck($db->query('SELECT * FROM BookCopies')->fetchAll(PDO::FETCH_ASSOC)===$before,'Migration touched copies.');
$source=fn($key,$price,$currency,$kind,$ids)=>['source_id'=>$key,'title'=>'TEST '.$key,'price'=>$price,'currency'=>$currency,'kind'=>$kind,'ids'=>$ids,'checked'=>'2026-11-23'];
$old=$source('TEST_OLD',900,'HUF','Historical purchase, not current market value',['10000']);
$set=$source('TEST_SET',395,'EUR','Historical shared set price',['10001','10002']);
$ask=$source('TEST_ASK',2000,'HUF','Asking price, not a completed sale',['10002']);
$headers=array_merge(['ID','Title','Authors','Publisher','Year','ISBN'],array_values(valuation_columns()),['Használt irányérték forrása','Pótlási referencia forrása','Forrásrészletek (JSON)']);
$input=[];
foreach ([10000=>'TEST Verne',10001=>'TEST set I',10002=>'TEST set II'] as $id=>$title) {
    $r=array_fill_keys($headers,'');$r['ID']=(string)$id;$r['Title']=$title;
    if ($id!==10002) $r['Megőrzési értékkategória']='Nagy értékű – külön kezelendő';
    $r['Forrásrészletek (JSON)']=json_encode($id===10000?[$old]:($id===10001?[$set]:[$set,$ask]));
    $r['Kiemelés']='Elsőként ellenőrizendő';
    if ($id===10002) {
        $r['Használtpiaci irányérték (Ft; becslés)']='1234,50';$r['Pótlási könyvár (Ft; referencia)']='2000';
        $r['Használt irányérték forrása']='TEST_ASK';$r['Pótlási referencia forrása']='TEST_ASK';
    }
    $input[]=$r;
}
$path=tempnam(sys_get_temp_dir(),'valuation_test_');
function vwrite(string $path,array $headers,array $input): void {
    $fh=fopen($path,'wb');fwrite($fh,"\xEF\xBB\xBF");fputcsv($fh,$headers,';','"','');
    foreach ($input as $row) fputcsv($fh,array_map(fn($key)=>$row[$key]??'',$headers),';','"','');fclose($fh);
}
try {
    vwrite($path,$headers,$input);$rows=valuation_read_csv($path);
    vcheck(valuation_match_rows($db,$rows)===[],'Matching CSV rejected.');
    $result=valuation_apply_import($db,$rows,hash_file('sha256',$path),'test.csv');
    vcheck($result['applied']===3,'Import count mismatch.');
    vcheck(valuation_apply_import($db,$rows,hash_file('sha256',$path),'test.csv')['already_imported'],'Repeat import was not idempotent.');
    $verne=valuation_get_latest($db,10000);
    vcheck($verne['used_amount']===null && $verne['replacement_amount']===null,'Historical price became market value.');
    vcheck($verne['sources'][0]['amount']==='900.00','Historical amount lost.');
    $priced=valuation_get_latest($db,10002);
    vcheck($priced['used_amount']==='1234.50' && $priced['replacement_amount']==='2000.00','Decimal amount changed.');
    vcheck((int)$db->query("SELECT COUNT(*) FROM PriceObservations WHERE source_key='TEST_SET'")->fetchColumn()===1,'Shared source duplicated.');
    vcheck((int)$db->query("SELECT COUNT(*) FROM BookAssessmentSources l JOIN PriceObservations p ON p.observation_id=l.observation_id WHERE p.source_key='TEST_SET'")->fetchColumn()===2,'Shared source not linked to both books.');
    vcheck((int)$db->query('SELECT COUNT(*) FROM BookCopyPhotos')->fetchColumn()===1,'Photo changed.');
    vcheck((int)$db->query('SELECT COUNT(*) FROM BookCopyAcquisitionDocuments')->fetchColumn()===1,'Invoice link changed.');
    vcheck($db->query('SELECT * FROM BookCopies')->fetchAll(PDO::FETCH_ASSOC)===$before,'Import changed copies.');
    $count_filter=function(string $filter) use($db): int {$_GET['value_filter']=$filter;$where=[];$params=[];valuation_add_filter($db,$where,$params);return (int)$db->query('SELECT COUNT(*) FROM Books b WHERE '.implode(' AND ',$where))->fetchColumn();};
    vcheck($count_filter('high_value')===2,'High-value filter mismatch.');
    vcheck($count_filter('estimated')===1,'Price filter mismatch.');
    $revision=$rows[0];$revision['is_high_value']=0;$revision['preservation_label']=null;
    valuation_apply_import($db,[$revision],hash('sha256','second revision'),'revision.csv');
    vcheck($count_filter('high_value')===1,'Filter selected an outdated assessment.');
    vcheck(count(valuation_get_latest($db,10000)['history'])===2,'History not retained.');
    $bad=$rows;$bad[0]['bibliography']['Title']='WRONG BOOK';
    try {valuation_apply_import($db,$bad,hash('sha256','conflict'),'wrong.csv');throw new LogicException('Conflict was accepted.');} catch (RuntimeException $e) {vcheck($e->getCode()===409,'Unexpected conflict exception.');}
    vcheck((int)$db->query('SELECT COUNT(*) FROM ValuationImports')->fetchColumn()===2,'Failed import was not rolled back.');
    vcheck(valuation_amount('')===null && valuation_amount('0')==='0.00','Blank and zero conflated.');
    try {valuation_amount('-100');throw new LogicException('Negative price accepted.');} catch (InvalidArgumentException $expected) {}
    $duplicate=$input;$duplicate[]=$input[0];vwrite($path,$headers,$duplicate);
    try {valuation_read_csv($path);throw new LogicException('Duplicate book ID accepted.');} catch (InvalidArgumentException $expected) {}
    $conflicting=$input;$conflicting[2]['Forrásrészletek (JSON)']=json_encode([array_merge($set,['price'=>999]),$ask]);vwrite($path,$headers,$conflicting);
    try {valuation_read_csv($path);throw new LogicException('Contradictory shared source accepted.');} catch (InvalidArgumentException $expected) {}
    echo "OK: schema/migration equivalence, idempotent imports, shared observations, historical-vs-market prices, latest-value filters, history, conflicts, unchanged photos/copies and rollback.\n";
} finally {unlink($path);}
