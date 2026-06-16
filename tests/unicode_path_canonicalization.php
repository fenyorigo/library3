<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/functions.php';

if (!unicode_path_normalization_available()) {
    fwrite(STDERR, "PHP intl Normalizer is required for this test\n");
    exit(1);
}

$nfd = Normalizer::normalize('/Books/0_HU/Szabó|János - invázió [hu].pdf', Normalizer::FORM_D);
$cases = [
    [$nfd, "/Books/0_HU/Szabó|János - invázió [hu].pdf"],
    ["/Books/0_HU/Mongol\u{200B} invázió.pdf", "/Books/0_HU/Mongol invázió.pdf"],
    ["/Books/0_HU/A\u{00A0}B.pdf", "/Books/0_HU/A B.pdf"],
    ["/Books//0_HU///A  B.pdf", "/Books/0_HU/A B.pdf"],
];

foreach ($cases as [$input, $expected]) {
    $actual = canonicalPathString($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "canonicalPathString mismatch\nInput: " . json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\nExpected: " . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\nActual: " . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
}

echo "unicode path canonicalization cases ok\n";
