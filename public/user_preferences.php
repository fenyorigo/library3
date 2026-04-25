<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');

$me = require_login();
$uid = (int)$me['uid'];

const LOGO_MAX_PX = 180;
const LOGO_MAX_BYTES = 5_000_000;
const MIN_CONTRAST = 4.5;

function hex_to_rgb(string $hex): array {
  $hex = ltrim(trim($hex), '#');
  if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return [];
  return [
    hexdec(substr($hex, 0, 2)),
    hexdec(substr($hex, 2, 2)),
    hexdec(substr($hex, 4, 2)),
  ];
}

function srgb_to_linear(float $c): float {
  $c = $c / 255.0;
  return ($c <= 0.03928) ? ($c / 12.92) : pow(($c + 0.055) / 1.055, 2.4);
}

function contrast_ratio(string $bg, string $fg): float {
  $bg_rgb = hex_to_rgb($bg);
  $fg_rgb = hex_to_rgb($fg);
  if (!$bg_rgb || !$fg_rgb) return 0.0;

  $bg_l = 0.2126 * srgb_to_linear($bg_rgb[0])
       + 0.7152 * srgb_to_linear($bg_rgb[1])
       + 0.0722 * srgb_to_linear($bg_rgb[2]);
  $fg_l = 0.2126 * srgb_to_linear($fg_rgb[0])
       + 0.7152 * srgb_to_linear($fg_rgb[1])
       + 0.0722 * srgb_to_linear($fg_rgb[2]);

  $l1 = max($bg_l, $fg_l);
  $l2 = min($bg_l, $fg_l);
  return ($l1 + 0.05) / ($l2 + 0.05);
}

function read_pref_bool(array $data, string $key): array {
  if (!array_key_exists($key, $data)) return [false, null];
  $val = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
  if ($val === null) {
    json_fail("Invalid {$key} value", 400);
  }
  return [true, $val ? 1 : 0];
}

function ensure_user_assets_dir(int $uid): string {
  $base = __DIR__ . '/user-assets/' . $uid;
  if (!is_dir($base) && !mkdir($base, 0775, true)) {
    throw new RuntimeException('Unable to create user-assets directory');
  }
  return $base;
}

function cleanup_logo_files(string $dir): void {
  foreach (glob($dir . '/logo*.*') ?: [] as $old) {
    @unlink($old);
  }
}

function process_logo_upload(int $uid, array $file): string {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Logo upload failed');
  }
  if (($file['size'] ?? 0) > LOGO_MAX_BYTES) {
    throw new RuntimeException('Logo file too large');
  }

  $tmp_path = $file['tmp_name'];
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($tmp_path);
  $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
  if (!isset($allowed[$mime])) {
    throw new RuntimeException('Unsupported logo file type');
  }

  if (!class_exists('Imagick')) {
    throw new RuntimeException('Imagick is required for logo resize');
  }

  $dir = ensure_user_assets_dir($uid);
  cleanup_logo_files($dir);

  $ext = $allowed[$mime];
  $stamp = date('YmdHis');
  $suffix = bin2hex(random_bytes(3));
  $dst = $dir . '/logo-' . $stamp . '-' . $suffix . '.' . $ext;

  $img = new Imagick();
  $img->readImage($tmp_path);
  $w = $img->getImageWidth();
  $h = $img->getImageHeight();
  if ($w < 1 || $h < 1) {
    $img->clear(); $img->destroy();
    throw new RuntimeException('Invalid logo image geometry');
  }

  if ($w > LOGO_MAX_PX || $h > LOGO_MAX_PX) {
    $img->thumbnailImage(LOGO_MAX_PX, LOGO_MAX_PX, true);
  }
  $img->stripImage();
  $img->setImageFormat($ext === 'jpg' ? 'jpeg' : $ext);
  if (!$img->writeImage($dst)) {
    $img->clear(); $img->destroy();
    throw new RuntimeException('Failed to save logo');
  }
  $img->clear(); $img->destroy();

  return 'user-assets/' . $uid . '/logo-' . $stamp . '-' . $suffix . '.' . $ext;
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pdo = pdo();
    $prefs = fetch_user_preferences($pdo, $uid);
    json_out([
      'ok' => true,
      'data' => [
        'preferences' => $prefs,
      ],
    ]);
  }

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Method Not Allowed', 405);
  }

  $content_type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
  $data = (strpos($content_type, 'application/json') !== false) ? json_in() : $_POST;

  $bg_set = array_key_exists('bg_color', $data);
  $fg_set = array_key_exists('fg_color', $data);
  $ts_set = array_key_exists('text_size', $data);

  $bg_color = $bg_set ? N($data['bg_color']) : null;
  $fg_color = $fg_set ? N($data['fg_color']) : null;
  $text_size = $ts_set ? N($data['text_size']) : null;
  $per_page_raw = $data['per_page'] ?? null;
  $remove_logo = !empty($data['remove_logo']);

  [$show_cover_set, $show_cover] = read_pref_bool($data, 'show_cover');
  [$show_subtitle_set, $show_subtitle] = read_pref_bool($data, 'show_subtitle');
  [$show_series_set, $show_series] = read_pref_bool($data, 'show_series');
  [$show_is_hungarian_set, $show_is_hungarian] = read_pref_bool($data, 'show_is_hungarian');
  [$show_publisher_set, $show_publisher] = read_pref_bool($data, 'show_publisher');
  [$show_year_set, $show_year] = read_pref_bool($data, 'show_year');
  [$show_copy_count_set, $show_copy_count] = read_pref_bool($data, 'show_copy_count');
  [$show_status_set, $show_status] = read_pref_bool($data, 'show_status');
  [$show_placement_set, $show_placement] = read_pref_bool($data, 'show_placement');
  [$show_isbn_set, $show_isbn] = read_pref_bool($data, 'show_isbn');
  [$show_loaned_to_set, $show_loaned_to] = read_pref_bool($data, 'show_loaned_to');
  [$show_loaned_date_set, $show_loaned_date] = read_pref_bool($data, 'show_loaned_date');
  [$show_subjects_set, $show_subjects] = read_pref_bool($data, 'show_subjects');
  [$show_notes_set, $show_notes] = read_pref_bool($data, 'show_notes');

  $per_page = null;
  if ($per_page_raw !== null && $per_page_raw !== '') {
    $per_page = (int)$per_page_raw;
    if ($per_page < 1 || $per_page > 200) {
      json_fail('Invalid items-per-page value', 400);
    }
  }

  $allowed_sizes = ['small', 'medium', 'large'];
  if ($text_size !== null && !in_array($text_size, $allowed_sizes, true)) {
    json_fail('Invalid text size', 400);
  }

  if ($bg_color !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $bg_color)) {
    json_fail('Invalid background color', 400);
  }
  if ($fg_color !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $fg_color)) {
    json_fail('Invalid foreground color', 400);
  }

  if ($bg_color !== null && $fg_color !== null) {
    $ratio = contrast_ratio($bg_color, $fg_color);
    if ($ratio < MIN_CONTRAST) {
      json_fail('Foreground/background contrast too low', 400);
    }
  }

  $logo_path = null;
  if (!empty($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $logo_path = process_logo_upload($uid, $_FILES['logo']);
  }

  if ($remove_logo) {
    $dir = ensure_user_assets_dir($uid);
    cleanup_logo_files($dir);
    $logo_path = '';
  }

  $pdo = pdo();
  $sql = "INSERT INTO UserPreferences
          (user_id, logo_path, bg_color, fg_color, text_size, per_page,
          show_cover, show_subtitle, show_series, show_is_hungarian, show_publisher,
          show_year, show_copy_count, show_status, show_placement, show_isbn, show_loaned_to,
           show_loaned_date, show_subjects, show_notes, updated_at)
          VALUES (:uid, :logo_ins, :bg_ins, :fg_ins, :ts_ins, :per_ins,
           :show_cover_ins, :show_subtitle_ins, :show_series_ins, :show_is_hungarian_ins, :show_publisher_ins,
           :show_year_ins, :show_copy_count_ins, :show_status_ins, :show_placement_ins, :show_isbn_ins, :show_loaned_to_ins,
           :show_loaned_date_ins, :show_subjects_ins, :show_notes_ins, NOW())
          ON DUPLICATE KEY UPDATE
            logo_path = IF(:logo_set, :logo_upd, logo_path),
            bg_color = IF(:bg_set, :bg_upd, bg_color),
            fg_color = IF(:fg_set, :fg_upd, fg_color),
            text_size = IF(:ts_set, :ts_upd, text_size),
            per_page = IF(:per_set, :per_upd, per_page),
            show_cover = IF(:show_cover_set, :show_cover_upd, show_cover),
            show_subtitle = IF(:show_subtitle_set, :show_subtitle_upd, show_subtitle),
            show_series = IF(:show_series_set, :show_series_upd, show_series),
            show_is_hungarian = IF(:show_is_hungarian_set, :show_is_hungarian_upd, show_is_hungarian),
            show_publisher = IF(:show_publisher_set, :show_publisher_upd, show_publisher),
            show_year = IF(:show_year_set, :show_year_upd, show_year),
            show_copy_count = IF(:show_copy_count_set, :show_copy_count_upd, show_copy_count),
            show_status = IF(:show_status_set, :show_status_upd, show_status),
            show_placement = IF(:show_placement_set, :show_placement_upd, show_placement),
            show_isbn = IF(:show_isbn_set, :show_isbn_upd, show_isbn),
            show_loaned_to = IF(:show_loaned_to_set, :show_loaned_to_upd, show_loaned_to),
            show_loaned_date = IF(:show_loaned_date_set, :show_loaned_date_upd, show_loaned_date),
            show_subjects = IF(:show_subjects_set, :show_subjects_upd, show_subjects),
            show_notes = IF(:show_notes_set, :show_notes_upd, show_notes),
            updated_at = NOW()";

  $logo_set = ($logo_path !== null);
  $logo_for_db = ($logo_path === '') ? null : $logo_path;
  $per_set = ($per_page !== null);
  $defaults = [
    'show_cover' => 1,
    'show_subtitle' => 1,
    'show_series' => 1,
    'show_is_hungarian' => 1,
    'show_publisher' => 1,
    'show_year' => 1,
    'show_copy_count' => 0,
    'show_status' => 1,
    'show_placement' => 1,
    'show_isbn' => 0,
    'show_loaned_to' => 0,
    'show_loaned_date' => 0,
    'show_subjects' => 0,
    'show_notes' => 0,
  ];

  $st = $pdo->prepare($sql);
  $st->execute([
    ':uid' => $uid,
    ':logo_ins' => $logo_for_db,
    ':bg_ins' => $bg_color,
    ':fg_ins' => $fg_color,
    ':ts_ins' => $text_size,
    ':per_ins' => $per_page ?? 25,
    ':show_cover_ins' => $show_cover ?? $defaults['show_cover'],
    ':show_subtitle_ins' => $show_subtitle ?? $defaults['show_subtitle'],
    ':show_series_ins' => $show_series ?? $defaults['show_series'],
    ':show_is_hungarian_ins' => $show_is_hungarian ?? $defaults['show_is_hungarian'],
    ':show_publisher_ins' => $show_publisher ?? $defaults['show_publisher'],
    ':show_year_ins' => $show_year ?? $defaults['show_year'],
    ':show_copy_count_ins' => $show_copy_count ?? $defaults['show_copy_count'],
    ':show_status_ins' => $show_status ?? $defaults['show_status'],
    ':show_placement_ins' => $show_placement ?? $defaults['show_placement'],
    ':show_isbn_ins' => $show_isbn ?? $defaults['show_isbn'],
    ':show_loaned_to_ins' => $show_loaned_to ?? $defaults['show_loaned_to'],
    ':show_loaned_date_ins' => $show_loaned_date ?? $defaults['show_loaned_date'],
    ':show_subjects_ins' => $show_subjects ?? $defaults['show_subjects'],
    ':show_notes_ins' => $show_notes ?? $defaults['show_notes'],
    ':logo_set' => $logo_set ? 1 : 0,
    ':logo_upd' => $logo_for_db,
    ':bg_set' => $bg_set ? 1 : 0,
    ':bg_upd' => $bg_color,
    ':fg_set' => $fg_set ? 1 : 0,
    ':fg_upd' => $fg_color,
    ':ts_set' => $ts_set ? 1 : 0,
    ':ts_upd' => $text_size,
    ':per_set' => $per_set ? 1 : 0,
    ':per_upd' => $per_page,
    ':show_cover_set' => $show_cover_set ? 1 : 0,
    ':show_cover_upd' => $show_cover,
    ':show_subtitle_set' => $show_subtitle_set ? 1 : 0,
    ':show_subtitle_upd' => $show_subtitle,
    ':show_series_set' => $show_series_set ? 1 : 0,
    ':show_series_upd' => $show_series,
    ':show_is_hungarian_set' => $show_is_hungarian_set ? 1 : 0,
    ':show_is_hungarian_upd' => $show_is_hungarian,
    ':show_publisher_set' => $show_publisher_set ? 1 : 0,
    ':show_publisher_upd' => $show_publisher,
    ':show_year_set' => $show_year_set ? 1 : 0,
    ':show_year_upd' => $show_year,
    ':show_copy_count_set' => $show_copy_count_set ? 1 : 0,
    ':show_copy_count_upd' => $show_copy_count,
    ':show_status_set' => $show_status_set ? 1 : 0,
    ':show_status_upd' => $show_status,
    ':show_placement_set' => $show_placement_set ? 1 : 0,
    ':show_placement_upd' => $show_placement,
    ':show_isbn_set' => $show_isbn_set ? 1 : 0,
    ':show_isbn_upd' => $show_isbn,
    ':show_loaned_to_set' => $show_loaned_to_set ? 1 : 0,
    ':show_loaned_to_upd' => $show_loaned_to,
    ':show_loaned_date_set' => $show_loaned_date_set ? 1 : 0,
    ':show_loaned_date_upd' => $show_loaned_date,
    ':show_subjects_set' => $show_subjects_set ? 1 : 0,
    ':show_subjects_upd' => $show_subjects,
    ':show_notes_set' => $show_notes_set ? 1 : 0,
    ':show_notes_upd' => $show_notes,
  ]);

  $prefs = fetch_user_preferences($pdo, $uid);
  json_out([
    'ok' => true,
    'data' => [
      'preferences' => $prefs,
    ],
  ]);
} catch (Throwable $e) {
  json_fail($e->getMessage(), 500);
}
