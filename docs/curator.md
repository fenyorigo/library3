# Kurátori rendezés (3.10.0)

Az admin főoldalán a **Kurátori rendezés** gomb nyitja meg. A keresés ugyanazt a
logikát használja, mint a főoldal; a szűrő **Összes / Nyomtatott / E-könyv**.
A rendezés aktív könyvekre vonatkozik, a törölt könyveket nem módosítja.

## Példa: Wodehouse egységesítése

1. Keress rá: **Pelham**.
2. Jelöld ki a kívánt könyveket, vagy az **Összes találat kijelölése** boxot.
   Ez az összes oldalon talált könyvet kijelöli, nem csak a látható oldalt.
3. Az **Új szerzőnév** mezőbe írd: **P G Wodehouse**.
4. A régi szerző automatikusan a kereséssel megtalált **Pelham Grenville
   Wodehouse** lesz, ha egyértelmű. Több egyező szerzőnél válassz közülük.
   Csak a keresésben szereplő szerző cserélhető; másik társszerzőhöz új keresés kell.
5. **Save**. A kijelölt könyvek szerzőkapcsolata a már meglévő P G Wodehouse
   rekordhoz kerül. A többi szerző, sorrend és szerzői alias megmarad.

A kijelölés lapváltáskor megmarad. Új keresés vagy szűrőváltás törli a kijelölést.
A **Kijelölések törlése** csak a kijelölést szünteti meg, könyvet nem töröl.
Ha a keresés egy címet is megtalál, amelyhez a régi szerző nem tartozik, annak a
szerzői változatlanok maradnak. A felület mentés előtt jelzi, hány kijelölt könyvnél
szerepel a régi szerző, mentés után pedig a tényleges módosítások számát.

## Sorozat

Az **Új sorozat** önállóan és a szerzőcserével együtt is használható. Üresen
hagyva a sorozat változatlan. Törléséhez jelöld be a **Sorozat törlése** boxot.
Ez a kijelölt könyvekre vonatkozik, a szerzőtől függetlenül.

## Adatvédelem és mentések

A művelet csak a kijelölt könyv-szerző kapcsolatokat és/vagy a Books.series mezőt
módosítja. A közös Authors rekordot nem nevezi át globálisan. Nem módosít címet,
példányt, e-könyvfájlt, képet, dokumentumot vagy korábbi értékelést. A régi,
kapcsolat nélkül maradó szerzőrekord megmarad a szerzőkarbantartás számára.

Ha egy könyv vagy annak szerzői/példányai közben megváltoztak, új keresés szükséges.
Ha ugyanazon könyvnél a régi és az új szerző is szerepel, a kapcsolatok egyesülnek;
eltérő, nem üres szerzői aliasok ütközésekor a teljes mentés visszagördül, és a könyvet
előbb külön kell rendezni. Sikertelen mentésnél a kijelölés és a beírt mezők megmaradnak.

A módosítások és az előtte/utána értékek az AuthEvents naplóba kerülnek, ugyanabban
az adatbázis-tranzakcióban. Nincs új tábla vagy sémafrissítés. A friss szerzőkapcsolatokat
és sorozatokat a meglévő CSV és Full backup/portable ZIP viszi át. A felhasználói
auditnapló helyi rendszeradat marad, ahogy korábban is.

Egy menet legfeljebb 20000 találatot kezel; nagyobb állományhoz szűkítsd a keresést.

## Tesztelés

Egy `library3_schema_test` kezdetű nevű eldobható adatbázison:
`LIBRARY3_SCHEMA_TEST_DSN='mysql:unix_socket=...;dbname=library3_schema_test_curator' php tests/curator.php`.
A próbaadatbázist újralétrehozza; az éles katalógust nem használja. A felület próbái:
`npm --prefix frontend run test:unit -- --run`.
