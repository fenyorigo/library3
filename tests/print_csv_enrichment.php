<?php
declare(strict_types=1);
require_once __DIR__.'/../public/functions.php';
$dsn=getenv('LIBRARY3_SCHEMA_TEST_DSN');
if (!$dsn) exit(2);
$db=new PDO($dsn,getenv('LIBRARY3_SCHEMA_TEST_USER')?:'root',getenv('LIBRARY3_SCHEMA_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if (!preg_match('/^library3_schema_test[a-zA-Z0-9_]*$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('Use a disposable test database after valuation_import.php.');
function pcheck(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message);}
// valuation_import.php prepares these three synthetic records and shared observations.
$db->exec("INSERT INTO BookCopies (copy_id,book_id,format,quantity) VALUES (10001,10001,'epub',1),(10002,10002,'print',1),(10003,10000,'epub',1)");
$note='Invoice "quoted" C:\\paper\\';
$db->prepare('UPDATE AcquisitionDocuments SET notes=? WHERE document_id=1')->execute([$note]);
$copies=fetch_book_copies_map($db,[10000,10001,10002]);
$map=print_enrichment_csv_map($db,$copies);$headers=print_enrichment_csv_headers();
pcheck(!isset($map[10001]),'Ebook-only record has print metadata.');
pcheck(isset($map[10000]) && isset($map[10002]),'Print/mixed record missing.');
pcheck($map[10002]['Használtpiaci irányérték (Ft; becslés)']==='1234.50','Used estimate omitted.');
pcheck($map[10002]['Pótlási könyvár (Ft; referencia)']==='2000.00','Replacement reference omitted.');
pcheck($map[10000]['Használtpiaci irányérték (Ft; becslés)']==='','Historical price became an estimate.');
$history=json_decode($map[10000]['Értékelési előzmények (JSON)'],true,64,JSON_THROW_ON_ERROR);
pcheck(count($history)===2,'Assessment history omitted.');
pcheck($history[0]['sources'][0]['observation']['price']==='900.00','Original observation lost.');
$docs=json_decode($map[10000]['Példánydokumentáció (JSON)'],true,64,JSON_THROW_ON_ERROR);
pcheck(count($docs['copies'])===1 && count($docs['copies'][0]['photos'])===1,'Wrong copy scope for photos.');
pcheck($docs['copies'][0]['documents'][0]['notes']===$note,'Document notes changed.');
pcheck($docs['copies'][0]['documents'][0]['original_location']==='In the book','Paper location omitted.');
$path=tempnam(sys_get_temp_dir(),'print_export_');
try {
    $base=['ID','Title','Authors','Publisher','Year','ISBN','Copies JSON'];$all=array_merge($base,$headers);
    $fh=fopen($path,'wb');fputcsv($fh,$all,',','"','');
    foreach ([10000=>'TEST Verne',10001=>'TEST set I',10002=>'TEST set II'] as $id=>$title) {
        $row=[$id,$title,'','','','',json_encode($copies[$id])];
        $row=array_merge($row,array_values($map[$id]??array_fill_keys($headers,'')));
        fputcsv($fh,$row,',','"','');
    }
    fclose($fh);$fh=fopen($path,'rb');fgetcsv($fh,0,',','"','');$first=fgetcsv($fh,0,',','"','');fclose($fh);
    $parsed=array_combine($all,$first);
    pcheck(json_decode($parsed['Példánydokumentáció (JSON)'],true,64,JSON_THROW_ON_ERROR)===$docs,'Quoted/backslash JSON failed CSV round trip.');
    $rows=valuation_read_csv($path);pcheck(count($rows)===2,'Valuation CSV re-import did not skip ebook-only row.');
    pcheck($rows[1]['used_amount']==='1234.50','Export cannot be read by valuation importer.');
    echo "OK: print-only CSV enrichment, mixed copies, estimates, sources, full history, photo/PDF metadata, paper location, RFC CSV JSON and valuation re-import.\n";
} finally {unlink($path);}
