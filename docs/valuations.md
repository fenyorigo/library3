# Könyvértékelések és megőrzési jelölések

Alkalmazás: 3.8.0. Adatbázisséma: 3.5.0. Meglévő 3.4.0 adatbázishoz a
`00-basedata/sql/migrations/06_v3_add_book_valuations.sql` additív migráció kell.
A migráció nem írja át a könyveket, példányokat, fotókat vagy számlákat.

## Hol található?

- A főoldalon az **Értékelés / megőrzés** választó szűr a nagy értékűként
  megőrzendő, az elsőként ellenőrizendő és az árbecsléssel/pótlási referenciával
  rendelkező könyvekre. A szűrő a kereséssel, formátummal, nyelvvel és
  rekordállapottal együtt használható, és az oldal URL-jében is megmarad.
- A lista a cím alatt is jelzi: **Nagy értékű – külön kezelendő**.
- A **View** és **Edit** könyvadatlap **Értékelés és megőrzés** része mutatja az
  összegeket, megbízhatóságot, módszert, örökösi teendőt, példányadatokat és az
  ellenőrizhető forrásokat. Olvasó szerepkörben is látható.
- Adminisztrátorként az **Import valuations** gomb nyitja az előnézetes importot.
  Ez az **Import books** funkciótól különálló művelet.

## Adatmodell és jelentés

`ValuationImports`: egy importfájl SHA256-azonosítója és importideje. Ugyanazon
fájl újbóli importja nem hoz létre új változatot. Más tartalmú import új
értékelési pillanatképeket készít; a régi változatok megmaradnak.

`BookAssessments`: könyvenként használtpiaci **becslés**, pótlási **referencia**,
külön megbízhatóság és módszer, megőrzési kategória, kutatási prioritás,
örökösi útmutatás és importált példány/beszerzési megjegyzések. A megőrzési
jelölés ár nélkül is érvényes. Az aktuális adat mindig az adott könyv legújabb
értékelési rekordja; a szűrés sem választ elavult változatot.

`PriceObservations`: a forrásban ténylegesen szereplő összeg, saját pénznemben,
szállítás külön, az ár/esemény jellege és dátuma, megtekintési dátum, eladó,
kiadásegyezés, korlátok és forráshivatkozás. A korábbi vételár, eladói leírásban
feltüntetett ár, elfogyott tétel és aktuális ajánlat nem azonos fogalmak.
A forrás jellege szövegesen megmarad; az import nem minősíti át élő ajánlattá.

`BookAssessmentSources`: az értékelés és a források kapcsolata, külön
`reference`, `used`, `replacement` szereppel. Egy közös sorozat-/csomagár egy
forrásrekord, több könyvhöz kapcsolva. Sem kötetenkénti szétosztás, sem
automatikus többszörözés, sem teljes gyűjteményérték-összegzés nem történik.

A hiányzó összeg NULL, nem nulla. Az értékelési összegek a jelen CSV-ben HUF-ban
szerepelnek; az eredeti forrás devizája ettől függetlenül EUR, GBP, USD stb.
lehet. Az import nem végez árfolyam-átváltást, nem gyárt új becslést és nem
ellenőrzi újra a webes kínálatot.

## Értékelési CSV-import

UTF-8 CSV, BOM-mal vagy anélkül, pontosvessző vagy vessző elválasztóval,
legfeljebb 20 MB / 20000 rekord. Szükséges eredeti mezők:
`ID`, `Title`, `Authors`, `Publisher`, `Year`; `ISBN` ajánlott.
Az azonosító és cím egyezése kötelező; a megadott kiadó, év és ISBN eltérését
is ütközésként jelzi. Az Authors mező megmarad a bibliográfiai pillanatképben;
nem önálló automatikus azonosító. Hiányzó/eltérő könyv vagy ismételt ID esetén
nem történik részleges import. A program nem hoz létre könyvet vagy példányt.

Kötelező értékelési oszlopok (üres érték megengedett):

- `Használtpiaci irányérték (Ft; becslés)`
- `Pótlási könyvár (Ft; referencia)`
- `Megőrzési értékkategória`: üres vagy `Nagy értékű – külön kezelendő`

A további fejléceket a `valuation_columns()` felsorolás dokumentálja a
`public/functions.php` fájlban; ezek megegyeznek a kiegészített katalógustáblázat
fejléceivel. Az összeg nemnegatív, legfeljebb két tizedesjegyű szám lehet,
tizedesponttal vagy tizedesvesszővel, pénznem és ezreselválasztó nélkül.

Az új **Forrásrészletek (JSON)** oszlop egy JSON-tömb. Elemenként kötelező:
`source_id`, `title`, `kind`. További mezők: `price`, `currency`, `shipping`,
`match`, `url`, `seller`, `note`, `event`, `checked` (YYYY-MM-DD), `ids` és/vagy
`record_ids`. Összeghez hárombetűs pénznem kötelező. Azonos `source_id` ugyanazon
fájlban nem tartalmazhat eltérő forrásadatokat.

Ha használtpiaci/pótlási összeg szerepel, a megfelelő
`Használt irányérték forrása` / `Pótlási referencia forrása` oszlopnak létező
forrásazonosítóra kell mutatnia. A régi, forrásrészleteket nem tartalmazó CSV
áradatokkal önmagában nem importálható: az ellenőrizhető forrásrészleteket
előbb hozzá kell adni. A keresőlink önmagában nem árforrás.

Előnézetkor még nincs adatbázis-írás. Az előnézet munkamenethez kötött,
30 percig érvényes; az alkalmazáskor a program újra ellenőrzi az egyezéseket.
Az összes értékelés és forrás egy tranzakcióban kerül be, hiba esetén együtt
visszagörgetve. Az importált példánymegjegyzések nem írják felül a tényleges
példányrekord vagy feltöltött dokumentum adatait.

## Első import: ellenőrzött eredmény

A 2026-11-23-i feldolgozás 3162 nyomtatott könyvhöz készült, 91
forrásmegfigyeléssel. 58 rekord kapott nagyértékű-megőrzési jelölést,
15 használtpiaci becslés és 16 pótlási referencia áll rendelkezésre;
ez nem 3162 beárazott könyv. A Verne 109-es rekordjánál a fotós kiegészítés
szerepel, de nincs ellenőrizetlen aktuális ár vagy bizonyított elsőnyomás-állítás.

## Mentés és átvitel

A teljes ZIP-mentés a négy tábla összes változatát a `data/valuations.json`
manifesztbe teszi, ellenőrzőösszeggel. A szűrt könyv-CSV, a „CSV + covers”
export és a statisztikai export követi a megőrzési szűrőt, de a szokásos könyv-CSV
nem helyettesíti az értékelési mentést.

Mac → Fedora átvitelhez a **teljes SQL-dumpot** használd, az eredeti azonosítók
megőrzésével; ez mind a négy új táblát is átviszi. A `private-attachments/`
fájljait külön kell másolni, mert az SQL-dump csak az elérési útjukat tartalmazza.
A feltöltött fotókra és számlákra vonatkozó jogosultságok/SELinux-leírás a
[példánydokumentációban](copy-documentation.md) található.

Az értékelési előzményekre hivatkozó könyv végleges törlését az idegen kulcs
védi; a soft delete működik. Az automatikus duplum-összevonás ilyen rekordot
nem törölhet. Az értékelések archiválását/áthelyezését előbb rendezni kell.

## Teszt

`tests/valuation_import.php` destruktív, csak elkülönített,
`library3_schema_test*` nevű adatbázison futtatható. Ugyanazokat a
`LIBRARY3_SCHEMA_TEST_DSN`, `LIBRARY3_SCHEMA_TEST_USER` és
`LIBRARY3_SCHEMA_TEST_PASSWORD` környezeti változókat használja, mint a
példánydokumentációs teszt. Ellenőrzi a friss séma/migráció egyezését,
idempotenciát, közös forrásokat, árjelentést, aktuális változatra szűrést,
előzménymegőrzést, ütközést, visszagörgetést, valamint a meglévő fotók és
számlakapcsolatok változatlanságát.
