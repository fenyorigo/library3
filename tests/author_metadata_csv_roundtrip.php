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

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function make_test_pdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->sqliteCreateFunction('CONCAT', static function (...$parts): string {
        return implode('', array_map(static fn ($part): string => (string)($part ?? ''), $parts));
    }, -1);

    $pdo->exec("
        CREATE TABLE Authors (
            author_id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT DEFAULT NULL,
            first_name TEXT DEFAULT NULL,
            last_name TEXT DEFAULT NULL,
            sort_name TEXT DEFAULT NULL,
            is_hungarian INTEGER NOT NULL DEFAULT 0
        )
    ");
    $pdo->exec("
        CREATE TABLE Books_Authors (
            book_id INTEGER NOT NULL,
            author_id INTEGER NOT NULL,
            author_ord INTEGER NOT NULL DEFAULT 0,
            author_alias TEXT DEFAULT NULL,
            PRIMARY KEY (book_id, author_id)
        )
    ");

    return $pdo;
}

function test_export_json_uses_unescaped_unicode_and_order(): void {
    $pdo = make_test_pdo();
    $ins_author = $pdo->prepare("
        INSERT INTO Authors (name, first_name, last_name, sort_name, is_hungarian)
        VALUES (?, ?, ?, ?, ?)
    ");
    $ins_author->execute(['Gavin Menzies', 'Gavin', 'Menzies', 'Menzies, Gavin', 0]);
    $gavin_id = (int)$pdo->lastInsertId();
    $ins_author->execute(['Bihari Péter', 'Péter', 'Bihari', 'Bihari, Péter', 1]);
    $bihari_id = (int)$pdo->lastInsertId();

    $ins_link = $pdo->prepare('INSERT INTO Books_Authors (book_id, author_id, author_ord) VALUES (?, ?, ?)');
    $ins_link->execute([1, $gavin_id, 1]);
    $ins_link->execute([1, $bihari_id, 2]);

    $map = fetch_book_authors_metadata_map($pdo, [1]);
    $json = build_authors_metadata_json($map[1] ?? []);

    assert_same(
        '[{"name":"Gavin Menzies","is_hungarian":false},{"name":"Bihari Péter","is_hungarian":true}]',
        $json,
        'Export should emit ordered author metadata JSON with unescaped Unicode.'
    );
}

function test_import_with_metadata_preserves_flags_and_order(): void {
    $pdo = make_test_pdo();
    $json = '[{"name":"Gavin Menzies","is_hungarian":false},{"name":"Bihari Péter","is_hungarian":true}]';

    attachAuthorsMetadataToBook($pdo, 7, 'Gavin Menzies; Bihari Péter', $json);

    $authors = $pdo->query('SELECT name, is_hungarian FROM Authors ORDER BY author_id ASC')->fetchAll();
    assert_same(
        [
            ['name' => 'Gavin Menzies', 'is_hungarian' => 0],
            ['name' => 'Bihari Péter', 'is_hungarian' => 1],
        ],
        $authors,
        'Import should preserve explicit is_hungarian values from the metadata column.'
    );

    $links = $pdo->query('SELECT author_ord FROM Books_Authors WHERE book_id = 7 ORDER BY author_ord ASC')->fetchAll();
    assert_same(
        [
            ['author_ord' => 1],
            ['author_ord' => 2],
        ],
        $links,
        'Import should preserve author order for round-trip exports.'
    );
}

function test_import_without_metadata_keeps_legacy_behavior(): void {
    $pdo = make_test_pdo();

    attachAuthorsMetadataToBook($pdo, 9, 'Gavin Menzies', null);

    $author = $pdo->query('SELECT name, is_hungarian FROM Authors LIMIT 1')->fetch();
    assert_true(is_array($author), 'Import without metadata should still create an author.');
    assert_same('Gavin Menzies', $author['name'], 'Legacy import should keep the author name.');
    assert_same(1, (int)$author['is_hungarian'], 'Legacy import behavior should remain unchanged when metadata is absent.');
}

function test_metadata_can_update_existing_author_flag(): void {
    $pdo = make_test_pdo();
    $pdo->prepare("
        INSERT INTO Authors (name, first_name, last_name, sort_name, is_hungarian)
        VALUES (?, ?, ?, ?, ?)
    ")->execute(['Gavin Menzies', 'Gavin', 'Menzies', 'Menzies, Gavin', 1]);

    attachAuthorsMetadataToBook(
        $pdo,
        11,
        'Gavin Menzies',
        '[{"name":"Gavin Menzies","is_hungarian":false}]'
    );

    $flag = (int)$pdo->query("SELECT is_hungarian FROM Authors WHERE name = 'Gavin Menzies' LIMIT 1")->fetchColumn();
    assert_same(0, $flag, 'Import should use metadata to correct an existing author flag where possible.');
}


function test_author_alias_roundtrip_uses_relation_metadata(): void {
    $pdo = make_test_pdo();
    $json = '[{"name":"Rejtő|Jenő","is_hungarian":true,"author_alias":"P Howard"}]';

    attachAuthorsMetadataToBook($pdo, 12, 'Rejtő|Jenő', $json);

    $author = $pdo->query('SELECT name, first_name, last_name, sort_name, is_hungarian FROM Authors LIMIT 1')->fetch();
    assert_same('Rejtő Jenő', $author['name'], 'Pipe-form Hungarian author should be stored as canonical display name.');
    assert_same('Jenő', $author['first_name'], 'Pipe-form Hungarian author should preserve given name.');
    assert_same('Rejtő', $author['last_name'], 'Pipe-form Hungarian author should preserve family name.');

    $alias = $pdo->query('SELECT author_alias FROM Books_Authors WHERE book_id = 12')->fetchColumn();
    assert_same('P Howard', $alias, 'Import should store author_alias on the Books_Authors relation.');

    $map = fetch_book_authors_metadata_map($pdo, [12]);
    $export_json = build_authors_metadata_json($map[12] ?? []);
    assert_same(
        '[{"name":"Rejtő Jenő","is_hungarian":true,"author_alias":"P Howard"}]',
        $export_json,
        'Export should round-trip relation-level author_alias in Authors Metadata JSON.'
    );
}

function test_single_phrase_author_markers_are_not_split(): void {
    $pdo = make_test_pdo();
    attachAuthorsMetadataToBook($pdo, 13, '@Dante@', null);

    $author = $pdo->query('SELECT name, first_name, last_name, sort_name FROM Authors LIMIT 1')->fetch();
    assert_same('Dante', $author['name'], 'Single phrase @...@ author should strip markers for display name.');
    assert_same(null, $author['first_name'], 'Single phrase @...@ author should not create a given name.');
    assert_same('Dante', $author['last_name'], 'Single phrase @...@ author should store the phrase as family/primary name.');
    assert_same('Dante', $author['sort_name'], 'Single phrase @...@ author should sort by the phrase itself.');
}

$tests = [
    'export_json_uses_unescaped_unicode_and_order' => 'test_export_json_uses_unescaped_unicode_and_order',
    'import_with_metadata_preserves_flags_and_order' => 'test_import_with_metadata_preserves_flags_and_order',
    'import_without_metadata_keeps_legacy_behavior' => 'test_import_without_metadata_keeps_legacy_behavior',
    'metadata_can_update_existing_author_flag' => 'test_metadata_can_update_existing_author_flag',
    'author_alias_roundtrip_uses_relation_metadata' => 'test_author_alias_roundtrip_uses_relation_metadata',
    'single_phrase_author_markers_are_not_split' => 'test_single_phrase_author_markers_are_not_split',
];

foreach ($tests as $name => $fn) {
    $fn();
    fwrite(STDOUT, "ok - {$name}\n");
}
