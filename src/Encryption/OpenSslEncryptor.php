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
 *   magic "BME1" (4B) | algorithm (1B) | IV | [GCM tag 16B] | ciphertext | [HMAC 32B]
 *
 * Algorithm ids: 1 = AES-256-CBC (legacy, unauthenticated, decrypt only),
 * 2 = AES-256-GCM, 3 = AES-256-CBC with an HMAC-SHA256 tag (encrypt-then-MAC).
 *
 * CBC is streamed block-by-block so arbitrarily large archives encrypt with
 * flat memory. Since 1.4 it is also authenticated: the HMAC covers the header,
 * the IV and the ciphertext, sits at the end so encryption stays a single
 * pass, and is checked in full *before* a byte is decrypted — a tampered
 * archive is refused rather than decrypted into something plausible, and no
 * padding error is ever reachable on unauthenticated input. The MAC key is
 * derived from the configured key with HKDF, so there is nothing new to set.
 * GCM is authenticated (AEAD) at the cost of buffering the archive in memory.
 */
final class OpenSslEncryptor implements Encryptor
{
    private const MAGIC = 'BME1';

    private const CHUNK = 1_048_576; // 1 MiB, a multiple of the 16-byte block size

    private const ID_CBC_LEGACY = 1;

    private const ID_GCM = 2;

    private const ID_CBC_HMAC = 3;

    private const MAC_LENGTH = 32;

    public function __construct(
        private readonly EncryptionAlgorithm $algorithm,
        private readonly EncryptionKey $key,
        /**
         * Maximum archive size, in bytes, that GCM will encrypt in memory.
         * null disables the guard. CBC streams and is unaffected.
         */
        private readonly ?int $maxGcmBytes = null,
        /**
         * Whether the unauthenticated pre-1.4 CBC format (id 1) is accepted on
         * decrypt. Off when the archive is known to be newer: stripping the tag
         * and flipping the id byte must not turn an authenticated archive into
         * one that is decrypted unchecked.
         */
        private readonly bool $allowLegacyCbc = true,
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

        $id = ord((string) fread($in, 1));
        $algorithm = $this->algorithmFromId($id);

        // The header must name the algorithm the manifest does: a header is
        // part of the file an attacker can rewrite, the expected algorithm is
        // not something they get to choose here.
        if ($algorithm !== $this->algorithm) {
            fclose($in);

            throw EncryptionException::cipherFailed(sprintf(
                'The archive header says [%s] but [%s] was expected; refusing to decrypt.',
                $algorithm->value,
                $this->algorithm->value,
            ));
        }

        match ($id) {
            self::ID_GCM => $this->decryptGcm($in, $destination),
            self::ID_CBC_HMAC => $this->decryptCbcAuthenticated($in, $destination),
            default => $this->allowLegacyCbc
                ? $this->decryptCbcLegacy($in, $algorithm, $destination)
                : $this->refuseLegacy($in),
        };
    }

    private function encryptCbc(string $source, string $destination): void
    {
        $iv = random_bytes(max(1, $this->algorithm->ivLength()));
        $in = $this->open($source, 'rb');
        $out = $this->open($destination, 'wb');

        $header = self::MAGIC.chr(self::ID_CBC_HMAC).$iv;
        $mac = hash_init('sha256', HASH_HMAC, $this->macKey());

        fwrite($out, $header);
        hash_update($mac, $header);

        $previous = $iv;
        $buffer = (string) fread($in, self::CHUNK);

        // A chunk is final when the following read yields nothing. Detecting the
        // end via an empty read (rather than feof) keeps the framing correct even
        // when the input length is an exact multiple of the chunk size.
        do {
            $next = (string) fread($in, self::CHUNK);
            $final = $next === '';
            $previous = $this->writeCbcChunk($out, $buffer, $previous, $final, $mac);
            $buffer = $next;
        } while (! $final);

        fwrite($out, hash_final($mac, true));

        fclose($in);
        fclose($out);
    }

    /**
     * Encrypt-then-MAC: verify the tag over everything before it, and only
     * then decrypt. Two passes over the file, flat memory in both.
     *
     * @param  resource  $in
     */
    private function decryptCbcAuthenticated($in, string $destination): void
    {
        $size = (int) (fstat($in)['size'] ?? 0);
        $ivLength = max(1, EncryptionAlgorithm::Aes256Cbc->ivLength());
        $headerLength = strlen(self::MAGIC) + 1 + $ivLength;
        $macOffset = $size - self::MAC_LENGTH;

        if ($macOffset < $headerLength) {
            fclose($in);

            throw EncryptionException::authenticationFailed();
        }

        // Pass 1: the MAC, over header + IV + ciphertext.
        rewind($in);
        $mac = hash_init('sha256', HASH_HMAC, $this->macKey());
        $remaining = $macOffset;

        while ($remaining > 0) {
            $chunk = (string) fread($in, min(self::CHUNK, $remaining));

            if ($chunk === '') {
                break;
            }

            hash_update($mac, $chunk);
            $remaining -= strlen($chunk);
        }

        $expected = (string) fread($in, self::MAC_LENGTH);

        if ($remaining !== 0 || ! hash_equals(hash_final($mac, true), $expected)) {
            fclose($in);

            throw EncryptionException::authenticationFailed();
        }

        // Pass 2: decrypt exactly the authenticated ciphertext.
        fseek($in, strlen(self::MAGIC) + 1);
        $iv = (string) fread($in, $ivLength);
        $out = $this->open($destination, 'wb');
        $remaining = $macOffset - $headerLength;
        $previous = $iv;
        $buffer = $this->readUpTo($in, $remaining);
        $remaining -= strlen($buffer);

        do {
            $next = $this->readUpTo($in, $remaining);
            $remaining -= strlen($next);
            $final = $next === '';
            $previous = $this->writeCbcPlainChunk($out, $buffer, EncryptionAlgorithm::Aes256Cbc, $previous, $final);
            $buffer = $next;
        } while (! $final);

        fclose($in);
        fclose($out);
    }

    /**
     * @param  resource  $in
     */
    private function readUpTo($in, int $remaining): string
    {
        return $remaining > 0 ? (string) fread($in, max(1, min(self::CHUNK, $remaining))) : '';
    }

    /**
     * @param  resource  $in
     */
    private function refuseLegacy($in): never
    {
        fclose($in);

        throw EncryptionException::cipherFailed(
            'This archive is in the unauthenticated pre-1.4 CBC format, which cannot prove it was not modified. '
            .'It is restored only from a signed backup, or with `backup:restore --allow-legacy-cbc` '
            .'(BACKUP_ALLOW_LEGACY_CBC=true) if you trust it.'
        );
    }

    /** The HMAC key: derived from the configured key, never the key itself. */
    private function macKey(): string
    {
        return hash_hkdf('sha256', $this->key->bytes, 32, 'backup-manager:cbc-hmac-sha256:v1');
    }

    /**
     * @param  resource  $in
     */
    private function decryptCbcLegacy($in, EncryptionAlgorithm $algorithm, string $destination): void
    {
        // Written before 1.4, with nothing to authenticate it by. Still
        // restorable — refusing would strand every older encrypted backup —
        // but only Pro manifest signing can tell such a file from a forgery.
        try {
            logger()->warning('[backup-manager] Decrypting an unauthenticated (pre-1.4) AES-256-CBC archive; enable manifest signing to detect tampering.');
        } catch (\Throwable) {
            // A warning is never a reason to fail a restore.
        }

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
    private function writeCbcChunk($out, string $plaintext, string $iv, bool $final, \HashContext $mac): string
    {
        $options = OPENSSL_RAW_DATA | ($final ? 0 : OPENSSL_ZERO_PADDING);

        $ciphertext = openssl_encrypt($plaintext, $this->algorithm->cipher(), $this->key->bytes, $options, $iv);

        if ($ciphertext === false) {
            throw EncryptionException::cipherFailed((string) openssl_error_string());
        }

        fwrite($out, $ciphertext);
        hash_update($mac, $ciphertext);

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
            self::MAGIC.chr(self::ID_GCM).$iv.$tag.$ciphertext,
        );
    }

    /**
     * @param  resource  $in
     */
    private function decryptGcm($in, string $destination): void
    {
        // GCM decrypts in memory, so the same cap that bounds encryption bounds
        // this: a file planted on the destination must not exhaust memory.
        $size = (int) (fstat($in)['size'] ?? 0);

        if ($this->maxGcmBytes !== null && $size > $this->maxGcmBytes + 64) {
            fclose($in);

            throw EncryptionException::gcmArchiveTooLarge($size, $this->maxGcmBytes);
        }

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
            self::ID_CBC_LEGACY, self::ID_CBC_HMAC => EncryptionAlgorithm::Aes256Cbc,
            self::ID_GCM => EncryptionAlgorithm::Aes256Gcm,
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
