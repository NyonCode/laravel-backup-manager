<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Support\BinaryLocator;
use Nyoncode\BackupManager\Tests\Integration\DatabaseTestKit;

it('backs up and restores a live MariaDB database (zstd, unencrypted)', function (): void {
    $config = DatabaseTestKit::mariadb();

    if (! DatabaseTestKit::reachable($config)) {
        $this->markTestSkipped('MariaDB is not reachable — set BM_MARIADB_* to a running server (default 127.0.0.1:33307).');
    }

    $compression = (new BinaryLocator)->has('zstd') ? 'zstd' : 'gzip';

    assertDatabaseRoundtrip($config, [
        'label' => 'MariaDB Acme',
        'compression' => $compression,
        'encryption' => 'none',
    ]);
})->group('integration', 'mariadb');
