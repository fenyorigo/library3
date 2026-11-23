<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
$me = require_login();
ini_set('display_errors', '0');

function documentation_copy(PDO $db, int $id, array $me, bool $lock = false): array {
    $s = $db->prepare('SELECT bc.*, bc.record_status copy_status, b.record_status FROM BookCopies bc JOIN Books b ON b.book_id=bc.book_id WHERE bc.copy_id=?' . ($lock ? ' FOR UPDATE' : ''));
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r || (($r['record_status'] === 'deleted' || $r['copy_status']==='deleted') && $me['role'] !== 'admin')) throw new RuntimeException('Copy not found', 404);
    return $r;
}

function documentation_upload(array $upload, bool $pdf): array {
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new RuntimeException('Choose a file; upload limit is 25 MB.', 400);
    $tmp = $upload['tmp_name'];
    $size = filesize($tmp);
    if ($size === false || $size < 1 || $size > 25 * 1024 * 1024) throw new RuntimeException('File must be between 1 byte and 25 MB.', 400);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $types = $pdf ? ['application/pdf' => 'pdf'] : ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($types[$mime])) throw new RuntimeException($pdf ? 'Choose a PDF scan.' : 'Choose a JPEG, PNG or WebP photo.', 415);
    if (!$pdf && (!validate_image_magic_bytes($tmp, $mime) || !getimagesize($tmp))) throw new RuntimeException('Invalid image.', 415);
    if ($pdf && file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') throw new RuntimeException('Invalid PDF.', 415);
    $root = copy_documentation_root();
    if (!is_dir($root) && !mkdir($root, 0700, true)) throw new RuntimeException('Unable to create private attachment directory.', 500);
    $path = bin2hex(random_bytes(16)) . '.' . $types[$mime];
    if (!move_uploaded_file($tmp, $root . '/' . $path)) throw new RuntimeException('Unable to save attachment.', 500);
    chmod($root . '/' . $path, 0600);
    return ['file_path'=>$path,'mime_type'=>$mime,'original_filename'=>mb_substr(basename($upload['name'] ?? $path),0,255),'file_size'=>$size,'sha256'=>hash_file('sha256',$root.'/'.$path)];
}

$stored = null;
try {
    $db = pdo();
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET' && ($_GET['action'] ?? '') === 'file') {
        $photo = (int)($_GET['photo_id'] ?? 0);
        if ($photo > 0) {
            $s=$db->prepare('SELECT * FROM BookCopyPhotos WHERE photo_id=?'); $s->execute([$photo]); $row=$s->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Photo not found',404);
            documentation_copy($db,(int)$row['copy_id'],$me);
        } else {
            $id=(int)($_GET['document_id']??0);
            $s=$db->prepare('SELECT d.* FROM AcquisitionDocuments d JOIN BookCopyAcquisitionDocuments l ON l.document_id=d.document_id JOIN BookCopies c ON c.copy_id=l.copy_id JOIN Books b ON b.book_id=c.book_id WHERE d.document_id=? AND ((b.record_status=\'active\' AND c.record_status=\'active\' AND l.record_status=\'active\') OR ?=\'admin\') LIMIT 1');
            $s->execute([$id,$me['role']]); $row=$s->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Document not found',404);
        }
        $file=copy_documentation_file((string)($row['file_path']??''));
        if (!$file) throw new RuntimeException('Attachment is missing from disk',404);
        $name='attachment-'.($photo?:$id).'.'.pathinfo($file,PATHINFO_EXTENSION);
        $inline=$photo>0 && ($_GET['inline']??'')==='1';
        header('Content-Type: '.$row['mime_type']);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.$name.'"');
        header('Content-Length: '.filesize($file));
        session_write_close(); readfile($file); exit;
    }
    $copy_id=(int)(($method==='POST'?$_POST:$_GET)['copy_id']??0);
    $copy=documentation_copy($db,$copy_id,$me);
    if ($method==='GET') {
        $s=$db->prepare('SELECT * FROM BookCopyPhotos WHERE copy_id=? ORDER BY slot_number'); $s->execute([$copy_id]); $photos=$s->fetchAll(PDO::FETCH_ASSOC);
        $s=$db->prepare('SELECT d.*, l.item_reference, l.notes AS link_notes, (SELECT COUNT(*) FROM BookCopyAcquisitionDocuments x WHERE x.document_id=d.document_id) AS linked_copies FROM AcquisitionDocuments d JOIN BookCopyAcquisitionDocuments l ON l.document_id=d.document_id WHERE l.copy_id=? ORDER BY d.document_id');
        $s->execute([$copy_id]);
        json_out(['ok'=>true,'data'=>['copy_id'=>$copy_id,'photos'=>$photos,'documents'=>$s->fetchAll(PDO::FETCH_ASSOC)]]);
        exit;
    }
    if ($method!=='POST') throw new RuntimeException('Method Not Allowed',405);
    require_admin();
    $db->beginTransaction();
    $copy=documentation_copy($db,$copy_id,$me,true);
    if ($copy['record_status']==='deleted' || $copy['copy_status']==='deleted') throw new RuntimeException('Restore this book/copy before changing documentation.',409);
    if ($copy['format']!=='print' || (int)$copy['quantity']!==1) throw new RuntimeException('Documentation requires a saved individual print copy (quantity 1).',409);
    $action=$_POST['action']??'';
    if ($action==='photo') {
        $slot=(int)($_POST['slot_number']??0);
        if ($slot<1 || $slot>10) throw new RuntimeException('Choose photo slot 1–10.',400);
        $s=$db->prepare('SELECT photo_id FROM BookCopyPhotos WHERE copy_id=? AND slot_number=?'); $s->execute([$copy_id,$slot]);
        if ($s->fetchColumn()) throw new RuntimeException('This photo slot is already occupied.',409);
        $stored=documentation_upload($_FILES['file']??[],false);
        $date=trim($_POST['taken_on']??'');
        if ($date!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))) throw new RuntimeException('Invalid photo date.',400);
        $s=$db->prepare('INSERT INTO BookCopyPhotos (copy_id,slot_number,file_path,mime_type,original_filename,file_size,sha256,photo_type,caption,taken_on) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $type=$_POST['photo_type']??'other';
        if (!in_array($type,['front_cover','back_cover','spine','title_page','imprint','map','supplement','condition_detail','other'],true)) throw new RuntimeException('Invalid photo type.',400);
        $s->execute([$copy_id,$slot,...array_values($stored),$type,trim($_POST['caption']??'')?:null,$date?:null]);
    } elseif ($action==='document') {
        $title=trim($_POST['title']??'');
        if ($title==='' || mb_strlen($title)>512) throw new RuntimeException('A document title is required (maximum 512 characters).',400);
        $type=$_POST['document_type']??'invoice';
        if (!in_array($type,['invoice','receipt','seller_description','other'],true)) throw new RuntimeException('Invalid document type.',400);
        if (($_FILES['file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) $stored=documentation_upload($_FILES['file'],true);
        $s=$db->prepare('INSERT INTO AcquisitionDocuments (title,document_type,file_path,mime_type,original_filename,file_size,sha256,document_number,seller,original_location,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$title,$type,...array_values($stored??array_fill(0,5,null)),trim($_POST['document_number']??'')?:null,trim($_POST['seller']??'')?:null,trim($_POST['original_location']??'')?:null,trim($_POST['notes']??'')?:null]);
        $id=(int)$db->lastInsertId();
        $s=$db->prepare('INSERT INTO BookCopyAcquisitionDocuments (copy_id,document_id,item_reference) VALUES (?,?,?)'); $s->execute([$copy_id,$id,trim($_POST['item_reference']??'')?:null]);
    } elseif ($action==='link') {
        $id=(int)($_POST['document_id']??0);
        $s=$db->prepare('SELECT document_id FROM AcquisitionDocuments WHERE document_id=? FOR UPDATE'); $s->execute([$id]);
        if (!$s->fetchColumn()) throw new RuntimeException('Document not found.',404);
        $s=$db->prepare('INSERT INTO BookCopyAcquisitionDocuments (copy_id,document_id,item_reference) VALUES (?,?,?)'); $s->execute([$copy_id,$id,trim($_POST['item_reference']??'')?:null]);
    } elseif ($action==='location') {
        $s=$db->prepare('UPDATE AcquisitionDocuments d JOIN BookCopyAcquisitionDocuments l ON l.document_id=d.document_id SET d.original_location=?, d.updated_at=CURRENT_TIMESTAMP WHERE l.copy_id=? AND d.document_id=?');
        $s->execute([trim($_POST['original_location']??'')?:null,$copy_id,(int)($_POST['document_id']??0)]);
    } else throw new RuntimeException('Unknown action.',400);
    catalog_evidence_sync($db);
    $db->commit(); $stored=null;
    json_out(['ok'=>true,'data'=>['copy_id'=>$copy_id]]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if ($stored) { $file=copy_documentation_file($stored['file_path']); if ($file) unlink($file); }
    $status=(int)$e->getCode();
    if ($e instanceof PDOException) {
        json_fail('Unable to save or load documentation. Check that schema migration 05 is installed; duplicate links and occupied photo slots are not allowed.',409);
    }
    json_fail($status>=400 && $status<500?$e->getMessage():'Unable to process documentation.',$status>=400 && $status<500?$status:500);
}
