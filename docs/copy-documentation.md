# Példányfotók és beszerzési dokumentumok — séma 3.4.0

A könyvadatlap szerkesztésekor egyedi nyomtatott példányokhoz tíz állapotfotó
és közös beszerzési dokumentum csatolható. Az olvasási nézetben is elérhetők a
fotók és a PDF-letöltések. A CSV-import továbbra sem importálja e mellékleteket. Piaci árak és értékbecslések külön
következő bővítéshez tartoznak; a számlán szereplő ár nem mai piaci érték.

## Kapcsolatok

- `BookCopyPhotos`: egy konkrét `BookCopies.copy_id` rekord legfeljebb tíz
  állapotfotója. A `slot_number` 1–10, példányonként egyedi; így párhuzamos
  feltöltés sem lépheti túl a korlátot. Üres helyhez nem készül rekord.
- `AcquisitionDocuments`: egy számla vagy kereskedői leírás egy rekord,
  opcionális PDF-szkennel. A `document_type` javasolt értékei: `invoice`,
  `receipt`, `seller_description`, `other`. A számla és a puszta eladói
  árjegyzék eltérő dokumentumtípus.
- `BookCopyAcquisitionDocuments`: több példány közös számlája és egy példány
  több dokumentuma is kezelhető. Az `item_reference` a számla tételszáma.
  A dokumentum nincs példányonként megkettőzve.

A fotók javasolt típusai: `front_cover`, `back_cover`, `spine`, `title_page`,
`imprint`, `map`, `supplement`, `condition_detail`, `other`. Ezek alkalmazási
értékek, nem merev adatbázis-enumok. A `caption` megfigyelést tárolhat; a
fotó dátuma ismeretlen esetben NULL, nem a feltöltés napja.

Az `original_location` a papír helyét írja le. Példa:
„A La Fontaine: Fables I. kötetében; az ugyanakkor vásárolt további könyvek
közös számlája.” Ez magyarázó példa, a migráció nem hoz létre ilyen adatot.

Ha egy nyomtatott `BookCopies` rekord `quantity > 1`, a külön dokumentált
fizikai példányokat előbb külön, `quantity = 1` rekordokra kell bontani,
azonos összdarabszám megtartásával. A migráció nem bont szét meglévő rekordot.
A feltöltőfelület és az API csak quantity = 1 nyomtatott példányt enged dokumentálni.
A példányazonosító könyvszerkesztéskor megmarad. Dokumentált példány
formátuma és mennyisége nem változtatható; automatikus duplum-összevonása tiltott.

## Fájlok és megőrzés

A `file_path` hordozható relatív útvonal egy később beállítandó privát
mellékletkönyvtárhoz képest, például `documents/03830010.pdf`. Ne kerüljön ide
iMac-specifikus abszolút útvonal. A fotóhoz létező fájl szükséges; dokumentum
fájl nélkül is nyilvántartható, ha még csak az eredeti papír lelőhelye ismert.
Az `original_filename`, MIME-típus, méret és SHA256 a fájl azonosítását segíti.

A későbbi feltöltésnek ellenőriznie kell a fájl tartalmát, méretét, jogosultságot
és az útvonalat (abszolút út és `..` tiltása). Számlák személyes adatai miatt
az állományok ne legyenek nyilvános URL-ről közvetlenül elérhetők.

A kapcsolatok szándékosan `ON DELETE RESTRICT` szabályúak: egy példány vagy
könyv végleges törlése nem tüntetheti el észrevétlenül a dokumentációt. A szokásos
könyv-soft-delete működés változatlan. A végleges törlés/összevonás műveletei
az új kapcsolatokkal bővítendők a funkció bevezetésekor. SQL-törlés fájlt nem töröl.

## Átvitel iMac → think260

1. Készíts adatbázis-mentést, és ellenőrizd, hogy a kiinduló séma 3.3.0.
2. A meglévő adatbázison futtasd a
   `00-basedata/sql/migrations/05_v3_add_copy_photos_and_acquisition_documents.sql`
   fájlt hibánál leálló SQL-klienssel (`--force` nélkül), majd ellenőrizd mindhárom
   új táblát és a `SystemInfo.schema_version = 3.4.0` értéket.
3. Ezután telepítsd a hozzá tartozó alkalmazásfájlokat. A verzióállandó önmagában
   nem futtat migrációt; a bejelentkezés verziószinkronja sem sémaellenőrzés.
4. Ugyanezt a migrációt a think260 adatbázisán is futtasd, ha azt nem a már
   frissített adatbázis teljes mentéséből állítod vissza.

Az SQL újrafuttatható a már sikeresen frissített sémán. A `CREATE TABLE IF NOT
EXISTS` nem javít egy korábban kézzel létrehozott, eltérő szerkezetű táblát.
DDL esetén nincs tranzakciós visszagörgetés; hiba után ellenőrizd a létrejött
táblákat, és javítás után futtasd újra a migrációt.

Friss telepítéshez a kibővített `schema.sql` használható. **Meglévő katalóguson
ne futtasd a baseline fájlt**, mert az táblákat töröl és újra létrehoz.

## Használat és mentés

A könyvadatlap szerkesztési nézetében a „Condition photos and acquisition
 documents” részben válassz fizikai példányt. Feltöltésenként legfeljebb 25 MB-os
JPEG/PNG/WebP fotó vagy PDF fogadható. Fotót csak üres helyre lehet felvenni;
ez a kezdeti felület nem kínál törlést vagy felülírást. A dátum csak akkor kerül
be, ha megadod. Számla fájl nélkül is nyilvántartható.

A közös számlát egyszer vedd fel, majd az új dokumentum #azonosítójával más
könyvpéldányokhoz is kapcsolhatod. A papír helyének módosítása minden kapcsolt
könyvnél érvényes. Az eladói leírás külön típusként szerepel, nem számlaként.
A letöltések bejelentkezést igényelnek; módosítás csak adminisztrátorként lehetséges.

A mellékletek alapértelmezett helye a projekt gyökerében a `private-attachments/`,
a `public/` könyvtáron kívül. A webkiszolgáló felhasználójának írnia kell ezt a
könyvtárat. Más hely a `BOOKCATALOG_ATTACHMENTS_DIR` környezeti változóval adható
meg. A fájlnevek véletlen azonosítók, az eredeti név az adatbázisban marad.

A teljes ZIP-mentés tartalmazza a három új tábla adatait
(`data/copy_documentation.json`), a mellékleteket (`copy-attachments/`) és azok
ellenőrzőösszegeit. Hiányzó melléklet esetén a mentés hibával leáll.
**A szokásos CSV/ZIP-import még nem állítja vissza ezt a részt.** iMac → think260
átvitelhez teljes SQL-mentést használj az azonosítók megőrzésével, és másold a
privát mellékletkönyvtárat is. A JSON-manifeszt külön helyreállítási forrás,
nem a jelenlegi CSV-import bemenete.

## Fedora telepítés: jogosultságok és SELinux

A PHP-t futtató felhasználónak olvasási/írási joga kell a privát
mellékletkönyvtárhoz. Fedorán ez gyakran `apache`; ellenőrizd a PHP-FPM pool
felhasználóját. Az alábbi példa `/var/www/library3/private-attachments` helyet
és `apache` felhasználót feltételez; a saját útvonaladat és felhasználódat használd.

```bash
sudo mkdir -p /var/www/library3/private-attachments
sudo chown -R apache:apache /var/www/library3/private-attachments
sudo find /var/www/library3/private-attachments -type d -exec chmod 700 {} +
sudo find /var/www/library3/private-attachments -type f -exec chmod 600 {} +
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/www/library3/private-attachments(/.*)?'
sudo restorecon -Rv /var/www/library3/private-attachments
ls -ldZ /var/www/library3/private-attachments
```

Ha az adott útvonalhoz már van helyi SELinux-szabály, `-a` helyett `-m` kell.
A szülőkönyvtárakon áthaladási jog szükséges. A Macről másolt fájlok tulajdonosát
és SELinux-címkéjét az átvitel után is ellenőrizd. A `public/` és az alkalmazáskód
nem igényel írható SELinux-címkét ehhez a funkcióhoz. A privát könyvtár ne kapjon
nyilvános Apache-aliast.

Háttér: [Red Hat SELinux — Apache konfigurációs példák](https://docs.redhat.com/en/documentation/red_hat_enterprise_linux/7/htmlsingle/selinux_users_and_administrators_guide/sect-managing_confined_services-the_apache_http_server-configuration_examples).

## Ellenőrzés

A `tests/copy_documentation_schema.php` külön, eldobható adatbázist igényel,
amelynek neve `library3_schema_test` előtaggal kezdődik. A teszt ezen belül
táblákat töröl és újra létrehoz. Beállítás: `LIBRARY3_SCHEMA_TEST_DSN`,
`LIBRARY3_SCHEMA_TEST_USER`, `LIBRARY3_SCHEMA_TEST_PASSWORD`. Ellenőrzi a friss
sémát, a migráció egyezését, az adatmegőrzést, az újrafuttatást, a fotókorlátot,
a közös számlát és a törlési szabályokat.

A `tests/copy_documentation_identity.php` tesztet a sémateszt után, ugyanazon
tesztadatbázison kell futtatni. Meglévő fotók/számlák mellett ellenőrzi az
azonosítók megőrzését és a hibás módosítások visszagörgetését.
