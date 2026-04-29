<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/functions.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

const EBOOK_SUPPORTED_FORMATS = ['epub', 'mobi', 'azw3', 'pdf', 'djvu', 'lit', 'prc', 'rtf', 'odt'];

function ebook_usage(): never {
    $script = basename(__FILE__);
    fwrite(STDERR, "Usage: php {$script} <input.tsv> [output.csv]\n");
    exit(1);
}

function ebook_norm(?string $value): string {
    $value = normalize_unicode_nfc(strip_invisible_format_chars((string)$value));
    $value = preg_replace('/ {2,}/u', ' ', $value) ?? $value;
    return trim($value);
}

function ebook_detect_format(string $name, string $kind): ?string {
    $kind = strtolower(ebook_norm($kind));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    foreach (EBOOK_SUPPORTED_FORMATS as $format) {
        if ($kind === $format || str_contains($kind, $format)) return $format;
    }
    if (in_array($ext, EBOOK_SUPPORTED_FORMATS, true)) return $ext;
    return null;
}

function ebook_parse_authors(string $author_part): array {
    $authors = [];
    $raw_parts = preg_split('/\s*;\s*/u', $author_part) ?: [];
    foreach ($raw_parts as $raw_author) {
        $name = ebook_norm($raw_author);
        if ($name === '') continue;
        $authors[] = [
            'name' => $name,
            'is_hungarian' => strpos($name, ',') === false,
        ];
    }
    return $authors;
}

function ebook_parse_row(string $name, string $path, string $kind): array {
    $name = ebook_norm($name);
    $path = ebook_norm($path);
    $kind = ebook_norm($kind);

    $format = ebook_detect_format($name, $kind);
    if ($format === null) {
        throw new RuntimeException("Unsupported format for '{$name}' (kind='{$kind}')");
    }

    $basename = preg_replace('/\.[^.]+$/u', '', $name) ?? $name;
    $parts = preg_split('/\s[-–—]\s/u', $basename) ?: [];
    if (count($parts) < 2) {
        throw new RuntimeException("Could not split author/title for '{$name}'");
    }

    $author_part = array_shift($parts);
    $title = ebook_norm((string)array_shift($parts));
    $subtitle = $parts ? ebook_norm(implode(' - ', $parts)) : '';
    $authors = ebook_parse_authors((string)$author_part);

    if (!$authors) {
        throw new RuntimeException("No authors parsed for '{$name}'");
    }
    if ($title === '') {
        throw new RuntimeException("No title parsed for '{$name}'");
    }

    return [
        'authors' => $authors,
        'authors_csv' => implode('; ', array_map(static fn (array $author): string => $author['name'], $authors)),
        'title' => $title,
        'subtitle' => $subtitle !== '' ? $subtitle : null,
        'format' => $format,
        'file_path' => $path,
        'raw_name' => $name,
    ];
}

function ebook_group_key(array $parsed): string {
    return mb_strtolower(
        $parsed['authors_csv'] . '|' . $parsed['title'] . '|' . ($parsed['subtitle'] ?? ''),
        'UTF-8'
    );
}

$input_path = $argv[1] ?? '';
if ($input_path === '') {
    ebook_usage();
}

$resolved_input = realpath($input_path);
if ($resolved_input === false || !is_readable($resolved_input)) {
    fwrite(STDERR, "Input file is missing or unreadable: {$input_path}\n");
    exit(1);
}

$output_path = $argv[2] ?? preg_replace('/(\.[^.]+)?$/', '.bookcatalog_v3.csv', $resolved_input);
if (!is_string($output_path) || $output_path === '') {
    fwrite(STDERR, "Could not derive output path.\n");
    exit(1);
}

$in = fopen($resolved_input, 'rb');
if ($in === false) {
    fwrite(STDERR, "Failed to open input file.\n");
    exit(1);
}

$header = fgetcsv($in, 0, "\t");
if (!is_array($header)) {
    fclose($in);
    fwrite(STDERR, "Input file is empty.\n");
    exit(1);
}

$header = array_map(static fn ($value): string => ebook_norm((string)$value), $header);
$required = ['Name', 'Path', 'Kind'];
if ($header !== $required) {
    fclose($in);
    fwrite(STDERR, "Unexpected header. Expected tab-delimited: Name, Path, Kind\n");
    fwrite(STDERR, "Actual header: " . implode(' | ', $header) . "\n");
    exit(1);
}

$groups = [];
$warnings = [];
$line_no = 1;
$source_rows = 0;

while (($row = fgetcsv($in, 0, "\t")) !== false) {
    $line_no++;
    if ($row === [null] || $row === false) continue;
    $source_rows++;

    $name = ebook_norm($row[0] ?? '');
    $path = ebook_norm($row[1] ?? '');
    $kind = ebook_norm($row[2] ?? '');
    if ($name === '' || $path === '' || $kind === '') {
        $warnings[] = "Line {$line_no}: missing Name/Path/Kind";
        continue;
    }

    try {
        $parsed = ebook_parse_row($name, $path, $kind);
    } catch (Throwable $e) {
        $warnings[] = "Line {$line_no}: " . $e->getMessage();
        continue;
    }

    $key = ebook_group_key($parsed);
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'title' => $parsed['title'],
            'subtitle' => $parsed['subtitle'],
            'authors_csv' => $parsed['authors_csv'],
            'language' => 'unknown',
            'copies' => [],
        ];
    }

    $copy = [
        'format' => $parsed['format'],
        'quantity' => 1,
        'physical_location' => null,
        'file_path' => $parsed['file_path'],
        'notes' => null,
    ];

    $duplicate = false;
    foreach ($groups[$key]['copies'] as $existing) {
        if (($existing['format'] ?? '') === $copy['format'] && ($existing['file_path'] ?? '') === $copy['file_path']) {
            $duplicate = true;
            break;
        }
    }
    if ($duplicate) {
        $warnings[] = "Line {$line_no}: duplicate copy skipped for '{$parsed['raw_name']}'";
        continue;
    }

    $groups[$key]['copies'][] = $copy;
}
fclose($in);

$out = fopen($output_path, 'wb');
if ($out === false) {
    fwrite(STDERR, "Failed to open output path: {$output_path}\n");
    exit(1);
}

$headers = [
    'ID', 'Title', 'Subtitle', 'Series', 'Language', 'Copy Count', 'Year', 'ISBN', 'LCCN', 'Notes',
    'Publisher', 'Authors', 'Subjects', 'Loaned To', 'Loaned Date', 'Record Status', 'Bookcase', 'Shelf', 'Cover Image',
    'Cover Filename', 'Copies JSON',
];
fputcsv($out, $headers, ',', '"', "\\");

ksort($groups, SORT_STRING);
foreach ($groups as $group) {
    $copy_count = total_book_copy_quantity($group['copies'], 1);
    $row = [
        '',
        $group['title'],
        $group['subtitle'] ?? '',
        '',
        $group['language'],
        $copy_count,
        '',
        '',
        '',
        '',
        '',
        $group['authors_csv'],
        '',
        '',
        '',
        'active',
        '',
        '',
        '',
        '',
        json_encode($group['copies'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $row = array_map('sanitize_csv_value', $row);
    fputcsv($out, $row, ',', '"', "\\");
}
fclose($out);

$summary = [
    'input' => $resolved_input,
    'output' => $output_path,
    'source_rows' => $source_rows,
    'grouped_books' => count($groups),
    'copy_rows' => array_sum(array_map(static fn (array $group): int => count($group['copies']), $groups)),
    'warnings' => count($warnings),
];

fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
if ($warnings) {
    foreach ($warnings as $warning) {
        fwrite(STDERR, $warning . PHP_EOL);
    }
}
