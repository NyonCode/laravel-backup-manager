# Inkrementální a deduplikované zálohy

> **Funkce Pro.** Inkrementální/deduplikované zálohy vyžadují
> [Backup Manager Pro](13-pro-a-ui.md). Free jádro umí snapshoty vypsat, ale
> odmítá je vytvořit, obnovit nebo ověřit.

Každá běžná záloha je úplný, soběstačný archiv. **Inkrementální** zálohy místo
toho ukládají *snapshot*, jehož obsah souborů žije ve sdíleném, obsahově
adresovaném **poolu blobů** — takže se nahrává jen obsah změněný od minulého
snapshotu a obsah, který se opakuje napříč zálohami, se uloží jen jednou.

To je ideální pro **častou kadenci** (hodinově/denně), kde záleží na místě a čase.
Úplné archivy zůstávají spolehlivými, samostatnými kotvami; inkrementální zálohy
je doplňují, nenahrazují.

> **Režim je nezávislý na rozsahu.** `type` profilu (`full` / `database` /
> `files`) říká, *co* zálohovat; jeho `mode` (`archive` / `incremental`) říká,
> *jak* to uložit. Libovolný rozsah může použít oba režimy.

## Zapnutí

Nastavte na profilu `mode`:

```php
'profiles' => [
    'hourly' => [
        'name' => env('APP_NAME'),
        'type' => 'files',
        'mode' => 'incremental',      // ← ulož jako deduplikovaný snapshot
        'files' => ['include' => [base_path()]],
        'destinations' => ['s3'],
        'path' => 'backups',
    ],
],
```

Pak jej spusťte jako každý jiný profil:

```bash
php artisan backup:run --profile=hourly
```

Příkaz oznámí, kolik se deduplikovalo:

```text
Snapshot       app-2026-07-19-020000-1a2b3c.manifest.json
Mode           Incremental snapshot
Files          12,481
Deduplicated   37 new, 12,444 reused (18.2 MB new content)
Destinations   s3
```

## Jak to funguje

```text
{path}/
  snapshots/
    {name}.manifest.json     jeden index na snapshot (seznam souborů + jejich hash)
  blobs/
    {algorithm}/{ab}/{digest} obsahově adresovaný pool (jeden blob na unikátní obsah)
```

- Každý soubor je uložen jako **blob adresovaný hashem svého obsahu**. Shodný
  obsah — napříč soubory, snapshoty i běhy — je uložen právě jednou.
- **Manifest snapshotu je jeho index**: obsahuje každý soubor s hashem, který
  lokalizuje jeho blob. Protože se adresuje obsahem, je **každý snapshot
  soběstačný** vůči poolu — při obnově se neprochází žádný křehký řetězec
  přírůstků.
- Archivní zálohy a snapshoty **koexistují** pod stejným `path` bez kolizí.

## Obnova

Obnova je identická jako u archivních záloh — snapshot se z poolu transparentně
znovu sestaví:

```bash
php artisan backup:restore --profile=hourly           # nejnovější snapshot
php artisan backup:restore app-2026-07-19-...manifest.json --profile=hourly
```

Obnova **staršího** snapshotu věrně vrátí jeho historický obsah i poté, co
existují novější snapshoty, protože každý blob je adresován obsahem. Platí
všechny volby obnovy: `--database`, `--files`, `--only=`, `--unsafe`,
`--no-verify`. Ověřené obnovy kontrolují kotvu indexu souborů snapshotu i
kontrolní součet každého blobu. Podrobnosti viz [Obnova](07-obnova.md).

## Komprese a šifrování

Bloby respektují nastavení `compression` a `encryption` profilu: každý blob se
zkomprimuje a poté zašifruje, ale stále je **adresován hashem svého
plaintextu**, takže deduplikace zůstává plně zachována a bajty na disku jsou
chráněné.

> **Algoritmus a klíč považujte za neměnné po dobu života poolu**, jako heslo k
> repozitáři. Jejich změna nechá dřívější bloby nečitelné pro pozdější snapshoty,
> které očekávají nové nastavení.

## Retence a garbage collection

`backup:clean` prořezává snapshoty stejnou politikou jako archivy (GFS / simple +
limity stáří / velikosti) a poté získává zpět místo v poolu **mark-and-sweep s
počítáním referencí**:

```bash
php artisan backup:clean --profile=hourly --dry-run
```

```text
s3             would delete 3 backup(s)
  app-2026-07-12-...  2026-07-12 02:00
  app-2026-07-13-...  2026-07-13 02:00
  app-2026-07-14-...  2026-07-14 02:00
  blob pool    would reclaim 214 blob(s) · 1.9 GB
```

Blob se smaže **jen tehdy, když jej nereferencuje žádný přeživší snapshot** —
prořezání jednoho snapshotu nikdy nerozbije jiný, který s ním sdílí obsah. Sweep
je **fail-closed**: pokud nelze přečíst kterýkoli přeživší manifest, nesmaže se
žádný blob. Viz [Retence](08-retence.md).

> `backup:clean` spouštějte, když **neběží** zálohy. Jako každý mark-and-sweep
> collector předpokládá stabilní množinu snapshotů po dobu sweepu.

## Ověření a monitoring

- `backup:verify` potvrdí index souborů snapshotu, že je přítomen každý
  referencovaný blob a že každý blob (dešifrovaný/dekomprimovaný) hashuje na svou
  zaznamenanou hodnotu — reálná kontrola obnovitelnosti.
- `backup:list` a `backup:monitor` počítají snapshoty vedle archivů, takže profil
  jen se snapshoty není nikdy hlášen jako prázdný. Viz [Přehled CLI](11-cli.md).

## Kdy použít co

| Případ užití | Doporučení |
| --- | --- |
| Noční úplná záloha, držená měsíce | **archive** (soběstačná kotva) |
| Hodinově/denně, citlivé na místo | **incremental** |
| Bod pro rollback před nasazením | malý **archive**, jen databáze |
| Mnoho téměř identických hostů/záloh | **incremental** (dedup napříč zálohami) |
