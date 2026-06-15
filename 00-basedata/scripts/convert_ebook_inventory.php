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


function ebook_parse_authors(string $author_part): array {
    $authors = [];
    $raw_parts = preg_split('/\s*;\s*/u', $author_part) ?: [];
    foreach ($raw_parts as $raw_author) {
        $name = ebook_norm($raw_author);
        if ($name === '') continue;
        if (preg_match('/^@(.+)@$/u', $name, $m)) {
            $name = ebook_norm((string)$m[1]);
        }
        if ($name === '') continue;
        $authors[] = [
            'name' => $name,
            'is_hungarian' => strpos($name, ',') === false,
            'author_alias' => null,
        ];
    }
    return $authors;
}

function ebook_extract_metadata_blocks(string $value): array {
    $blocks = [];
    $clean = preg_replace_callback('/\s*\{([^{}]+)\}\s*/u', static function (array $m) use (&$blocks): string {
        $blocks[] = ebook_norm($m[1] ?? '');
        return ' ';
    }, $value);
    return [ebook_norm((string)$clean), array_values(array_filter($blocks, static fn (string $v): bool => $v !== ''))];
}

function ebook_extract_language_tag(string $value): array {
    if (preg_match('/^(.*?)\s*\[([a-z]{2,3})\]\s*$/isu', $value, $m)) {
        return [ebook_norm((string)$m[1]), strtolower((string)$m[2])];
    }
    return [$value, null];
}

function ebook_language_from_path(string $path): ?string {
    foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
        if (preg_match('/^\d+_([A-Z]{2,3})$/i', $segment, $m)) {
            return strtolower((string)$m[1]);
        }
    }
    return null;
}

function ebook_apply_metadata_blocks(array $blocks, array &$authors): array {
    $series = [];
    $aliases = [];
    foreach ($blocks as $block) {
        if (preg_match('/^aka\s+(.+)$/iu', $block, $m)) {
            $alias = ebook_norm((string)$m[1]);
            if ($alias !== '') $aliases[] = $alias;
        } else {
            $series[] = $block;
        }
    }

    if ($aliases && isset($authors[0])) {
        $authors[0]['author_alias'] = implode('; ', $aliases);
    }

    return [
        'series' => $series ? implode('; ', $series) : '',
        'author_alias' => $aliases ? implode('; ', $aliases) : '',
    ];
}

function ebook_parse_row(string $name, string $path, int $file_size): array {
    $name = ebook_norm($name);
    $path = ebook_norm($path);

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, EBOOK_SUPPORTED_FORMATS, true)) {
        throw new RuntimeException("Unsupported format '{$ext}' for '{$name}'");
    }
    $format = $ext;

    $basename = preg_replace('/\.[^.]+$/u', '', $name) ?? $name;
    $parts = preg_split('/\s[-–—]\s/u', $basename) ?: [];
    if (count($parts) < 2) {
        throw new RuntimeException("Could not split author/title for '{$name}'");
    }

    $author_part = array_shift($parts);
    $title = ebook_norm((string)array_shift($parts));
    $subtitle = $parts ? ebook_norm(implode(' - ', $parts)) : '';
    $authors = ebook_parse_authors((string)$author_part);
    [$title, $title_blocks] = ebook_extract_metadata_blocks($title);
    [$title, $title_lang]   = ebook_extract_language_tag($title);
    [$subtitle, $subtitle_blocks] = ebook_extract_metadata_blocks($subtitle);
    [$subtitle, $subtitle_lang]   = ebook_extract_language_tag($subtitle);
    $metadata = ebook_apply_metadata_blocks(array_merge($title_blocks, $subtitle_blocks), $authors);
    $language = $title_lang ?? $subtitle_lang ?? ebook_language_from_path($path) ?? 'unknown';

    if (!$authors) {
        throw new RuntimeException("No authors parsed for '{$name}'");
    }
    if ($title === '') {
        throw new RuntimeException("No title parsed for '{$name}'");
    }

    return [
        'authors' => $authors,
        'authors_csv' => implode('; ', array_map(static fn (array $author): string => $author['name'], $authors)),
        'authors_metadata_json' => build_authors_metadata_json($authors),
        'title' => $title,
        'subtitle' => $subtitle !== '' ? $subtitle : null,
        'series' => $metadata['series'] !== '' ? $metadata['series'] : null,
        'language' => $language,
        'format' => $format,
        'file_path' => $path,
        'file_size' => $file_size,
        'raw_name' => $name,
    ];
}

function ebook_group_key(array $parsed): string {
    return mb_strtolower(
        $parsed['authors_csv'] . '|' . ($parsed['authors_metadata_json'] ?? '') . '|' . $parsed['title'] . '|' . ($parsed['subtitle'] ?? '') . '|' . ($parsed['series'] ?? ''),
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

$raw = file_get_contents($resolved_input);
if ($raw === false) {
    fwrite(STDERR, "Failed to read input file.\n");
    exit(1);
}

// Strip UTF-8 BOM if present
if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
    $raw = substr($raw, 3);
}

// Normalize line endings (\r\n and \r-only → \n)
$raw = str_replace("\r\n", "\n", $raw);
$raw = str_replace("\r", "\n", $raw);

$lines = explode("\n", $raw);
unset($raw);

// Scan for the header row (NeoFinder may prefix the export with metadata lines)
$col_name = false;
$col_path = false;
$col_size = false;
$header_line_no = 0;
$data_start = 0;

foreach ($lines as $i => $line) {
    $header_line_no = $i + 1;
    $fields = str_getcsv($line, "\t");
    $header = array_map(static fn ($value): string => ebook_norm((string)$value), $fields);
    $col_name = array_search('Name', $header, true);
    $col_path = array_search('Path', $header, true);
    $col_size = array_search('Size', $header, true);

    if ($col_name !== false && $col_path !== false && $col_size !== false) {
        $data_start = $i + 1;
        break;
    }
    $col_name = false;
    $col_path = false;
    $col_size = false;
}

if ($col_name === false || $col_path === false || $col_size === false) {
    fwrite(STDERR, "Could not find a header row with Name, Path, Size columns (scanned {$header_line_no} lines).\n");
    exit(1);
}

$groups = [];
$warnings = [];
$line_no = $data_start;
$source_rows = 0;

foreach (array_slice($lines, $data_start) as $line) {
    $line_no++;
    if (trim($line) === '') continue;
    $row = str_getcsv($line, "\t");
    $source_rows++;

    $name = ebook_norm($row[$col_name] ?? '');
    $path = ebook_norm($row[$col_path] ?? '');
    $file_size = max(0, (int)($row[$col_size] ?? 0));
    if ($name === '' || $path === '') {
        $warnings[] = "Line {$line_no}: missing Name/Path";
        continue;
    }

    // Skip folder entries (no file extension)
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === '') {
        continue;
    }

    try {
        $parsed = ebook_parse_row($name, $path, $file_size);
    } catch (Throwable $e) {
        $warnings[] = "Line {$line_no}: " . $e->getMessage();
        continue;
    }

    $key = ebook_group_key($parsed);
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'title' => $parsed['title'],
            'subtitle' => $parsed['subtitle'],
            'series' => $parsed['series'],
            'authors_csv' => $parsed['authors_csv'],
            'authors_metadata_json' => $parsed['authors_metadata_json'],
            'language' => $parsed['language'],
            'copies' => [],
        ];
    }

    $copy = [
        'format' => $parsed['format'],
        'quantity' => 1,
        'physical_location' => null,
        'file_path' => $parsed['file_path'],
        'file_size' => $parsed['file_size'],
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
unset($lines);

$out = fopen($output_path, 'wb');
if ($out === false) {
    fwrite(STDERR, "Failed to open output path: {$output_path}\n");
    exit(1);
}

$headers = [
    'ID', 'Title', 'Subtitle', 'Series', 'Language', 'Copy Count', 'Year', 'ISBN', 'LCCN', 'Notes',
    'Publisher', 'Authors', 'Authors Metadata JSON', 'Subjects', 'Loaned To', 'Loaned Date', 'Record Status', 'Bookcase', 'Shelf', 'Cover Image',
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
        $group['series'] ?? '',
        $group['language'],
        $copy_count,
        '',
        '',
        '',
        '',
        '',
        $group['authors_csv'],
        $group['authors_metadata_json'],
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
    'header_line' => $header_line_no,
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
