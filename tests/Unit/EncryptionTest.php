<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;
use Nyoncode\BackupManager\Exceptions\EncryptionException;
use Nyoncode\BackupManager\Managers\EncryptorManager;

function encryptionKey(): string
{
    return 'base64:'.base64_encode(str_repeat('k', 32));
}

function encryptor(EncryptionAlgorithm $algorithm)
{
    return (new EncryptorManager(encryptionKey()))->for($algorithm);
}

dataset('ciphers', [
    'aes-256-cbc' => [EncryptionAlgorithm::Aes256Cbc],
    'aes-256-gcm' => [EncryptionAlgorithm::Aes256Gcm],
]);

it('encrypts and decrypts small payloads', function (EncryptionAlgorithm $algorithm): void {
    $dir = sys_get_temp_dir().'/bm-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/plain", 'a short secret');

    $encryptor = encryptor($algorithm);
    $encryptor->encrypt("{$dir}/plain", "{$dir}/enc");
    $encryptor->decrypt("{$dir}/enc", "{$dir}/dec");

    expect(file_get_contents("{$dir}/dec"))->toBe('a short secret')
        ->and(file_get_contents("{$dir}/enc"))->not->toBe('a short secret');
})->with('ciphers');

it('streams large payloads across chunk boundaries without loss', function (EncryptionAlgorithm $algorithm): void {
    $dir = sys_get_temp_dir().'/bm-'.uniqid();
    mkdir($dir);
    // 2.5 MiB exercises the multi-chunk CBC streaming path (chunk = 1 MiB).
    file_put_contents("{$dir}/plain", random_bytes(2_621_440 + 7));

    $encryptor = encryptor($algorithm);
    $encryptor->encrypt("{$dir}/plain", "{$dir}/enc");
    $encryptor->decrypt("{$dir}/enc", "{$dir}/dec");

    expect(hash_file('sha256', "{$dir}/dec"))->toBe(hash_file('sha256', "{$dir}/plain"));
})->with('ciphers');

it('detects tampering of an authenticated (GCM) archive', function (): void {
    $dir = sys_get_temp_dir().'/bm-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/plain", 'authentic data');

    $encryptor = encryptor(EncryptionAlgorithm::Aes256Gcm);
    $encryptor->encrypt("{$dir}/plain", "{$dir}/enc");

    $bytes = file_get_contents("{$dir}/enc");
    $bytes[strlen($bytes) - 1] = $bytes[strlen($bytes) - 1] === 'A' ? 'B' : 'A';
    file_put_contents("{$dir}/enc", $bytes);

    $encryptor->decrypt("{$dir}/enc", "{$dir}/dec");
})->throws(EncryptionException::class);

it('rejects an invalid key length', function (): void {
    (new EncryptorManager('too-short'))->for(EncryptionAlgorithm::Aes256Cbc);
})->throws(EncryptionException::class);
