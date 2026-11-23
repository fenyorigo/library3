<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/functions.php';
$dsn = getenv('LIBRARY3_SCHEMA_TEST_DSN');
if (!$dsn) exit(2);
$db = new PDO($dsn, getenv('LIBRARY3_SCHEMA_TEST_USER') ?: 'root', getenv('LIBRARY3_SCHEMA_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if (!preg_match('/^library3_schema_test[a-zA-Z0-9_]*$/D', (string)$db->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('Use a disposable test database.');
function check_copy(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$copy = fetch_book_copies($db,109)[0];
$id = (int)$copy['copy_id'];
$copy['physical_location'] = 'Updated shelf';
$updated = replace_book_copies($db,109,[$copy]);
check_copy((int)$updated[0]['copy_id']===$id,'Copy ID changed');
check_copy($updated[0]['physical_location']==='Updated shelf','Copy did not update');
check_copy((int)$db->query("SELECT COUNT(*) FROM BookCopyPhotos WHERE copy_id=$id")->fetchColumn()===10,'Photos lost');
check_copy((int)$db->query("SELECT COUNT(*) FROM BookCopyAcquisitionDocuments WHERE copy_id=$id")->fetchColumn()===2,'Invoice links lost');
try { replace_book_copies($db,109,[['copy_id'=>2,'format'=>'print']]); throw new LogicException('Foreign copy accepted'); } catch (InvalidArgumentException $expected) {}
try { replace_book_copies($db,109,[$copy,$copy]); throw new LogicException('Duplicate copy accepted'); } catch (InvalidArgumentException $expected) {}
$bad = $copy; $bad['quantity']=2;
try { replace_book_copies($db,109,[$bad]); throw new LogicException('Documented copies combined'); } catch (RuntimeException $expected) { check_copy($expected->getCode()===409,'Unexpected failure'); }
try { replace_book_copies($db,109,[['format'=>'print','quantity'=>1]]); throw new LogicException('Protected copy deleted'); } catch (PDOException $expected) {}
check_copy(count(fetch_book_copies($db,109))===1,'Failed edit was not rolled back');
check_copy(copy_documentation_file('../config.php')===null,'Traversal path allowed');
check_copy(copy_documentation_file('/etc/passwd')===null,'Absolute path allowed');
// Prepare two clean copies for the HTTP integration check.
$db->exec("INSERT INTO Books (book_id,title) VALUES (20000,'HTTP test'),(20001,'Shared invoice test')");
$db->exec("INSERT INTO BookCopies (copy_id,book_id,format,quantity) VALUES (20000,20000,'print',1),(20001,20001,'print',1)");
echo "OK: stable copy IDs, edits preserve photos and shared documents, rollback and path guards.\n";
