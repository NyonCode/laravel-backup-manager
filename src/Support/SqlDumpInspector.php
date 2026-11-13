<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Nyoncode\BackupManager\Enums\DatabaseDriver;
use Nyoncode\BackupManager\Exceptions\RestoreException;

/**
 * Refuses a SQL dump that carries client commands before it is fed to the
 * `mysql` / `psql` client.
 *
 * Both clients act on more than SQL. `\! cmd` (and `system cmd`) runs a shell
 * command as the PHP user, `\. file` / `source` and `\i` read arbitrary files,
 * `tee`, `\o` and `\w` write them, and `\connect host=…` hands the restore's
 * password to another server. A dump is data from backup storage — and without
 * Pro signing nothing proves it is the dump this package wrote — so a restore
 * must never be the step that turns write access to a bucket into code
 * execution on the application host.
 *
 * What the dumpers emit never needs any of that. mysqldump writes no backslash
 * outside a string literal at all; pg_dump's plain format uses `\connect` (to a
 * database name), `\restrict` / `\unrestrict` and the `\.` that ends a
 * `COPY … FROM stdin` block. So the rule is an allow-list over a lexer that
 * knows where strings, quoted identifiers, comments, dollar-quoted bodies and
 * COPY data are, and where each statement ends — a backslash inside any of
 * those is data, not a command.
 *
 * The lexer must read the dump exactly as the client will, so a statement
 * that would change how the client reads what follows is refused outright:
 * MySQL's `NO_BACKSLASH_ESCAPES` / `ANSI_QUOTES` (or a `sql_mode` set from
 * anything but a plain literal or mysqldump's own saved value), PostgreSQL's
 * `standard_conforming_strings` set to anything but `on`, and a client
 * character set in which a backslash byte can be half of another character
 * (SJIS, BIG5, GBK …). No dump this package writes contains one.
 *
 * Streamed line by line and skipping uninteresting runs with strcspn(), so a
 * multi-gigabyte dump is inspected without loading it.
 */
final class SqlDumpInspector
{
    /**
     * Every long-form mysql client command. At the start of a line, with no
     * statement pending, the client runs these instead of sending SQL. Only
     * `delimiter` and `use` appear in real dumps; they are handled apart.
     */
    private const MYSQL_NAMED = [
        'charset', 'clear', 'connect', 'edit', 'ego', 'exit', 'go', 'help', 'nopager',
        'notee', 'nowarning', 'pager', 'print', 'prompt', 'query_attributes', 'quit',
        'rehash', 'resetconnection', 'source', 'ssl_session_data_print', 'status',
        'system', 'tee', 'warnings',
    ];

    /** psql meta-commands pg_dump itself writes. */
    private const PSQL_ALLOWED = ['connect', 'c', 'restrict', 'unrestrict'];

    /**
     * Inspect a dump for whichever client restores it; SQLite has none.
     *
     * @throws RestoreException when the dump carries a client command.
     */
    public static function assertSafe(DatabaseDriver $driver, string $path): void
    {
        match ($driver) {
            DatabaseDriver::PostgreSql => self::assertSafeForPsql($path),
            DatabaseDriver::Sqlite => null,
            default => self::assertSafeForMysql($path),
        };
    }

    // ------------------------------------------------------------------
    // MySQL / MariaDB
    // ------------------------------------------------------------------

    /**
     * @throws RestoreException when the dump carries a client command.
     */
    public static function assertSafeForMysql(string $path): void
    {
        $handle = self::open($path);
        $lexer = ['state' => 'normal', 'delimiter' => ';', 'code' => '', 'literal' => '', 'literals' => []];
        $lineNo = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $lineNo++;
                $idle = $lexer['state'] === 'normal' && trim($lexer['code']) === '';

                // Client commands are read at the start of a line with nothing
                // pending; anywhere else the same word is SQL.
                if ($idle && preg_match('/^\s*DELIMITER\s+(\S+)/i', $line, $m) === 1) {
                    $lexer['delimiter'] = $m[1];

                    continue;
                }

                if ($idle && preg_match('/^\s*('.implode('|', self::MYSQL_NAMED).')(\s|;|$)/i', $line, $m) === 1) {
                    throw RestoreException::unsafeDump($path, $lineNo, strtolower($m[1]));
                }

                self::scanMysqlLine($line, $lexer, $path, $lineNo);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array{state: string, delimiter: string, code: string, literal: string, literals: list<string>}  $lexer
     */
    private static function scanMysqlLine(string $line, array &$lexer, string $path, int $lineNo): void
    {
        $length = strlen($line);
        $i = 0;

        while ($i < $length) {
            switch ($lexer['state']) {
                case 'normal':
                    $run = strcspn($line, "'\"`\\#-/".$lexer['delimiter'][0], $i);
                    $lexer['code'] .= substr($line, $i, $run);
                    $i += $run;

                    if ($i >= $length) {
                        break 2;
                    }

                    $char = $line[$i];

                    if (substr($line, $i, strlen($lexer['delimiter'])) === $lexer['delimiter']) {
                        self::endMysqlStatement($lexer, $path, $lineNo);
                        $i += strlen($lexer['delimiter']);

                        break;
                    }

                    if ($char === '\\') {
                        throw RestoreException::unsafeDump($path, $lineNo, '\\'.($line[$i + 1] ?? ''));
                    }

                    if ($char === '#' || ($char === '-' && ($line[$i + 1] ?? '') === '-' && ctype_space($line[$i + 2] ?? "\n"))) {
                        $lexer['code'] .= ' ';

                        break 2; // comment to end of line
                    }

                    if ($char === '/' && ($line[$i + 1] ?? '') === '*') {
                        $next = $line[$i + 2] ?? '';

                        // `/*!50003 … */` and optimizer hints are SQL the server runs.
                        if ($next === '!' || $next === '+') {
                            $i += 3;
                            $i += strspn($line, '0123456789', $i); // the version: `/*!40101`
                            $lexer['code'] .= ' ';

                            break;
                        }

                        $lexer['state'] = 'block';
                        $lexer['code'] .= ' ';
                        $i += 2;

                        break;
                    }

                    if ($char === "'" || $char === '"' || $char === '`') {
                        $lexer['state'] = match ($char) {
                            "'" => 'sq',
                            '"' => 'dq',
                            default => 'bt',
                        };
                        $lexer['literal'] = '';
                        $i++;

                        break;
                    }

                    $lexer['code'] .= $char;
                    $i++;

                    break;

                case 'sq':
                case 'dq':
                    $quote = $lexer['state'] === 'sq' ? "'" : '"';
                    $run = strcspn($line, $quote.'\\', $i);
                    $lexer['literal'] .= substr($line, $i, $run);
                    $i += $run;

                    if ($i >= $length) {
                        break 2;
                    }

                    if ($line[$i] === '\\') {
                        $lexer['literal'] .= $line[$i + 1] ?? '';
                        $i += 2; // escaped character, whatever it is

                        break;
                    }

                    self::closeLiteral($lexer, $quote);
                    $i++;

                    break;

                case 'bt':
                    $run = strcspn($line, '`', $i);
                    $lexer['literal'] .= substr($line, $i, $run);
                    $i += $run;

                    if ($i < $length) {
                        self::closeLiteral($lexer, '`');
                        $i++;
                    }

                    break;

                case 'block':
                    $end = strpos($line, '*/', $i);

                    if ($end === false) {
                        break 2;
                    }

                    $lexer['state'] = 'normal';
                    $i = $end + 2;

                    break;
            }
        }
    }

    /**
     * @param  array{state: string, delimiter: string, code: string, literal: string, literals: list<string>}  $lexer
     */
    private static function closeLiteral(array &$lexer, string $quote): void
    {
        $lexer['code'] .= $quote.'#'.count($lexer['literals']).$quote;
        $lexer['literals'][] = $lexer['literal'];
        $lexer['literal'] = '';
        $lexer['state'] = 'normal';
    }

    /**
     * A finished statement: refused if it would change how the client reads
     * the rest of the dump.
     *
     * @param  array{state: string, delimiter: string, code: string, literal: string, literals: list<string>}  $lexer
     */
    private static function endMysqlStatement(array &$lexer, string $path, int $lineNo): void
    {
        $code = strtolower((string) preg_replace('/\s+/', ' ', $lexer['code']));
        $literals = $lexer['literals'];
        $lexer['code'] = '';
        $lexer['literals'] = [];

        $literalAt = static fn (string $index): string => strtolower($literals[(int) $index] ?? '');

        // Quoted identifiers are names, not data: put them back as plain words
        // so `` @@`sql_mode` `` reads as what it is. The closing `*/` of a
        // versioned comment is noise here.
        $code = (string) preg_replace_callback('/`#(\d+)`/', static fn (array $m): string => $literalAt($m[1]), $code);
        $code = trim((string) preg_replace('/\s+/', ' ', str_replace('*/', ' ', $code)));

        // After `DELIMITER ;;` one "statement" can be a batch of several; the
        // client sends it whole and the server runs each. Judge every part.
        foreach (explode(';', $code) as $part) {
            self::checkMysqlStatement(trim($part), $literalAt, $path, $lineNo);
        }
    }

    /**
     * @param  callable(string): string  $literalAt
     */
    private static function checkMysqlStatement(string $code, callable $literalAt, string $path, int $lineNo): void
    {
        if ($code === '') {
            return;
        }

        // Anything that touches sql_mode must be one of the exact forms
        // mysqldump writes; everything else — a variable, PREPARE, INTO
        // @saved_sql_mode, SET STATEMENT, a routine body — is refused. A
        // literal mode is fine unless it changes how strings are read.
        if (str_contains($code, 'sql_mode')) {
            $safeLiteral = static fn (string $index): bool => preg_match('/no_backslash_escapes|ansi_quotes|\bansi\b/', $literalAt($index)) !== 1;
            $ok = preg_match("/^set @old_sql_mode ?= ?@@sql_mode, ?sql_mode ?= ?'#(\\d+)'$/", $code, $m) === 1 && $safeLiteral($m[1])
                || preg_match("/^set sql_mode ?= ?'#(\\d+)'$/", $code, $m) === 1 && $safeLiteral($m[1])
                || preg_match('/^set (?:sql_mode ?= ?@(?:old|saved)_sql_mode|@saved_sql_mode ?= ?@@sql_mode)$/', $code) === 1;

            if (! $ok) {
                throw RestoreException::unsafeDump($path, $lineNo, 'a statement that changes sql_mode');
            }
        }

        // A user variable spelled with quotes (`@'saved_sql_mode'`) would hide
        // its name from every rule here; mysqldump never quotes one.
        if (preg_match('/@[\'"]/', $code) === 1) {
            throw RestoreException::unsafeDump($path, $lineNo, 'a quoted user variable');
        }

        // Dynamic SQL, a stored routine run on the spot, or a value smuggled
        // into a variable could do the same out of sight. mysqldump writes none
        // of them outside a routine's own body.
        if (preg_match('/^(?:prepare|execute|call)\b/', $code) === 1 || preg_match('/\binto\s*@/', $code) === 1) {
            throw RestoreException::unsafeDump($path, $lineNo, strtok($code, ' ') ?: 'prepare');
        }

        // Client character sets: a statement that touches one must be, as a
        // whole, one of the shapes mysqldump writes — a list of assignments of
        // allow-listed charsets, or of the server's own values into and back
        // out of its saved variables. A table's `DEFAULT CHARSET=sjis` or a
        // column's `CHARACTER SET sjis` is the server's business and passes.
        if (preg_match('/character_set_(?:client|connection|results)|collation_connection|@(?:saved|old)_c|\bset (?:session |local |global )?(?:names|character set|charset)\b/', $code) === 1) {
            $value = "(?:'#\\d+'|@{0,2}[\\w.]+)";
            $target = '(?:@@)?(?:character_set_(?:client|connection|results)|collation_connection)|@(?:saved|old)_c\\w+';
            $assignment = "(?:(?:{$target}) ?:?= ?{$value}|(?:names|character set|charset) {$value}(?: collate {$value})?)";

            if (preg_match("/^set (?:session |local |global )?{$assignment}(?:, ?{$assignment})*$/", $code) !== 1) {
                throw RestoreException::unsafeDump($path, $lineNo, 'a statement that changes the client character set');
            }

            preg_match_all("/(?:(?:{$target}) ?:?= ?|(?:names|character set|charset|collate) )({$value})/", $code, $sets);

            foreach ($sets[1] as $set) {
                $set = preg_match("/^'#(\\d+)'$/", $set, $m) === 1 ? $literalAt($m[1]) : $set;

                if (! self::safeMysqlCharset($set)) {
                    throw RestoreException::unsafeDump($path, $lineNo, 'client character set '.$set);
                }
            }
        }
    }

    private static function safeMysqlCharset(string $value): bool
    {
        if (str_starts_with($value, '@')) {
            return preg_match('/^(?:@@(?:character_set_\w+|collation_connection)|@(?:saved_c\w+|old_c\w+))$/', $value) === 1;
        }

        // Character sets (and their collations) in which 0x5C is always a backslash.
        return preg_match('/^(?:utf8|utf8mb3|utf8mb4|latin1|latin2|ascii|binary|default)(?:_\w+)?$/', $value) === 1;
    }

    // ------------------------------------------------------------------
    // PostgreSQL
    // ------------------------------------------------------------------

    /**
     * @throws RestoreException when the dump carries a disallowed meta-command.
     */
    public static function assertSafeForPsql(string $path): void
    {
        $handle = self::open($path);
        $lexer = ['state' => 'normal', 'tag' => '', 'depth' => 0, 'code' => '', 'literal' => '', 'literals' => []];
        $lineNo = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $lineNo++;

                if ($lexer['state'] === 'copy') {
                    if (rtrim($line, "\r\n") === '\\.') {
                        $lexer['state'] = 'normal';
                    }

                    continue;
                }

                self::scanPsqlLine($line, $lexer, $path, $lineNo);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array{state: string, tag: string, depth: int, code: string, literal: string, literals: list<string>}  $lexer
     */
    private static function scanPsqlLine(string $line, array &$lexer, string $path, int $lineNo): void
    {
        $length = strlen($line);
        $i = 0;

        while ($i < $length) {
            switch ($lexer['state']) {
                case 'normal':
                    $run = strcspn($line, "'\"\\-/$;", $i);
                    $lexer['code'] .= substr($line, $i, $run);
                    $i += $run;

                    if ($i >= $length) {
                        break 2;
                    }

                    $char = $line[$i];

                    if ($char === ';') {
                        $copy = self::endPsqlStatement($lexer, $path, $lineNo);
                        $i++;

                        if ($copy) {
                            // COPY data starts on the next line; anything after the
                            // statement on this one would be read ambiguously.
                            if (trim(substr($line, $i)) !== '') {
                                throw RestoreException::unsafeDump($path, $lineNo, 'text after COPY … FROM stdin');
                            }

                            $lexer['state'] = 'copy';

                            break 2;
                        }

                        break;
                    }

                    if ($char === '\\') {
                        self::metaCommand(substr($line, $i), $path, $lineNo);

                        break 2; // an allowed command consumes the rest of the line
                    }

                    if ($char === '-' && ($line[$i + 1] ?? '') === '-') {
                        $lexer['code'] .= ' ';

                        break 2;
                    }

                    if ($char === '/' && ($line[$i + 1] ?? '') === '*') {
                        $lexer['state'] = 'block';
                        $lexer['depth'] = 1;
                        $lexer['code'] .= ' ';
                        $i += 2;

                        break;
                    }

                    $previous = $i > 0 ? $line[$i - 1] : '';
                    $afterIdentifier = $previous !== '' && preg_match('/[A-Za-z0-9_$]/', $previous) === 1;

                    if ($char === '$') {
                        // A dollar quote: $$ or $tag$ — never straight after an
                        // identifier character (`a$b$` is an identifier).
                        if (! $afterIdentifier && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $line, $m, 0, $i) === 1) {
                            $lexer['tag'] = $m[0];
                            $lexer['state'] = 'dollar';
                            $lexer['literal'] = '';
                            $i += strlen($m[0]);

                            break;
                        }

                        $lexer['code'] .= '$';
                        $i++;

                        break;
                    }

                    if ($char === "'") {
                        // E'…' allows backslash escapes — but only as a prefix,
                        // not as the last letter of an identifier.
                        $beforePrefix = $i > 1 ? $line[$i - 2] : '';
                        $escaped = ($previous === 'E' || $previous === 'e')
                            && ($beforePrefix === '' || preg_match('/[A-Za-z0-9_$]/', $beforePrefix) !== 1);

                        $lexer['state'] = $escaped ? 'esq' : 'sq';
                        $lexer['literal'] = '';
                        $i++;

                        break;
                    }

                    if ($char === '"') {
                        $lexer['state'] = 'dq';
                        $lexer['literal'] = '';
                        $i++;

                        break;
                    }

                    // a lone `-` or `/`
                    $lexer['code'] .= $char;
                    $i++;

                    break;

                case 'sq':
                case 'dq':
                    // Standard strings and identifiers: a doubled quote is the only
                    // escape, and it reads naturally as close-then-reopen.
                    $quote = $lexer['state'] === 'sq' ? "'" : '"';
                    $run = strcspn($line, $quote, $i);
                    $lexer['literal'] .= substr($line, $i, $run);
                    $i += $run;

                    if ($i < $length) {
                        self::closePsqlLiteral($lexer, $quote);
                        $i++;
                    }

                    break;

                case 'esq':
                    $run = strcspn($line, "'\\", $i);
                    $lexer['literal'] .= substr($line, $i, $run);
                    $i += $run;

                    if ($i >= $length) {
                        break 2;
                    }

                    if ($line[$i] === '\\') {
                        $lexer['literal'] .= $line[$i + 1] ?? '';
                        $i += 2;

                        break;
                    }

                    self::closePsqlLiteral($lexer, "'");
                    $i++;

                    break;

                case 'dollar':
                    $end = strpos($line, $lexer['tag'], $i);

                    if ($end === false) {
                        $lexer['literal'] .= substr($line, $i);

                        break 2;
                    }

                    $lexer['literal'] .= substr($line, $i, $end - $i);
                    $i = $end + strlen($lexer['tag']);
                    $lexer['tag'] = '';
                    self::closePsqlLiteral($lexer, "'");

                    break;

                case 'block':
                    // PostgreSQL block comments nest.
                    $i += strcspn($line, '/*', $i);

                    if ($i >= $length) {
                        break 2;
                    }

                    $pair = substr($line, $i, 2);

                    if ($pair === '/*') {
                        $lexer['depth']++;
                        $i += 2;
                    } elseif ($pair === '*/') {
                        $lexer['depth']--;
                        $i += 2;

                        if ($lexer['depth'] === 0) {
                            $lexer['state'] = 'normal';
                        }
                    } else {
                        $i++;
                    }

                    break;
            }
        }
    }

    /**
     * @param  array{state: string, tag: string, depth: int, code: string, literal: string, literals: list<string>}  $lexer
     */
    private static function closePsqlLiteral(array &$lexer, string $quote): void
    {
        $lexer['code'] .= $quote.'#'.count($lexer['literals']).$quote;
        $lexer['literals'][] = $lexer['literal'];
        $lexer['literal'] = '';
        $lexer['state'] = 'normal';
    }

    /**
     * A finished statement. Returns whether it starts COPY data on the next
     * line; refuses one that would change how psql reads the rest.
     *
     * @param  array{state: string, tag: string, depth: int, code: string, literal: string, literals: list<string>}  $lexer
     */
    private static function endPsqlStatement(array &$lexer, string $path, int $lineNo): bool
    {
        $code = strtolower(trim((string) preg_replace('/\s+/', ' ', $lexer['code'])));
        $literals = $lexer['literals'];
        $lexer['code'] = '';
        $lexer['literals'] = [];

        // Quoted identifiers become plain names; strings and dollar-quoted
        // bodies keep their quotes. A setting named anywhere — in code, as a
        // quoted identifier, in a literal handed to set_config(), inside a DO
        // block's body — makes the statement answer to the exact forms pg_dump
        // writes. pg_dump carries table data in COPY blocks, never in literals.
        $resolved = (string) preg_replace_callback('/"#(\\d+)"/', static fn (array $m): string => strtolower($literals[(int) $m[1]] ?? ''), $code);
        $resolved = (string) preg_replace_callback("/'#(\\d+)'/", static fn (array $m): string => "'".strtolower($literals[(int) $m[1]] ?? '')."'", $resolved);
        $resolved = trim((string) preg_replace('/\s+/', ' ', $resolved));

        $mentions = static fn (string $setting): bool => str_contains($resolved, $setting);

        // Shapes pg_dump never writes at the top level, each able to change the
        // session out of sight: an anonymous block, dynamic SQL, a procedure
        // run on the spot, Unicode-escaped names or strings that hide what they
        // spell, and set_config() for anything but the search path.
        if (preg_match('/^(?:do|prepare|execute|call)\b/', $code) === 1 || preg_match('/\bu&[\'"]/', $code) === 1) {
            throw RestoreException::unsafeDump($path, $lineNo, strtok($code, ' ') ?: 'statement');
        }

        // Settings can also be written through the pg_settings view.
        if (str_contains($resolved, 'pg_settings')) {
            throw RestoreException::unsafeDump($path, $lineNo, 'pg_settings');
        }

        // A top-level SELECT runs whatever it calls — including a function the
        // dump itself defined. pg_dump only ever selects a handful of catalog
        // functions, with literal arguments: those, and nothing else.
        if (preg_match('/^select\\b/', $code) === 1
            && preg_match("/^select pg_catalog\\.(?:set_config|setval|lo_create|lo_open|lowrite|lo_close|lo_unlink)\\((?:\\s*(?:'#\\d+'|-?\\d+|true|false)\\s*,?)*\\)$/", $code) !== 1) {
            throw RestoreException::unsafeDump($path, $lineNo, 'a SELECT pg_dump does not write');
        }

        if (str_contains($resolved, 'set_config') && preg_match("/^select pg_catalog\\.set_config\\('search_path', '', false\\)$/", $resolved) !== 1) {
            throw RestoreException::unsafeDump($path, $lineNo, 'set_config');
        }

        if ($mentions('standard_conforming_strings')
            && preg_match("/^set standard_conforming_strings (?:=|to) '?on'?$/", $resolved) !== 1) {
            throw RestoreException::unsafeDump($path, $lineNo, 'standard_conforming_strings');
        }

        if ($mentions('client_encoding') || preg_match('/^set names\b/', $code) === 1) {
            $safe = preg_match("/^set (?:client_encoding (?:=|to)|names) '([^']*)'$/", $resolved, $m) === 1
                && self::safePgEncoding($m[1]);

            if (! $safe) {
                throw RestoreException::unsafeDump($path, $lineNo, 'client_encoding');
            }
        }

        return preg_match('/^copy\b.*\bfrom stdin\b/', $code) === 1;
    }

    /**
     * Encodings in which a 0x5C byte is always a backslash — compared the way
     * PostgreSQL compares encoding names: alphanumerics only, any case.
     */
    private static function safePgEncoding(string $name): bool
    {
        $name = (string) preg_replace('/[^a-z0-9]/', '', strtolower($name));

        return preg_match('/^(?:utf8|unicode|sqlascii|latin(?:[1-9]|10)|iso8859[1-9]|iso885910|iso885913|iso885914|iso885915|iso885916|win(?:866|874|125[0-8])|koi8r|koi8u|eucjp|euccn|euckr|euctw|eucjis2004|mulecinternal)$/', $name) === 1;
    }

    /**
     * An allowed meta-command, or a refusal. `\connect` may only name a
     * database: a host, port or service would send the restore's credentials
     * to another server.
     */
    private static function metaCommand(string $rest, string $path, int $lineNo): void
    {
        preg_match('/^\\\\([A-Za-z]+|.)/', $rest, $m);
        $command = $m[1] ?? '';

        if (! in_array($command, self::PSQL_ALLOWED, true)) {
            throw RestoreException::unsafeDump($path, $lineNo, '\\'.$command);
        }

        $argument = trim(substr($rest, 1 + strlen($command)));

        // psql ends an argument at the next backslash and runs that as another
        // meta-command (`\c db \! id`), so none may follow.
        if (str_contains($argument, '\\')) {
            throw RestoreException::unsafeDump($path, $lineNo, '\\'.$command.' … \\');
        }

        if (($command === 'connect' || $command === 'c')
            && preg_match('/^(?:-reuse-previous=on\s+)?(?:"dbname=\'[^\'"=\s]+\'"|[A-Za-z0-9_.\-]+)$/', $argument) !== 1) {
            throw RestoreException::unsafeDump($path, $lineNo, '\\'.$command.' '.$argument);
        }

        if (($command === 'restrict' || $command === 'unrestrict') && preg_match('/^[A-Za-z0-9]+$/', $argument) !== 1) {
            throw RestoreException::unsafeDump($path, $lineNo, '\\'.$command.' '.$argument);
        }
    }

    /**
     * @return resource
     */
    private static function open(string $path)
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw RestoreException::failed("Dump file [{$path}] cannot be read.");
        }

        return $handle;
    }
}
