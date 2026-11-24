<?php
declare(strict_types=1);
$dsn=getenv('LIBRARY3_SCHEMA_TEST_DSN');if(!$dsn)throw new RuntimeException('Disposable test DSN required.');
$db=new PDO($dsn,getenv('LIBRARY3_SCHEMA_TEST_USER')?:'root',getenv('LIBRARY3_SCHEMA_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!preg_match('/^library3_schema_test[a-zA-Z0-9_]*$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Refusing non-test database.');
$source=dirname(__DIR__);$fixture=sys_get_temp_dir().'/library3-purge-'.bin2hex(random_bytes(8));mkdir($fixture.'/public/uploads',0700,true);
foreach(['functions.php','catalog_purge_helpers.php'] as $file)copy($source.'/public/'.$file,$fixture.'/public/'.$file);
putenv('BOOKCATALOG_ATTACHMENTS_DIR='.$fixture.'/private-attachments');
require $fixture.'/public/catalog_purge_helpers.php';
function purge_check(bool $ok,string $why):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function purge_script(PDO $db,string $file):void {$sql=preg_replace('~/\*(?![!])[^*]*\*+(?:[^/*][^*]*\*+)*/~s','',file_get_contents($file));$sql=preg_replace('/^\s*;\s*$/m','',$sql);$db->exec($sql);}
function purge_snapshot(PDO $db,array $tables):array {$result=[];foreach($tables as $table){$rows=$db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);$encoded=array_map(fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$rows);sort($encoded);$result[$table]=$encoded;}return $result;}
function purge_test_remove(string $path):void {if(!is_link($path)&&is_dir($path)){chmod($path,0700);foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..')purge_test_remove($path.'/'.$name);rmdir($path);}elseif(file_exists($path)||is_link($path))unlink($path);}
$checks=0;$preserved=['Users','UserPreferences','Settings','SystemInfo','AuthEvents'];
try {
    purge_script($db,$source.'/00-basedata/sql/schema.sql');
    $db->exec("INSERT INTO Users(user_id,username,password_hash,role) VALUES(1,'TEST admin','TEST hash','admin')");
    $db->exec("INSERT INTO UserPreferences(user_id,logo_path,bg_color) VALUES(1,'user-assets/logo.png','#123456')");
    $db->exec("INSERT INTO AuthEvents(user_id,username_snapshot,event_type,ip_address) VALUES(1,'TEST admin','login_ok','127.0.0.1')");
    $db->exec("INSERT INTO SystemInfo(key_name,value) VALUES('schema_version','3.6.0')");
    $db->exec("INSERT INTO Authors(author_id,name) VALUES(1,'TEST author')");
    $db->exec("INSERT INTO Publishers(publisher_id,name) VALUES(1,'TEST publisher')");
    $db->exec("INSERT INTO Subjects(subject_id,name) VALUES(1,'TEST subject')");
    $db->exec("INSERT INTO Placement(placement_id,bookcase_no,shelf_no) VALUES(1,7,3)");
    $db->exec("INSERT INTO Books(book_id,title,publisher_id,placement_id,cover_image) VALUES(1,'TEST A',1,1,'uploads/1/cover.png'),(2,'TEST B',1,NULL,NULL),(3,'TEST ebook',NULL,NULL,NULL)");
    $db->exec("INSERT INTO Books_Authors(book_id,author_id) VALUES(1,1),(2,1)");
    $db->exec("INSERT INTO Books_Subjects(book_id,subject_id) VALUES(1,1)");
    $db->exec("INSERT INTO BookCopies(copy_id,book_id,format,physical_location) VALUES(1,1,'print','#7/3'),(2,2,'print',NULL),(3,3,'epub',NULL)");
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4XcAAAAASUVORK5CYII=');$pdf="%PDF-1.4\n%%EOF\n";
    $files=['uploads/1/cover.png'=>$png,'copy-attachments/'.str_repeat('a',32).'.png'=>$png,'copy-attachments/'.str_repeat('b',32).'.pdf'=>$pdf];
    foreach($files as $path=>$bytes){$target=portable_target($path);if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);file_put_contents($target,$bytes);}
    $db->prepare('INSERT INTO BookCopyPhotos(photo_id,copy_id,slot_number,file_path,mime_type,file_size,sha256) VALUES(1,1,1,?,?,?,?)')->execute([str_repeat('a',32).'.png','image/png',strlen($png),hash('sha256',$png)]);
    $db->prepare('INSERT INTO AcquisitionDocuments(document_id,title,file_path,mime_type,file_size,sha256,original_location) VALUES(1,?,?,?,?,?,?)')->execute(['TEST shared invoice',str_repeat('b',32).'.pdf','application/pdf',strlen($pdf),hash('sha256',$pdf),'In La Fontaine']);
    $db->exec('INSERT INTO BookCopyAcquisitionDocuments(copy_id,document_id) VALUES(1,1),(2,1)');
    $db->exec("INSERT INTO ValuationImports(import_id,content_sha256,original_filename,record_count) VALUES(1,REPEAT('a',64),'test.csv',2)");
    $db->exec("INSERT INTO BookAssessments(assessment_id,book_id,import_id,raw_json) VALUES(1,1,1,'{}'),(2,2,1,'{}')");
    $db->exec("INSERT INTO PriceObservations(observation_id,import_id,source_key,title,observation_kind,raw_json) VALUES(1,1,'SHARED','TEST price','Historical shared price','{}')");
    $db->exec('INSERT INTO BookAssessmentSources(assessment_id,observation_id) VALUES(1,1),(2,1)');
    $db->exec("INSERT INTO PortablePackageImports(package_sha256) VALUES(REPEAT('b',64))");
    $db->exec("INSERT INTO duplicate_review(dup_key) VALUES('TEST duplicate')");
    mkdir($fixture.'/ebooks/Books',0700,true);file_put_contents($fixture.'/ebooks/Books/test.epub','TEST ebook bytes');
    $db->prepare("UPDATE Settings SET setting_value=? WHERE setting_key='ebook_library_root'")->execute([$fixture.'/ebooks']);
    $db->prepare('UPDATE BookCopies SET file_path=? WHERE copy_id=3')->execute([$fixture.'/ebooks/Books/test.epub']);
    mkdir($fixture.'/public/user-assets',0700,true);file_put_contents($fixture.'/public/user-assets/logo.png',$png);
    foreach(['.htaccess','.gitignore','default-cover.jpg','default_cover.jpg'] as $name)file_put_contents($fixture.'/public/uploads/'.$name,'TEST keep '.$name);
    file_put_contents($fixture.'/private-attachments/.htaccess','TEST private protection');
    file_put_contents($fixture.'/public/uploads/.purge-state.json','TEST pre-existing orphan file');
    file_put_contents($fixture.'/private-attachments/'.str_repeat('c',32).'.pdf',$pdf); // Orphaned media must also be removed.
    symlink($fixture.'/ebooks/Books',$fixture.'/public/uploads/ebook-link');
    catalog_retire_book($db,2); // Include archived evidence, not only active records.
    $before=purge_snapshot($db,array_merge(catalog_purge_tables(),$preserved));
    $db->exec("CREATE TRIGGER purge_test_fail BEFORE DELETE ON Books FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TEST injected failure'");
    try {catalog_purge($db);throw new LogicException('DB failure was ignored.');}catch(PDOException $expected){}
    purge_check(purge_snapshot($db,array_merge(catalog_purge_tables(),$preserved))===$before,'DB failure changed catalog or preserved rows.');
    foreach($files as $path=>$bytes)purge_check(hash_file('sha256',portable_target($path))===hash('sha256',$bytes),'DB failure lost media.');
    purge_check(!glob($fixture.'/public/uploads/.catalog-purge-*'),'Rollback left staged public files.');
    purge_check(!glob($fixture.'/private-attachments/.catalog-purge-*'),'Rollback left staged private files.');
    $db->exec('DROP TRIGGER purge_test_fail');
    mkdir($fixture.'/private-attachments/.catalog-purge-interrupted',0700);
    try {catalog_purge($db);throw new LogicException('Unfinished file staging was ignored.');}catch(RuntimeException $e){purge_check(str_contains($e->getMessage(),'unfinished'),'Wrong pending-files error.');}
    purge_check(purge_snapshot($db,array_merge(catalog_purge_tables(),$preserved))===$before,'File preparation failure changed DB.');
    purge_check(is_file($fixture.'/public/uploads/1/cover.png'),'Second-directory failure did not restore public media.');
    rmdir($fixture.'/private-attachments/.catalog-purge-interrupted');

    putenv('BOOKCATALOG_ATTACHMENTS_DIR='.$fixture.'/ebooks/Books');
    try {catalog_purge($db);throw new LogicException('Ebook root was accepted.');}catch(RuntimeException $e){purge_check(str_contains($e->getMessage(),'overlaps'),'Wrong unsafe-root error.');}
    putenv('BOOKCATALOG_ATTACHMENTS_DIR='.$fixture.'/private-attachments');
    purge_check(purge_snapshot($db,array_merge(catalog_purge_tables(),$preserved))===$before,'Preflight failure changed DB.');
    symlink($fixture.'/private-attachments',$fixture.'/attachment-alias');putenv('BOOKCATALOG_ATTACHMENTS_DIR='.$fixture.'/attachment-alias');
    try {catalog_purge($db);throw new LogicException('Root symlink was accepted.');}catch(RuntimeException $e){purge_check(str_contains($e->getMessage(),'symbolic-link'),'Wrong symlink-root error.');}
    putenv('BOOKCATALOG_ATTACHMENTS_DIR='.$fixture.'/private-attachments');
    $archive=$fixture.'/backup.zip';$z=new ZipArchive();$z->open($archive,ZipArchive::CREATE);portable_add_to_zip($z,$db,[1,2,3]);$z->close();
    $manifest=portable_manifest($db,[1,2,3]);$keep=purge_snapshot($db,$preserved);
    $result=catalog_purge($db);
    foreach(catalog_purge_tables() as $table)purge_check((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===0,'Table not empty: '.$table);
    purge_check(purge_snapshot($db,$preserved)===$keep,'Users/preferences/settings/schema/audit changed.');
    purge_check($result['deleted_rows']['BookCopyAcquisitionDocuments']===2,'Shared invoice links not counted.');
    purge_check($result['deleted_attachment_files']===3,'Private media/orphan not removed once each.');
    purge_check($result['warnings']===[],'Unexpected cleanup warnings.');
    foreach($files as $path=>$bytes)purge_check(!file_exists(portable_target($path)),'Media file remains after purge.');
    foreach(['.htaccess','.gitignore','default-cover.jpg','default_cover.jpg'] as $name)purge_check(is_file($fixture.'/public/uploads/'.$name),'Default/security file removed.');
    purge_check(is_file($fixture.'/private-attachments/.htaccess'),'Private security file removed.');
    purge_check(file_get_contents($fixture.'/ebooks/Books/test.epub')==='TEST ebook bytes','External ebook was touched through symlink.');
    purge_check(is_file($fixture.'/public/user-assets/logo.png'),'Profile logo deleted.');
    $preview=portable_restore($db,$archive,true);purge_check($preview['total']===3,'Post-purge preview failed.');
    purge_check((int)$db->query('SELECT COUNT(*) FROM Books')->fetchColumn()===0,'Preview changed catalog.');
    portable_restore($db,$archive);$restored=portable_manifest($db,[1,2,3]);
    purge_check($restored['tables']===$manifest['tables'],'Purge/restore metadata, IDs or deleted flags differ.');
    purge_check($restored['files']===$manifest['files'],'Purge/restore file hashes differ.');
    purge_check(purge_snapshot($db,$preserved)===$keep,'Restoration changed retained configuration.');
    // Normal web credentials need no schema-altering privileges to clear/restore.
    $limited_name='l3purge_'.bin2hex(random_bytes(4));
    $db->exec("CREATE USER '".$limited_name."'@'localhost'");
    try {
        $database=(string)$db->query('SELECT DATABASE()')->fetchColumn();
        $db->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `".$database."`.* TO '".$limited_name."'@'localhost'");
        $limited=new PDO($dsn,$limited_name,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $cleared=catalog_purge($limited);
        purge_check($cleared['warnings']===[],'Restricted application user failed purge.');
        purge_check(count($cleared['id_counters_retained'])>0,'Restricted user unexpectedly reset schema counters.');
        portable_restore($limited,$archive);
        purge_check(portable_manifest($limited,[1,2,3])['tables']===$manifest['tables'],'Restricted user could not restore IDs.');
    } finally {$limited=null;$db->exec("DROP USER '".$limited_name."'@'localhost'");}
    // Fault injection is confined to the test PDO: permissions fail after commit.
    $cleanupFailure=new class($dsn,getenv('LIBRARY3_SCHEMA_TEST_USER')?:'root',getenv('LIBRARY3_SCHEMA_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]) extends PDO {
        public string $mediaRoot;
        public function commit():bool {$ok=parent::commit();foreach(glob($this->mediaRoot.'/.catalog-purge-*/1') as $path)chmod($path,0555);return $ok;}
    };
    $cleanupFailure->mediaRoot=$fixture.'/public/uploads';
    $partial=catalog_purge($cleanupFailure);
    purge_check(count($partial['warnings'])>0,'Post-commit file failure was hidden.');
    purge_check((int)$db->query('SELECT COUNT(*) FROM Books')->fetchColumn()===0,'Post-commit state was misreported.');
    $pending=glob($fixture.'/public/uploads/.catalog-purge-*');
    purge_check(count($pending)===1,'Failed cleanup lost its recovery directory.');
    $journals=glob($pending[0].'/.purge-state*.json');
    $journal=null;foreach($journals as $file){$entry=json_decode(file_get_contents($file),true);if(is_array($entry)&&isset($entry['phase']))$journal=$entry;}
    purge_check($journal['phase']==='database_committed','Cleanup journal has wrong phase.');
    purge_check(is_file($pending[0].'/1/cover.png'),'Failed deletion lost the remaining media.');
    chmod($pending[0].'/1',0700);purge_test_remove($pending[0]);
    portable_restore($db,$archive);
    // Empty/missing media directories are valid, including before migrations 05–07.
    catalog_purge($db);purge_test_remove($fixture.'/private-attachments');purge_test_remove($fixture.'/public/uploads');
    $empty=catalog_purge($db);purge_check($empty['deleted_attachment_files']===0 && $empty['deleted_upload_files']===0,'Empty purge failed.');
    foreach(['BookAssessmentSources','BookCopyAcquisitionDocuments','BookCopyPhotos','BookAssessments','PriceObservations','ValuationImports','AcquisitionDocuments','PortablePackageImports'] as $table)$db->exec('DROP TABLE `'.$table.'`');
    $db->exec('ALTER TABLE BookCopies DROP COLUMN record_status, DROP COLUMN deleted_with_book');
    $db->exec("UPDATE SystemInfo SET value='3.3.0' WHERE key_name='schema_version'");
    $db->exec("INSERT INTO Books(book_id,title) VALUES(99,'TEST legacy')");$db->exec("INSERT INTO BookCopies(book_id,format) VALUES(99,'print')");
    $legacy=catalog_purge($db);purge_check($legacy['deleted_rows']['Books']===1,'Legacy 3.3 catalog purge failed.');
    foreach(['05_v3_add_copy_photos_and_acquisition_documents.sql','06_v3_add_book_valuations.sql','07_v3_add_evidence_lifecycle.sql'] as $file)purge_script($db,$source.'/00-basedata/sql/migrations/'.$file);
    portable_restore($db,$archive);purge_check(portable_manifest($db,[1,2,3])['tables']===$manifest['tables'],'Legacy purge → migrations → ZIP restore failed.');
    echo json_encode(['ok'=>true,'checks'=>$checks,'features'=>['all catalog tables','retained users/settings/passwords/audit/logos','shared and archived evidence','private and orphan files','external ebook protection','DB rollback restores staged files','unsafe roots rejected','portable ZIP roundtrip','empty purge','3.3 purge then 05/06/07 and restore']],JSON_PRETTY_PRINT)."\n";
} finally {purge_test_remove($fixture);}
