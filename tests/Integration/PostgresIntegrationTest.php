<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Tests\Integration\DatabaseTestKit;

it('backs up and restores a live PostgreSQL database (bzip2 + AES-256-CBC)', function (): void {
    $config = DatabaseTestKit::postgres();

    if (! DatabaseTestKit::reachable($config)) {
        $this->markTestSkipped('PostgreSQL is not reachable — set BM_PGSQL_* to a running server (default 127.0.0.1:55432).');
    }

    assertDatabaseRoundtrip($config, [
        'label' => 'PostgreSQL Acme',
        'compression' => 'bzip2',
        'encryption' => 'aes-256-cbc',
    ]);
})->group('integration', 'pgsql');
