<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Tests\Integration\DatabaseTestKit;

it('backs up and restores a live MySQL database (gzip + AES-256-GCM)', function (): void {
    $config = DatabaseTestKit::mysql();

    if (! DatabaseTestKit::reachable($config)) {
        $this->markTestSkipped('MySQL is not reachable — set BM_MYSQL_* to a running server (default 127.0.0.1:33306).');
    }

    assertDatabaseRoundtrip($config, [
        'label' => 'MySQL Acme',
        'compression' => 'gzip',
        'encryption' => 'aes-256-gcm',
    ]);
})->group('integration', 'mysql');
