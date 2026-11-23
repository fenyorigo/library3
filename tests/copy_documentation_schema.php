<?php
declare(strict_types=1);

// Destructive ONLY in a dedicated test database named library3_schema_test*.
// LIBRARY3_SCHEMA_TEST_DSN='mysql:unix_socket=/tmp/...;dbname=library3_schema_test'
// LIBRARY3_SCHEMA_TEST_USER=root LIBRARY3_SCHEMA_TEST_PASSWORD='' php tests/copy_documentation_schema.php
$dsn = getenv('LIBRARY3_SCHEMA_TEST_DSN');
if (!$dsn) {
    fwrite(STDERR, "Set LIBRARY3_SCHEMA_TEST_DSN to a disposable library3_schema_test* database.\n");
    exit(2);
}
$db = new PDO($dsn, getenv('LIBRARY3_SCHEMA_TEST_USER') ?: 'root', getenv('LIBRARY3_SCHEMA_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = (string)$db->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/^library3_schema_test[a-zA-Z0-9_]*$/D', $name)) {
    throw new RuntimeException('Refusing to change a database outside the dedicated test namespace.');
}
$root = dirname(__DIR__);
$schema = file_get_contents($root . '/00-basedata/sql/schema.sql');
$migration = file_get_contents($root . '/00-basedata/sql/migrations/05_v3_add_copy_photos_and_acquisition_documents.sql');
function run_script(PDO $db, string $sql): void {
    // Match the line-delimited installer input, excluding comment-only statements.
    $sql = preg_replace("~/\*(?![!])[^*]*\*+(?:[^/*][^*]*\*+)*/~s", "", $sql);
    $buffer = "";
    foreach (preg_split("/\r?\n/", $sql) as $line) {
        if (trim($line) === "" || str_starts_with(trim($line), "--")) continue;
        $buffer .= $line . "\n";
        if (preg_match("/;\s*$/", $line)) {
            if (trim($buffer, " \r\n\t;") !== "") $db->exec($buffer);
            $buffer = "";
        }
    }
    if (trim($buffer) !== "") $db->exec($buffer);
}
function require_true(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function reject_sql(PDO $db, string $sql): void {
    try {
        $db->exec($sql);
    } catch (PDOException $e) {
        if (in_array((int)($e->errorInfo[1] ?? 0), [1062, 1451, 1452, 4025, 3819], true)) return;
        throw $e;
    }
    throw new RuntimeException('Expected a constraint rejection: ' . $sql);
}
function structure(PDO $db): array {
    $out = [];
    foreach (['BookCopyPhotos', 'AcquisitionDocuments', 'BookCopyAcquisitionDocuments'] as $table) {
        $out[$table] = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    }
    return $out;
}

// Fresh installation and repeat baseline import must both create the same tables.
run_script($db, $schema);
$fresh = structure($db);
run_script($db, $schema);
require_true(structure($db) === $fresh, 'Baseline is inconsistent on repeat import.');

// Existing 3.3.0 tables are unchanged by this migration: emulate them by removing
// just the new, empty tables, then seed real catalog data before upgrading.
$db->exec('DROP TABLE BookCopyAcquisitionDocuments, BookCopyPhotos, AcquisitionDocuments');
$db->exec("INSERT INTO SystemInfo VALUES ('schema_version','3.3.0')");
$db->exec("INSERT INTO Books (book_id,title) VALUES (109,'Verne test'),(2854,'La Fontaine test')");
$db->exec("INSERT INTO BookCopies (copy_id,book_id,format) VALUES (1,109,'print'),(2,2854,'print')");
$before = $db->query('SELECT * FROM BookCopies ORDER BY copy_id')->fetchAll(PDO::FETCH_ASSOC);
run_script($db, $migration);
require_true(structure($db) === $fresh, 'Migration and fresh-install schemas differ.');
require_true($db->query('SELECT * FROM BookCopies ORDER BY copy_id')->fetchAll(PDO::FETCH_ASSOC) === $before, 'Migration changed existing copies.');
require_true($db->query("SELECT value FROM SystemInfo WHERE key_name='schema_version'")->fetchColumn() === '3.4.0', 'Version was not updated.');

for ($slot = 1; $slot <= 10; $slot++) {
    $db->exec("INSERT INTO BookCopyPhotos (copy_id,slot_number,file_path,mime_type) VALUES (1,$slot,'photos/test-$slot.jpeg','image/jpeg')");
}
reject_sql($db, "INSERT INTO BookCopyPhotos (copy_id,slot_number,file_path,mime_type) VALUES (1,11,'photos/extra.jpeg','image/jpeg')");
reject_sql($db, "INSERT INTO BookCopyPhotos (copy_id,slot_number,file_path,mime_type) VALUES (2,0,'photos/zero.jpeg','image/jpeg')");
reject_sql($db, "INSERT INTO BookCopyPhotos (copy_id,slot_number,file_path,mime_type) VALUES (1,1,'photos/duplicate.jpeg','image/jpeg')");
reject_sql($db, "INSERT INTO BookCopyPhotos (copy_id,slot_number,file_path,mime_type) VALUES (999,1,'photos/orphan.jpeg','image/jpeg')");
reject_sql($db, "INSERT INTO BookCopyPhotos (copy_id,slot_number,file_path,mime_type) VALUES (2,1,'','image/jpeg')");
$db->exec("INSERT INTO BookCopyPhotos (copy_id,slot_number,file_path,mime_type) VALUES (2,1,'photos/other.jpeg','image/jpeg')");
$db->exec("INSERT INTO AcquisitionDocuments (document_id,title,file_path,mime_type,original_location) VALUES (1,'Shared invoice','documents/invoice.pdf','application/pdf','A La Fontaine kötetében')");
$db->exec("INSERT INTO AcquisitionDocuments (document_id,title,original_location) VALUES (2,'Paper only','A könyvben')");
$db->exec('INSERT INTO BookCopyAcquisitionDocuments (copy_id,document_id) VALUES (1,1),(2,1),(1,2)');
require_true((int)$db->query('SELECT COUNT(*) FROM AcquisitionDocuments WHERE document_id=1')->fetchColumn() === 1, 'Shared invoice was duplicated.');
reject_sql($db, 'INSERT INTO BookCopyAcquisitionDocuments (copy_id,document_id) VALUES (1,1)');
reject_sql($db, 'INSERT INTO BookCopyAcquisitionDocuments (copy_id,document_id) VALUES (1,999)');
reject_sql($db, 'INSERT INTO BookCopyAcquisitionDocuments (copy_id,document_id) VALUES (999,1)');
reject_sql($db, 'DELETE FROM AcquisitionDocuments WHERE document_id=1');
reject_sql($db, 'DELETE FROM BookCopies WHERE copy_id=1');
reject_sql($db, 'DELETE FROM Books WHERE book_id=109');
$db->exec("UPDATE Books SET record_status='deleted' WHERE book_id=109");
require_true((int)$db->query('SELECT COUNT(*) FROM BookCopyPhotos WHERE copy_id=1')->fetchColumn() === 10, 'Soft delete lost photos.');
run_script($db, $migration);
require_true((int)$db->query('SELECT COUNT(*) FROM BookCopyPhotos')->fetchColumn() === 11, 'Repeat migration lost photos.');
require_true((int)$db->query('SELECT COUNT(*) FROM BookCopyAcquisitionDocuments')->fetchColumn() === 3, 'Repeat migration lost document links.');
echo "OK: fresh schema, migration, repeat execution, preserved catalog data, ten-photo limit, shared documents, foreign keys and soft delete.\n";
