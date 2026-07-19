<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;
use Nyoncode\BackupManager\Exceptions\EncryptionException;
use Nyoncode\BackupManager\Managers\EncryptorManager;

function edgeEncryptor(EncryptionAlgorithm $algorithm)
{
    return (new EncryptorManager('base64:'.base64_encode(str_repeat('K', 32))))->for($algorithm);
}

function roundtrip(EncryptionAlgorithm $algorithm, string $plaintext): string
{
    $dir = sys_get_temp_dir().'/bm-enc-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/plain", $plaintext);

    $enc = edgeEncryptor($algorithm);
    $enc->encrypt("$dir/plain", "$dir/enc");
    $enc->decrypt("$dir/enc", "$dir/dec");

    return (string) file_get_contents("$dir/dec");
}

$oneMiB = 1_048_576;

dataset('boundary sizes', [
    'empty' => [0],
    'one byte' => [1],
    'block minus one' => [15],
    'one block' => [16],
    'block plus one' => [17],
    'chunk minus one' => [1_048_575],
    'exactly one chunk' => [1_048_576],
    'chunk plus one' => [1_048_577],
    'exactly two chunks' => [2_097_152],
    'two chunks plus a bit' => [2_097_152 + 123],
]);

it('CBC round-trips at every buffer boundary', function (int $size) {
    $data = $size === 0 ? '' : random_bytes($size);

    expect(roundtrip(EncryptionAlgorithm::Aes256Cbc, $data))->toBe($data);
})->with('boundary sizes');

it('GCM round-trips at every buffer boundary', function (int $size) {
    $data = $size === 0 ? '' : random_bytes($size);

    expect(roundtrip(EncryptionAlgorithm::Aes256Gcm, $data))->toBe($data);
})->with('boundary sizes');

it('produces different ciphertext each time (random IV)', function () {
    $dir = sys_get_temp_dir().'/bm-enc-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/plain", 'repeatable plaintext');

    $enc = edgeEncryptor(EncryptionAlgorithm::Aes256Cbc);
    $enc->encrypt("$dir/plain", "$dir/a");
    $enc->encrypt("$dir/plain", "$dir/b");

    expect(file_get_contents("$dir/a"))->not->toBe(file_get_contents("$dir/b"));
});

it('fails to decrypt with the wrong key', function (EncryptionAlgorithm $algorithm) {
    $dir = sys_get_temp_dir().'/bm-enc-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/plain", str_repeat('secret data ', 5000));

    $good = (new EncryptorManager('base64:'.base64_encode(str_repeat('A', 32))))->for($algorithm);
    $wrong = (new EncryptorManager('base64:'.base64_encode(str_repeat('B', 32))))->for($algorithm);

    $good->encrypt("$dir/plain", "$dir/enc");

    // GCM authenticates and throws; CBC cannot yield the original plaintext.
    try {
        $wrong->decrypt("$dir/enc", "$dir/dec");
        expect(file_get_contents("$dir/dec"))->not->toBe(file_get_contents("$dir/plain"));
    } catch (EncryptionException) {
        expect(true)->toBeTrue();
    }
})->with([
    'cbc' => [EncryptionAlgorithm::Aes256Cbc],
    'gcm' => [EncryptionAlgorithm::Aes256Gcm],
]);

it('guards AES-256-GCM against oversized in-memory archives (CBC unaffected)', function () {
    $dir = sys_get_temp_dir().'/bm-enc-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/plain", random_bytes(4096));

    $key = 'base64:'.base64_encode(str_repeat('K', 32));

    // GCM with a 1 KiB cap must refuse the 4 KiB archive.
    $gcm = (new EncryptorManager($key, maxGcmBytes: 1024))->for(EncryptionAlgorithm::Aes256Gcm);
    expect(fn () => $gcm->encrypt("$dir/plain", "$dir/enc"))
        ->toThrow(EncryptionException::class);

    // CBC streams, so the same cap does not apply.
    $cbc = (new EncryptorManager($key, maxGcmBytes: 1024))->for(EncryptionAlgorithm::Aes256Cbc);
    $cbc->encrypt("$dir/plain", "$dir/enc-cbc");
    expect(is_file("$dir/enc-cbc"))->toBeTrue();
});

it('rejects a file that is not in the package encryption format', function () {
    $dir = sys_get_temp_dir().'/bm-enc-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/plain", 'not encrypted by us at all');

    edgeEncryptor(EncryptionAlgorithm::Aes256Cbc)->decrypt("$dir/plain", "$dir/out");
})->throws(EncryptionException::class);
