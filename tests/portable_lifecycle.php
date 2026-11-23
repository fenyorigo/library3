<?php
declare(strict_types=1);
// Keep test media away from the application's live upload directory.
$fixture_root=sys_get_temp_dir().'/library3-portable-runtime-'.bin2hex(random_bytes(8));
mkdir($fixture_root.'/public',0700,true);
copy(__DIR__.'/../public/functions.php',$fixture_root.'/public/functions.php');
putenv('BOOKCATALOG_ATTACHMENTS_DIR='.$fixture_root.'/private-attachments');
require $fixture_root.'/public/functions.php';
$dsn=getenv('LIBRARY3_SCHEMA_TEST_DSN');if(!$dsn)throw new RuntimeException('Disposable database required.');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!preg_match('/^library3_schema_test[a-zA-Z0-9_]*$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Refusing non-test database.');
$root=dirname(__DIR__);$checks=0;
function ck(bool $ok,string $why):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function resetdb(PDO $db,string $root):void{$sql=preg_replace('~/\*(?![!])[^*]*\*+(?:[^/*][^*]*\*+)*/~s','',file_get_contents($root.'/00-basedata/sql/schema.sql'));$sql=preg_replace('/^\s*;\s*$/m','',$sql);$db->exec($sql);}
function flag(PDO $db,string $table,string $where='1=1'):string{return (string)$db->query("SELECT record_status FROM `$table` WHERE $where LIMIT 1")->fetchColumn();}
function tx(PDO $db,callable $action){$db->beginTransaction();try{$r=$action();$db->commit();return $r;}catch(Throwable $e){$db->rollBack();throw $e;}}
resetdb($db,$root);
$db->exec(file_get_contents($root.'/00-basedata/sql/migrations/07_v3_add_evidence_lifecycle.sql'));
$db->exec(file_get_contents($root.'/00-basedata/sql/migrations/07_v3_add_evidence_lifecycle.sql'));
$db->exec("INSERT INTO Books(book_id,title) VALUES(900001,'TEST A'),(900002,'TEST B'),(900003,'TEST mixed')");
$db->exec("INSERT INTO BookCopies(copy_id,book_id,format) VALUES(900001,900001,'print'),(900002,900002,'print'),(900003,900003,'print'),(900004,900003,'epub'),(900005,900001,'print')");
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4XcAAAAASUVORK5CYII=');
$pdf="%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
$paths=['copy-attachments/'.str_repeat('a',32).'.png'=>$png,'copy-attachments/'.str_repeat('b',32).'.pdf'=>$pdf,'uploads/900001/cover.png'=>$png];
foreach($paths as $path=>$data){$target=portable_target($path);if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);file_put_contents($target,$data);}
$db->exec("UPDATE Books SET cover_image='uploads/900001/cover.png' WHERE book_id=900001");
$db->prepare('INSERT INTO BookCopyPhotos(copy_id,slot_number,file_path,mime_type,file_size,sha256) VALUES(900001,1,?,?,?,?)')->execute([str_repeat('a',32).'.png','image/png',strlen($png),hash('sha256',$png)]);
$db->prepare('INSERT INTO AcquisitionDocuments(document_id,title,file_path,mime_type,file_size,sha256,original_location) VALUES(900001,?,?,?,?,?,?)')->execute(['TEST shared invoice',str_repeat('b',32).'.pdf','application/pdf',strlen($pdf),hash('sha256',$pdf),'La Fontaine kötetben']);
$db->exec('INSERT INTO BookCopyAcquisitionDocuments(copy_id,document_id) VALUES(900001,900001),(900002,900001)');
$db->exec("INSERT INTO ValuationImports(import_id,content_sha256,original_filename,record_count) VALUES(900001,REPEAT('a',64),'test.csv',3)");
$db->exec("INSERT INTO BookAssessments(assessment_id,book_id,import_id,raw_json,is_high_value) VALUES(900001,900001,900001,'{}',1),(900002,900002,900001,'{}',1),(900003,900003,900001,'{}',1)");
$db->exec("INSERT INTO PriceObservations(observation_id,import_id,source_key,title,observation_kind,raw_json) VALUES(900001,900001,'SHARED','TEST reference','Historical purchase','{}')");
$db->exec('INSERT INTO BookAssessmentSources(assessment_id,observation_id) VALUES(900001,900001),(900002,900001)');
catalog_evidence_sync($db);
tx($db,fn()=>delete_book_copy_record($db,900005));
tx($db,fn()=>catalog_retire_book($db,900001));
ck(flag($db,'BookCopyPhotos')==='deleted','Photo not archived');ck(flag($db,'BookAssessments','book_id=900001')==='deleted','Assessment not archived');ck(flag($db,'AcquisitionDocuments')==='active','Shared invoice prematurely archived');ck(flag($db,'PriceObservations')==='active','Shared price prematurely archived');
$partial=portable_manifest($db,[900001]);ck($partial['tables']['AcquisitionDocuments'][0]['record_status']==='deleted','Subset shared invoice scope wrong');ck($partial['tables']['PriceObservations'][0]['record_status']==='deleted','Subset shared source scope wrong');portable_validate_metadata($partial['tables'],array_column($partial['files'],null,'path'));
tx($db,fn()=>catalog_restore_book($db,900001));ck(flag($db,'BookCopies','copy_id=900005')==='deleted','Unrelated retired copy resurrected');ck(flag($db,'BookCopyPhotos')==='active','Photo not restored');
tx($db,fn()=>catalog_retire_book($db,900001));tx($db,fn()=>catalog_retire_book($db,900002));ck(flag($db,'AcquisitionDocuments')==='deleted','Unused invoice remains active');ck(flag($db,'PriceObservations')==='deleted','Unused price remains active');
tx($db,fn()=>delete_book_copy_record($db,900003));ck(flag($db,'Books','book_id=900003')==='active','Mixed book retired');ck(count(fetch_book_copies($db,900003))===1,'Archived print still counted');ck(flag($db,'BookAssessments','book_id=900003')==='deleted','Ebook-only assessment active');
tx($db,fn()=>catalog_restore_copy($db,900003));ck(flag($db,'BookAssessments','book_id=900003')==='active','Copy restore lost valuation');
replace_book_copies($db,900003,[['copy_id'=>900003,'format'=>'pdf','quantity'=>1],['copy_id'=>900004,'format'=>'epub','quantity'=>1]]);ck(flag($db,'BookCopies','copy_id=900003')==='deleted','Print conversion discarded archive');ck(count(fetch_book_copies($db,900003))===2,'Conversion did not create ebook');
foreach($paths as $path=>$data)ck(hash_file('sha256',portable_target($path))===hash('sha256',$data),'Retirement changed file');
$manifest=portable_manifest($db,[900001,900002,900003]);portable_validate_metadata($manifest['tables'],array_column($manifest['files'],null,'path'));
$archive=sys_get_temp_dir().'/library3-portable-test.zip';$z=new ZipArchive();$z->open($archive,ZipArchive::CREATE|ZipArchive::OVERWRITE);portable_add_to_zip($z,$db,[900001,900002,900003]);$z->close();
$before=$manifest['tables'];resetdb($db,$root);foreach($paths as $path=>$data)unlink(portable_target($path));
$preview=portable_restore($db,$archive,true);ck($preview['total']===3,'Preview wrong count');ck((int)$db->query('SELECT COUNT(*) FROM Books')->fetchColumn()===0,'Preview changed DB');ck(!is_file(portable_target(array_key_first($paths))),'Preview wrote file');
$result=portable_restore($db,$archive);ck($result['total']===3&&$result['files']===3,'Restore missing payload');
$after=portable_manifest($db,[900001,900002,900003])['tables'];ck($before===$after,'Restore altered rows or flags');foreach($paths as $path=>$data)ck(hash_file('sha256',portable_target($path))===hash('sha256',$data),'Restore bytes differ');
$again=portable_restore($db,$archive);ck($again['inserted']===0&&$again['reused']>0,'Repeat duplicated metadata');
$db->exec("UPDATE Books SET title='Conflict' WHERE book_id=900001");try{portable_restore($db,$archive);throw new RuntimeException('Conflict accepted');}catch(RuntimeException $e){ck($e->getCode()===409,'Wrong conflict error');}ck($db->query('SELECT title FROM Books WHERE book_id=900001')->fetchColumn()==='Conflict','Conflict overwrote row');
$db->exec("UPDATE Books SET title='TEST A' WHERE book_id=900001");
$bad=sys_get_temp_dir().'/library3-portable-bad.zip';copy($archive,$bad);$z=new ZipArchive();$z->open($bad);$z->addFromString(array_key_first($paths),'bad bytes');$z->close();try{portable_restore($db,$bad);throw new RuntimeException('Bad hash accepted');}catch(InvalidArgumentException $e){ck(str_contains($e->getMessage(),'Checksum')||str_contains($e->getMessage(),'invalid file'),'Wrong hash error');}
copy($archive,$bad);$z=new ZipArchive();$z->open($bad);$z->addFromString('../escape','bad');$z->close();try{portable_restore($db,$bad);throw new RuntimeException('Traversal accepted');}catch(InvalidArgumentException $e){ck(str_contains($e->getMessage(),'Unsafe'),'Wrong traversal error');}
foreach($paths as $path=>$data)unlink(portable_target($path));unlink($archive);unlink($bad);
echo json_encode(['ok'=>true,'checks'=>$checks,'features'=>['shared evidence lifecycle','mixed book','conversion','stable IDs','binary roundtrip','dry run','repeat','conflict rollback','checksum and traversal rejection']],JSON_PRETTY_PRINT)."\n";

function remove_portable_fixture(string $path):void {if(is_dir($path)){foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..')remove_portable_fixture($path.'/'.$name);rmdir($path);}else unlink($path);}
remove_portable_fixture($fixture_root);
