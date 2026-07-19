# Soubory

Záloha může vedle databází — nebo místo nich — zahrnout soubory a složky z vašeho
projektu. Tato stránka popisuje, co se do zálohy dostane a jak to doladit.

## Zahrnuté kořeny

Uveďte adresáře (nebo jednotlivé soubory) k archivaci:

```php
'files' => [
    'include' => [
        base_path('app'),
        base_path('.env'),
        storage_path('app/public'),
    ],
],
```

Každý soubor je v archivu uložen pod prefixem `files/`, který zrcadlí jeho
absolutní cestu (např. `files/var/www/app/Models/User.php`), takže položky z více
kořenů nikdy nekolidují a při obnově se čistě namapují zpět.

## Vyloučení

```php
'files' => [
    'exclude' => [
        base_path('vendor'),      // prefix cesty
        base_path('node_modules'),
        '*.log',                  // fnmatch glob
        storage_path('framework'),
    ],
],
```

Vzor odpovídá, pokud je **prefixem cesty** souboru, nebo pokud odpovídá cestě
(či jejímu názvu souboru) jako **fnmatch glob**. Díky tomu lze v jednom seznamu
vyloučit celé adresáře i typy souborů.

## Skryté soubory

```php
'files' => ['include_hidden' => true],  // zahrnout dotfiles jako .env
```

Nastavte na `false` pro přeskočení dotfiles a dot-adresářů.

## Symlinky

```php
'files' => ['follow_symlinks' => false],
```

- **`false` (výchozí)** — symlink je v manifestu zaznamenán jako metadata odkazu
  (jeho cíl), bez archivace obsahu cíle. Při obnově se symlink znovu vytvoří.
  Zachovává to sémantiku odkazu bez duplikace dat.
- **`true`** — odkazy se následují a obsah cíle se archivuje jako běžné soubory.

## Komprese

Komprese se aplikuje na celý archiv (ne na jednotlivé soubory):

| Algoritmus | Vyžaduje | Poznámka |
|------------|----------|----------|
| `none` | — | nejrychlejší, největší |
| `gzip` | vestavěné | dobrá výchozí volba |
| `bzip2` | `ext-bz2` | menší, pomalejší |
| `zstd` | binárka `zstd` | nejlepší poměr rychlost/velikost |

```php
'compression' => ['algorithm' => 'gzip', 'level' => 6],
```

Komprese je streamovaná, paměť tak zůstává plochá bez ohledu na velikost archivu.

## Formát archivu a limity

Zálohy se balí do **streamovaného TAR archivu** s GNU rozšířeními, který zapisuje
sám balíček (ne PharData). To znamená:

- **Žádný limit 8 GiB na soubor** — velké databázové dumpy i média jsou
  podporované přes GNU base‑256 pole velikosti.
- **Žádný limit 100 bajtů na cestu** — dlouhé cesty používají GNU long‑name entry.
- **Plochá paměť** — čtení i zápis jsou streamované po 1 MiB, velikost archivu ani
  souboru neovlivní spotřebu paměti.

Archivy jsou standardní a čitelné pomocí GNU `tar` / `bsdtar`.

## Zálohy pouze souborů

```php
'profiles' => [
    'uploads' => [
        'type' => 'files',
        'files' => ['include' => [storage_path('app/public')]],
        'destinations' => ['s3'],
    ],
],
```
