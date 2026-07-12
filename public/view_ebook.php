<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
$me = require_login();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
set_time_limit(0);
ignore_user_abort(true);

function ebook_view_h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ebook_view_html_header(string $title): void {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'none'; frame-src 'self'; object-src 'none'");
    echo "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
    echo '<title>' . ebook_view_h($title) . '</title>';
    echo '<style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #1f2933; background: #f5f7fb; }
        .reader-shell { max-width: 980px; margin: 0 auto; padding: 1rem; }
        .reader-bar { position: sticky; top: 0; z-index: 2; display: flex; gap: .5rem; align-items: center; justify-content: space-between; flex-wrap: wrap; padding: .75rem 1rem; margin: -1rem -1rem 1rem; background: rgba(245,247,251,.96); border-bottom: 1px solid #d8dee9; backdrop-filter: blur(6px); }
        .reader-title { font-weight: 700; overflow-wrap: anywhere; }
        .reader-actions { display: flex; gap: .45rem; flex-wrap: wrap; align-items: center; }
        .reader-actions a, .reader-actions span { border: 1px solid #b8c2cc; border-radius: 6px; padding: .35rem .6rem; background: #fff; color: #1f2933; text-decoration: none; font-size: .95rem; }
        .reader-actions span { opacity: .5; }
        .reader-content { background: #fff; border: 1px solid #d8dee9; border-radius: 8px; padding: clamp(1rem, 3vw, 2.4rem); line-height: 1.65; overflow-wrap: anywhere; }
        .reader-content img, .reader-content svg { max-width: 100%; height: auto; }
        .reader-content table { max-width: 100%; border-collapse: collapse; }
        .reader-content pre { white-space: pre-wrap; }
        .notice { background: #fff; border: 1px solid #d8dee9; border-radius: 8px; padding: 1rem 1.25rem; line-height: 1.5; }
    </style></head><body>';
}

function ebook_view_html_footer(): void {
    echo '</body></html>';
}

function ebook_view_copy(PDO $pdo, int $copy_id, array $me): array {
    if (!bookcopies_table_exists($pdo)) {
        throw new RuntimeException('BookCopies table is not available');
    }
    $st = $pdo->prepare("\n        SELECT bc.copy_id, bc.book_id, bc.format, bc.file_path,\n               b.title, " . (books_table_has_record_status($pdo) ? "b.record_status" : "'active' AS record_status") . "\n        FROM BookCopies bc\n        JOIN Books b ON b.book_id = bc.book_id\n        WHERE bc.copy_id = ?\n        LIMIT 1\n    ");
    $st->execute([$copy_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new InvalidArgumentException('Ebook copy not found');
    }
    $record_status = normalize_book_record_status($row['record_status'] ?? 'active');
    if ($record_status === 'deleted' && (($me['role'] ?? '') !== 'admin')) {
        throw new InvalidArgumentException('Ebook copy not found');
    }
    $format = normalize_book_copy_format($row['format'] ?? null);
    $stored_path = normalize_book_copy_file_path($row['file_path'] ?? null);
    if ($format === 'print' || $stored_path === null) {
        throw new InvalidArgumentException('Selected copy is not a readable ebook file');
    }
    $resolved = resolveFilesystemPath($stored_path);
    if ($resolved === null || !is_file($resolved)) {
        throw new RuntimeException('Ebook file is not available on disk');
    }
    if (!is_readable($resolved)) {
        throw new RuntimeException('Ebook file is not readable');
    }
    $row['format'] = $format;
    $row['file_path'] = $stored_path;
    $row['resolved_path'] = $resolved;
    return $row;
}

function ebook_view_mime(string $path): string {
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'css' => 'text/css; charset=utf-8',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'otf' => 'font/otf',
        'ttf' => 'font/ttf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'xhtml', 'html', 'htm' => 'application/xhtml+xml; charset=utf-8',
        default => 'application/octet-stream',
    };
}

function epub_zip_path(string $path): string {
    $path = str_replace('\\', '/', trim($path));
    $path = ltrim($path, '/');
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

function epub_join_path(string $base, string $href): string {
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $href) || str_starts_with($href, '#')) return $href;
    $href = strtok($href, '#') ?: $href;
    return epub_zip_path(($base !== '' ? rtrim($base, '/') . '/' : '') . $href);
}

function epub_xpath_first(SimpleXMLElement $xml, string $query): ?SimpleXMLElement {
    $found = $xml->xpath($query);
    return ($found && isset($found[0]) && $found[0] instanceof SimpleXMLElement) ? $found[0] : null;
}

function epub_package(ZipArchive $zip): array {
    $container = $zip->getFromName('META-INF/container.xml');
    if ($container === false) throw new RuntimeException('EPUB container.xml not found');
    $container_xml = @simplexml_load_string($container);
    if (!$container_xml) throw new RuntimeException('EPUB container.xml could not be parsed');
    $rootfile = epub_xpath_first($container_xml, '//*[local-name()="rootfile"]');
    $opf_path = $rootfile ? (string)($rootfile['full-path'] ?? '') : '';
    $opf_path = epub_zip_path($opf_path);
    if ($opf_path === '') throw new RuntimeException('EPUB OPF package path not found');

    $opf = $zip->getFromName($opf_path);
    if ($opf === false) throw new RuntimeException('EPUB OPF package not found');
    $opf_xml = @simplexml_load_string($opf);
    if (!$opf_xml) throw new RuntimeException('EPUB OPF package could not be parsed');
    $opf_dir = trim(dirname($opf_path), '.');
    $opf_dir = $opf_dir === '/' ? '' : $opf_dir;

    $manifest = [];
    foreach (($opf_xml->xpath('//*[local-name()="manifest"]/*[local-name()="item"]') ?: []) as $item) {
        $id = (string)($item['id'] ?? '');
        $href = (string)($item['href'] ?? '');
        if ($id === '' || $href === '') continue;
        $manifest[$id] = [
            'href' => epub_join_path($opf_dir, $href),
            'media_type' => (string)($item['media-type'] ?? ''),
        ];
    }

    $chapters = [];
    foreach (($opf_xml->xpath('//*[local-name()="spine"]/*[local-name()="itemref"]') ?: []) as $itemref) {
        $idref = (string)($itemref['idref'] ?? '');
        if ($idref === '' || empty($manifest[$idref]['href'])) continue;
        $href = (string)$manifest[$idref]['href'];
        $ext = strtolower(pathinfo($href, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xhtml', 'html', 'htm'], true)) continue;
        $chapters[] = [
            'id' => $idref,
            'href' => $href,
            'media_type' => $manifest[$idref]['media_type'] ?? '',
        ];
    }
    if (!$chapters) throw new RuntimeException('EPUB spine does not contain readable HTML chapters');

    $title_node = epub_xpath_first($opf_xml, '//*[local-name()="metadata"]/*[local-name()="title"]');
    return [
        'opf_path' => $opf_path,
        'opf_dir' => $opf_dir,
        'title' => $title_node ? trim((string)$title_node) : 'EPUB reader',
        'chapters' => $chapters,
    ];
}

function epub_resource_url(int $copy_id, string $path): string {
    return 'view_ebook.php?action=resource&copy_id=' . rawurlencode((string)$copy_id) . '&path=' . rawurlencode($path);
}

function epub_chapter_url(int $copy_id, int $chapter): string {
    return 'view_ebook.php?copy_id=' . rawurlencode((string)$copy_id) . '&chapter=' . rawurlencode((string)$chapter);
}

function epub_rewrite_link(string $value, string $base_dir, int $copy_id, array $chapter_map): string {
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '#')) return $value;
    if (preg_match('/^(https?:|mailto:|data:|blob:)/i', $value)) return $value;
    if (preg_match('/^javascript:/i', $value)) return '#';

    $fragment = '';
    $path = $value;
    if (str_contains($path, '#')) {
        [$path, $fragment] = explode('#', $path, 2);
        $fragment = '#' . $fragment;
    }
    $target = epub_join_path($base_dir, $path);
    if (isset($chapter_map[$target])) {
        return epub_chapter_url($copy_id, (int)$chapter_map[$target]) . $fragment;
    }
    return epub_resource_url($copy_id, $target) . $fragment;
}

function epub_inner_html(DOMNode $node): string {
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument?->saveHTML($child) ?: '';
    }
    return $html;
}

function epub_render_chapter_html(string $html, string $base_dir, int $copy_id, array $chapter_map): string {
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    foreach (iterator_to_array($xpath->query('//script') ?: []) as $script) {
        $script->parentNode?->removeChild($script);
    }
    foreach (iterator_to_array($xpath->query('//*[@src or @href]') ?: []) as $node) {
        if (!$node instanceof DOMElement) continue;
        foreach (['src', 'href'] as $attr) {
            if ($node->hasAttribute($attr)) {
                $node->setAttribute($attr, epub_rewrite_link($node->getAttribute($attr), $base_dir, $copy_id, $chapter_map));
            }
        }
    }
    foreach (iterator_to_array($xpath->query('//*[@*]') ?: []) as $node) {
        if (!$node instanceof DOMElement) continue;
        $remove = [];
        foreach ($node->attributes ?: [] as $attr) {
            if (preg_match('/^on/i', $attr->name)) $remove[] = $attr->name;
        }
        foreach ($remove as $attr_name) $node->removeAttribute($attr_name);
    }
    $body = $dom->getElementsByTagName('body')->item(0);
    return $body ? epub_inner_html($body) : ebook_view_h($html);
}

function epub_rewrite_css(string $css, string $base_dir, int $copy_id): string {
    return (string)preg_replace_callback('/url\(([^)]+)\)/i', static function (array $m) use ($base_dir, $copy_id): string {
        $raw = trim($m[1], " \t\n\r\0\x0B'\"");
        if ($raw === '' || preg_match('/^(https?:|data:|blob:)/i', $raw)) return $m[0];
        return 'url("' . epub_resource_url($copy_id, epub_join_path($base_dir, $raw)) . '")';
    }, $css);
}

function serve_pdf_inline(array $copy): void {
    $path = (string)$copy['resolved_path'];
    $size = filesize($path);
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/pdf');
    if ($size !== false) header('Content-Length: ' . (string)$size);
    header('Content-Disposition: inline; filename="' . addcslashes(basename((string)$copy['file_path']), "\\\"") . '"');
    header('Cache-Control: private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function serve_epub_resource(array $copy, string $resource_path): void {
    $zip = new ZipArchive();
    if ($zip->open((string)$copy['resolved_path']) !== true) throw new RuntimeException('Could not open EPUB file');
    $resource_path = epub_zip_path($resource_path);
    $content = $resource_path !== '' ? $zip->getFromName($resource_path) : false;
    if ($content === false) {
        $zip->close();
        json_fail('EPUB resource not found', 404);
    }
    if (strtolower(pathinfo($resource_path, PATHINFO_EXTENSION)) === 'css') {
        $content = epub_rewrite_css($content, trim(dirname($resource_path), '.'), (int)$copy['copy_id']);
    }
    $zip->close();
    header('Content-Type: ' . ebook_view_mime($resource_path));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

function serve_epub_reader(array $copy): void {
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive is not available in PHP runtime');
    $zip = new ZipArchive();
    if ($zip->open((string)$copy['resolved_path']) !== true) throw new RuntimeException('Could not open EPUB file');
    $package = epub_package($zip);
    $chapters = $package['chapters'];
    $chapter = max(0, min(count($chapters) - 1, (int)($_GET['chapter'] ?? 0)));
    $entry = $chapters[$chapter];
    $html = $zip->getFromName((string)$entry['href']);
    if ($html === false) {
        $zip->close();
        throw new RuntimeException('EPUB chapter not found');
    }
    $zip->close();

    $chapter_map = [];
    foreach ($chapters as $idx => $item) $chapter_map[(string)$item['href']] = $idx;
    $content = epub_render_chapter_html($html, trim(dirname((string)$entry['href']), '.'), (int)$copy['copy_id'], $chapter_map);
    $title = trim((string)($copy['title'] ?? '')) ?: (string)$package['title'];
    ebook_view_html_header($title);
    echo '<main class="reader-shell">';
    echo '<nav class="reader-bar"><div class="reader-title">' . ebook_view_h($title) . '</div><div class="reader-actions">';
    echo $chapter > 0 ? '<a href="' . ebook_view_h(epub_chapter_url((int)$copy['copy_id'], $chapter - 1)) . '">Previous</a>' : '<span>Previous</span>';
    echo '<span>' . ebook_view_h((string)($chapter + 1)) . ' / ' . ebook_view_h((string)count($chapters)) . '</span>';
    echo $chapter < count($chapters) - 1 ? '<a href="' . ebook_view_h(epub_chapter_url((int)$copy['copy_id'], $chapter + 1)) . '">Next</a>' : '<span>Next</span>';
    echo '<a href="download_ebook.php?copy_id=' . ebook_view_h((string)$copy['copy_id']) . '">Download</a>';
    echo '</div></nav><article class="reader-content">' . $content . '</article></main>';
    ebook_view_html_footer();
    exit;
}

function serve_unsupported(string $format, array $copy): void {
    $title = trim((string)($copy['title'] ?? 'Ebook preview')) ?: 'Ebook preview';
    ebook_view_html_header($title);
    echo '<main class="reader-shell"><div class="notice"><h1>' . ebook_view_h(strtoupper($format)) . ' preview is not supported yet</h1>';
    echo '<p>This file type is not rendered in BookCatalog yet. Use Download to open it with an external reader.</p>';
    echo '<p><a href="download_ebook.php?copy_id=' . ebook_view_h((string)$copy['copy_id']) . '">Download ebook</a></p></div></main>';
    ebook_view_html_footer();
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') json_fail('Method Not Allowed', 405);
    $copy_id = (int)($_GET['copy_id'] ?? 0);
    if ($copy_id <= 0) json_fail('Missing or invalid copy id', 400);
    requireEbookRepositoryAvailable(false);
    $copy = ebook_view_copy(pdo(), $copy_id, $me);
    $format = (string)$copy['format'];
    $action = (string)($_GET['action'] ?? 'view');

    if ($action === 'resource') {
        if ($format !== 'epub') json_fail('Resource view is only available for EPUB files', 400);
        serve_epub_resource($copy, (string)($_GET['path'] ?? ''));
    }
    if ($format === 'pdf') serve_pdf_inline($copy);
    if ($format === 'epub') serve_epub_reader($copy);
    if ($format === 'rtf') serve_unsupported('rtf', $copy);
    serve_unsupported($format, $copy);
} catch (Throwable $e) {
    ebook_view_html_header('Ebook preview error');
    echo '<main class="reader-shell"><div class="notice"><h1>Ebook preview failed</h1><p>' . ebook_view_h($e->getMessage()) . '</p></div></main>';
    ebook_view_html_footer();
    exit;
}
