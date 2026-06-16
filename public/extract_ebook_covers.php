<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: application/json; charset=utf-8');

/* ---------- epub cover extraction ---------- */

function ebook_extract_epub_cover(string $epub_path, string $tmp_dir): ?string {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($epub_path) !== true) return null;
    try {
        $container = $zip->getFromName('META-INF/container.xml');
        if ($container === false) return null;

        $opf_path = null;
        if (preg_match('/full-path\s*=\s*["\']([^"\']+\.opf)["\']/', $container, $m)) {
            $opf_path = $m[1];
        }
        if (!$opf_path) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if ($n !== false && strtolower(pathinfo($n, PATHINFO_EXTENSION)) === 'opf') {
                    $opf_path = $n; break;
                }
            }
        }
        if (!$opf_path) return null;

        $opf_xml = $zip->getFromName($opf_path);
        if ($opf_xml === false || strlen($opf_xml) > 2 * 1024 * 1024) return null;

        $opf_dir = dirname($opf_path);
        if ($opf_dir === '.') $opf_dir = '';

        $cover_href = null;

        // EPUB3: <item properties="... cover-image ...">
        if (preg_match_all('/<item\b([^>]+)>/i', $opf_xml, $all_items)) {
            foreach ($all_items[1] as $attrs) {
                if (!preg_match('/\bproperties\s*=\s*["\']([^"\']*)["\']/', $attrs, $pm)) continue;
                if (strpos($pm[1], 'cover-image') === false) continue;
                if (preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/', $attrs, $hm)) {
                    $cover_href = $hm[1]; break;
                }
            }
        }

        // EPUB2: <meta name="cover" content="item-id">
        if (!$cover_href && preg_match('/<meta\b[^>]*\bname\s*=\s*["\']cover["\'][^>]*>/i', $opf_xml, $mm)) {
            if (preg_match('/\bcontent\s*=\s*["\']([^"\']+)["\']/', $mm[0], $cm)) {
                $target_id = $cm[1];
                if (!isset($all_items)) preg_match_all('/<item\b([^>]+)>/i', $opf_xml, $all_items);
                foreach ($all_items[1] as $attrs) {
                    if (!preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/', $attrs, $idm)) continue;
                    if ($idm[1] !== $target_id) continue;
                    if (preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/', $attrs, $hm)) {
                        $cover_href = $hm[1]; break;
                    }
                }
            }
        }

        if (!$cover_href) return null;

        if (($fpos = strpos($cover_href, '#')) !== false) {
            $cover_href = substr($cover_href, 0, $fpos);
        }
        $cover_href = urldecode($cover_href);

        $raw_parts = explode('/', ($opf_dir !== '' ? $opf_dir . '/' : '') . $cover_href);
        $parts = [];
        foreach ($raw_parts as $p) {
            if ($p === '' || $p === '.') continue;
            if ($p === '..') { array_pop($parts); } else { $parts[] = $p; }
        }
        $zip_entry = implode('/', $parts);

        $ext = strtolower(pathinfo($cover_href, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) return null;

        $entry_idx = $zip->locateName($zip_entry);
        if ($entry_idx === false) return null;
        $entry_stat = $zip->statIndex($entry_idx);
        if ($entry_stat === false || ($entry_stat['size'] ?? PHP_INT_MAX) > 20 * 1024 * 1024) return null;

        $cover_data = $zip->getFromName($zip_entry);
        if ($cover_data === false || strlen($cover_data) < 100) return null;

        $out_ext = ($ext === 'jpeg') ? 'jpg' : $ext;
        $tmp_file = $tmp_dir . '/epub_cover_' . bin2hex(random_bytes(4)) . '.' . $out_ext;
        if (@file_put_contents($tmp_file, $cover_data) === false) return null;
        return $tmp_file;
    } finally {
        $zip->close();
    }
}

/* ---------- pdf cover extraction ---------- */

function ebook_extract_pdf_cover(string $pdf_path, string $tmp_dir): ?string {
    // Imagick extension intentionally avoided: GhostScript blocks indefinitely
    // on some PDFs with no PHP-side interrupt. Use proc_open + hard timeout.
    if (!function_exists('proc_open')) return null;

    $magick = '/opt/homebrew/bin/magick';
    if (!is_executable($magick)) {
        $which = trim((string)@shell_exec('which magick 2>/dev/null'));
        $magick = ($which !== '') ? $which : null;
    }
    if (!$magick) return null;

    $tmp_jpg = $tmp_dir . '/pdf_cover_' . bin2hex(random_bytes(4)) . '.jpg';
    $cmd = [$magick, '-density', '150', $pdf_path . '[0]',
            '-flatten', '-background', 'white', '-thumbnail', '600x', $tmp_jpg];
    $proc = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return null;

    fclose($pipes[0]);
    $deadline = time() + 10;
    $success = false;
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) { $success = true; break; }
        if (time() >= $deadline) { proc_terminate($proc, 9); break; }
        usleep(100_000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    if (!$success || !is_file($tmp_jpg) || filesize($tmp_jpg) < 100) {
        @unlink($tmp_jpg);
        return null;
    }
    return $tmp_jpg;
}

/* ---------- save extracted cover to uploads + DB ---------- */

function ebook_save_cover(PDO $pdo, int $book_id, string $src_path): bool {
    $uploads_root = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
    ensure_uploads_htaccess($uploads_root);
    $target_dir = $uploads_root . '/' . $book_id;
    if (!is_dir($target_dir) && !@mkdir($target_dir, 0775, true) && !is_dir($target_dir)) return false;

    $ext = strtolower(pathinfo($src_path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';

    foreach (glob($target_dir . '/cover*.*') ?: [] as $old) @unlink($old);
    foreach (glob($target_dir . '/cover-thumb*.*') ?: [] as $old) @unlink($old);

    $cover_dst = $target_dir . '/cover.' . $ext;
    $thumb_dst = $target_dir . '/cover-thumb.' . $ext;
    if (!@copy($src_path, $cover_dst)) return false;

    $thumb_ok = make_thumb($cover_dst, $thumb_dst, 200);
    $rel_cover = 'uploads/' . $book_id . '/cover.' . $ext;
    $rel_thumb = $thumb_ok ? ('uploads/' . $book_id . '/cover-thumb.' . $ext) : $rel_cover;

    $pdo->prepare('UPDATE Books SET cover_image = ?, cover_thumb = ? WHERE book_id = ?')
        ->execute([$rel_cover, $rel_thumb, $book_id]);
    return true;
}

/* ---------- main ---------- */

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_fail('Method Not Allowed', 405);
    }

    $limit  = max(1, min(50, (int)($_GET['limit']  ?? 5)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $force  = !empty($_GET['force']) && $_GET['force'] !== '0';

    $pdo = pdo();

    $status_clause = books_table_has_record_status($pdo) ? "AND b.record_status = 'active'" : "";
    $cover_clause  = $force ? "" : "AND (b.cover_image IS NULL OR b.cover_image = '')";

    $total = (int)$pdo->query("
        SELECT COUNT(DISTINCT b.book_id)
        FROM Books b
        JOIN BookCopies bc ON bc.book_id = b.book_id
        WHERE bc.format IN ('epub','pdf')
          AND bc.file_path IS NOT NULL AND bc.file_path != ''
          {$status_clause}
          {$cover_clause}
    ")->fetchColumn();

    $batch_st = $pdo->prepare("
        SELECT b.book_id,
          MAX(CASE WHEN bc.format = 'epub' AND bc.file_path != '' THEN bc.file_path ELSE NULL END) AS epub_path,
          MAX(CASE WHEN bc.format = 'pdf'  AND bc.file_path != '' THEN bc.file_path ELSE NULL END) AS pdf_path
        FROM Books b
        JOIN BookCopies bc ON bc.book_id = b.book_id
        WHERE bc.format IN ('epub','pdf')
          AND bc.file_path IS NOT NULL AND bc.file_path != ''
          {$status_clause}
          {$cover_clause}
        GROUP BY b.book_id
        ORDER BY b.book_id
        LIMIT :lim OFFSET :off
    ");
    $batch_st->execute([':lim' => $limit, ':off' => $offset]);
    $rows = $batch_st->fetchAll(PDO::FETCH_ASSOC);

    $processed = 0;
    $extracted = 0;
    $skipped   = 0;
    $errors    = [];

    if ($rows) {
        $tmp_dir = sys_get_temp_dir() . '/bc_covers_' . bin2hex(random_bytes(6));
        @mkdir($tmp_dir, 0775, true);
        try {
            foreach ($rows as $row) {
                $processed++;
                $book_id = (int)$row['book_id'];
                $fp_stored = $row['epub_path'] ?? $row['pdf_path'] ?? null;
                $fp = $fp_stored ? resolveFilesystemPath((string)$fp_stored) : null;
                if (!$fp || !file_exists($fp)) { $skipped++; continue; }

                $tmp_cover = null;
                try {
                    $ext = strtolower(pathinfo($fp, PATHINFO_EXTENSION));
                    $tmp_cover = ($ext === 'epub')
                        ? ebook_extract_epub_cover($fp, $tmp_dir)
                        : ebook_extract_pdf_cover($fp, $tmp_dir);

                    if ($tmp_cover && is_file($tmp_cover)) {
                        if (ebook_save_cover($pdo, $book_id, $tmp_cover)) {
                            $extracted++;
                        } else {
                            $skipped++;
                        }
                    } else {
                        $skipped++;
                    }
                } catch (Throwable $e) {
                    if (count($errors) < 25) $errors[] = "book #{$book_id}: " . $e->getMessage();
                    $skipped++;
                } finally {
                    if ($tmp_cover && is_file($tmp_cover)) @unlink($tmp_cover);
                }
            }
        } finally {
            foreach (@scandir($tmp_dir) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') @unlink($tmp_dir . '/' . $f);
            }
            @rmdir($tmp_dir);
        }
    }

    json_out([
        'ok' => true,
        'data' => [
            'total'     => $total,
            'offset'    => $offset,
            'limit'     => $limit,
            'processed' => $processed,
            'extracted' => $extracted,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ],
    ]);
} catch (Throwable $e) {
    json_fail($e->getMessage(), 500);
}
