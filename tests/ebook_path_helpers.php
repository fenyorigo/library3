<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/functions.php';

$original_root = getSetting('ebook_library_root', '/Volumes/SanDisk 2T');
setSetting('ebook_library_root', '/Volumes/SanDisk 2T');

try {
$cases = [
    ['/Volumes/SanDisk 2T/Books/0_HU/Bukowsky, Walter - Orosz rulett [hu].epub', '/Books/0_HU/Bukowsky, Walter - Orosz rulett [hu].epub'],
    ['/Books/0_HU/Bukowsky, Walter - Orosz rulett [hu].epub', '/Books/0_HU/Bukowsky, Walter - Orosz rulett [hu].epub'],
    ['Books/0_HU/Bukowsky, Walter - Orosz rulett [hu].epub', '/Books/0_HU/Bukowsky, Walter - Orosz rulett [hu].epub'],
    ['/Books', '/Books'],
    [null, null],
];

foreach ($cases as [$input, $expected]) {
    $actual = absoluteToRelativeEbookPath($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "absoluteToRelativeEbookPath failed for " . var_export($input, true) . "
Expected: " . var_export($expected, true) . "
Actual: " . var_export($actual, true) . "
");
        exit(1);
    }
}

try {
    absoluteToRelativeEbookPath('/Volumes/Other SSD/Books/Bad.epub');
    fwrite(STDERR, "outside-root path was accepted unexpectedly
");
    exit(1);
} catch (InvalidArgumentException $e) {
    // Expected.
}

$absolute = relativeToAbsoluteEbookPath('/Books/0_HU/Bukowsky, Walter - Orosz rulett [hu].epub');
if (!str_ends_with((string)$absolute, '/Books/0_HU/Bukowsky, Walter - Orosz rulett [hu].epub')) {
    fwrite(STDERR, "relativeToAbsoluteEbookPath did not preserve the /Books suffix
");
    exit(1);
}


$tmp_base = sys_get_temp_dir() . '/bookcatalog_path_alias_' . bin2hex(random_bytes(4));
$real_root = $tmp_base . '/real-root';
$link_root = $tmp_base . '/link-root';
$book_dir = $real_root . '/Books/0_HU';
@mkdir($book_dir, 0775, true);
$book_file = $book_dir . '/Alias Test [hu].epub';
file_put_contents($book_file, 'alias path test');
@symlink($real_root, $link_root);
try {
    if (is_link($link_root)) {
        setSetting('ebook_library_root', $link_root);
        $alias_actual = absoluteToRelativeEbookPath($book_file);
        if ($alias_actual !== '/Books/0_HU/Alias Test [hu].epub') {
            fwrite(STDERR, "absoluteToRelativeEbookPath did not accept a realpath alias under the configured root
Expected: '/Books/0_HU/Alias Test [hu].epub'
Actual: " . var_export($alias_actual, true) . "
");
            exit(1);
        }
    }
} finally {
    @unlink($book_file);
    @rmdir($book_dir);
    @rmdir(dirname($book_dir));
    @unlink($link_root);
    @rmdir($real_root);
    @rmdir($tmp_base);
}

} finally {
    setSetting('ebook_library_root', (string)$original_root);
}

echo "ebook path helper cases ok
";
