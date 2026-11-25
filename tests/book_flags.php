<?php
declare(strict_types=1);
$dsn=getenv('LIBRARY3_SCHEMA_TEST_DSN');if(!$dsn)throw new RuntimeException('Disposable test DSN required.');
$db=new PDO($dsn,getenv('LIBRARY3_SCHEMA_TEST_USER')?:'root',getenv('LIBRARY3_SCHEMA_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!preg_match('/^library3_schema_test[a-zA-Z0-9_]*$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Use disposable database after valuation_import.php.');
$fixture=sys_get_temp_dir().'/library3-book-flags-'.bin2hex(random_bytes(8));mkdir($fixture.'/public',0700,true);copy(__DIR__.'/../public/functions.php',$fixture.'/public/functions.php');putenv('BOOKCATALOG_ATTACHMENTS_DIR='.$fixture.'/private-attachments');require $fixture.'/public/functions.php';
$checks=0;
function fcheck(bool $ok,string $why):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function ftx(PDO $db,callable $fn){$db->beginTransaction();try{$result=$fn();$db->commit();return $result;}catch(Throwable $e){$db->rollBack();throw $e;}}
function fclean(string $path):void{if(is_dir($path)&&!is_link($path)){foreach(scandir($path) as $n)if($n!=='.'&&$n!=='..')fclean($path.'/'.$n);rmdir($path);}else unlink($path);}
try {
    $cases=[[null,false],['',false],['   ',false],['uploads/default-cover.jpg',false],['uploads/default_cover.jpg',false],['uploads/DEFAULT-COVER.PNG?v=2#image',false],['default-cover.webp',false],['https://example.test/default_cover.jpeg',false],['uploads/1/cover.png',true],['https://example.test/a.jpg?image=1',true],['uploads/1/my-default-cover.jpg',true]];
    $db->exec('CREATE TEMPORARY TABLE CoverCases(cover_image varchar(1024))');$s=$db->prepare('INSERT INTO CoverCases VALUES(?)');
    foreach($cases as [$path,$expected]){$s->execute([$path]);fcheck(catalog_has_real_cover($path)===$expected,'PHP default-cover test differs.');}
    $rows=$db->query('SELECT cover_image,'.catalog_real_cover_sql('cover_image').' present FROM CoverCases')->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $i=>$row)fcheck((bool)$row['present']===$cases[$i][1],'SQL/PHP cover disagreement.');
    $prior=valuation_get_latest($db,10002);$original=$db->query('SELECT * FROM BookAssessments WHERE assessment_id='.(int)$prior['assessment_id'])->fetch(PDO::FETCH_ASSOC);
    fcheck(ftx($db,fn()=>valuation_set_high_value($db,10002,true,42)),'Classification not saved.');$marked=valuation_get_latest($db,10002);
    foreach(['used_amount','replacement_amount','currency','used_confidence','replacement_confidence','used_method','replacement_method','priority','reason','heirs_action','research_status','checked_on','provenance','completeness','owner_condition'] as $column)fcheck($marked[$column]===$prior[$column],'Manual category altered '.$column);
    fcheck($marked['sources']===$prior['sources'],'Source observations/roles changed.');
    fcheck((int)$marked['is_high_value']===1 && count($marked['history'])===count($prior['history'])+1,'Classification revision missing.');
    $raw=json_decode($marked['raw_json'],true);fcheck($raw['manual_classification']['actor_id']===42,'Actor not retained.');
    $count=(int)$db->query('SELECT COUNT(*) FROM ValuationImports')->fetchColumn();
    fcheck(!ftx($db,fn()=>valuation_set_high_value($db,10002,true,42)),'Repeat checkbox save made a revision.');fcheck((int)$db->query('SELECT COUNT(*) FROM ValuationImports')->fetchColumn()===$count,'Repeat save created import audit.');
    $_GET['value_filter']='high_value';$where=[];$params=[];valuation_add_filter($db,$where,$params);fcheck((bool)$db->query('SELECT 1 FROM Books b WHERE b.book_id=10002 AND '.implode(' AND ',$where))->fetchColumn(),'High-value filter misses manual category.');unset($_GET['value_filter']);
    $map=print_enrichment_csv_map($db,fetch_book_copies_map($db,[10002]));fcheck($map[10002]['Megőrzési értékkategória']==='Nagy értékű – külön kezelendő','Manual category missing from CSV.');
    fcheck(ftx($db,fn()=>valuation_set_high_value($db,10002,false,42)),'Unchecking not saved.');$cleared=valuation_get_latest($db,10002);fcheck((int)$cleared['is_high_value']===0 && $cleared['preservation_label']===null,'Unchecking did not clear category.');fcheck($cleared['sources']===$prior['sources'] && $cleared['used_amount']===$prior['used_amount'],'Unchecking lost prices/sources.');
    fcheck($db->query('SELECT * FROM BookAssessments WHERE assessment_id='.(int)$prior['assessment_id'])->fetch(PDO::FETCH_ASSOC)===$original,'Original assessment mutated.');
    $db->exec("UPDATE BookCopies SET format='epub' WHERE book_id=10001");catalog_evidence_sync($db);
    try{ftx($db,fn()=>valuation_set_high_value($db,10001,true,42));throw new LogicException('Ebook classification accepted.');}catch(InvalidArgumentException $expected){fcheck(true,'Ebook-only request rejected.');}
    $db->exec("INSERT INTO Books(book_id,title) VALUES(99,'TEST no valuation')");$db->exec("INSERT INTO BookCopies(book_id,format) VALUES(99,'print')");
    fcheck(!ftx($db,fn()=>valuation_set_high_value($db,99,false,42)),'Unchecked unassessed book created history.');
    ftx($db,fn()=>valuation_set_high_value($db,99,true,42));$new=valuation_get_latest($db,99);fcheck($new['used_amount']===null&&$new['replacement_amount']===null&&$new['sources']===[],'Checkbox invented a price/source.');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4XcAAAAASUVORK5CYII=');mkdir(copy_documentation_root(),0700,true);file_put_contents(copy_documentation_root().'/'.str_repeat('0',32).'.png',$png);
    $db->prepare('UPDATE BookCopyPhotos SET file_path=?,mime_type=?,file_size=?,sha256=?')->execute([str_repeat('0',32).'.png','image/png',strlen($png),hash('sha256',$png)]);
    $ids=[99,10000,10001,10002];$before=portable_manifest($db,$ids);$archive=$fixture.'/manual.zip';$z=new ZipArchive();$z->open($archive,ZipArchive::CREATE);portable_add_to_zip($z,$db,$ids);$z->close();
    $db->beginTransaction();foreach(['BookAssessmentSources','BookCopyAcquisitionDocuments','BookCopyPhotos','BookAssessments','PriceObservations','ValuationImports','AcquisitionDocuments','Books_Authors','Books_Subjects','BookCopies','Books'] as $table)$db->exec('DELETE FROM `'.$table.'`');$db->commit();
    portable_restore($db,$archive);fcheck(portable_manifest($db,$ids)['tables']===$before['tables'],'Manual assessment history failed ZIP roundtrip.');
    fcheck((int)valuation_get_latest($db,99)['is_high_value']===1,'Restored checkbox category lost.');
    echo json_encode(['ok'=>true,'checks'=>$checks,'features'=>['default cover SQL/PHP agreement','immutable manual revisions','unchanged prices and shared sources','check/uncheck/no-op','printed-only validation','existing filters/CSV','ZIP roundtrip without new schema']],JSON_PRETTY_PRINT)."\n";
}finally{fclean($fixture);}
