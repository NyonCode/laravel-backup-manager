<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use Nyoncode\BackupManager\Exceptions\DatabaseDumpException;
use Nyoncode\BackupManager\Exceptions\EncryptionException;
use Nyoncode\BackupManager\Exceptions\IntegrityException;
use Nyoncode\BackupManager\Exceptions\InvalidConfigurationException;
use Nyoncode\BackupManager\Exceptions\ManifestException;
use Nyoncode\BackupManager\Exceptions\RestoreException;
use Nyoncode\BackupManager\Exceptions\StorageException;
use Nyoncode\BackupManager\Exceptions\UnsupportedDriverException;

/**
 * Exceptions are built through named static factories (never `new` at call
 * sites). These assert the messages surfaced to operators are accurate and that
 * every factory produces the shared base type so a single catch handles them.
 */
it('every factory yields the shared base exception type', function (Throwable $exception): void {
    expect($exception)
        ->toBeInstanceOf(BackupManagerException::class)
        ->toBeInstanceOf(RuntimeException::class);
})->with([
    fn () => IntegrityException::checksumMismatch('a.tar', 'abc', 'def'),
    fn () => IntegrityException::signatureMismatch(),
    fn () => IntegrityException::signingRequiresPro(),
    fn () => EncryptionException::missingKey(),
    fn () => EncryptionException::invalidKeyLength(32, 16),
    fn () => RestoreException::backupNotFound('nightly'),
    fn () => ManifestException::missingKey('mode'),
    fn () => DatabaseDumpException::binaryNotFound('mysqldump'),
    fn () => UnsupportedDriverException::forDatabaseDriver('mongodb'),
    fn () => InvalidConfigurationException::unknownProfile('ghost'),
    fn () => StorageException::writeFailed('s3', 'backups/x.tar'),
]);

it('reports the offending values in an integrity mismatch', function (): void {
    $exception = IntegrityException::checksumMismatch('backups/a.tar', 'expected-hash', 'actual-hash');

    expect($exception->getMessage())
        ->toContain('backups/a.tar')
        ->toContain('expected-hash')
        ->toContain('actual-hash');
});

it('reports expected and actual key lengths', function (): void {
    expect(EncryptionException::invalidKeyLength(32, 16)->getMessage())
        ->toContain('32 bytes')
        ->toContain('16 bytes');
});

it('names the missing backup in a restore lookup failure', function (): void {
    expect(RestoreException::backupNotFound('2026-07-19-nightly')->getMessage())
        ->toContain('2026-07-19-nightly');
});

it('preserves the previous exception when wrapping a restore failure', function (): void {
    $previous = new RuntimeException('disk error');
    $exception = RestoreException::failed('rollback aborted', $previous);

    expect($exception->getMessage())->toContain('rollback aborted')
        ->and($exception->getPrevious())->toBe($previous);
});

it('names the unsupported driver', function (): void {
    expect(UnsupportedDriverException::forDatabaseDriver('mongodb')->getMessage())
        ->toContain('mongodb');
});

it('names the unknown profile', function (): void {
    expect(InvalidConfigurationException::unknownProfile('ghost')->getMessage())
        ->toContain('ghost');
});

it('explains that GCM buffers the whole archive when it is too large', function (): void {
    $exception = EncryptionException::gcmArchiveTooLarge(100, 50);

    expect($exception->getMessage())
        ->toContain('100')
        ->toContain('50')
        ->toContain('aes-256-cbc');
});
