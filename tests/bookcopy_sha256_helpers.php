<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/functions.php';

$tmp = tempnam(sys_get_temp_dir(), 'bc_sha_');
if ($tmp === false) {
    fwrite(STDERR, "tempnam failed
");
    exit(1);
}
file_put_contents($tmp, 'BookCatalog sha256 test');
$expected = hash('sha256', 'BookCatalog sha256 test');
$actual = calculateFileSha256($tmp);
@unlink($tmp);

if ($actual !== $expected) {
    fwrite(STDERR, "calculateFileSha256 mismatch
Expected: {$expected}
Actual: {$actual}
");
    exit(1);
}

if (calculateFileSha256(sys_get_temp_dir() . '/missing-bookcatalog-file.epub') !== null) {
    fwrite(STDERR, "missing file returned a checksum
");
    exit(1);
}

$upper = strtoupper($expected);
if (normalize_book_copy_sha256($upper) !== $expected) {
    fwrite(STDERR, "uppercase sha256 was not normalized to lowercase
");
    exit(1);
}

if (normalize_book_copy_sha256('') !== null || normalize_book_copy_sha256(null) !== null) {
    fwrite(STDERR, "empty sha256 did not normalize to null
");
    exit(1);
}

try {
    normalize_book_copy_sha256('not-a-valid-sha');
    fwrite(STDERR, "invalid sha256 was accepted
");
    exit(1);
} catch (InvalidArgumentException $e) {
    // Expected.
}

if (calculateBookCopySha256(['format' => 'print', 'file_path' => null]) !== null) {
    fwrite(STDERR, "print copy returned a checksum
");
    exit(1);
}

echo "bookcopy sha256 helper cases ok
";
