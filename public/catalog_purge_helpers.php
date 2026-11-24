<?php
declare(strict_types=1);
require_once __DIR__.'/functions.php';

/** Child tables must be emptied before their parents; never disable FK checks. */
function catalog_purge_tables(): array {
    return ['BookAssessmentSources','BookCopyAcquisitionDocuments','BookCopyPhotos',
        'BookAssessments','PriceObservations','ValuationImports','AcquisitionDocuments',
        'PortablePackageImports','Books_Authors','Books_Subjects','duplicate_review',
        'BookCopies','Books','Authors','Subjects','Publishers','Placement'];
}

function catalog_purge_under(string $path, string $parent): bool {
    return $path===$parent || str_starts_with($path,rtrim($parent,'/').'/');
}

/** Resolve only dedicated media roots; never follow a root symlink. */
function catalog_purge_roots(PDO $db): array {
    $project=dirname(__DIR__);
    $uploads=__DIR__.'/uploads';
    $attachments=copy_documentation_root();
    $roots=[];
    foreach (['uploads'=>$uploads,'attachments'=>$attachments] as $kind=>$path) {
        if (is_link($path)) throw new RuntimeException('Purge refuses a symbolic-link media root: '.$path);
        if (!file_exists($path)) { $roots[$kind]=null; continue; }
        if (!is_dir($path) || !is_readable($path) || !is_writable($path)) throw new RuntimeException('Purge needs a readable, writable media directory: '.$path);
        $root=realpath($path);
        if ($root===false || $root==='/') throw new RuntimeException('Unsafe purge media directory.');
        $roots[$kind]=$root;
    }
    if ($roots['attachments']!==null) {
        $private=$roots['attachments'];
        $public=realpath(__DIR__)?:__DIR__;
        if (catalog_purge_under($private,$public) || catalog_purge_under(realpath($project)?:$project,$private)) throw new RuntimeException('Private attachments must use a dedicated directory outside public/.');
        $home=getenv('HOME');
        if ($home && catalog_purge_under(realpath($home)?:$home,$private)) throw new RuntimeException('Purge refuses a home directory as its attachment root.');
        $mount=$db->query("SELECT setting_value FROM Settings WHERE setting_key='ebook_library_root'")->fetchColumn();
        if (is_string($mount) && trim($mount)!=='') {
            $mount=rtrim($mount,'/');$ebooks=realpath($mount.'/Books')?:$mount.'/Books';
            if (catalog_purge_under($private,$ebooks) || catalog_purge_under($ebooks,$private)) throw new RuntimeException('Private attachment root overlaps the ebook repository.');
        }
        if ($roots['uploads']!==null && (catalog_purge_under($private,$roots['uploads']) || catalog_purge_under($roots['uploads'],$private))) throw new RuntimeException('Purge media directories overlap.');
    }
    return $roots;
}

/** Stage on the same filesystem so a failed DB transaction can restore names. */
function catalog_purge_stage(string $root, string $kind): array {
    $entries=scandir($root);
    if ($entries===false) throw new RuntimeException('Unable to scan purge media directory.');
    $names=[];
    foreach ($entries as $name) {
        if (in_array($name,['.','..','.htaccess','.gitignore'],true)) continue;
        if ($kind==='uploads' && in_array($name,['default-cover.jpg','default_cover.jpg'],true)) continue;
        if (str_starts_with($name,'.catalog-purge-')) throw new RuntimeException('An unfinished purge needs inspection before retrying: '.$root.'/'.$name);
        $names[]=$name;
    }
    $stage=['root'=>$root,'kind'=>$kind,'path'=>null,'journal'=>null,'entries'=>[]];
    if (!$names) return $stage;
    $path=$root.'/.catalog-purge-'.bin2hex(random_bytes(12));
    if (!mkdir($path,0700)) throw new RuntimeException('Unable to prepare purge media.');
    $stage['path']=$path;
    $journal_name='.purge-state.json';
    while (in_array($journal_name,$names,true)) $journal_name='.purge-state-'.bin2hex(random_bytes(8)).'.json';
    $stage['journal']=$path.'/'.$journal_name;
    try {
        $journal=json_encode(['phase'=>'prepared','entries'=>$names],JSON_THROW_ON_ERROR);
        if (file_put_contents($stage['journal'],$journal)===false) throw new RuntimeException('Unable to write purge recovery journal.');
        foreach ($names as $name) {
            if (!@rename($root.'/'.$name,$path.'/'.$name)) throw new RuntimeException('Unable to stage purge file: '.$name);
            $stage['entries'][]=$name;
        }
        return $stage;
    } catch (Throwable $e) {
        catalog_purge_unstage($stage);
        throw $e;
    }
}

function catalog_purge_unstage(array $stage): void {
    if ($stage['path']===null) return;
    foreach (array_reverse($stage['entries']) as $name) {
        $source=$stage['path'].'/'.$name;$target=$stage['root'].'/'.$name;
        if (file_exists($target) || is_link($target) || !@rename($source,$target)) throw new RuntimeException('Purge rollback needs file recovery: '.$source);
    }
    @unlink($stage['journal']);
    @rmdir($stage['path']);
}

function catalog_purge_remove(string $path,array &$counts): void {
    if (is_link($path) || !is_dir($path)) {
        if (!@unlink($path)) throw new RuntimeException('Unable to remove staged media: '.$path);
        $counts['files']++;
        $name=strtolower(basename($path));
        if (preg_match('/^cover-thumb\.[a-z0-9]+$/D',$name)) $counts['thumbs']++;
        elseif (preg_match('/^cover\.[a-z0-9]+$/D',$name)) $counts['covers']++;
        else $counts['other_files']++;
        return;
    }
    $names=scandir($path);
    if ($names===false) throw new RuntimeException('Unable to scan staged media: '.$path);
    foreach ($names as $name) if ($name!=='.' && $name!=='..') catalog_purge_remove($path.'/'.$name,$counts);
    if (!@rmdir($path)) throw new RuntimeException('Unable to remove staged directory: '.$path);
    $counts['dirs']++;
}

/** Called only after admin/CSRF/DELETE confirmation in purge_catalog.php. */
function catalog_purge(PDO $db): array {
    if ($db->inTransaction()) throw new LogicException('Purge must own its transaction.');
    $existing=array_fill_keys($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),true);
    foreach (['Books','BookCopies','Users','Settings'] as $table) if (!isset($existing[$table])) throw new RuntimeException('Missing required table: '.$table);
    $roots=catalog_purge_roots($db);
    $staged=[];$deleted=[];
    try {
        $db->beginTransaction();
        foreach ($roots as $kind=>$root) if ($root!==null) $staged[]=catalog_purge_stage($root,$kind);
        foreach (catalog_purge_tables() as $table) {
            if (!isset($existing[$table])) continue; // Older targets may not yet have migrations 05–07.
            $deleted[$table]=(int)$db->exec('DELETE FROM `'.$table.'`');
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $recovery=[];
        foreach (array_reverse($staged) as $stage) {
            try { catalog_purge_unstage($stage); } catch (Throwable $restore) { $recovery[]=$restore->getMessage(); }
        }
        if ($recovery) throw new RuntimeException($e->getMessage().' '.implode(' ',$recovery),0,$e);
        throw $e;
    }
    $warnings=[];$counts=[];
    foreach (['uploads','attachments'] as $kind) $counts[$kind]=['files'=>0,'covers'=>0,'thumbs'=>0,'other_files'=>0,'dirs'=>0];
    foreach ($staged as $stage) {
        if ($stage['path']===null) continue;
        try {
            if (file_put_contents($stage['journal'],json_encode(['phase'=>'database_committed','entries'=>$stage['entries']],JSON_THROW_ON_ERROR))===false) throw new RuntimeException('Unable to update purge recovery journal.');
            foreach ($stage['entries'] as $name) catalog_purge_remove($stage['path'].'/'.$name,$counts[$stage['kind']]);
            if (!@unlink($stage['journal']) || !@rmdir($stage['path'])) throw new RuntimeException('Unable to remove purge recovery directory.');
        } catch (Throwable $e) { $warnings[]='Catalog rows were cleared; media cleanup needs attention: '.$stage['path'].' ('.$e->getMessage().')'; }
    }
    // This is optional housekeeping; restoration preserves IDs and needs no ALTER privilege.
    $retained_counters=[];
    foreach (['Books','BookCopies','Authors','Subjects','Publishers','Placement','BookCopyPhotos','AcquisitionDocuments','ValuationImports','BookAssessments','PriceObservations'] as $table) {
        if (!isset($existing[$table])) continue;
        try { $db->exec('ALTER TABLE `'.$table.'` AUTO_INCREMENT=1'); }
        catch (Throwable $e) { $retained_counters[]=$table; }
    }
    return ['deleted_rows'=>$deleted,'warnings'=>$warnings,'id_counters_retained'=>$retained_counters,
        'deleted_upload_files'=>$counts['uploads']['files'],'deleted_upload_cover_files'=>$counts['uploads']['covers'],
        'deleted_upload_thumb_files'=>$counts['uploads']['thumbs'],'deleted_upload_other_files'=>$counts['uploads']['other_files'],
        'deleted_upload_dirs'=>$counts['uploads']['dirs'],'deleted_attachment_files'=>$counts['attachments']['files'],
        'deleted_attachment_dirs'=>$counts['attachments']['dirs']];
}
