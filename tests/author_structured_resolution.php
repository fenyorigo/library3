<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/functions.php';

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("\n    CREATE TABLE Authors (\n        author_id INTEGER PRIMARY KEY AUTOINCREMENT,\n        name TEXT DEFAULT NULL,\n        first_name TEXT DEFAULT NULL,\n        last_name TEXT DEFAULT NULL,\n        sort_name TEXT DEFAULT NULL,\n        is_hungarian INTEGER NOT NULL DEFAULT 0\n    )\n");

$pdo->exec("\n    INSERT INTO Authors (name, first_name, last_name, sort_name, is_hungarian) VALUES\n    ('Cornwell Bernard', 'Cornwell', 'Bernard', 'Bernard, Cornwell', 0),\n    ('György Babák', 'Babák', 'György', 'György, Babák', 1),\n    ('Ajtmatov Csingiz', 'Csingiz', 'Ajtmatov', 'Ajtmatov, Csingiz', 0)\n");

$cornwell = getAuthorId($pdo, 'Cornwell, Bernard', 0);
$cornwell_row = $pdo->query("SELECT * FROM Authors WHERE author_id = " . (int)$cornwell)->fetch(PDO::FETCH_ASSOC);
assert_same('Bernard', $cornwell_row['first_name'], 'Structured foreign author must not reuse flipped legacy first_name.');
assert_same('Cornwell', $cornwell_row['last_name'], 'Structured foreign author must not reuse flipped legacy last_name.');
assert_same('Bernard Cornwell', $cornwell_row['name'], 'Structured foreign author display should be canonical.');
assert_same('Cornwell, Bernard', $cornwell_row['sort_name'], 'Structured foreign author sort_name should be canonical.');

$babak = getAuthorId($pdo, 'Babák|György', 1);
$babak_row = $pdo->query("SELECT * FROM Authors WHERE author_id = " . (int)$babak)->fetch(PDO::FETCH_ASSOC);
assert_same('György', $babak_row['first_name'], 'Structured Hungarian author must not reuse flipped legacy first_name.');
assert_same('Babák', $babak_row['last_name'], 'Structured Hungarian author must not reuse flipped legacy last_name.');
assert_same('Babák György', $babak_row['name'], 'Structured Hungarian author display should be canonical.');
assert_same('Babák, György', $babak_row['sort_name'], 'Structured Hungarian author sort_name should be canonical.');

$ajtmatov = getAuthorId($pdo, 'Ajtmatov, Csingiz', 0);
$ajtmatov_row = $pdo->query("SELECT * FROM Authors WHERE author_id = " . (int)$ajtmatov)->fetch(PDO::FETCH_ASSOC);
assert_same('Csingiz', $ajtmatov_row['first_name'], 'Existing correct structured fields should be reused.');
assert_same('Ajtmatov', $ajtmatov_row['last_name'], 'Existing correct structured fields should be reused.');
assert_same('Csingiz Ajtmatov', $ajtmatov_row['name'], 'Existing structured row display should be canonicalized.');
assert_same('Ajtmatov, Csingiz', $ajtmatov_row['sort_name'], 'Existing structured row sort_name should remain canonical.');

fwrite(STDOUT, "ok - author_structured_resolution\n");
