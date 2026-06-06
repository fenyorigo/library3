<?php
declare(strict_types=1);

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

$tmp_base = sys_get_temp_dir() . '/bookcatalog_ebook_parser_' . bin2hex(random_bytes(4));
$input = $tmp_base . '.tsv';
$output = $tmp_base . '.csv';
$repo = dirname(__DIR__);
$script = $repo . '/00-basedata/scripts/convert_ebook_inventory.php';

$rows = [
    ['Name', 'Path', 'Kind'],
    ['@Dante@ - Isteni színjáték [hu].epub', '/ebooks/dante.epub', 'epub'],
    ['Molnár|Éva - Barbárság tengere {aka Vavyan Fable} [hu].epub', '/ebooks/barbarsag.epub', 'epub'],
    ['Hughes, Jobie - Lorieni Krónikák 1 - A negyedik {aka Pittacus Lore} {Lorieni krónikák} [hu].epub', '/ebooks/lorien.epub', 'epub'],
    ['_NoAuthor - Magyar népmesék [hu].epub', '/ebooks/nepmesek.epub', 'epub'],
    ['Asimov, Isaac - Foundation [en].epub', '/ebooks/foundation.epub', 'epub'],
    ['Gárdonyi|Géza - Egri csillagok [hu].epub', '/ebooks/egri.epub', 'epub'],
];

$fh = fopen($input, 'wb');
foreach ($rows as $row) {
    fputcsv($fh, $row, "\t", '"', '\\');
}
fclose($fh);

$cmd = PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($input) . ' ' . escapeshellarg($output) . ' 2>&1';
exec($cmd, $cmd_out, $code);
if ($code !== 0) {
    @unlink($input);
    @unlink($output);
    throw new RuntimeException("Converter failed:\n" . implode("\n", $cmd_out));
}

$fh = fopen($output, 'rb');
$header = fgetcsv($fh, 0, ',', '"', '\\');
$records = [];
while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
    if ($row === [null]) continue;
    $records[] = array_combine($header, $row);
}
fclose($fh);
@unlink($input);
@unlink($output);

$by_author = [];
foreach ($records as $record) {
    $by_author[$record['Authors']] = $record;
}

assert_same('Dante', $by_author['Dante']['Authors'], '@...@ author should strip marker characters.');
assert_same('Isteni színjáték [hu]', $by_author['Dante']['Title'], '@...@ title should parse normally.');
assert_same('[{"name":"Dante","is_hungarian":true}]', $by_author['Dante']['Authors Metadata JSON'], '@...@ author metadata should stay single phrase.');

assert_same('Molnár|Éva', $by_author['Molnár|Éva']['Authors'], 'Pipe-form author should remain in canonical CSV author field.');
assert_same('Barbárság tengere [hu]', $by_author['Molnár|Éva']['Title'], '{aka ...} should be removed from title.');
assert_same('', $by_author['Molnár|Éva']['Series'], '{aka ...} should not be treated as series.');
assert_same('[{"name":"Molnár|Éva","is_hungarian":true,"author_alias":"Vavyan Fable"}]', $by_author['Molnár|Éva']['Authors Metadata JSON'], '{aka ...} should round-trip as author_alias metadata.');

assert_same('Lorieni Krónikák 1', $by_author['Hughes, Jobie']['Title'], 'Title before subtitle should be preserved.');
assert_same('A negyedik [hu]', $by_author['Hughes, Jobie']['Subtitle'], '{aka ...} and series metadata should be removed from subtitle.');
assert_same('Lorieni krónikák', $by_author['Hughes, Jobie']['Series'], 'Non-aka metadata block should remain series.');
assert_same('[{"name":"Hughes, Jobie","is_hungarian":false,"author_alias":"Pittacus Lore"}]', $by_author['Hughes, Jobie']['Authors Metadata JSON'], 'Foreign author alias should round-trip in metadata JSON.');

assert_same('_NoAuthor', $by_author['_NoAuthor']['Authors'], '_NoAuthor should continue to parse as before.');
assert_same('Foundation [en]', $by_author['Asimov, Isaac']['Title'], 'Existing foreign author filename should continue to parse.');
assert_same('Egri csillagok [hu]', $by_author['Gárdonyi|Géza']['Title'], 'Existing Hungarian pipe author filename should continue to parse.');

fwrite(STDOUT, "ok - ebook_filename_parser_cases\n");
