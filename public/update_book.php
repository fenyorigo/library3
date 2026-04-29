<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

// Never leak warnings/notices into JSON
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_fail('Method Not Allowed', 405);
    }

    $is_valid_date = function (?string $val): bool {
        if ($val === null || $val === '') return true;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return false;
        $dt = DateTime::createFromFormat('Y-m-d', $val);
        return $dt && $dt->format('Y-m-d') === $val;
    };

    $pdo = pdo();
    $d   = json_in(); // <- from functions.php

    // Accept either id or book_id
    $id = (int)($d['id'] ?? $d['book_id'] ?? 0);
    if ($id <= 0) {
        json_fail('Missing or invalid book id', 400);
    }

    // Whitelisted fields from UI
    $allowed = [
        'title',
        'subtitle',
        'series',
        'language',
        'copy_count',
        'year_published',
        'isbn',
        'lccn',
        'notes',
        'loaned_to',
        'loaned_date',
        'placement_id',
    ];

    $author_key_present = array_key_exists('authors', $d);
    $subjects_key_present = array_key_exists('subjects', $d);
    $copies_key_present = array_key_exists('copies', $d) && is_array($d['copies']);
    $authors_is_hu = array_key_exists('authors_is_hungarian', $d)
        ? (int)!!$d['authors_is_hungarian']
        : null;

    if (array_key_exists('loaned_to', $d)) {
        $lt = N($d['loaned_to'] ?? null);
        if ($lt === null) {
            $d['loaned_date'] = null;
        }
    }

    // Init sets/params FIRST
    $sets   = [];
    $params = [':id' => $id];

    // Core fields
    foreach ($allowed as $col) {
        if (array_key_exists($col, $d)) {
            if ($col === 'year_published') {
                // allow null or int
                $val = $d[$col];
                $val = ($val === '' || $val === null) ? null : (int)$val;
            } elseif ($col === 'language') {
                $val = normalize_book_language((string)$d[$col]);
            } elseif ($col === 'copy_count') {
                $val = (int)$d[$col];
                if ($val < 1) $val = 1;
            } elseif ($col === 'loaned_date') {
                $val = N($d[$col]);
                if (!$is_valid_date($val)) {
                    json_fail('Invalid loaned_date (expected YYYY-MM-DD)', 400);
                }
            } else {
                $val = N($d[$col]);
            }
            $sets[]          = " $col = :$col ";
            $params[":$col"] = $val;
        }
    }

    /*
     * Publisher updates (single unified block):
     * - If client sends publisher_id (numeric), we set publisher_id to that.
     * - Else if client sends publisher (name), resolve/create and set publisher_id.
     * - If client explicitly sends publisher_id === null OR publisher === '' (after trim),
     *   we clear publisher_id (set to NULL).
     * - If neither key present, we leave publisher_id unchanged.
     */
    $publisher_key_present = array_key_exists('publisher_id', $d) || array_key_exists('publisher', $d);
    $placement_key_present = array_key_exists('placement', $d) || array_key_exists('placement_id', $d);

    if ($publisher_key_present) {
        $final_pub_id = null;

        // Prefer explicit numeric publisher_id if provided and > 0
        if (array_key_exists('publisher_id', $d) && $d['publisher_id'] !== '' && $d['publisher_id'] !== null) {
            $pub_id_in = (int)$d['publisher_id'];
            if ($pub_id_in > 0) {
                $final_pub_id = $pub_id_in;
            } else {
                $final_pub_id = null; // explicit clear if 0/invalid
            }
        }

        // If no valid publisher_id but a publisher name is provided → resolve/create
        if ($final_pub_id === null && array_key_exists('publisher', $d)) {
            $pub_name_in = N($d['publisher']);
            if ($pub_name_in !== null) {
                $final_pub_id = getPublisherId($pdo, $pub_name_in); // may create
            } else {
                $final_pub_id = null; // explicit clear if empty string sent
            }
        }

        $sets[]                   = " publisher_id = :publisher_id ";
        $params[':publisher_id']  = $final_pub_id; // can be null
    }

    if ($placement_key_present) {
        $placement_id = null;
        if (array_key_exists('placement_id', $d) && $d['placement_id'] !== '' && $d['placement_id'] !== null) {
            $pid = (int)$d['placement_id'];
            $placement_id = ($pid > 0) ? $pid : null;
        } elseif (array_key_exists('placement', $d) && is_array($d['placement'])) {
            $placement_id = getOrCreatePlacementId($pdo, $d['placement']);
        }
        $sets[] = " placement_id = :placement_id ";
        $params[':placement_id'] = $placement_id;
    }

    if (!$sets && !$author_key_present && !$subjects_key_present && !$copies_key_present) {
        json_fail('No updatable fields provided', 400);
    }

    $own_tx = false;
    if (($author_key_present || $subjects_key_present || $copies_key_present) && !$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $own_tx = true;
    }

    if ($sets) {
        $sql = "UPDATE Books SET " . implode(', ', $sets) . " WHERE book_id = :id";
        $st  = $pdo->prepare($sql);
        $st->execute($params);
    }

    if ($author_key_present) {
        $authors_csv = N($d['authors'] ?? null);
        $pdo->prepare('DELETE FROM Books_Authors WHERE book_id = ?')->execute([$id]);
        if ($authors_csv) {
            attachAuthorsToBook($pdo, $id, $authors_csv, $authors_is_hu);
        }
    }

    if ($subjects_key_present) {
        $subjects_csv = N($d['subjects'] ?? null);
        $pdo->prepare('DELETE FROM Books_Subjects WHERE book_id = ?')->execute([$id]);
        if ($subjects_csv) {
            $parts = preg_split('/[;,]+/', $subjects_csv) ?: [];
            $seen = [];
            foreach ($parts as $part) {
                $name = trim($part);
                if ($name === '') continue;
                $key = strtolower($name);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $sid = getOrCreateIdByName($pdo, 'Subjects', 'subject_id', 'name', $name);
                if ($sid) {
                    $pdo->prepare('INSERT INTO Books_Subjects (book_id, subject_id) VALUES (?, ?)')
                        ->execute([$id, $sid]);
                }
            }
        }
    }

    if ($copies_key_present && bookcopies_table_exists($pdo)) {
        $saved_copies = replace_book_copies($pdo, $id, $d['copies']);
        sync_book_copy_derived_fields($pdo, $id, $saved_copies);
    } elseif ($placement_key_present || array_key_exists('copy_count', $d)) {
        $physical_location = null;
        if (array_key_exists('placement', $d) && is_array($d['placement'])) {
            $physical_location = format_physical_location_from_placement(
                isset($d['placement']['bookcase_no']) ? (int)$d['placement']['bookcase_no'] : null,
                isset($d['placement']['shelf_no']) ? (int)$d['placement']['shelf_no'] : null
            );
        }
        $copy_count = array_key_exists('copy_count', $d) ? max(1, (int)$d['copy_count']) : 1;
        upsert_default_print_copy($pdo, $id, $copy_count, $physical_location);
    }

    if ($authors_is_hu !== null) {
        $pdo->prepare("
            UPDATE Authors a
            JOIN Books_Authors ba ON ba.author_id = a.author_id
            SET a.is_hungarian = :hu
            WHERE ba.book_id = :id
        ")->execute([':hu' => $authors_is_hu, ':id' => $id]);

        $pdo->prepare("
            UPDATE Authors a
            JOIN Books_Authors ba ON ba.author_id = a.author_id
            SET a.name = CASE
                  WHEN a.is_hungarian = 1 THEN TRIM(CONCAT(COALESCE(a.last_name,''),' ',COALESCE(a.first_name,'')))
                  ELSE TRIM(CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,'')))
                END,
                a.sort_name = CASE
                  WHEN COALESCE(a.first_name,'') = '' THEN TRIM(COALESCE(a.last_name,''))
                  WHEN COALESCE(a.last_name,'') = '' THEN TRIM(COALESCE(a.first_name,''))
                  ELSE TRIM(CONCAT(COALESCE(a.last_name,''), ', ', COALESCE(a.first_name,'')))
                END
            WHERE ba.book_id = :id
        ")->execute([':id' => $id]);
    }

    if ($own_tx) $pdo->commit();

    $affected_rows = isset($st) ? $st->rowCount() : 0;
    $copies = fetch_book_copies($pdo, $id);
    json_out([
        'ok' => true,
        'data' => [
            'id' => $id,
            'affected_rows' => $affected_rows,
            'copies' => $copies,
            'copy_count' => total_book_copy_quantity($copies, 1),
        ],
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_fail($e->getMessage(), 500);
}
