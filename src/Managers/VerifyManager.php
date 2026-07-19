<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Managers;

use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Contracts\Integrity\Signer;
use Nyoncode\BackupManager\Contracts\Snapshot\SnapshotDriver;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\Support\Workspace;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;
use Nyoncode\BackupManager\ValueObjects\VerificationResult;

/**
 * Verifies a stored backup without restoring it.
 *
 * For an archive this confirms the stored bytes match the checksum recorded in
 * the manifest. For an incremental snapshot — which has no single archive — the
 * snapshot driver confirms the manifest's file index is internally consistent
 * and that every referenced blob is present and, once decoded, hashes to the
 * value recorded for it. Either way, an authentic manifest signature is checked
 * when present. This is the lightweight restorability test.
 */
final class VerifyManager
{
    public function __construct(
        private readonly Repository $config,
        private readonly BackupRepository $repository,
        private readonly SnapshotDriver $snapshots,
        private readonly ?Signer $signer = null,
    ) {}

    public function verify(StoredBackup $backup): VerificationResult
    {
        $manifest = $this->repository->readManifest($backup);

        $checksumValid = $manifest->mode->isIncremental()
            ? $this->snapshots->verify($backup, $manifest)
            : $this->verifyArchive($backup, $manifest);

        return new VerificationResult(
            $backup->name,
            $checksumValid,
            $this->verifySignature($manifest),
            $manifest,
        );
    }

    private function verifyArchive(StoredBackup $backup, Manifest $manifest): bool
    {
        $workspace = Workspace::create($this->workingDirectory(), 'verify');

        try {
            $local = $workspace->path('archive');
            $this->repository->download($backup, $local);

            return $manifest->archiveChecksum->matchesFile($local);
        } finally {
            $workspace->delete();
        }
    }

    private function verifySignature(Manifest $manifest): ?bool
    {
        $signature = $manifest->signature;

        if ($signature === null || $this->signer === null) {
            return null;
        }

        return $this->signer->verify($manifest->withoutSignature()->toJson(), $signature);
    }

    private function workingDirectory(): string
    {
        return (string) $this->config->get(
            'backup-manager.working_directory',
            sys_get_temp_dir().'/backup-manager',
        );
    }
}
