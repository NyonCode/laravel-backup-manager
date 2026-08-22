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
automaticky (vypnete přes `--no-verify`).
