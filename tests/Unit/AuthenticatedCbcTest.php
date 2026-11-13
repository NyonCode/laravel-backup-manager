<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Encryption\OpenSslEncryptor;
use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;
use Nyoncode\BackupManager\Exceptions\EncryptionException;
use Nyoncode\BackupManager\Managers\EncryptorManager;
use Nyoncode\BackupManager\Support\EncryptionKey;

function cbcEncryptor(?string $key = null): OpenSslEncryptor
{
    return new OpenSslEncryptor(EncryptionAlgorithm::Aes256Cbc, EncryptionKey::fromConfig($key ?? 'base64:'.base64_encode(str_repeat('k', 32))));
}

function cbcTemp(string $contents = ''): string
{
    $path = tempnam(sys_get_temp_dir(), 'bm-cbc-');
    file_put_contents($path, $contents);

    return $path;
}

it('round-trips archives of every awkward size', function (int $size): void {
    $plain = cbcTemp($size === 0 ? '' : random_bytes($size));
    $enc = cbcTemp();
    $back = cbcTemp();

    cbcEncryptor()->encrypt($plain, $enc);
    cbcEncryptor()->decrypt($enc, $back);

    expect(file_get_contents($enc)[4] ?? '')->toBe(chr(3))
        ->and(hash_file('sha256', $back))->toBe(hash_file('sha256', $plain));
})->with([0, 1, 15, 16, 17, 1_048_576, 1_048_576 * 2 + 5]);

it('refuses a tampered archive before decrypting a byte', function (string $where): void {
    $plain = cbcTemp(str_repeat('backup data ', 10_000));
    $enc = cbcTemp();
    $back = cbcTemp('untouched');
    cbcEncryptor()->encrypt($plain, $enc);

    $bytes = (string) file_get_contents($enc);
    $offset = match ($where) {
        'iv' => 6,
        'ciphertext' => intdiv(strlen($bytes), 2),
        'tag' => strlen($bytes) - 1,
    };
    $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x01);
    file_put_contents($enc, $bytes);

    try {
        cbcEncryptor()->decrypt($enc, $back);
        $this->fail('Tampering went unnoticed.');
    } catch (EncryptionException) {
        expect(file_get_contents($back))->toBe('untouched');
    }
})->with(['iv', 'ciphertext', 'tag']);

it('refuses a truncated archive and the wrong key', function (): void {
    $plain = cbcTemp('secret');
    $enc = cbcTemp();
    cbcEncryptor()->encrypt($plain, $enc);

    $truncated = cbcTemp(substr((string) file_get_contents($enc), 0, -5));

    expect(fn () => cbcEncryptor()->decrypt($truncated, cbcTemp()))->toThrow(EncryptionException::class)
        ->and(fn () => cbcEncryptor('base64:'.base64_encode(str_repeat('x', 32)))->decrypt($enc, cbcTemp()))->toThrow(EncryptionException::class);
});

it('still restores a pre-1.3 unauthenticated CBC archive', function (): void {
    $key = str_repeat('k', 32);
    $iv = random_bytes(16);
    $legacy = cbcTemp('BME1'.chr(1).$iv.openssl_encrypt('old archive', 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));
    $back = cbcTemp();

    cbcEncryptor()->decrypt($legacy, $back);

    expect(file_get_contents($back))->toBe('old archive');
});

it('refuses a header that names another algorithm than the manifest', function (): void {
    $gcm = new OpenSslEncryptor(EncryptionAlgorithm::Aes256Gcm, EncryptionKey::fromConfig('base64:'.base64_encode(str_repeat('k', 32))));
    $enc = cbcTemp();
    $gcm->encrypt(cbcTemp('x'), $enc);

    cbcEncryptor()->decrypt($enc, cbcTemp());
})->throws(EncryptionException::class, 'archive header says');

it('refuses to load an oversized GCM archive into memory on decrypt', function (): void {
    $key = EncryptionKey::fromConfig('base64:'.base64_encode(str_repeat('k', 32)));
    $enc = cbcTemp();
    (new OpenSslEncryptor(EncryptionAlgorithm::Aes256Gcm, $key))->encrypt(cbcTemp(str_repeat('x', 4096)), $enc);

    (new OpenSslEncryptor(EncryptionAlgorithm::Aes256Gcm, $key, maxGcmBytes: 1024))->decrypt($enc, cbcTemp());
})->throws(EncryptionException::class);

it('refuses the legacy format when the archive is known to be newer', function (): void {
    $key = str_repeat('k', 32);
    $iv = random_bytes(16);
    $legacy = cbcTemp('BME1'.chr(1).$iv.openssl_encrypt('downgraded', 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));

    (new EncryptorManager('base64:'.base64_encode($key)))
        ->for(EncryptionAlgorithm::Aes256Cbc, allowLegacyCbc: false)
        ->decrypt($legacy, cbcTemp());
})->throws(EncryptionException::class, 'cannot prove it was not modified');
