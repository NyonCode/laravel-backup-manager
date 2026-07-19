<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Encryption;

use Nyoncode\BackupManager\Contracts\Encryption\Encryptor;
use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;
use Nyoncode\BackupManager\Exceptions\EncryptionException;
use Nyoncode\BackupManager\Support\EncryptionKey;

/**
 * AES-256 encryptor built on ext-openssl.
 *
 * The archive is written with a small self-describing header:
 *
 *   magic "BME1" (4B) | algorithm (1B) | IV | [GCM tag 16B] | ciphertext
 *
 * CBC mode is streamed block-by-block so arbitrarily large archives encrypt
 * with flat memory. GCM mode is authenticated (AEAD): tampering is detected
 * on decrypt, at the cost of buffering the archive in memory.
 */
final class OpenSslEncryptor implements Encryptor
{
    private const MAGIC = 'BME1';

    private const CHUNK = 1_048_576; // 1 MiB, a multiple of the 16-byte block size

    private const ALGO_IDS = [
        'aes-256-cbc' => 1,
        'aes-256-gcm' => 2,
    ];

    public function __construct(
        private readonly EncryptionAlgorithm $algorithm,
        private readonly EncryptionKey $key,
        /**
         * Maximum archive size, in bytes, that GCM will encrypt in memory.
         * null disables the guard. CBC streams and is unaffected.
         */
        private readonly ?int $maxGcmBytes = null,
    ) {}

    public function encrypt(string $source, string $destination): void
    {
        $this->algorithm === EncryptionAlgorithm::Aes256Gcm
            ? $this->encryptGcm($source, $destination)
            : $this->encryptCbc($source, $destination);
    }

    public function decrypt(string $source, string $destination): void
    {
        $in = $this->open($source, 'rb');
        $magic = (string) fread($in, 4);

        if ($magic !== self::MAGIC) {
            fclose($in);
            throw EncryptionException::cipherFailed('Unrecognised archive header; the file is not encrypted by this package.');
        }

        $algorithm = $this->algorithmFromId(ord((string) fread($in, 1)));

        $algorithm === EncryptionAlgorithm::Aes256Gcm
            ? $this->decryptGcm($in, $destination)
            : $this->decryptCbc($in, $algorithm, $destination);
    }

    private function encryptCbc(string $source, string $destination): void
    {
        $iv = random_bytes(max(1, $this->algorithm->ivLength()));
        $in = $this->open($source, 'rb');
        $out = $this->open($destination, 'wb');

        fwrite($out, self::MAGIC.chr(self::ALGO_IDS[$this->algorithm->cipher()]).$iv);

        $previous = $iv;
        $buffer = (string) fread($in, self::CHUNK);

        // A chunk is final when the following read yields nothing. Detecting the
        // end via an empty read (rather than feof) keeps the framing correct even
        // when the input length is an exact multiple of the chunk size.
        do {
            $next = (string) fread($in, self::CHUNK);
            $final = $next === '';
            $previous = $this->writeCbcChunk($out, $buffer, $previous, $final);
            $buffer = $next;
        } while (! $final);

        fclose($in);
        fclose($out);
    }

    /**
     * @param  resource  $in
     */
    private function decryptCbc($in, EncryptionAlgorithm $algorithm, string $destination): void
    {
        $iv = (string) fread($in, max(1, $algorithm->ivLength()));
        $out = $this->open($destination, 'wb');

        $previous = $iv;
        $buffer = (string) fread($in, self::CHUNK);

        do {
            $next = (string) fread($in, self::CHUNK);
            $final = $next === '';
            $previous = $this->writeCbcPlainChunk($out, $buffer, $algorithm, $previous, $final);
            $buffer = $next;
        } while (! $final);

        fclose($in);
        fclose($out);
    }

    /**
     * Encrypt one chunk in CBC mode and return the new IV (last cipher block).
     *
     * @param  resource  $out
     */
    private function writeCbcChunk($out, string $plaintext, string $iv, bool $final): string
    {
        $options = OPENSSL_RAW_DATA | ($final ? 0 : OPENSSL_ZERO_PADDING);

        $ciphertext = openssl_encrypt($plaintext, $this->algorithm->cipher(), $this->key->bytes, $options, $iv);

        if ($ciphertext === false) {
            throw EncryptionException::cipherFailed((string) openssl_error_string());
        }

        fwrite($out, $ciphertext);

        return $final ? $iv : substr($ciphertext, -16);
    }

    /**
     * Decrypt one chunk in CBC mode and return the new IV (last cipher block).
     *
     * @param  resource  $out
     */
    private function writeCbcPlainChunk($out, string $ciphertext, EncryptionAlgorithm $algorithm, string $iv, bool $final): string
    {
        $options = OPENSSL_RAW_DATA | ($final ? 0 : OPENSSL_ZERO_PADDING);

        $plaintext = openssl_decrypt($ciphertext, $algorithm->cipher(), $this->key->bytes, $options, $iv);

        if ($plaintext === false) {
            throw EncryptionException::cipherFailed((string) openssl_error_string());
        }

        fwrite($out, $plaintext);

        return $final ? $iv : substr($ciphertext, -16);
    }

    private function encryptGcm(string $source, string $destination): void
    {
        $size = (int) (filesize($source) ?: 0);

        if ($this->maxGcmBytes !== null && $size > $this->maxGcmBytes) {
            throw EncryptionException::gcmArchiveTooLarge($size, $this->maxGcmBytes);
        }

        $plaintext = (string) file_get_contents($source);
        $iv = random_bytes(max(1, $this->algorithm->ivLength()));
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            $this->algorithm->cipher(),
            $this->key->bytes,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16,
        );

        if ($ciphertext === false) {
            throw EncryptionException::cipherFailed((string) openssl_error_string());
        }

        file_put_contents(
            $destination,
            self::MAGIC.chr(self::ALGO_IDS[$this->algorithm->cipher()]).$iv.$tag.$ciphertext,
        );
    }

    /**
     * @param  resource  $in
     */
    private function decryptGcm($in, string $destination): void
    {
        $iv = (string) fread($in, max(1, EncryptionAlgorithm::Aes256Gcm->ivLength()));
        $tag = (string) fread($in, 16);
        $ciphertext = (string) stream_get_contents($in);
        fclose($in);

        $plaintext = openssl_decrypt(
            $ciphertext,
            EncryptionAlgorithm::Aes256Gcm->cipher(),
            $this->key->bytes,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw EncryptionException::authenticationFailed();
        }

        file_put_contents($destination, $plaintext);
    }

    private function algorithmFromId(int $id): EncryptionAlgorithm
    {
        return match ($id) {
            1 => EncryptionAlgorithm::Aes256Cbc,
            2 => EncryptionAlgorithm::Aes256Gcm,
            default => throw EncryptionException::cipherFailed("Unknown algorithm id [{$id}] in archive header."),
        };
    }

    /**
     * @return resource
     */
    private function open(string $path, string $mode)
    {
        $handle = fopen($path, $mode);

        if ($handle === false) {
            throw EncryptionException::cipherFailed("Unable to open [{$path}].");
        }

        return $handle;
    }
}
