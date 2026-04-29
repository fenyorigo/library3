<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
$me = require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$pdo = pdo();

$prefs = fetch_user_preferences($pdo, (int)$me['uid']);
$pref_bg = isset($prefs['bg_color']) && $prefs['bg_color'] ? (string)$prefs['bg_color'] : '#ffffff';
$pref_fg = isset($prefs['fg_color']) && $prefs['fg_color'] ? (string)$prefs['fg_color'] : '#1a1a1a';

$allowed_status = ['ALL', 'NEW', 'IGNORE', 'CONFIRMED', 'MERGED'];
$status_filter = strtoupper((string)($_GET['status'] ?? 'NEW'));
if (!in_array($status_filter, $allowed_status, true)) {
    $status_filter = 'NEW';
}

$save_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dup_key = trim((string)($_POST['dup_key'] ?? ''));
    $status_in = strtoupper(trim((string)($_POST['status'] ?? 'NEW')));
    $note = N($_POST['note'] ?? null);
    $redirect_status = strtoupper((string)($_POST['status_filter'] ?? $status_filter));
    if (!in_array($redirect_status, $allowed_status, true)) $redirect_status = 'NEW';

    if ($dup_key === '') {
        $save_error = 'Missing duplicate key.';
    } elseif (!in_array($status_in, ['NEW', 'IGNORE', 'CONFIRMED'], true)) {
        $save_error = 'Invalid status.';
    } else {
        $cur = $pdo->prepare("SELECT status FROM duplicate_review WHERE dup_key = ? LIMIT 1");
        $cur->execute([$dup_key]);
        $cur_status = strtoupper((string)($cur->fetchColumn() ?: ''));
        if ($cur_status === 'MERGED') {
            $save_error = 'MERGED groups are system-managed and read-only.';
        }
    }

    if ($save_error === null) {
        $ins = $pdo->prepare(
            "INSERT INTO duplicate_review (dup_key, status, note)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE status=VALUES(status), note=VALUES(note), updated_at=CURRENT_TIMESTAMP"
        );
        $ins->execute([$dup_key, $status_in, $note]);
        header('Location: duplicate_candidates.php?status=' . urlencode($redirect_status) . '&saved=1');
        exit;
    }
}

$normalize_sort_name = static function (string $name): string {
    $n = trim($name);
    $n = mb_strtolower($n, 'UTF-8');
    $n = preg_replace('/\s+/u', ' ', $n);
    return $n ?? '';
};

$normalize_title = static function (string $title): string {
    $t = trim($title);
    $t = mb_strtolower($t, 'UTF-8');
    $t = preg_replace('/\s+/u', ' ', $t);
    if ($t === null) $t = '';
    $t = preg_replace('/\b([ivxlcdm]+|\d+)\.$/i', '$1', $t);
    return $t ?? '';
};

$format_title_display = static function (string $title, ?string $subtitle): string {
    $sub = trim((string)$subtitle);
    if ($sub === '') return $title;
    return $title . ': ' . $sub;
};

$author_sort_map = [];
$author_rows = $pdo->query("SELECT author_id, sort_name FROM Authors")->fetchAll(PDO::FETCH_ASSOC);
foreach ($author_rows as $row) {
    $author_id = (int)$row['author_id'];
    $sort_name = $normalize_sort_name((string)($row['sort_name'] ?? ''));
    if ($sort_name === '') $sort_name = 'author#' . $author_id;
    $author_sort_map[$author_id] = $sort_name;
}

$by_book = [];
$rows = $pdo->query(
    "SELECT b.book_id, b.title, b.subtitle, ba.author_id
     FROM Books b
     JOIN Books_Authors ba ON ba.book_id = b.book_id"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $book_id = (int)$row['book_id'];
    $title = (string)($row['title'] ?? '');
    $subtitle = (string)($row['subtitle'] ?? '');
    $author_id = (int)$row['author_id'];
    if (!isset($by_book[$book_id])) {
        $by_book[$book_id] = [
            'title' => $title,
            'subtitle' => $subtitle,
            'authors' => [],
        ];
    }
    $by_book[$book_id]['authors'][$author_id] = true;
}

$groups = [];
foreach ($by_book as $book_id => $info) {
    $author_ids = array_keys($info['authors']);
    $author_names = [];
    foreach ($author_ids as $author_id) {
        $author_names[] = $author_sort_map[$author_id] ?? ('author#' . $author_id);
    }
    sort($author_names, SORT_STRING);
    $authors_key = implode(';', $author_names);
    $title_raw = $info['title'];
    $subtitle = trim((string)$info['subtitle']);
    if ($subtitle !== '') $title_raw .= '||' . $subtitle;
    // Changing this logic invalidates existing duplicate_review keys.
    $title_key = $normalize_title($title_raw);
    $dup_key = $title_key . '|' . $authors_key;

    if (!isset($groups[$dup_key])) {
        $groups[$dup_key] = [
            'dup_key' => $dup_key,
            'title' => $info['title'],
            'subtitle' => $info['subtitle'],
            'book_ids' => [],
        ];
    }
    $groups[$dup_key]['book_ids'][] = $book_id;
}

foreach ($groups as $key => $group) {
    if (count($group['book_ids']) < 2) {
        unset($groups[$key]);
    }
}

uksort($groups, static function ($a, $b) {
    return strcmp($a, $b);
});

$review_rows = [];
$dup_keys = array_keys($groups);
if ($dup_keys) {
    $placeholders = [];
    $params = [];
    foreach ($dup_keys as $i => $key) {
        $ph = ':k' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $key;
    }
    $sql = "SELECT dup_key, status, note FROM duplicate_review WHERE dup_key IN (" . implode(',', $placeholders) . ")";
    $st = $pdo->prepare($sql);
    foreach ($params as $ph => $val) {
        $st->bindValue($ph, $val, PDO::PARAM_STR);
    }
    $st->execute();
    $review_rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

$reviews = [];
foreach ($review_rows as $row) {
    $reviews[$row['dup_key']] = [
        'status' => (string)($row['status'] ?? 'NEW'),
        'note' => $row['note'] ?? null,
    ];
}

$filtered_groups = [];
foreach ($groups as $key => $group) {
    $review = $reviews[$key] ?? ['status' => 'NEW', 'note' => null];
    $status = strtoupper((string)$review['status']);
    $note = $review['note'];

    if ($status_filter === 'ALL') {
        $pass = true;
    } elseif ($status_filter === 'NEW') {
        $pass = ($status === 'NEW');
    } else {
        $pass = ($status === $status_filter);
    }

    if (!$pass) continue;

    $group['status'] = $status;
    $group['note'] = $note;
    $filtered_groups[$key] = $group;
}

$book_details = [];
$book_ids = [];
foreach ($filtered_groups as $group) {
    foreach ($group['book_ids'] as $book_id) {
        $book_ids[$book_id] = true;
    }
}
$book_ids = array_keys($book_ids);

if ($book_ids) {
    $placeholders = [];
    $params = [];
    foreach ($book_ids as $i => $id) {
        $ph = ':b' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $id;
    }

    $sql = "
        SELECT b.book_id, b.title, b.subtitle, b.series, b.notes, b.copy_count,
               b.year_published, b.isbn, b.cover_image, b.cover_thumb,
               b.publisher_id, p.name AS publisher_name,
               b.placement_id, pl.bookcase_no, pl.shelf_no,
               (
                   SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR '; ')
                   FROM Books_Subjects bs
                   JOIN Subjects s ON s.subject_id = bs.subject_id
                   WHERE bs.book_id = b.book_id
               ) AS subjects,
               ba.author_id, ba.author_ord,
               a.sort_name, a.name, a.first_name, a.last_name
        FROM Books b
        LEFT JOIN Publishers p ON p.publisher_id = b.publisher_id
        LEFT JOIN Placement pl ON pl.placement_id = b.placement_id
        JOIN Books_Authors ba ON ba.book_id = b.book_id
        JOIN Authors a ON a.author_id = ba.author_id
        WHERE b.book_id IN (" . implode(',', $placeholders) . ")
        ORDER BY b.book_id, ba.author_ord
    ";
    $st = $pdo->prepare($sql);
    foreach ($params as $ph => $val) {
        $st->bindValue($ph, $val, PDO::PARAM_INT);
    }
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $format_author = static function (array $row): string {
        $sort_name = trim((string)($row['sort_name'] ?? ''));
        if ($sort_name !== '') return $sort_name;
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') return $name;
        $last = trim((string)($row['last_name'] ?? ''));
        $first = trim((string)($row['first_name'] ?? ''));
        if ($last !== '' && $first !== '') return $last . ', ' . $first;
        if ($last !== '') return $last;
        if ($first !== '') return $first;
        return '(unknown author)';
    };

    foreach ($rows as $row) {
        $book_id = (int)$row['book_id'];
        if (!isset($book_details[$book_id])) {
            $book_details[$book_id] = [
                'book_id' => $book_id,
                'title' => (string)($row['title'] ?? ''),
                'subtitle' => (string)($row['subtitle'] ?? ''),
                'series' => (string)($row['series'] ?? ''),
                'notes' => (string)($row['notes'] ?? ''),
                'copy_count' => (int)($row['copy_count'] ?? 1),
                'year_published' => $row['year_published'] ?? null,
                'isbn' => (string)($row['isbn'] ?? ''),
                'cover_image' => (string)($row['cover_image'] ?? ''),
                'cover_thumb' => (string)($row['cover_thumb'] ?? ''),
                'publisher_name' => (string)($row['publisher_name'] ?? ''),
                'placement_id' => $row['placement_id'] ?? null,
                'bookcase_no' => $row['bookcase_no'] ?? null,
                'shelf_no' => $row['shelf_no'] ?? null,
                'subjects' => (string)($row['subjects'] ?? ''),
                'authors' => [],
            ];
        }
        $book_details[$book_id]['authors'][] = $format_author($row);
    }
}

$location_display = static function (array $book): string {
    $bookcase = $book['bookcase_no'] ?? null;
    $shelf = $book['shelf_no'] ?? null;
    if ($bookcase !== null && $shelf !== null) {
        return $bookcase . '/' . $shelf;
    }
    $placement_id = $book['placement_id'] ?? null;
    if ($placement_id !== null && $placement_id !== '') return (string)$placement_id;
    return '';
};

$group_sort_year = static function (array $group) use ($book_details): int {
    $years = [];
    foreach ($group['book_ids'] as $book_id) {
        if (!isset($book_details[$book_id])) continue;
        $year = $book_details[$book_id]['year_published'] ?? null;
        if ($year === null || $year === '') continue;
        $years[] = (int)$year;
    }
    if (!$years) return 0;
    return max($years);
};

$sorted_groups = array_values($filtered_groups);
usort($sorted_groups, static function (array $a, array $b) use ($group_sort_year) {
    $count_a = count($a['book_ids']);
    $count_b = count($b['book_ids']);
    $bucket_a = $count_a >= 3 ? 0 : 1;
    $bucket_b = $count_b >= 3 ? 0 : 1;
    if ($bucket_a !== $bucket_b) return $bucket_a <=> $bucket_b;
    $year_a = $group_sort_year($a);
    $year_b = $group_sort_year($b);
    if ($year_a !== $year_b) return $year_a <=> $year_b;
    return strcmp($a['dup_key'], $b['dup_key']);
});

$saved = isset($_GET['saved']) && (string)$_GET['saved'] !== '';
$merged = isset($_GET['merged']) && (string)$_GET['merged'] !== '';
$export_csv = isset($_GET['export']) && (string)$_GET['export'] === '1';

if ($export_csv) {
    $os_family = PHP_OS_FAMILY;
    if (strcasecmp($os_family, 'Darwin') === 0) {
        $os_family = 'macos';
    }
    $os_label = preg_replace('/[^A-Za-z0-9_-]+/', '', $os_family);
    if ($os_label === '') $os_label = 'unknown';
    $ts_label = gmdate('Ymd_His');
    $status_label = preg_replace('/[^A-Za-z0-9_-]+/', '', strtoupper($status_filter));
    if ($status_label === '') $status_label = 'UNKNOWN';
    $filename = 'duplicate_candidates_' . $status_label . '_' . $os_label . '_' . $ts_label . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'group_index',
        'group_size',
        'group_dup_key',
        'group_status',
        'group_note',
        'group_title_display',
        'group_authors_display',
        'group_publishers',
        'book_id',
        'book_title',
        'book_subtitle',
        'book_authors',
        'publisher_name',
        'year_published',
        'isbn',
        'copy_count',
        'location',
    ]);
    $group_index = 0;
    foreach ($sorted_groups as $group) {
        $group_index++;
        $book_ids = $group['book_ids'];
        $first_id = $book_ids[0] ?? null;
        $first_book = $first_id !== null && isset($book_details[$first_id]) ? $book_details[$first_id] : null;
        $group_authors = $first_book ? implode('; ', $first_book['authors']) : '';
        $group_title = $first_book
            ? $format_title_display($first_book['title'], $first_book['subtitle'] ?? '')
            : $format_title_display($group['title'], $group['subtitle'] ?? '');
        $pub_pairs = [];
        foreach ($book_ids as $book_id) {
            $book = $book_details[$book_id] ?? null;
            if (!$book) continue;
            $pub = trim((string)($book['publisher_name'] ?? ''));
            if ($pub === '') continue;
            $year = $book['year_published'] ?? null;
            $label = $pub;
            if ($year !== null && $year !== '') $label .= ' (' . $year . ')';
            $pub_pairs[$label] = true;
        }
        $group_publishers = implode(', ', array_keys($pub_pairs));
        foreach ($book_ids as $book_id) {
            $book = $book_details[$book_id] ?? null;
            if (!$book) continue;
            fputcsv($out, [
                $group_index,
                count($book_ids),
                $group['dup_key'],
                $group['status'],
                $group['note'] ?? '',
                $group_title,
                $group_authors,
                $group_publishers,
                $book['book_id'],
                $book['title'],
                $book['subtitle'] ?? '',
                implode('; ', $book['authors']),
                $book['publisher_name'] ?? '',
                $book['year_published'] ?? '',
                $book['isbn'] ?? '',
                $book['copy_count'] ?? 1,
                $location_display($book),
            ]);
        }
    }
    fclose($out);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<meta charset="utf-8">
<title>Duplicate candidates</title>
<style>
  :root {
    --bg: <?php echo htmlspecialchars($pref_bg, ENT_QUOTES, 'UTF-8'); ?>;
    --fg: <?php echo htmlspecialchars($pref_fg, ENT_QUOTES, 'UTF-8'); ?>;
  }
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; margin: 2rem; color: var(--fg); background: var(--bg); }
  a { color: var(--fg); }
  h1 { margin-bottom: .2rem; }
  .filters { margin: 1rem 0 1.5rem; }
  .filters a { margin-right: .75rem; text-decoration: none; }
  .filters .active { font-weight: 700; text-decoration: underline; }
  .notice { margin: .75rem 0; padding: .5rem .75rem; background: rgba(60, 160, 60, 0.15); border: 1px solid rgba(60, 160, 60, 0.4); border-radius: 6px; }
  .error { margin: .75rem 0; padding: .5rem .75rem; background: rgba(200, 60, 60, 0.15); border: 1px solid rgba(200, 60, 60, 0.4); border-radius: 6px; }
  .group { margin: 1.5rem 0; padding: 1rem; border: 1px solid rgba(0,0,0,0.2); border-radius: 10px; }
  .group-header { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1rem; align-items: center; margin-bottom: .75rem; }
  .group-title { font-size: 1.05rem; font-weight: 700; }
  .group-meta { color: #555; font-size: .95rem; }
  .group form { display: grid; gap: .5rem; }
  .form-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
  .form-row label { display: inline-flex; align-items: center; gap: .4rem; }
  .group select, .group textarea { width: 100%; max-width: 520px; }
  .group textarea { min-height: 60px; }
  table { width: 100%; border-collapse: collapse; margin-top: .75rem; }
  th, td { padding: .4rem .5rem; border-bottom: 1px solid rgba(0,0,0,0.1); text-align: left; vertical-align: top; }
  th { background: rgba(0,0,0,0.05); font-weight: 600; }
  .muted { color: rgba(0,0,0,0.6); }
  .actions { margin-bottom: .75rem; }
  .merge-controls { margin: .75rem 0; display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
  .merge-btn { background: #8a1f1f; color: #fff; border: 1px solid #6d1717; border-radius: 6px; padding: .45rem .7rem; cursor: pointer; }
  .merge-btn[disabled] { opacity: .55; cursor: not-allowed; }
  .system-note { margin-top: .4rem; font-size: .92rem; }
  .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 1000; }
  .modal-backdrop.open { display: flex; }
  .modal { width: min(860px, 95vw); max-height: 88vh; overflow: auto; background: #fff; color: #111; border-radius: 10px; border: 1px solid rgba(0,0,0,0.2); box-shadow: 0 12px 28px rgba(0,0,0,0.25); }
  .modal h2 { margin: 0; font-size: 1.1rem; }
  .modal-header, .modal-footer { padding: .8rem 1rem; border-bottom: 1px solid rgba(0,0,0,0.12); }
  .modal-footer { border-bottom: 0; border-top: 1px solid rgba(0,0,0,0.12); display: flex; justify-content: flex-end; gap: .5rem; }
  .modal-body { padding: .8rem 1rem; }
  .modal pre { white-space: pre-wrap; background: #f7f7f7; border: 1px solid rgba(0,0,0,0.1); border-radius: 8px; padding: .7rem; margin: .7rem 0; }
  .modal .row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
  .modal input[type="text"] { padding: .4rem .5rem; border: 1px solid rgba(0,0,0,0.25); border-radius: 6px; min-width: 160px; }
  .modal button { padding: .45rem .7rem; border: 1px solid rgba(0,0,0,0.25); border-radius: 6px; cursor: pointer; }
  .modal button.danger { background: #8a1f1f; color: #fff; border-color: #6d1717; }
</style>

<h1>Duplicate candidates</h1>
<div class="actions"><a href="./">Back to app</a></div>

<?php if ($saved): ?>
  <div class="notice">Review saved.</div>
<?php endif; ?>

<?php if ($merged): ?>
  <div class="notice">Selected duplicates were merged as copies.</div>
<?php endif; ?>

<?php if ($save_error): ?>
  <div class="error"><?php echo htmlspecialchars($save_error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="filters">
  Show:
  <?php foreach ($allowed_status as $st): ?>
    <?php $active = $status_filter === $st ? 'active' : ''; ?>
    <a class="<?php echo $active; ?>" href="duplicate_candidates.php?status=<?php echo urlencode($st); ?>"><?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></a>
  <?php endforeach; ?>
  · <a href="duplicate_candidates.php?status=<?php echo urlencode($status_filter); ?>&export=1">Export CSV</a>
</div>

<?php if (!$sorted_groups): ?>
  <p class="muted">No duplicate groups found for this filter.</p>
<?php else: ?>
  <?php foreach ($sorted_groups as $group): ?>
    <?php
      $book_ids = $group['book_ids'];
      $first_id = $book_ids[0] ?? null;
      $first_book = $first_id !== null && isset($book_details[$first_id]) ? $book_details[$first_id] : null;
      $authors_display = $first_book ? implode('; ', $first_book['authors']) : '';
      $title_display = $first_book
        ? $format_title_display($first_book['title'], $first_book['subtitle'] ?? '')
        : $format_title_display($group['title'], $group['subtitle'] ?? '');
      $count = count($book_ids);
      $pub_pairs = [];
      foreach ($book_ids as $book_id) {
        $book = $book_details[$book_id] ?? null;
        if (!$book) continue;
        $pub = trim((string)($book['publisher_name'] ?? ''));
        if ($pub === '') continue;
        $year = $book['year_published'] ?? null;
        $label = $pub;
        if ($year !== null && $year !== '') $label .= ' (' . $year . ')';
        $pub_pairs[$label] = true;
      }
      $pub_list = implode(', ', array_keys($pub_pairs));
      $is_merged = strtoupper((string)$group['status']) === 'MERGED';
      $group_payload_books = [];
      foreach ($book_ids as $book_id) {
        $book = $book_details[$book_id] ?? null;
        if (!$book) continue;
        $group_payload_books[] = [
          'book_id' => (int)$book['book_id'],
          'title' => (string)$book['title'],
          'subtitle' => (string)($book['subtitle'] ?? ''),
          'authors' => implode('; ', $book['authors']),
          'publisher' => (string)($book['publisher_name'] ?? ''),
          'year_published' => (string)($book['year_published'] ?? ''),
          'isbn' => (string)($book['isbn'] ?? ''),
          'series' => (string)($book['series'] ?? ''),
          'subjects' => (string)($book['subjects'] ?? ''),
          'notes' => (string)($book['notes'] ?? ''),
          'location' => (string)$location_display($book),
          'copy_count' => (int)($book['copy_count'] ?? 1),
        ];
      }
      $group_payload = [
        'dupKey' => (string)$group['dup_key'],
        'status' => (string)$group['status'],
        'books' => $group_payload_books,
      ];
      $group_payload_json = htmlspecialchars((string)json_encode($group_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    ?>
    <section class="group js-dup-group" data-group-json="<?php echo $group_payload_json; ?>">
      <div class="group-header">
        <div>
          <div class="group-title"><?php echo htmlspecialchars($title_display, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="group-meta"><?php echo htmlspecialchars($authors_display, ENT_QUOTES, 'UTF-8'); ?> · <?php echo $count; ?> books</div>
        </div>
        <div class="group-meta">Key: <?php echo htmlspecialchars($group['dup_key'], ENT_QUOTES, 'UTF-8'); ?></div>
      </div>

      <form method="post">
        <input type="hidden" name="dup_key" value="<?php echo htmlspecialchars($group['dup_key'], ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-row">
          <label>
            Status
            <select name="status" <?php echo $is_merged ? 'disabled' : ''; ?>>
              <?php foreach (['NEW','IGNORE','CONFIRMED'] as $opt): ?>
                <option value="<?php echo $opt; ?>" <?php echo $group['status'] === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" <?php echo $is_merged ? 'disabled' : ''; ?>>Save</button>
        </div>
        <label>
          Note (optional)
          <textarea name="note" placeholder="Add context or decision rationale..." <?php echo $is_merged ? 'readonly' : ''; ?>><?php echo htmlspecialchars((string)($group['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <?php if ($is_merged): ?>
          <div class="muted system-note">MERGED is system-managed and read-only.</div>
        <?php endif; ?>
      </form>

      <div class="merge-controls">
        <button
          type="button"
          class="merge-btn"
          data-action="merge"
          <?php echo $is_merged ? 'disabled' : ''; ?>
        >Merge selected as copies</button>
        <span class="merge-hint muted" data-merge-hint>Select at least two rows, then choose one selected row as Master.</span>
      </div>

      <?php if ($pub_list !== ''): ?>
        <div class="muted">Publishers: <?php echo htmlspecialchars($pub_list, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <table>
        <thead>
          <tr>
            <th>Include</th>
            <th>Master</th>
            <th>Book ID</th>
            <th>Title</th>
            <th>Authors</th>
            <th>Publisher</th>
            <th>Year</th>
            <th>ISBN</th>
            <th>Copies</th>
            <th>Location</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($book_ids as $book_id): ?>
            <?php $book = $book_details[$book_id] ?? null; ?>
            <?php if (!$book) continue; ?>
            <tr>
              <td>
                <input
                  type="checkbox"
                  data-role="include-select"
                  value="<?php echo (int)$book['book_id']; ?>"
                  checked
                  <?php echo $is_merged ? 'disabled' : ''; ?>
                >
              </td>
              <td>
                <input
                  type="radio"
                  data-role="master-select"
                  name="master_<?php echo md5($group['dup_key']); ?>"
                  value="<?php echo (int)$book['book_id']; ?>"
                  <?php echo $is_merged ? 'disabled' : ''; ?>
                >
              </td>
              <td><?php echo (int)$book['book_id']; ?></td>
              <td><?php echo htmlspecialchars($format_title_display($book['title'], $book['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars(implode('; ', $book['authors']), ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($book['publisher_name'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars((string)($book['year_published'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($book['isbn'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo (int)($book['copy_count'] ?? 1); ?></td>
              <td><?php echo htmlspecialchars($location_display($book), ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

<div class="modal-backdrop" id="mergeModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mergeModalTitle">
    <div class="modal-header">
      <h2 id="mergeModalTitle">Confirm duplicate merge</h2>
    </div>
    <div class="modal-body">
      <pre id="mergeModalText"></pre>
      <div class="row">
        <label for="mergeConfirmInput"><strong>Type MERGE to continue:</strong></label>
        <input type="text" id="mergeConfirmInput" autocomplete="off" spellcheck="false">
      </div>
      <div class="error" id="mergeModalError" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button type="button" id="mergeCancelBtn">Cancel</button>
      <button type="button" class="danger" id="mergeConfirmBtn" disabled>Merge</button>
    </div>
  </div>
</div>

<script>
(function () {
  const norm = (v) => String(v || "").trim().toLowerCase().replace(/\s+/g, " ");
  const normList = (v) => {
    return String(v || "")
      .split(";")
      .map((x) => norm(x))
      .filter(Boolean)
      .sort()
      .join(";");
  };

  const groups = Array.from(document.querySelectorAll(".js-dup-group"));
  const modal = document.getElementById("mergeModal");
  const modalText = document.getElementById("mergeModalText");
  const modalError = document.getElementById("mergeModalError");
  const confirmInput = document.getElementById("mergeConfirmInput");
  const confirmBtn = document.getElementById("mergeConfirmBtn");
  const cancelBtn = document.getElementById("mergeCancelBtn");

  let pending = null;

  function computeDiffs(master, others) {
    const fields = [
      ["title", "title"],
      ["subtitle", "subtitle"],
      ["authors", "authors (normalized list)"],
      ["publisher", "publisher"],
      ["year_published", "year_published"],
      ["isbn", "ISBN"],
      ["series", "series"],
      ["subjects", "subjects (normalized list)"],
      ["notes", "notes"],
      ["location", "placement/location (informational)"],
    ];
    const lines = [];
    let hardDiff = false;

    others.forEach((other) => {
      fields.forEach(([key, label]) => {
        const m = (key === "authors" || key === "subjects") ? normList(master[key]) : norm(master[key]);
        const o = (key === "authors" || key === "subjects") ? normList(other[key]) : norm(other[key]);
        if (m === o) return;
        if (key !== "location") hardDiff = true;
        const info = key === "location" ? " (informational)" : "";
        lines.push(`- ${label}${info}: "${master[key] || "—"}" vs "${other[key] || "—"}" (Book ID ${other.book_id})`);
      });
    });

    return { hardDiff, lines };
  }

  function setGroupHint(groupEl, text) {
    const hint = groupEl.querySelector("[data-merge-hint]");
    if (hint) hint.textContent = text;
  }

  function updateGroupState(groupEl) {
    const payload = JSON.parse(groupEl.dataset.groupJson || "{}");
    const status = String(payload.status || "NEW").toUpperCase();
    const radios = Array.from(groupEl.querySelectorAll("[data-role='master-select']"));
    const includes = Array.from(groupEl.querySelectorAll("[data-role='include-select']"));
    const includedIds = new Set(
      includes.filter((c) => c.checked).map((c) => Number(c.value))
    );

    radios.forEach((radio) => {
      const bookId = Number(radio.value);
      const enabled = includedIds.has(bookId) && status !== "MERGED";
      radio.disabled = !enabled;
      if (!enabled) radio.checked = false;
    });

    const selected = radios.filter((r) => r.checked);
    const mergeBtn = groupEl.querySelector("[data-action='merge']");
    const selectedMasterId = selected.length === 1 ? Number(selected[0].value) : null;
    const books = Array.isArray(payload.books) ? payload.books : [];
    const selectedBooks = books.filter((b) => includedIds.has(Number(b.book_id)));
    const nonMasterCount = selectedMasterId
      ? selectedBooks.filter((b) => Number(b.book_id) !== selectedMasterId).length
      : 0;

    let reason = "Select at least two rows, then choose one selected row as Master.";
    let canMerge = false;
    if (status === "IGNORE") {
      reason = "Merge is disabled for IGNORE groups.";
    } else if (status === "MERGED") {
      reason = "MERGED groups are system-managed.";
    } else if (selectedBooks.length < 2) {
      reason = "Select at least two rows to merge.";
    } else if (selected.length !== 1) {
      reason = "Select exactly one Master.";
    } else if (nonMasterCount < 1) {
      reason = "Selected rows must include at least one non-master duplicate.";
    } else {
      canMerge = true;
      reason = "Ready to merge.";
    }

    if (mergeBtn) mergeBtn.disabled = !canMerge;
    setGroupHint(groupEl, reason);

    return {
      canMerge,
      payload,
      selectedMasterId,
      includedIds,
    };
  }

  function openModal(data) {
    pending = data;
    modalText.textContent = data.message;
    modalError.style.display = "none";
    modalError.textContent = "";
    confirmInput.value = "";
    confirmBtn.disabled = true;
    modal.classList.add("open");
    modal.setAttribute("aria-hidden", "false");
    confirmInput.focus();
  }

  function closeModal() {
    pending = null;
    modal.classList.remove("open");
    modal.setAttribute("aria-hidden", "true");
  }

  groups.forEach((groupEl) => {
    const radios = Array.from(groupEl.querySelectorAll("[data-role='master-select']"));
    const includes = Array.from(groupEl.querySelectorAll("[data-role='include-select']"));
    radios.forEach((radio) => radio.addEventListener("change", () => updateGroupState(groupEl)));
    includes.forEach((checkbox) => checkbox.addEventListener("change", () => updateGroupState(groupEl)));
    updateGroupState(groupEl);

    const mergeBtn = groupEl.querySelector("[data-action='merge']");
    if (!mergeBtn) return;
    mergeBtn.addEventListener("click", () => {
      const state = updateGroupState(groupEl);
      if (!state.canMerge) return;
      const books = Array.isArray(state.payload.books) ? state.payload.books : [];
      const selectedBooks = books.filter((b) => state.includedIds.has(Number(b.book_id)));
      const master = selectedBooks.find((b) => Number(b.book_id) === state.selectedMasterId);
      if (!master) return;
      const others = selectedBooks.filter((b) => Number(b.book_id) !== state.selectedMasterId);
      if (!others.length) return;

      const otherIds = others.map((b) => Number(b.book_id)).join(", ");
      const diff = computeDiffs(master, others);

      let text = "";
      if (!diff.hardDiff) {
        text += "The selected records appear identical based on catalog data.\n\n";
        text += `This will keep Book ID ${master.book_id} as master, merge included BookCopies rows/items into it, and remove Book IDs ${otherIds}.\n\n`;
        if (diff.lines.length) {
          text += "Informational differences:\n";
          text += diff.lines.join("\n") + "\n\n";
        }
        text += "Type MERGE to continue or press Cancel.";
      } else {
        text += "Warning: selected records are not fully identical.\n\n";
        text += "Differences found:\n";
        text += diff.lines.join("\n") + "\n\n";
        text += "These may represent different editions, translations, bindings, conditions, dedications, or otherwise distinct copies.\n\n";
        text += "Only merge if these are truly identical physical copies.\n\n";
        text += "Type MERGE to continue or press Cancel.";
      }

      openModal({
        masterBookId: Number(master.book_id),
        duplicateBookIds: others.map((b) => Number(b.book_id)),
        message: text,
      });
    });
  });

  confirmInput.addEventListener("input", () => {
    confirmBtn.disabled = confirmInput.value !== "MERGE";
    modalError.style.display = "none";
  });

  confirmInput.addEventListener("keydown", (ev) => {
    if (ev.key !== "Enter") return;
    if (confirmInput.value !== "MERGE") {
      ev.preventDefault();
      return;
    }
    ev.preventDefault();
    confirmBtn.click();
  });

  cancelBtn.addEventListener("click", closeModal);
  modal.addEventListener("click", (ev) => {
    if (ev.target === modal) closeModal();
  });
  document.addEventListener("keydown", (ev) => {
    if (ev.key === "Escape" && modal.classList.contains("open")) closeModal();
  });

  confirmBtn.addEventListener("click", async () => {
    if (!pending || confirmInput.value !== "MERGE") return;
    confirmBtn.disabled = true;
    cancelBtn.disabled = true;
    try {
      const res = await fetch("merge_duplicate_books.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({
          masterBookId: pending.masterBookId,
          duplicateBookIds: pending.duplicateBookIds,
          confirm: "MERGE",
        }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || json.ok === false) {
        throw new Error(json.error || `HTTP ${res.status}`);
      }
      const currentUrl = new URL(window.location.href);
      currentUrl.searchParams.set("status", currentUrl.searchParams.get("status") || "NEW");
      currentUrl.searchParams.set("merged", "1");
      window.location.href = currentUrl.toString();
    } catch (err) {
      modalError.textContent = err && err.message ? err.message : "Merge failed.";
      modalError.style.display = "block";
      confirmBtn.disabled = confirmInput.value !== "MERGE";
      cancelBtn.disabled = false;
    }
  });
})();
</script>
