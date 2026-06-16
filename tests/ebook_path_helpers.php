<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/functions.php';

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

echo "ebook path helper cases ok
";
