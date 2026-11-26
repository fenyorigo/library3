<?php
declare(strict_types=1);
require_once __DIR__.'/functions.php';

/** Versions cover only curated fields and the identities displayed to the operator. */
function curator_annotate(PDO $db,array $rows,string $query=''): array {
    if (!$rows) return [];
    $ids=array_map(fn($row)=>(int)$row['id'],$rows);$in=implode(',',array_fill(0,count($ids),'?'));
    $s=$db->prepare('SELECT ba.book_id,ba.author_id,ba.author_ord,ba.author_alias,COALESCE(NULLIF(TRIM(a.name),\'\'),a.sort_name,CONCAT(\'#\',a.author_id)) name,a.first_name,a.last_name,a.sort_name,a.is_hungarian FROM Books_Authors ba JOIN Authors a ON a.author_id=ba.author_id WHERE ba.book_id IN ('.$in.') ORDER BY ba.book_id,ba.author_ord,ba.author_id');$s->execute($ids);$authors=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $author){$book=(int)$author['book_id'];unset($author['book_id']);$authors[$book][]=$author;}
    $s=$db->prepare('SELECT book_id,copy_id,format,record_status FROM BookCopies WHERE book_id IN ('.$in.') ORDER BY book_id,copy_id');$s->execute($ids);$copies=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $copy){$book=(int)$copy['book_id'];unset($copy['book_id']);$copies[$book][]=$copy;}
    foreach($rows as &$row){$id=(int)$row['id'];$row['curator_authors']=array_map(fn($author)=>$author+['query_match'=>curator_author_matches($query,$author)],$authors[$id]??[]);$row['curator_formats']=array_values(array_unique(array_column(array_filter($copies[$id]??[],fn($copy)=>$copy['record_status']==='active'),'format')));$row['curator_version']=hash('sha256',json_encode([$id,$row['title'],$row['series'],$row['record_status'],$authors[$id]??[],$copies[$id]??[]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}unset($row);
    return $rows;
}

function curator_write_audit(PDO $db,array $actor,array $summary,array $changes): void {
    $chunks=[];$chunk=[];
    foreach($changes as $change){$next=[...$chunk,$change];if(strlen(json_encode($summary+['changes'=>$next],JSON_THROW_ON_ERROR))>48000 && $chunk){$chunks[]=$chunk;$chunk=[];}$chunk[]=$change;}
    if($chunk)$chunks[]=$chunk;
    $s=$db->prepare('INSERT INTO AuthEvents(user_id,username_snapshot,event_type,ip_address,user_agent,details) VALUES(?,?,?,?,?,?)');
    foreach($chunks as $index=>$entries){$details=json_encode($summary+['part'=>$index+1,'parts'=>count($chunks),'changes'=>$entries],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($details)>60000)throw new InvalidArgumentException('Egy könyv módosítási naplója túl nagy; rendezd külön a szerzőit.');$s->execute([(int)$actor['uid'],(string)$actor['username'],'catalog_curate',substr($_SERVER['REMOTE_ADDR']??'CLI',0,45),substr($_SERVER['HTTP_USER_AGENT']??'',0,512),$details]);}
}

function curator_apply(PDO $db,array $input,array $actor): array {
    $raw=$input['ids']??null;
    if(!is_array($raw)||!array_is_list($raw)||!$raw||count($raw)>20000)throw new InvalidArgumentException('Jelölj ki 1–20000 könyvet.');
    $ids=[];foreach($raw as $id){if(!is_int($id)||$id<1)throw new InvalidArgumentException('Érvénytelen könyvazonosító.');$ids[$id]=$id;}sort($ids,SORT_NUMERIC);$ids=array_values($ids);
    foreach(['replace_author','set_series'] as $key)if(!is_bool($input[$key]??null))throw new InvalidArgumentException('Érvénytelen módosítási kapcsoló.');
    $replace=$input['replace_author'];$set_series=$input['set_series'];
    if(!$replace&&!$set_series)throw new InvalidArgumentException('Adj meg új szerzőnevet vagy sorozatot.');
    if(!is_string($input['new_author_name']??''))throw new InvalidArgumentException('Érvénytelen szerzőnév.');
    $name=normalize_author_metadata_name($input['new_author_name']??'');$source_id=$input['source_author_id']??null;
    if($replace&&(!is_int($source_id)||$source_id<1||$name===''||mb_strlen($name)>255||preg_match('/[;\r\n]/u',$name)))throw new InvalidArgumentException('Válaszd ki a cserélendő szerzőt, és adj meg egy új szerzőnevet.');
    $series=$input['series']??'';if(!is_string($series)||mb_strlen($series)>255)throw new InvalidArgumentException('A sorozat legfeljebb 255 karakter lehet.');$series=trim($series);$series=$series===''?null:$series;
    $versions=$input['versions']??[];if(!is_array($versions))throw new InvalidArgumentException('Hiányzó ellenőrző adatok; ismételd meg a keresést.');
    if($db->inTransaction())throw new LogicException('Curation owns its transaction.');
    $db->beginTransaction();
    try {
        $in=implode(',',array_fill(0,count($ids),'?'));$s=$db->prepare('SELECT book_id id,title,series,record_status FROM Books WHERE book_id IN ('.$in.') ORDER BY book_id FOR UPDATE');$s->execute($ids);$rows=curator_annotate($db,$s->fetchAll(PDO::FETCH_ASSOC),is_string($input['search']??null)?$input['search']:'');
        if(count($rows)!==count($ids))throw new RuntimeException('Egy kijelölt könyv már nem található. Keresés újra szükséges.',409);
        foreach($rows as $row){$version=$versions[(int)$row['id']]??'';if($row['record_status']!=='active'||!is_string($version)||!hash_equals($row['curator_version'],$version))throw new RuntimeException('Egy kijelölt könyv időközben megváltozott. Ismételd meg a keresést.',409);}
        $target=null;$source=null;$matching=0;
        if($replace){$s=$db->prepare('SELECT * FROM Authors WHERE author_id=?');$s->execute([$source_id]);$source=$s->fetch(PDO::FETCH_ASSOC);if(!$source)throw new InvalidArgumentException('A régi szerző nem található.');if(!is_string($input['search']??null)||!curator_author_matches($input['search'],$source))throw new InvalidArgumentException('Csak a keresésben szereplő régi szerző cserélhető.');foreach($rows as $row)foreach($row['curator_authors'] as $a)if((int)$a['author_id']===$source_id)$matching++;if(!$matching)throw new InvalidArgumentException('A kiválasztott régi szerző egyik kijelölt könyvhöz sem tartozik.');
            $s=$db->prepare('SELECT * FROM Authors WHERE name=? ORDER BY author_id LIMIT 1 FOR UPDATE');$s->execute([$name]);$target=$s->fetch(PDO::FETCH_ASSOC);
            if(!$target){[$first,$last,$sort]=parse_author_free_text($name,(bool)$source['is_hungarian']);$db->prepare('INSERT INTO Authors(name,first_name,last_name,sort_name,is_hungarian) VALUES(?,?,?,?,?)')->execute([$name,$first,$last,$sort,(int)$source['is_hungarian']]);$target=['author_id'=>(int)$db->lastInsertId(),'name'=>$name];}
        }
        $changes=[];$author_changes=0;$series_changes=0;
        foreach($rows as $row){$id=(int)$row['id'];$change=['book_id'=>$id,'title'=>$row['title']];$changed=false;
            if($replace&&(int)$target['author_id']!==$source_id){$old=null;$existing=null;foreach($row['curator_authors'] as $author){if((int)$author['author_id']===$source_id)$old=$author;if((int)$author['author_id']===(int)$target['author_id'])$existing=$author;}
                if($old){$alias=trim((string)$old['author_alias'])!==''?$old['author_alias']:null;$order=(int)$old['author_ord'];if($existing){$existing_alias=trim((string)$existing['author_alias'])!==''?$existing['author_alias']:null;if($alias!==null&&$existing_alias!==null&&$alias!==$existing_alias)throw new InvalidArgumentException('Eltérő szerzői aliasok ütköznek a(z) #'.$id.' könyvnél; ezt előbb külön rendezd.');$alias=$existing_alias??$alias;$order=min($order,(int)$existing['author_ord']);$db->prepare('UPDATE Books_Authors SET author_ord=?,author_alias=? WHERE book_id=? AND author_id=?')->execute([$order,$alias,$id,$target['author_id']]);}else $db->prepare('INSERT INTO Books_Authors(book_id,author_id,author_ord,author_alias) VALUES(?,?,?,?)')->execute([$id,$target['author_id'],$order,$alias]);
                    $db->prepare('DELETE FROM Books_Authors WHERE book_id=? AND author_id=?')->execute([$id,$source_id]);$change['author_before']=$old;$change['target_link_before']=$existing;$change['target_author_id']=(int)$target['author_id'];$changed=true;$author_changes++;}
            }
            if($set_series&&$row['series']!==$series){$db->prepare('UPDATE Books SET series=? WHERE book_id=?')->execute([$series,$id]);$change['series_before']=$row['series'];$change['series_after']=$series;$changed=true;$series_changes++;}
            if($changed)$changes[]=$change;
        }
        $result=['selected'=>count($ids),'changed_books'=>count($changes),'author_changes'=>$author_changes,'series_changes'=>$series_changes,'author_not_present'=>$replace?count($ids)-$matching:0,'target_author'=>$target?['id'=>(int)$target['author_id'],'name'=>$target['name']]:null];
        if($changes)curator_write_audit($db,$actor,$result+['source_author'=>$source?['id'=>$source_id,'name'=>$source['name']]:null,'requested_series'=>$set_series?$series:null],$changes);
        $db->commit();return $result;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function curator_author_matches(string $query,array $author): bool {
    $name=search_fold_value(implode(' ',array_map(fn($key)=>(string)($author[$key]??''),['name','first_name','last_name','sort_name'])));
    foreach(preg_split('/\s+/u',trim($query),-1,PREG_SPLIT_NO_EMPTY)?:[] as $word)if(preg_match('~'.str_replace(['%','_'],['.*','.'],preg_quote(search_fold_value($word),'~')).'~us',$name))return true;
    return false;
}
