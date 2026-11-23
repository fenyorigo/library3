# Mac → Fedora átvitel, library3 3.8.1

A referenciaoldal: `https://localhost:8444/`, projektje a Macen
`/Users/bajanp/Projects/library3`. A 3.8.1 alkalmazás sémája 3.5.0.

## Mit kell átvinni?

1. Az alkalmazás 3.8.1-s forrását, és újra kell fordítani a felületet. A
   `public/dist/` a tárolóban figyelmen kívül hagyott build-kimenet:
   a forrás frissítése önmagában nem változtatja meg a kiszolgált oldalt.
2. **A teljes adatbázis SQL-dumpját**, az eredeti azonosítókkal. Nem elegendő
   az ismert régi táblákra korlátozott dump vagy a könyv-CSV.
3. A borítókat: `public/uploads/`.
4. A privát fotókat és PDF-eket: `private-attachments/`, vagy a
   `BOOKCATALOG_ATTACHMENTS_DIR` által megadott másik könyvtárat. Az SQL-dump
   a fájlok elérési útját és metaadatait viszi át, magukat a fájlokat nem.
5. A célgép saját konfigurációját: adatbázis-kapcsolat, e-könyv-gyökér,
   privát mellékletkönyvtár és mentési hely. A Mac konfigurációját ne másold
   változtatás nélkül a Fedora gépre.

## SQL-dump és visszaállítás

Készíts friss dumpot közvetlenül az átvitel előtt. A következő példa teljes
`books3` adatbázist exportál; a felhasználónevet a saját dump-jogosultságú
felhasználódra cseréld. A jelszót a kliens kéri be.

```bash
mariadb-dump -u DUMP_USER -p --single-transaction --skip-lock-tables --no-tablespaces books3 > library3-full.sql
```

Az átvitel idejére ne módosuljon a katalógus és ne legyen új feltöltés, hogy az
SQL és a fájlmásolat ugyanazt az állapotot képviselje. A dumpot és számlafájlokat
személyes adatként kezeld; ne kerüljenek a Git-tárolóba vagy a nyilvános webrootba.

A célon előbb legyen mentés a meglévő adatbázisról, majd az új vagy szándékosan
lecserélendő `books3` adatbázisba állítsd vissza a teljes dumpot:

```bash
mariadb -u RESTORE_USER -p books3 < library3-full.sql
```

A parancs meglévő táblákat is felülírhat. A `schema.sql` baseline fájlt ne
futtasd utána: az töröl és üres táblákat hoz létre. A 3.5.0-s teljes dump már
tartalmazza az 05 és 06 migráció eredményét; ezeket nem kell külön alkalmazni.
Régebbi dump esetén viszont előbb ellenőrizd a tényleges táblákat és a szükséges
migrációkat, ne csak a verziószövegre támaszkodj.

## Fájlok és felület

A célon a privát mellékletkönyvtár a `public/` könyvtáron kívül maradjon. A
relatív fájlnevek változtatás nélkül másolandók. Az írási/olvasási jogokat és
a Fedora SELinux-címkézést a [példánydokumentáció](copy-documentation.md#fedora-telepítés-jogosultságok-és-selinux)
szerint állítsd be. A Macről örökölt tulajdonos és módok nem helyettesítik a
Fedora-oldali PHP-felhasználó jogosultságait.

A frontend újrafordítása a projekt `frontend/` könyvtárából:

```bash
npm ci
npm run build
```

Ezután az oldalt teljesen frissítsd, és ellenőrizd a fejlécben a **3.8.1**
verziót. Az alkalmazás olvasási/módosítási adatbázis-felhasználója eltérhet a
dumpot vagy migrációt végző adminisztrátori felhasználótól.

## Ellenőrzések a célon

```sql
SELECT key_name, value FROM SystemInfo WHERE key_name IN ('app_version','schema_version');
SELECT COUNT(*) FROM Books;
SELECT COUNT(*) FROM BookCopies;
SELECT COUNT(*) FROM BookCopyPhotos;
SELECT COUNT(*) FROM AcquisitionDocuments;
SELECT COUNT(*) FROM BookCopyAcquisitionDocuments;
SELECT COUNT(*) FROM ValuationImports;
SELECT COUNT(*) FROM BookAssessments;
SELECT COUNT(*) FROM PriceObservations;
SELECT COUNT(*) FROM BookAssessmentSources;
SELECT COUNT(*) FROM BookAssessments a
WHERE a.is_high_value = 1
AND a.assessment_id = (SELECT MAX(v.assessment_id) FROM BookAssessments v WHERE v.book_id=a.book_id);
```

A 2026-11-23-i ellenőrzött állapot: 11273 könyv, 12252 példány, 9 állapotfotó,
3162 értékelési rekord, 91 forrásmegfigyelés és 58 aktuális nagyértékű-megőrzési
jelölés. Későbbi feltöltések/importok után a friss forrásgépi számokhoz hasonlíts.

A főoldalon válaszd az **Értékelés / megőrzés → Nagy értékű – külön kezelendő**
szűrőt. Nyisd meg a 109-es Verne-kötetet: értékelési információi, a korábbi
900 Ft vételár forrásadata és állapotfotói legyenek elérhetők. Ellenőrizd egy
fotó letöltését, illetve számla feltöltése esetén annak PDF-letöltését is.

A teljes SQL-dump visszaállítását 2026-11-23-án külön, elkülönített MariaDB
tesztadatbázison ellenőriztük: a könyvek, példányok, fotó/számlakapcsolatok,
értékelések és árforrások soronkénti ellenőrzőösszege egyezett. Ez a Macen
végzett visszaállítási próba; a Fedora-oldali jogosultságokat, adatbázisverziót
és tényleges működést a célgépen is ellenőrizni kell.
