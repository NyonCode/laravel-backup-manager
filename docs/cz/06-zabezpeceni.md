# Zabezpečení a manifest

Backup Manager poskytuje čtyři vrstvy ochrany: **šifrování**, **kontrolní
součty**, **podepisování** a **manifest**.

## Šifrování

Výsledný archiv lze zašifrovat pomocí AES-256 přes `ext-openssl`:

```php
'encryption' => [
    'algorithm' => 'aes-256-gcm',        // none | aes-256-cbc | aes-256-gcm
    'key' => env('BACKUP_ENCRYPTION_KEY'),
],
```

### Volba režimu

| Režim | Autentizovaný | Streamovaný | Použití |
|-------|---------------|-------------|---------|
| `aes-256-cbc` | ne | ano (plochá paměť) | velmi velké archivy |
| `aes-256-gcm` | **ano** (detekuje manipulaci) | v paměti | archivy, které se pohodlně vejdou do paměti |

GCM detekuje manipulaci při dešifrování (pozměněný archiv se nepodaří obnovit).
CBC streamuje po blocích, takže i libovolně velké archivy se šifrují s plochou
pamětí.

> **Paměť:** GCM načítá celý archiv do paměti. Aby nedošlo k jejímu vyčerpání u
> velkých záloh, GCM odmítne archiv větší než `encryption.gcm_max_megabytes`
> (výchozí 1024) s jasnou chybou — pro velké zálohy použijte `aes-256-cbc`, nebo
> limit zvyšte.

### Klíč

Klíč musí mít **32 bajtů**. Prefix `base64:` se automaticky dekóduje, takže
můžete využít konvenci Laravelu:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

```dotenv
BACKUP_ENCRYPTION=aes-256-gcm
BACKUP_ENCRYPTION_KEY=base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=
```

> ⚠️ Klíč ukládejte mimo zálohu. Bez něj nelze šifrovanou zálohu obnovit. Klíč se
> nikdy nezapisuje do manifestu ani do logů.

## Kontrolní součty

Každá záloha zaznamenává kontrolní součet (výchozí SHA-256, volitelně SHA-512) z:

- **celého archivu** — ověřuje se před každou obnovou a příkazem `backup:verify`;
- **každého jednotlivého souboru** — v manifestu pro ověření po souborech.

```php
'integrity' => ['checksum' => 'sha256'],  // sha256 | sha512
```

Porovnání probíhá v konstantním čase, aby nedocházelo k časovým postranním
kanálům.

## Podepisování

> **Funkce Pro.** HMAC podpis manifestu vyžaduje
> [Backup Manager Pro](13-pro-a-ui.md). Free jádro si ponechá kontrakt `Signer`,
> ale bez implementace: se zapnutým podpisem a chybějícím Pro záloha selže
> fail-closed, takže se nikdy nevytvoří nepodepsaná, zatímco ji považujete za
> podepsanou.

Volitelný klíčovaný HMAC podpis autentizuje samotný manifest — dokazuje, že
zálohu vytvořil držitel podpisového klíče a že nebyla pozměněna:

```php
'integrity' => [
    'signing' => [
        'enabled' => true,
        'key' => env('BACKUP_SIGNING_KEY'),  // výchozí = šifrovací klíč
    ],
],
```

`backup:verify` hlásí jak kontrolní součet archivu, tak stav podpisu.

## Manifest

Každá záloha nese přiložený `*.manifest.json` — sebepopisný záznam, díky němuž je
záloha ověřitelná nezávisle na tomto balíčku. Obsahuje:

- verzi schématu, název profilu, typ zálohy, čas vytvoření;
- **prostředí**: název a verzi aplikace, verzi Laravelu, verzi PHP, název
  prostředí, operační systém;
- použité **ovladače**: algoritmy komprese, šifrování, kontrolního součtu;
- zálohované **databáze** (připojení, ovladač, název databáze, cesta v archivu);
- velikost a kontrolní součet **archivu**;
- **každý soubor**: cesta v archivu, velikost, kontrolní součet, info o symlinku;
- **migrace**, které byly nasazené v okamžiku zálohy;
- volitelný **podpis**.

Protože jde o sidecar, můžete jej číst a ověřit kontrolní součet archivu **bez
dešifrování** — je to prostý JSON, který otevřete v jakémkoli editoru.

### Spárování dumpu s kódem

Databázový dump sedí jen ke kódu, jehož očekávání o schématu mu odpovídají — a
verze aplikace to nedokazuje: dva commity můžou mít stejný version string a lišit
se o migraci. Manifest proto zaznamenává nasazené migrace:

```json
"migrations": {
  "ran": [
    "2024_01_01_000000_create_users_table",
    "2024_02_01_000000_add_roles_to_users"
  ]
}
```

`MigrationState` odpoví na to, co je před obnovou podstatné:

```php
$manifest = app(BackupRepository::class)->readManifest($backup);
$current  = app(MigrationProbe::class)->capture();

$current->ahead($manifest->migrations);   // migrace, které má kód a dump ne
$manifest->migrations->ahead($current);   // migrace, které má dump a kód ne
$current->matches($manifest->migrations); // přesná shoda
```

První seznam znamená, že obnova nechá schéma pozadu za kódem — obvykle to spraví
následné zmigrování. Druhý znamená, že dump vznikl na novějším schématu, než jaké
kód očekává, a to migrací zpět nespravíte.

Čtení migrací nikdy nezhatí zálohu: aplikace bez databáze, s nedostupnou databází
nebo bez jediné migrace zapíše `null`. Stejně tak manifesty vzniklé před schématem
1.2 — `null` znamená *nezaznamenáno*, ne *žádné migrace neproběhly*.

## Ověření zálohy

```bash
php artisan backup:verify                    # nejnovější záloha
php artisan backup:verify moje-zaloha.tar.gz.enc
```

Stáhne archiv, přepočítá jeho kontrolní součet vůči manifestu a je-li podepsán,
ověří podpis — lehký test obnovitelnosti. Obnova tuto kontrolu provádí také
automaticky (vypnete přes `--no-verify`). Při zapnutém podepisování selže
ověření manifestu, který **žádný** podpis nenese — stejné pravidlo platí při
obnově, protože odstranění podpisu je nejlevnější způsob, jak se mu vyhnout.

## Zpřísnění v 1.3

Chování, které dostanete bez jakéhokoli nastavení:

- **Podepisování vyžaduje klíč.** Podepsání s prázdným klíčem se odmítne, místo
  aby vznikl podpis, který může padělat kdokoli.
- **Zapněte podepisování, obnovujete-li ze sdíleného úložiště.** Manifesty jsou
  jinak neověřený prostý JSON; kdo umí zapisovat do cíle, může jej upravit. Podpis
  v Pro (výše) tohle uzavírá.
- **Obnova drží symlinky uvnitř vašich cest.** Symbolický odkaz se znovu vytvoří
  jen tehdy, když jeho cíl zůstává uvnitř *aktuálních* cest `files.include`
  profilu. Jinak se přeskočí a zapíše se varování:

  ```text
  Backup Manager restore skipped the symbolic link [public/leak] -> [/etc]: its target lies outside the profile's include paths.
  ```

- **`--only` porovnává normalizované cesty.** Cesty z manifestu se před
  porovnáním v `backup:restore --only` normalizují, takže zvláštně zapsaná cesta
  v manifestu filtr neobejde.
- **Soukromý pracovní adresář.** Dočasný workspace se vytváří s oprávněním `0700`
  a při selhání zálohy se odstraní, takže po nedokončených dumpech nic nezůstane.
- **Digesty musí být hex.** Kontrolní součty čtené z manifestu musí být
  hexadecimální řetězce.
- **Retence vždy ponechá nejnovější zálohu**, i když by `max_age_days` nebo strop
  úložiště odstranily všechno (viz [Retence](08-retence.md)).
- **CBC je autentizované.** Archivy `aes-256-cbc` se nově vytvářejí jako
  encrypt-then-MAC (hlavička s id 3): HMAC-SHA256 značka na konci, s podklíčem
  odvozeným pomocí HKDF z vašeho šifrovacího klíče, se ověří *dřív*, než se
  dešifruje jediný bajt. Nic se nenastavuje. Algoritmus uvedený v hlavičce
  archivu se také musí shodovat s manifestem, jinak se dešifrování odmítne.

  Archivy z doby před 1.3 (hlavička s id 1) se stále dešifrují, se zapsaným
  varováním. Nic je neautentizuje — před padělkem nebo downgradem je chrání jen
  podepisování v Pro.

- **Obnovované SQL dumpy se kontrolují.** Před spuštěním klienta se odmítne dump,
  který nese příkazy klienta `mysql`/`psql` — `\!`, `system`, `source`, `tee`,
  `pager` nebo libovolný meta-příkaz psql kromě `\connect`, `\restrict` a
  `\unrestrict` —, protože takový příkaz spouští shellové příkazy nebo sahá na
  soubory hostitele. Podporuje-li to klient, obnova MySQL/MariaDB navíc předá
  `--sandbox` (nebo `--system-command=OFF`).
- **Výpisy počítají jen vaše archivy.** Soubor je záloha jen tehdy, když se jmenuje
  jako archivy tohoto balíčku —
  `{profil}-{Y-m-d-His}-{hex}.tar[.gz|.bz2|.zst][.enc]`. Ostatní soubory v cestě se
  nikdy nevypíšou, nespočítají ani neprořežou.
- **Obnova je omezena na vaše include cesty.** Obnova, jejíž soubory leží mimo
  *aktuální* cesty `files.include` profilu, se odmítne dřív, než se cokoli změní.
  Viz [Kam se soubory obnovují](07-obnova.md#kam-se-soubory-obnovují).
- **Oprávnění se obnovují.** Módy souborů se zaznamenávají do manifestu a při
  obnově znovu nastaví, takže soukromý soubor se nevrátí čitelný pro všechny.
- **Dešifrování GCM je omezené.** `aes-256-gcm` drží archiv v paměti kvůli
  autentizaci, proto se dešifrování nad `encryption.gcm_max_megabytes`
  (`BACKUP_GCM_MAX_MB`, výchozí 1024) odmítne.
- **Dlouhé názvy v tar jsou omezené.** Tar hlavička deklarující dlouhý název nad
  64 KiB se odmítne.
- **`backup:upgrade` chrání váš licenční token.** Odmítne repozitář na prostém
  `http` a token čte z `BACKUP_PRO_TOKEN` nebo z dotazu (viz
  [Pro a UI](13-pro-a-ui.md#přechod-na-pro)).
- **Slack upozornění jen přes https (Pro).** URL webhooku musí používat `https`,
  požadavek vyprší po 10 sekundách a URL se nikdy nezapisuje do logu.
- **Obnova kontroluje, kam zápis skutečně dopadne.** Cesty se porovnávají až po
  rozlišení všech symlinků, které na serveru existují, a symbolické odkazy se
  obnovují až po všech běžných souborech — zápis tak mimo profil nevynese ani
  odkaz, který už na disku je, ani odkaz ze zálohy. Povolené cesty určuje profil,
  pro který obnovujete (`backup:restore --profile=…`, profil v dashboardu);
  záloha, jejíž manifest uvádí jiný profil, se odmítne.
- **Starý formát CBC (před 1.3) vyžaduje ověření.** Archivy a bloby snapshotů v
  něm se dešifrují jen u podepsané zálohy, nebo s
  `backup:restore --allow-legacy-cbc` (`BACKUP_ALLOW_LEGACY_CBC=true`, přes MCP
  nenastavitelné) — přepsaný archiv se nemůže vydávat za starý.
- **Dumpy, které mění, jak je klient čte, se odmítnou.** Kromě klientských příkazů
  se odmítne dump, který přepne `sql_mode` MySQL na `NO_BACKSLASH_ESCAPES` /
  `ANSI_QUOTES` (nebo ho nastaví z čehokoli jiného než literálu či hodnoty uložené
  mysqldumpem), vypne `standard_conforming_strings` v PostgreSQL nebo zvolí
  vícebajtovou znakovou sadu klienta (SJIS, BIG5, GBK …). `\connect` smí uvést
  jen databázi — nikdy host, port ani službu.

### Zápisy do `.env` a přes MCP

Když asistent nastavuje Backup Manager přes MCP nástroj `backup_configure`
(viz [MCP](14-mcp.md)):

- Nikdy nemůže zapsat `mcp.*`, `binaries.*`, `pro.*` ani `working_directory` —
  ani s `force`. Asistent si tak nemůže rozšířit vlastní oprávnění ani nasměrovat
  balíček na jiný binární soubor.
- Hodnota obsahující `$` se do `.env` zapíše v jednoduchých uvozovkách, takže se
  nerozbalí `${VAR}`. Hodnota, kterou nelze zapsat bezpečně, se odmítne.
- Šifrovací ani podpisový klíč nejde přes asistenta vyměnit — jen nastavit, dokud
  žádný není: nový klíč by znepřístupnil všechny zálohy vytvořené tím starým.
  Klíče rotujte ručně.
- Zapnuté šifrování ani podepisování asistent nevypne.
