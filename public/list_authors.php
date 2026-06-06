<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_admin();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = pdo();

    $q = trim($_GET['q'] ?? '');
    $page = (int)($_GET['page'] ?? 1);
    $per = (int)($_GET['per'] ?? 50);
    $sort = trim($_GET['sort'] ?? 'name');
    $dir = strtolower(trim($_GET['dir'] ?? 'asc'));
    if ($page < 1) $page = 1;
    if ($per < 10) $per = 10;
    if ($per > 200) $per = 200;
    if (!in_array($dir, ['asc', 'desc'], true)) $dir = 'asc';
    $offset = ($page - 1) * $per;

    $has_hu = false;
    try {
        $col = $pdo->query("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'Authors'
              AND column_name = 'is_hungarian'
            LIMIT 1
        ")->fetchColumn();
        $has_hu = (bool)$col;
    } catch (Throwable $e) {
        $has_hu = false;
    }

    $has_alias = books_authors_has_author_alias($pdo);
    $books_status_filter = books_table_has_record_status($pdo)
        ? " AND COALESCE(b.record_status, 'active') <> 'deleted'"
        : "";
    $alias_select = $has_alias
        ? "(SELECT GROUP_CONCAT(DISTINCT ba.author_alias ORDER BY ba.author_alias SEPARATOR '; ') FROM Books_Authors ba JOIN Books b ON b.book_id = ba.book_id WHERE ba.author_id = Authors.author_id{$books_status_filter} AND ba.author_alias IS NOT NULL AND ba.author_alias <> '') AS author_aliases"
        : "NULL AS author_aliases";

    if ($has_hu) {
        $display_expr = "COALESCE(NULLIF(TRIM(name),''),
                                 NULLIF(TRIM(CASE WHEN is_hungarian = 1
                                      THEN CONCAT(COALESCE(last_name,''),' ',COALESCE(first_name,''))
                                      ELSE CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) END), ''),
                                 NULLIF(TRIM(sort_name),''))";
        $hu_select = "is_hungarian";
    } else {
        $display_expr = "COALESCE(NULLIF(TRIM(name),''),
                                 NULLIF(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))), ''),
                                 NULLIF(TRIM(sort_name),''))";
        $hu_select = "NULL AS is_hungarian";
    }

    $where = '';
    $params = [];
    if ($q !== '') {
        $alias_where = $has_alias
            ? " OR\n          EXISTS (SELECT 1 FROM Books_Authors ba JOIN Books b ON b.book_id = ba.book_id WHERE ba.author_id = Authors.author_id{$books_status_filter} AND ba.author_alias LIKE ? %s)"
            : "";
        $where = "WHERE (
          name LIKE ? %s OR
          first_name LIKE ? %s OR
          last_name LIKE ? %s OR
          sort_name LIKE ? %s{$alias_where}
        )";
        $like = "%$q%";
        $params = $has_alias
            ? [$like, $like, $like, $like, $like]
            : [$like, $like, $like, $like];
    }


    $where_collations = static function (string $collation) use ($has_alias): array {
        $args = [$collation, $collation, $collation, $collation];
        if ($has_alias) $args[] = $collation;
        return $args;
    };

    $sort_map = [
        'id' => 'author_id',
        'name' => $display_expr,
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'sort_name' => 'sort_name',
    ];
    if (!isset($sort_map[$sort])) $sort = 'name';
    $sort_is_text = in_array($sort, ['name', 'first_name', 'last_name', 'sort_name'], true);

    $count_sql = "SELECT COUNT(*) FROM Authors " . ($where ? sprintf($where, ...$where_collations('')) : '');
    $list_sql = "SELECT author_id, $display_expr AS name,
                        first_name, last_name, sort_name, $hu_select, $alias_select
                 FROM Authors " . ($where ? sprintf($where, ...$where_collations('')) : '') . "
                 ORDER BY {$sort_map[$sort]} $dir
                 LIMIT ? OFFSET ?";

    try {
        if ($where) {
            $count_sql = sprintf("SELECT COUNT(*) FROM Authors $where", ...$where_collations("COLLATE utf8mb4_0900_ai_ci"));
            $order_expr = $sort_is_text
                ? ($sort_map[$sort] . " COLLATE utf8mb4_0900_ai_ci")
                : $sort_map[$sort];
            $list_sql = sprintf("SELECT author_id, $display_expr AS name,
                                        first_name, last_name, sort_name, $hu_select, $alias_select
                                 FROM Authors $where
                                 ORDER BY $order_expr $dir
                                 LIMIT ? OFFSET ?",
                ...$where_collations("COLLATE utf8mb4_0900_ai_ci"));
        } else {
            $count_sql = "SELECT COUNT(*) FROM Authors";
            $order_expr = $sort_is_text
                ? ($sort_map[$sort] . " COLLATE utf8mb4_0900_ai_ci")
                : $sort_map[$sort];
            $list_sql = "SELECT author_id, $display_expr AS name,
                                first_name, last_name, sort_name, $hu_select, $alias_select
                         FROM Authors
                         ORDER BY $order_expr $dir
                         LIMIT ? OFFSET ?";
        }

        $count_st = $pdo->prepare($count_sql);
        if ($where) $count_st->execute($params);
        else $count_st->execute();
        $total = (int)$count_st->fetchColumn();

        $st = $pdo->prepare($list_sql);
        $exec_params = $where ? array_merge($params, [$per, $offset]) : [$per, $offset];
        $st->execute($exec_params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Fallback if collation not supported
        if ($where) {
            $count_sql = sprintf("SELECT COUNT(*) FROM Authors $where", ...$where_collations(""));
            $list_sql = sprintf("SELECT author_id, $display_expr AS name,
                                        first_name, last_name, sort_name, $hu_select, $alias_select
                                 FROM Authors $where
                                 ORDER BY {$sort_map[$sort]} $dir
                                 LIMIT ? OFFSET ?",
                ...$where_collations(""));
        } else {
            $count_sql = "SELECT COUNT(*) FROM Authors";
            $list_sql = "SELECT author_id, $display_expr AS name,
                                first_name, last_name, sort_name, $hu_select, $alias_select
                         FROM Authors
                         ORDER BY {$sort_map[$sort]} $dir
                         LIMIT ? OFFSET ?";
        }

        $count_st = $pdo->prepare($count_sql);
        if ($where) $count_st->execute($params);
        else $count_st->execute();
        $total = (int)$count_st->fetchColumn();

        $st = $pdo->prepare($list_sql);
        $exec_params = $where ? array_merge($params, [$per, $offset]) : [$per, $offset];
        $st->execute($exec_params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    json_out([
        'ok' => true,
        'data' => [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per' => $per,
        ],
    ]);
} catch (Throwable $e) {
    json_fail($e->getMessage(), 500);
}
