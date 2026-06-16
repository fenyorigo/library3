<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
set_time_limit(120);
ignore_user_abort(true);
header('Content-Type: application/json; charset=utf-8');

const RESCAN_SESSION_TTL = 86400;

function rescan_supported_formats(): array {
    return ['epub','mobi','azw3','pdf','djvu','lit','prc','rtf','odt'];
}

function rescan_session_dir(): string {
    $dir = sys_get_temp_dir() . '/bookcatalog_rescan';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function rescan_session_path(string $token): string {
    if (!preg_match('/^[a-f0-9]{24,64}$/', $token)) {
        throw new InvalidArgumentException('Invalid rescan token');
    }
    return rescan_session_dir() . '/rescan_' . $token . '.json';
}

function rescan_load(string $token): array {
    $path = rescan_session_path($token);
    if (!is_file($path)) throw new InvalidArgumentException('Rescan session not found');
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) throw new RuntimeException('Invalid rescan session');
    return $data;
}

function rescan_save(array $session): void {
    $path = rescan_session_path((string)$session['token']);
    file_put_contents($path, json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function rescan_cleanup_old(): void {
    foreach (glob(rescan_session_dir() . '/rescan_*.json') ?: [] as $path) {
        if (is_file($path) && filemtime($path) !== false && filemtime($path) < time() - RESCAN_SESSION_TTL) {
            @unlink($path);
        }
    }
}

function rescan_db_indexes(PDO $pdo): array {
    $rows = $pdo->query("\n        SELECT bc.copy_id, bc.book_id, bc.format, bc.file_path, bc.file_size, bc.sha256,\n               b.title, b.subtitle, b.series, b.language\n        FROM BookCopies bc\n        JOIN Books b ON b.book_id = bc.book_id\n        WHERE bc.file_path IS NOT NULL\n          AND TRIM(bc.file_path) <> ''\n          AND bc.format <> 'print'\n          " . (books_table_has_record_status($pdo) ? "AND b.record_status = 'active'" : '') . "\n        ORDER BY bc.copy_id ASC\n    ")->fetchAll(PDO::FETCH_ASSOC);

    $by_path = [];
    $by_sha = [];
    foreach ($rows as $row) {
        $row['copy_id'] = (int)$row['copy_id'];
        $row['book_id'] = (int)$row['book_id'];
        $row['file_path'] = normalize_book_copy_file_path($row['file_path'] ?? null);
        $sha = normalize_book_copy_sha256($row['sha256'] ?? null);
        $row['sha256'] = $sha;
        if ($row['file_path'] !== null) $by_path[$row['file_path']][] = $row;
        if ($sha !== null) $by_sha[$sha][] = $row;
    }
    return ['rows' => $rows, 'by_path' => $by_path, 'by_sha' => $by_sha];
}

function rescan_change_type(string $old, string $new): array {
    $old_dir = dirname($old);
    $new_dir = dirname($new);
    $old_name = basename($old);
    $new_name = basename($new);
    $folder_changed = canonicalPathString($old_dir) !== canonicalPathString($new_dir);
    $filename_changed = canonicalPathString($old_name) !== canonicalPathString($new_name);
    $type = $folder_changed && $filename_changed ? 'folder_and_filename_changed'
        : ($folder_changed ? 'folder_changed_only' : 'filename_changed_only');
    $old_lang = null;
    $new_lang = null;
    if (preg_match('#^/Books/([^/]+)#', $old, $m)) $old_lang = $m[1];
    if (preg_match('#^/Books/([^/]+)#', $new, $m)) $new_lang = $m[1];
    $language_changed = $old_lang !== null && $new_lang !== null && $old_lang !== $new_lang;
    return [
        'change_type' => $type,
        'language_folder_changed' => $language_changed,
        'old_language_folder' => $old_lang,
        'new_language_folder' => $new_lang,
        'metadata_review_recommended' => $filename_changed,
        'language_review_recommended' => $language_changed,
    ];
}

function rescan_store_result(array &$session, string $status, array $item): void {
    if (!isset($session['results'][$status])) $session['results'][$status] = [];
    $session['results'][$status][] = $item;
    $session['counters'][$status] = (int)($session['counters'][$status] ?? 0) + 1;
}

function rescan_metadata_value(?string $value): string {
    return canonicalPathString($value ?? '') ?? '';
}

function rescan_scan_files(string $books_root_abs, string $mount_root): array {
    $formats = array_flip(rescan_supported_formats());
    $files = [];
    $seen = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($books_root_abs, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $info) {
        if (!$info instanceof SplFileInfo || !$info->isFile()) continue;
        $ext = strtolower((string)$info->getExtension());
        if (!isset($formats[$ext])) continue;
        $actual = filesystemPathString($info->getPathname());
        if ($actual === null) continue;
        try {
            $stored = absoluteToRelativeEbookPath($actual);
        } catch (Throwable $e) {
            continue;
        }
        if ($stored === null) continue;
        $files[] = [
            'absolute_path' => $actual,
            'file_path' => $stored,
            'format' => normalize_book_copy_format($ext),
            'file_size' => max(0, (int)$info->getSize()),
        ];
        $seen[$stored] = true;
    }
    usort($files, static fn($a, $b): int => strcmp($a['file_path'], $b['file_path']));
    return [$files, $seen];
}

function rescan_candidate_norm(?string $value): string {
    return canonicalPathString((string)$value) ?? '';
}

function rescan_candidate_parse_authors(string $author_part): array {
    $authors = [];
    foreach (preg_split('/\s*;\s*/u', $author_part) ?: [] as $raw_author) {
        $name = rescan_candidate_norm($raw_author);
        if ($name === '') continue;
        if (preg_match('/^@(.+)@$/u', $name, $m)) {
            $name = rescan_candidate_norm((string)$m[1]);
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

function rescan_candidate_extract_metadata_blocks(string $value): array {
    $blocks = [];
    $clean = preg_replace_callback('/\s*\{([^{}]+)\}\s*/u', static function (array $m) use (&$blocks): string {
        $block = rescan_candidate_norm($m[1] ?? '');
        if ($block !== '') $blocks[] = $block;
        return ' ';
    }, $value);
    return [rescan_candidate_norm((string)$clean), $blocks];
}

function rescan_candidate_extract_language_tag(string $value): array {
    if (preg_match('/^(.*?)\s*\[([a-z]{2,3})\]\s*$/isu', $value, $m)) {
        return [rescan_candidate_norm((string)$m[1]), strtolower((string)$m[2])];
    }
    return [$value, null];
}

function rescan_candidate_language_from_path(string $path): ?string {
    foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
        if (preg_match('/^\d+_([A-Z]{2,3})$/i', $segment, $m)) {
            return strtolower((string)$m[1]);
        }
    }
    return null;
}

function rescan_candidate_apply_metadata_blocks(array $blocks, array &$authors): array {
    $series = [];
    $aliases = [];
    foreach ($blocks as $block) {
        if (preg_match('/^aka\s+(.+)$/iu', $block, $m)) {
            $alias = rescan_candidate_norm((string)$m[1]);
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

function rescan_parse_new_candidate(array $item): array {
    $path = normalize_book_copy_file_path($item['file_path'] ?? null);
    if ($path === null) {
        throw new RuntimeException('New candidate is missing file_path');
    }
    $name = basename($path);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, rescan_supported_formats(), true)) {
        throw new RuntimeException("Unsupported format '{$ext}' for '{$path}'");
    }
    $basename = preg_replace('/\.[^.]+$/u', '', $name) ?? $name;
    $parts = preg_split('/\s[-–—]\s/u', $basename) ?: [];
    if (count($parts) < 2) {
        throw new RuntimeException("Could not split author/title for '{$path}'");
    }

    $author_part = array_shift($parts);
    $title = rescan_candidate_norm((string)array_shift($parts));
    $subtitle = $parts ? rescan_candidate_norm(implode(' - ', $parts)) : '';
    $authors = rescan_candidate_parse_authors((string)$author_part);
    [$title, $title_blocks] = rescan_candidate_extract_metadata_blocks($title);
    [$title, $title_lang] = rescan_candidate_extract_language_tag($title);
    [$subtitle, $subtitle_blocks] = rescan_candidate_extract_metadata_blocks($subtitle);
    [$subtitle, $subtitle_lang] = rescan_candidate_extract_language_tag($subtitle);
    $metadata = rescan_candidate_apply_metadata_blocks(array_merge($title_blocks, $subtitle_blocks), $authors);
    $language = $title_lang ?? $subtitle_lang ?? rescan_candidate_language_from_path($path) ?? 'unknown';

    if (!$authors) {
        throw new RuntimeException("No authors parsed for '{$path}'");
    }
    if ($title === '') {
        throw new RuntimeException("No title parsed for '{$path}'");
    }

    $sha = normalize_book_copy_sha256($item['sha256'] ?? null);
    $copy = [
        'format' => normalize_book_copy_format($item['format'] ?? $ext),
        'quantity' => 1,
        'physical_location' => null,
        'file_path' => $path,
        'file_size' => max(0, (int)($item['file_size'] ?? 0)),
        'notes' => null,
        'sha256' => $sha,
    ];

    return [
        'authors' => $authors,
        'authors_csv' => implode('; ', array_map(static fn(array $author): string => $author['name'], $authors)),
        'authors_metadata_json' => build_authors_metadata_json($authors),
        'title' => $title,
        'subtitle' => $subtitle !== '' ? $subtitle : null,
        'series' => $metadata['series'] !== '' ? $metadata['series'] : null,
        'language' => $language,
        'copy' => $copy,
    ];
}

function rescan_filename_metadata_mismatch(array $base, array $copy): ?array {
    try {
        $parsed = rescan_parse_new_candidate($base);
    } catch (Throwable $e) {
        return null;
    }

    $parsed_series = $parsed['series'] ?? null;
    $current_series = $copy['series'] ?? null;
    // Avoid turning the rescan into a broad title-normalization tool. For already-known files,
    // only suggest bibliographic updates when explicit filename series metadata is involved.
    if (rescan_metadata_value($parsed_series) === '' && rescan_metadata_value($current_series) === '') {
        return null;
    }

    $checks = [
        'title' => [$copy['title'] ?? null, $parsed['title'] ?? null],
        'subtitle' => [$copy['subtitle'] ?? null, $parsed['subtitle'] ?? null],
        'series' => [$current_series, $parsed_series],
        'language' => [normalize_book_language($copy['language'] ?? 'unknown'), normalize_book_language($parsed['language'] ?? 'unknown')],
    ];
    $differs = false;
    foreach ($checks as [$current, $next]) {
        if (rescan_metadata_value($current) !== rescan_metadata_value($next)) {
            $differs = true;
            break;
        }
    }
    if (!$differs) return null;

    return $base + [
        'status' => 'filename_metadata_mismatch',
        'copy_id' => $copy['copy_id'],
        'book_id' => $copy['book_id'],
        'current_title' => $copy['title'] ?? null,
        'current_subtitle' => $copy['subtitle'] ?? null,
        'current_series' => $copy['series'] ?? null,
        'current_language' => $copy['language'] ?? null,
        'parsed_title' => $parsed['title'] ?? null,
        'parsed_subtitle' => $parsed['subtitle'] ?? null,
        'parsed_series' => $parsed['series'] ?? null,
        'parsed_language' => $parsed['language'] ?? null,
    ];
}

function rescan_apply_filename_metadata_updates(PDO $pdo, array $items): array {
    $updated = 0;
    $processed = 0;
    $warnings = [];
    $st = $pdo->prepare('UPDATE Books SET title = ?, subtitle = ?, series = ?, language = ? WHERE book_id = ?');
    foreach ($items as $index => $item) {
        if (!is_array($item)) continue;
        $book_id = (int)($item['book_id'] ?? 0);
        $path = normalize_book_copy_file_path($item['new_file_path'] ?? ($item['file_path'] ?? null));
        if ($book_id <= 0 || $path === null) {
            $warnings[] = ['index' => $index, 'error' => 'Missing book_id or file_path'];
            continue;
        }
        try {
            $parsed = rescan_parse_new_candidate(['file_path' => $path, 'format' => pathinfo($path, PATHINFO_EXTENSION)]);
            $title = N($parsed['title'] ?? null);
            if ($title === null) throw new RuntimeException('Parsed title is empty');
            $subtitle = N($parsed['subtitle'] ?? null);
            $series = N($parsed['series'] ?? null);
            $language = normalize_book_language($parsed['language'] ?? 'unknown');
            $st->execute([$title, $subtitle, $series, $language, $book_id]);
            $processed++;
            $updated += $st->rowCount();
        } catch (Throwable $e) {
            $warnings[] = ['index' => $index, 'file_path' => $path, 'error' => $e->getMessage()];
        }
    }
    return ['processed' => $processed, 'updated' => $updated, 'warnings' => $warnings];
}

function rescan_candidate_group_key(array $parsed): string {
    return mb_strtolower(
        $parsed['authors_csv'] . '|' . ($parsed['authors_metadata_json'] ?? '') . '|' . $parsed['title'] . '|' . ($parsed['subtitle'] ?? '') . '|' . ($parsed['series'] ?? ''),
        'UTF-8'
    );
}

function rescan_new_candidates_csv(array $items): array {
    $headers = [
        'ID', 'Title', 'Subtitle', 'Series', 'Language', 'Copy Count', 'Year', 'ISBN', 'LCCN', 'Notes',
        'Publisher', 'Authors', 'Authors Metadata JSON', 'Subjects', 'Loaned To', 'Loaned Date',
        'Record Status', 'Bookcase', 'Shelf', 'Cover Image', 'Cover Filename', 'Copies JSON',
    ];
    $groups = [];
    $warnings = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) continue;
        try {
            $parsed = rescan_parse_new_candidate($item);
            $key = rescan_candidate_group_key($parsed);
            if (!isset($groups[$key])) {
                $groups[$key] = $parsed + ['copies' => []];
            }
            $groups[$key]['copies'][] = $parsed['copy'];
        } catch (Throwable $e) {
            $warnings[] = [
                'index' => $index,
                'file_path' => $item['file_path'] ?? null,
                'error' => $e->getMessage(),
            ];
        }
    }

    $fh = fopen('php://temp', 'r+');
    if ($fh === false) throw new RuntimeException('Could not create CSV stream');
    fputcsv($fh, $headers);
    foreach ($groups as $group) {
        $copies = $group['copies'];
        fputcsv($fh, [
            '',
            $group['title'],
            $group['subtitle'] ?? '',
            $group['series'] ?? '',
            $group['language'],
            total_book_copy_quantity($copies, 1),
            '', '', '', '',
            '',
            $group['authors_csv'],
            $group['authors_metadata_json'],
            '',
            '', '',
            'active',
            '', '',
            '', '',
            json_encode($copies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return [
        'csv' => $csv === false ? '' : $csv,
        'rows' => count($groups),
        'warnings' => $warnings,
    ];
}

function rescan_duplicate_files_csv(array $session): array {
    $headers = [
        'duplicate_group', 'sha256', 'occurrence', 'file_path', 'absolute_path', 'format', 'file_size', 'duplicate_count',
    ];
    $files_by_path = [];
    foreach (($session['files'] ?? []) as $file) {
        if (!is_array($file)) continue;
        $path = (string)($file['file_path'] ?? '');
        if ($path !== '') $files_by_path[$path] = $file;
    }

    $fh = fopen('php://temp', 'r+');
    if ($fh === false) throw new RuntimeException('Could not create CSV stream');
    fputcsv($fh, $headers);

    $groups = 0;
    $rows = 0;
    $sha_paths = $session['scanned_sha_paths'] ?? [];
    ksort($sha_paths);
    foreach ($sha_paths as $sha => $paths) {
        if (!is_array($paths)) continue;
        $paths = array_values(array_unique(array_filter(array_map('strval', $paths), static fn(string $p): bool => $p !== '')));
        sort($paths, SORT_STRING);
        if (count($paths) < 2) continue;
        $groups++;
        $occurrence = 0;
        foreach ($paths as $path) {
            $occurrence++;
            $file = $files_by_path[$path] ?? [];
            fputcsv($fh, [
                $groups,
                $sha,
                $occurrence,
                $path,
                $file['absolute_path'] ?? '',
                $file['format'] ?? strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                isset($file['file_size']) ? (int)$file['file_size'] : '',
                count($paths),
            ]);
            $rows++;
        }
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return [
        'csv' => $csv === false ? '' : $csv,
        'groups' => $groups,
        'rows' => $rows,
    ];
}

try {
    $pdo = pdo();
    if (!bookcopies_table_exists($pdo) || !bookcopies_has_sha256($pdo)) {
        json_fail('BookCopies.sha256 is required. Run the SHA256 schema migration first.', 500);
    }
    $warnings = unicode_path_runtime_warnings();
    if ($warnings) json_fail($warnings[0], 500);

    $root = getEbookLibraryRoot();
    $books_root = rtrim($root, '/') . '/Books';
    if (!is_dir($books_root) || !is_readable($books_root)) {
        json_fail('Ebook scan root is not mounted or not readable: ' . $books_root, 400);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_out(['ok' => true, 'data' => ['ebook_library_root' => $root, 'scan_root' => $books_root]]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_fail('Method Not Allowed', 405);

    $in = json_in();
    $action = (string)($in['action'] ?? '');

    if ($action === 'start') {
        rescan_cleanup_old();
        [$files, $seen] = rescan_scan_files($books_root, $root);
        $token = bin2hex(random_bytes(16));
        $session = [
            'token' => $token,
            'created_at' => time(),
            'ebook_library_root' => $root,
            'scan_root' => $books_root,
            'files' => $files,
            'seen_paths' => array_keys($seen),
            'seen_sha' => [],
            'scanned_sha_paths' => [],
            'offset' => 0,
            'total_files' => count($files),
            'counters' => [
                'unchanged' => 0,
                'same_sha_path_changed' => 0,
                'filename_metadata_mismatch' => 0,
                'new_file_candidate' => 0,
                'same_path_different_sha' => 0,
                'duplicate_sha_in_database' => 0,
                'duplicate_file_on_disk' => 0,
                'missing_on_disk' => 0,
                'errors' => 0,
            ],
            'results' => [],
        ];
        rescan_save($session);
        json_out(['ok' => true, 'data' => [
            'token' => $token,
            'ebook_library_root' => $root,
            'scan_root' => $books_root,
            'total_files' => count($files),
            'counters' => $session['counters'],
        ]]);
    }

    $token = (string)($in['token'] ?? '');
    $session = rescan_load($token);

    if ($action === 'next') {
        $limit = max(1, min(50, (int)($in['limit'] ?? 20)));
        $db = rescan_db_indexes($pdo);
        $start = (int)($session['offset'] ?? 0);
        $files = $session['files'] ?? [];
        $batch = array_slice($files, $start, $limit);
        $processed = 0;
        $batch_results = [];

        foreach ($batch as $file) {
            $processed++;
            $path = (string)$file['file_path'];
            $abs = (string)$file['absolute_path'];
            $base = [
                'file_path' => $path,
                'absolute_path' => $abs,
                'format' => $file['format'],
                'file_size' => (int)$file['file_size'],
            ];
            try {
                if (!is_readable($abs)) {
                    $item = $base + ['status' => 'errors', 'error' => 'File is not readable'];
                    rescan_store_result($session, 'errors', $item);
                    $batch_results[] = $item;
                    continue;
                }
                $sha = calculateFileSha256($abs);
                if ($sha === null) {
                    $item = $base + ['status' => 'errors', 'error' => 'SHA256 calculation failed'];
                    rescan_store_result($session, 'errors', $item);
                    $batch_results[] = $item;
                    continue;
                }
                $base['sha256'] = $sha;
                $session['seen_sha'][$sha] = true;
                $prior_scanned_paths = $session['scanned_sha_paths'][$sha] ?? [];
                $session['scanned_sha_paths'][$sha][] = $path;
                $path_rows = $db['by_path'][$path] ?? [];
                $sha_rows = $db['by_sha'][$sha] ?? [];

                if ($prior_scanned_paths) {
                    $item = $base + [
                        'status' => 'duplicate_file_on_disk',
                        'matching_scanned_paths' => $prior_scanned_paths,
                    ];
                    rescan_store_result($session, 'duplicate_file_on_disk', $item);
                    $batch_results[] = $item;
                } elseif ($path_rows) {
                    $copy = $path_rows[0];
                    if (($copy['sha256'] ?? null) === $sha) {
                        $item = $base + ['status' => 'unchanged', 'copy_id' => $copy['copy_id'], 'book_id' => $copy['book_id'], 'title' => $copy['title'] ?? null];
                        $metadata_mismatch = rescan_filename_metadata_mismatch($base, $copy);
                        if ($metadata_mismatch !== null) {
                            rescan_store_result($session, 'filename_metadata_mismatch', $metadata_mismatch);
                            $batch_results[] = $metadata_mismatch;
                        } else {
                            rescan_store_result($session, 'unchanged', $item);
                        }
                    } else {
                        $item = $base + ['status' => 'same_path_different_sha', 'copy_id' => $copy['copy_id'], 'book_id' => $copy['book_id'], 'title' => $copy['title'] ?? null, 'old_sha256' => $copy['sha256'] ?? null, 'new_sha256' => $sha];
                        rescan_store_result($session, 'same_path_different_sha', $item);
                        $batch_results[] = $item;
                    }
                } elseif (count($sha_rows) === 1) {
                    $copy = $sha_rows[0];
                    $change = rescan_change_type((string)$copy['file_path'], $path);
                    $item = $base + [
                        'status' => 'same_sha_path_changed',
                        'copy_id' => $copy['copy_id'],
                        'book_id' => $copy['book_id'],
                        'title' => $copy['title'] ?? null,
                        'old_file_path' => $copy['file_path'],
                        'new_file_path' => $path,
                    ] + $change;
                    try {
                        $parsed = rescan_parse_new_candidate($base);
                        $item['parsed_title'] = $parsed['title'] ?? null;
                        $item['parsed_subtitle'] = $parsed['subtitle'] ?? null;
                        $item['parsed_series'] = $parsed['series'] ?? null;
                        $item['parsed_language'] = $parsed['language'] ?? null;
                    } catch (Throwable $e) {
                        $item['metadata_parse_error'] = $e->getMessage();
                    }
                    rescan_store_result($session, 'same_sha_path_changed', $item);
                    $batch_results[] = $item;
                } elseif (count($sha_rows) > 1) {
                    $item = $base + ['status' => 'duplicate_sha_in_database', 'matches' => array_map(static fn($r) => ['copy_id' => $r['copy_id'], 'book_id' => $r['book_id'], 'file_path' => $r['file_path'], 'title' => $r['title'] ?? null], $sha_rows)];
                    rescan_store_result($session, 'duplicate_sha_in_database', $item);
                    $batch_results[] = $item;
                } else {
                    $item = $base + ['status' => 'new_file_candidate'];
                    rescan_store_result($session, 'new_file_candidate', $item);
                    $batch_results[] = $item;
                }
            } catch (Throwable $e) {
                $item = $base + ['status' => 'errors', 'error' => $e->getMessage()];
                rescan_store_result($session, 'errors', $item);
                $batch_results[] = $item;
            }
        }

        $session['offset'] = $start + $processed;
        $done = $session['offset'] >= (int)$session['total_files'];
        if ($done && empty($session['missing_finalized'])) {
            $seen_paths = array_fill_keys($session['seen_paths'] ?? [], true);
            $seen_sha = $session['seen_sha'] ?? [];
            foreach (rescan_db_indexes($pdo)['rows'] as $row) {
                $path = (string)($row['file_path'] ?? '');
                $sha = (string)($row['sha256'] ?? '');
                if (isset($seen_paths[$path])) continue;
                if ($sha !== '' && isset($seen_sha[$sha])) continue;
                if (resolveFilesystemPath($path) !== null) continue;
                $item = [
                    'status' => 'missing_on_disk',
                    'copy_id' => $row['copy_id'],
                    'book_id' => $row['book_id'],
                    'title' => $row['title'] ?? null,
                    'file_path' => $path,
                    'sha256' => $sha ?: null,
                ];
                rescan_store_result($session, 'missing_on_disk', $item);
                $batch_results[] = $item;
            }
            $session['missing_finalized'] = true;
        }
        rescan_save($session);
        json_out(['ok' => true, 'data' => [
            'token' => $token,
            'processed' => $processed,
            'offset' => $session['offset'],
            'total_files' => $session['total_files'],
            'done' => $done,
            'counters' => $session['counters'],
            'results' => $batch_results,
        ]]);
    }

    if ($action === 'apply_path_updates') {
        $items = is_array($in['items'] ?? null) ? $in['items'] : ($session['results']['same_sha_path_changed'] ?? []);
        $updated = 0;
        $st = $pdo->prepare('UPDATE BookCopies SET file_path = ?, file_size = ?, updated_at = CURRENT_TIMESTAMP WHERE copy_id = ? AND sha256 = ?');
        foreach ($items as $item) {
            $copy_id = (int)($item['copy_id'] ?? 0);
            $new_path = normalize_book_copy_file_path($item['new_file_path'] ?? ($item['file_path'] ?? null));
            $sha = normalize_book_copy_sha256($item['sha256'] ?? null);
            if ($copy_id <= 0 || $new_path === null || $sha === null) continue;
            $st->execute([$new_path, max(0, (int)($item['file_size'] ?? 0)), $copy_id, $sha]);
            $updated += $st->rowCount();
        }
        json_out(['ok' => true, 'data' => ['updated' => $updated]]);
    }

    if ($action === 'apply_sha_updates') {
        $items = is_array($in['items'] ?? null) ? $in['items'] : ($session['results']['same_path_different_sha'] ?? []);
        $updated = 0;
        $st = $pdo->prepare('UPDATE BookCopies SET sha256 = ?, file_size = ?, updated_at = CURRENT_TIMESTAMP WHERE copy_id = ? AND file_path = ?');
        foreach ($items as $item) {
            $copy_id = (int)($item['copy_id'] ?? 0);
            $path = normalize_book_copy_file_path($item['file_path'] ?? null);
            $sha = normalize_book_copy_sha256($item['new_sha256'] ?? ($item['sha256'] ?? null));
            if ($copy_id <= 0 || $path === null || $sha === null) continue;
            $st->execute([$sha, max(0, (int)($item['file_size'] ?? 0)), $copy_id, $path]);
            $updated += $st->rowCount();
        }
        json_out(['ok' => true, 'data' => ['updated' => $updated]]);
    }

    if ($action === 'apply_filename_metadata_updates') {
        $items = is_array($in['items'] ?? null) ? $in['items'] : ($session['results']['filename_metadata_mismatch'] ?? []);
        json_out(['ok' => true, 'data' => rescan_apply_filename_metadata_updates($pdo, $items)]);
    }

    if ($action === 'export_new_candidates_csv') {
        $items = is_array($in['items'] ?? null) ? $in['items'] : ($session['results']['new_file_candidate'] ?? []);
        $export = rescan_new_candidates_csv($items);
        json_out(['ok' => true, 'data' => [
            'filename' => 'ebook_new_candidates_' . date('Ymd_His') . '.csv',
            'csv' => $export['csv'],
            'rows' => $export['rows'],
            'warnings' => $export['warnings'],
        ]]);
    }

    if ($action === 'export_duplicate_files_csv') {
        $export = rescan_duplicate_files_csv($session);
        json_out(['ok' => true, 'data' => [
            'filename' => 'ebook_duplicate_files_' . date('Ymd_His') . '.csv',
            'csv' => $export['csv'],
            'groups' => $export['groups'],
            'rows' => $export['rows'],
        ]]);
    }

    json_fail('Unknown rescan action', 400);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException ? 400 : 500;
    json_fail($e->getMessage(), $code);
}
