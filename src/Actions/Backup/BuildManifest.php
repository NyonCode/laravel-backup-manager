<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Actions\Backup;

use Closure;
use Nyoncode\BackupManager\Contracts\Backup\BackupStage;
use Nyoncode\BackupManager\Contracts\Integrity\Signer;
use Nyoncode\BackupManager\Support\BackupContext;
use Nyoncode\BackupManager\Support\MigrationProbe;
use Nyoncode\BackupManager\ValueObjects\Checksum;
use Nyoncode\BackupManager\ValueObjects\Manifest;

/**
 * Builds the backup manifest: the self-describing record of the environment,
 * drivers, archive checksum and full file listing. Optionally signs it.
 *
 * Runs after compression and encryption so the recorded archive checksum
 * matches the exact bytes that will be stored and later verified.
 */
final class BuildManifest implements BackupStage
{
    public function __construct(
        private readonly MigrationProbe $migrations,
        private readonly ?Signer $signer = null,
    ) {}

    public function handle(BackupContext $context, Closure $next): BackupContext
    {
        $manifest = new Manifest(
            name: $context->definition->name,
            type: $context->definition->type,
            createdAt: $context->startedAt,
            environment: $context->environment,
            compression: $context->compression,
            encryption: $context->encryption,
            databases: $context->databaseArtifacts,
            files: $context->manifestFiles,
            archiveSize: (int) (filesize($context->archivePath) ?: 0),
            archiveChecksum: Checksum::forFile($context->archivePath, $context->checksum),
            config: $this->configSnapshot($context),
            migrations: $this->migrations->capture(),
        );

        if ($this->signer !== null) {
            $manifest = $manifest->withSignature($this->signer->sign($manifest->toJson()));
        }

        $context->manifest = $manifest;

        return $next($context);
    }

    /**
     * @return array<string, mixed>
     */
    private function configSnapshot(BackupContext $context): array
    {
        return [
            'profile' => $context->definition->profile,
            'type' => $context->definition->type->value,
            'destinations' => $context->definition->destinations,
            'path' => $context->definition->path,
            'archive_file' => $context->archiveFileName,
        ];
    }
}
