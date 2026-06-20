<?php
declare(strict_types=1);

define('BOOKCATALOG_RESCAN_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/public/rescan_ebook_repository.php';

function assert_same($actual, $expected, string $message): void {
    if ($actual !== $expected) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$sha = str_repeat('a', 64);
$old_path = '/Books/2_DE/_NoAuthor - Beck Wissen - Der Westfaelische Frieden {Beck Wissen} [de].epub';
$new_path = '/Books/2_DE/Real Author - Der Westfälische Frieden {Beck Wissen} [de].epub';

$db_copy = [
    'copy_id' => 207229,
    'book_id' => 12345,
    'title' => 'Beck Wissen - Der Westfaelische Frieden',
    'file_path' => $old_path,
    'sha256' => $sha,
];

$scanned = [
    'file_path' => $new_path,
    'absolute_path' => '/Volumes/SanDisk 2T' . $new_path,
    'format' => 'epub',
    'file_size' => 123456,
    'sha256' => $sha,
];

$item = rescan_path_change_item($scanned, $db_copy, $new_path);

assert_same($item['status'], 'same_sha_path_changed', 'Renamed same-SHA file must be a path-change candidate, not missing.');
assert_same($item['copy_id'], 207229, 'copy_id should be preserved.');
assert_same($item['old_file_path'], $old_path, 'old DB path should be reported.');
assert_same($item['new_file_path'], $new_path, 'new scanned path should be reported.');
assert_same($item['sha256'], $sha, 'SHA256 should be preserved.');

echo "rescan SHA relink classification cases ok\n";

$metadata_item = rescan_filename_metadata_mismatch([
    'file_path' => $new_path,
    'absolute_path' => '/Volumes/SanDisk 2T' . $new_path,
    'format' => 'epub',
    'file_size' => 123456,
    'sha256' => $sha,
], [
    'copy_id' => 207229,
    'book_id' => 12345,
    'title' => 'Beck Wissen',
    'subtitle' => 'Der Westfaelische Frieden',
    'series' => null,
    'language' => 'de',
    'authors_csv' => '_NoAuthor',
    'authors_metadata' => [['name' => '_NoAuthor', 'is_hungarian' => 1, 'author_alias' => null]],
]);

if (!is_array($metadata_item)) {
    fwrite(STDERR, "Beck Wissen filename metadata mismatch should be detected.\n");
    exit(1);
}
assert_same($metadata_item['parsed_authors'], 'Real Author', 'parsed author should come from the filename.');
assert_same($metadata_item['parsed_title'], 'Der Westfälische Frieden', 'parsed title should replace generic Beck Wissen title.');
assert_same($metadata_item['parsed_series'], 'Beck Wissen', 'series should come from metadata block.');

echo "rescan filename metadata repair cases ok\n";

$old_key = rescan_bibliographic_key('Beck Wissen', 'Der Westfaelische Frieden', 'Beck Wissen', 'de');
$new_key = rescan_bibliographic_key('Der Westfälische Frieden', null, 'Beck Wissen', 'de');
assert_same($old_key, $new_key, 'German ae/umlaut replacement keys should match for Beck Wissen replacements.');

$same_author_key = rescan_authors_value([['name' => '_NoAuthor', 'is_hungarian' => 1, 'author_alias' => null]]);
$same_author_key_2 = rescan_authors_value([['name' => '_NoAuthor', 'is_hungarian' => 0, 'author_alias' => null]]);
assert_same($same_author_key, $same_author_key_2, '_NoAuthor should not mismatch only because of is_hungarian flag noise.');

$display_author_key = rescan_authors_value([['name' => 'Stewart Ross', 'is_hungarian' => 0, 'author_alias' => null]]);
$structured_author_key = rescan_authors_value([['name' => 'Ross, Stewart', 'is_hungarian' => 0, 'author_alias' => null]]);
assert_same($display_author_key, $structured_author_key, 'Foreign display author and structured filename author should compare equal.');

$hu_display_author_key = rescan_authors_value([['name' => 'Hegyvári Norbert', 'is_hungarian' => 1, 'author_alias' => null]]);
$hu_structured_author_key = rescan_authors_value([['name' => 'Hegyvári|Norbert', 'is_hungarian' => 1, 'author_alias' => null]]);
assert_same($hu_display_author_key, $hu_structured_author_key, 'Hungarian display author and pipe-structured filename author should compare equal.');


assert_same(rescan_authors_match([['name' => 'Miklós Zrínyi', 'is_hungarian' => 1, 'author_alias' => null]], [['name' => 'Zrínyi|Miklós', 'is_hungarian' => 1, 'author_alias' => null]]), true, 'Imported display author and Hungarian structured filename author should compare equal even if display order is reversed.');

assert_same(rescan_authors_match([['name' => 'Girard Patrick', 'is_hungarian' => 0, 'author_alias' => null]], [['name' => 'Girard, Patrick', 'is_hungarian' => 0, 'author_alias' => null]]), true, 'Imported display author and foreign structured filename author should compare equal even if display order is reversed.');

$multi_current = [['name' => 'Miklós Zrínyi', 'is_hungarian' => 1, 'author_alias' => null], ['name' => 'Benede Elek', 'is_hungarian' => 1, 'author_alias' => null]];
$multi_parsed = [['name' => 'Zrínyi|Miklós', 'is_hungarian' => 1, 'author_alias' => null], ['name' => 'Benede|Elek', 'is_hungarian' => 1, 'author_alias' => null]];
assert_same(rescan_authors_match($multi_current, $multi_parsed), true, 'Multi-author imported display names should match structured Hungarian filename authors.');

echo "rescan replacement matching cases ok\n";
