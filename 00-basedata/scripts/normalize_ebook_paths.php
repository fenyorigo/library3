<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/functions.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

const EBOOK_PATH_FORMATS = ['epub', 'mobi', 'azw3', 'pdf', 'djvu', 'lit', 'prc', 'rtf', 'odt'];

function ebook_path_usage(): never {
    $script = basename(__FILE__);
    fwrite(STDERR, "Usage: php {$script} [--apply] [--limit=N]\n");
    fwrite(STDERR, "Default mode is dry-run.\n");
    exit(1);
}

$apply = false;
$limit = 0;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        ebook_path_usage();
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int)$m[1];
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    ebook_path_usage();
}

try {
    $pdo = pdo();
    if (!bookcopies_table_exists($pdo)) {
        fwrite(STDOUT, "BookCopies table does not exist. Nothing to do.\n");
        exit(0);
    }

    $placeholders = implode(',', array_fill(0, count(EBOOK_PATH_FORMATS), '?'));
    $sql = "
        SELECT copy_id, book_id, format, file_path
        FROM BookCopies
        WHERE format IN ($placeholders)
          AND file_path IS NOT NULL
          AND TRIM(file_path) <> ''
        ORDER BY copy_id ASC
    ";
    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit;
    }

    $st = $pdo->prepare($sql);
    $st->execute(EBOOK_PATH_FORMATS);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $changes = [];
    foreach ($rows as $row) {
        $current = trim((string)($row['file_path'] ?? ''));
        $normalized = normalize_book_copy_file_path($current);
        if ($normalized === null || $normalized === $current) continue;

        $changes[] = [
            'copy_id' => (int)$row['copy_id'],
            'book_id' => (int)$row['book_id'],
            'format' => (string)$row['format'],
            'before' => $current,
            'after' => $normalized,
        ];
    }

    fwrite(STDOUT, sprintf(
        "Scanned %d ebook copy rows, %d path(s) need normalization.%s\n",
        count($rows),
        count($changes),
        $apply ? ' Applying changes...' : ' Dry-run only.'
    ));

    foreach (array_slice($changes, 0, 20) as $change) {
        fwrite(STDOUT, sprintf(
            "copy_id=%d book_id=%d format=%s\n  before: %s\n  after:  %s\n",
            $change['copy_id'],
            $change['book_id'],
            $change['format'],
            $change['before'],
            $change['after']
        ));
    }
    if (count($changes) > 20) {
        fwrite(STDOUT, sprintf("... and %d more change(s)\n", count($changes) - 20));
    }

    if (!$apply || !$changes) {
        exit(0);
    }

    $pdo->beginTransaction();
    $upd = $pdo->prepare('UPDATE BookCopies SET file_path = ?, updated_at = CURRENT_TIMESTAMP WHERE copy_id = ?');
    foreach ($changes as $change) {
        $upd->execute([$change['after'], $change['copy_id']]);
    }
    $pdo->commit();

    fwrite(STDOUT, sprintf("Updated %d ebook path(s).\n", count($changes)));
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
