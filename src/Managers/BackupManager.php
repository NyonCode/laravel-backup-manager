<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Managers;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Pipeline\Pipeline;
use Nyoncode\BackupManager\Contracts\Backup\BackupStage;
use Nyoncode\BackupManager\Contracts\Snapshot\SnapshotDriver;
use Nyoncode\BackupManager\Enums\BackupMode;
use Nyoncode\BackupManager\Enums\ChecksumAlgorithm;
use Nyoncode\BackupManager\Enums\CompressionAlgorithm;
use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;
use Nyoncode\BackupManager\Events\BackupCompleted;
use Nyoncode\BackupManager\Events\BackupFailed;
use Nyoncode\BackupManager\Events\BackupStarted;
use Nyoncode\BackupManager\Support\BackupContext;
use Nyoncode\BackupManager\Support\EnvironmentProbe;
use Nyoncode\BackupManager\Support\ProfileResolver;
use Nyoncode\BackupManager\ValueObjects\BackupResult;
use Throwable;

/**
 * The package's primary entry point for creating backups.
 *
 * Resolves a profile, assembles the pipeline context and runs the stages for
 * the profile's storage mode, emitting lifecycle events for monitoring.
 * Composition happens here: the core owns the self-contained archive pipeline,
 * while the incremental pipeline comes from the {@see SnapshotDriver} — so the
 * whole snapshot mode can be provided by an add-on without touching the manager.
 */
final class BackupManager
{
    /**
     * @param  list<BackupStage>  $archiveStages  The self-contained archive pipeline.
     */
    public function __construct(
        private readonly Repository $config,
        private readonly ProfileResolver $resolver,
        private readonly EnvironmentProbe $environment,
        private readonly Pipeline $pipeline,
        private readonly Dispatcher $events,
        private readonly array $archiveStages,
        private readonly SnapshotDriver $snapshots,
    ) {}

    public function run(?string $profile = null): BackupResult
    {
        $definition = $this->resolver->resolve($profile);
        $startedAt = new DateTimeImmutable;

        $context = new BackupContext(
            definition: $definition,
            environment: $this->environment->capture($definition->name),
            compression: CompressionAlgorithm::from((string) $this->config->get('backup-manager.compression.algorithm', 'gzip')),
            encryption: EncryptionAlgorithm::from((string) $this->config->get('backup-manager.encryption.algorithm', 'none')),
            checksum: ChecksumAlgorithm::from((string) $this->config->get('backup-manager.integrity.checksum', 'sha256')),
            startedAt: $startedAt,
            baseName: $this->baseName($definition->profile, $startedAt),
        );

        $this->events->dispatch(new BackupStarted($definition));

        try {
            /** @var BackupContext $completed */
            $completed = $this->pipeline->send($context)->through($this->stagesFor($definition->mode))->thenReturn();
        } catch (Throwable $exception) {
            $this->events->dispatch(new BackupFailed($definition, $exception));

            throw $exception;
        }

        $result = BackupResult::fromContext($completed);

        $this->events->dispatch(new BackupCompleted($result));

        return $result;
    }

    /**
     * @return list<BackupStage>
     */
    private function stagesFor(BackupMode $mode): array
    {
        return $mode->isIncremental()
            ? $this->snapshots->backupStages()
            : $this->archiveStages;
    }

    private function baseName(string $profile, DateTimeImmutable $startedAt): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $profile) ?: 'backup';

        // A short random suffix guarantees uniqueness even for two backups of the
        // same profile started within the same second, so neither overwrites the
        // other on the destination. The timestamp remains parseable for sorting.
        $unique = substr(bin2hex(random_bytes(3)), 0, 6);

        return trim($slug, '-').'-'.$startedAt->format('Y-m-d-His').'-'.$unique;
    }
}
