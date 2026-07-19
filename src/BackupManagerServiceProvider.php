<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Pipeline\Pipeline;
use Nyoncode\BackupManager\Actions\Backup\BuildArchive;
use Nyoncode\BackupManager\Actions\Backup\BuildManifest;
use Nyoncode\BackupManager\Actions\Backup\CleanupWorkspace;
use Nyoncode\BackupManager\Actions\Backup\CollectSourceFiles;
use Nyoncode\BackupManager\Actions\Backup\CompressArchive;
use Nyoncode\BackupManager\Actions\Backup\DumpDatabases;
use Nyoncode\BackupManager\Actions\Backup\EncryptArchive;
use Nyoncode\BackupManager\Actions\Backup\PrepareWorkspace;
use Nyoncode\BackupManager\Actions\Backup\StoreBackup;
use Nyoncode\BackupManager\Archive\PosixTarArchiver;
use Nyoncode\BackupManager\Commands\BackupCleanCommand;
use Nyoncode\BackupManager\Commands\BackupListCommand;
use Nyoncode\BackupManager\Commands\BackupRestoreCommand;
use Nyoncode\BackupManager\Commands\BackupRunCommand;
use Nyoncode\BackupManager\Commands\BackupUpgradeCommand;
use Nyoncode\BackupManager\Commands\BackupVerifyCommand;
use Nyoncode\BackupManager\Contracts\Archive\Archiver;
use Nyoncode\BackupManager\Contracts\Integrity\Signer;
use Nyoncode\BackupManager\Contracts\Notification\NotificationChannel;
use Nyoncode\BackupManager\Contracts\Snapshot\SnapshotDriver;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Exceptions\IntegrityException;
use Nyoncode\BackupManager\Listeners\LogsBackupActivity;
use Nyoncode\BackupManager\Listeners\SendBackupNotifications;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Managers\CompressorManager;
use Nyoncode\BackupManager\Managers\DatabaseManager;
use Nyoncode\BackupManager\Managers\EncryptorManager;
use Nyoncode\BackupManager\Notifications\MailChannel;
use Nyoncode\BackupManager\Retention\SimpleStrategy;
use Nyoncode\BackupManager\Services\BackupNotifier;
use Nyoncode\BackupManager\Snapshot\NullSnapshotDriver;
use Nyoncode\BackupManager\Storage\DiskBackupRepository;
use Nyoncode\BackupManager\Support\BinaryLocator;
use Nyoncode\BackupManager\Support\EnvironmentProbe;
use Nyoncode\BackupManager\Support\ProfileResolver;
use Nyoncode\BackupManager\Support\RetentionSelector;
use Nyoncode\BackupManager\Support\RetentionStrategyRegistry;
use NyonCode\LaravelPackageToolkit\Contracts\Packable;
use NyonCode\LaravelPackageToolkit\Packager;
use NyonCode\LaravelPackageToolkit\PackageServiceProvider;

final class BackupManagerServiceProvider extends PackageServiceProvider implements Packable
{
    /**
     * Container tag collecting the notification channels (mail in core, plus any
     * the Pro package adds). The Pro provider tags onto the same name.
     */
    public const NOTIFICATION_CHANNELS = 'backup-manager.notification-channels';

    public function configure(Packager $packager): void
    {
        $packager
            ->name('Backup Manager')
            ->hasShortName('backup-manager')
            ->hasConfig();
    }

    /**
     * Bind the package's services once the package has been registered.
     *
     * Using the toolkit's `registeredPackage` lifecycle hook keeps the
     * container wiring in the register phase without overriding register()
     * itself, and runs after the package config has been merged so bindings
     * can safely read configuration.
     */
    public function registeredPackage(): void
    {
        parent::registeredPackage();

        $this->registerServices();
        $this->registerManagers();
        $this->registerBackupManager();
    }

    public function bootedPackage(): void
    {
        parent::bootedPackage();

        $events = $this->app->make(Dispatcher::class);
        $events->subscribe(LogsBackupActivity::class);
        $events->subscribe(SendBackupNotifications::class);

        $this->registerSchedule();
    }

    /**
     * @return array<int, class-string>
     */
    public function packageCommands(): array
    {
        return [
            BackupRunCommand::class,
            BackupListCommand::class,
            BackupRestoreCommand::class,
            BackupCleanCommand::class,
            BackupVerifyCommand::class,
            BackupUpgradeCommand::class,
        ];
    }

    private function registerServices(): void
    {
        $this->app->singleton(BinaryLocator::class, fn ($app): BinaryLocator => new BinaryLocator(
            (array) $app->make(Repository::class)->get('backup-manager.binaries', []),
        ));

        $this->app->singleton(ProfileResolver::class, fn ($app): ProfileResolver => new ProfileResolver(
            $app->make(Repository::class),
        ));

        $this->app->singleton(EnvironmentProbe::class, fn ($app): EnvironmentProbe => new EnvironmentProbe($app));

        $this->app->bind(Archiver::class, PosixTarArchiver::class);

        $this->app->singleton(BackupRepository::class, fn ($app): DiskBackupRepository => new DiskBackupRepository(
            $app->make(FilesystemFactory::class),
        ));

        // The first backup stage is shared by both pipelines; bind it so the
        // archive pipeline and the snapshot driver can each resolve it. The
        // snapshot driver itself lives in the Pro package (see below).
        $this->app->singleton(PrepareWorkspace::class, fn ($app): PrepareWorkspace => new PrepareWorkspace(
            $this->workingDirectory($app->make(Repository::class)),
        ));

        // Incremental backups are provided through the SnapshotDriver contract.
        // The free core binds a null driver that can still read and list
        // snapshots but refuses to create, restore or verify them; the Pro
        // package (nyoncode/backup-manager-pro) overrides this binding with the
        // real pool-backed driver and its snapshot pipeline stages.
        $this->app->singleton(SnapshotDriver::class, NullSnapshotDriver::class);

        // Manifest signing (tamper-evidence) is a Pro capability. The core keeps
        // the Signer contract — BuildManifest and VerifyManager depend on it —
        // but binds no implementation: signing off resolves to null, signing on
        // fails closed. The Pro provider overrides this binding with the real
        // HmacSigner. We never silently produce an unsigned backup a user
        // believes is signed.
        $this->app->bind(Signer::class, function ($app): ?Signer {
            $config = $app->make(Repository::class);

            if (! $config->get('backup-manager.integrity.signing.enabled', false)) {
                return null;
            }

            throw IntegrityException::signingRequiresPro();
        });

        // Retention strategies are resolved by name from a registry so an
        // add-on can contribute one without touching the selector. The free
        // core registers only `simple`; the Pro package adds `gfs`. A policy
        // naming an unregistered strategy prunes nothing (fail-closed).
        $this->app->singleton(RetentionStrategyRegistry::class, function (): RetentionStrategyRegistry {
            $registry = new RetentionStrategyRegistry;

            $registry->register('simple', fn (RetentionPolicy $policy): SimpleStrategy => new SimpleStrategy($policy->keepLatest));

            return $registry;
        });

        // Total-storage eviction is a Pro capability, so the core selector is
        // built without an evictor (the size cap is not enforced). The Pro
        // provider rebinds the selector with its evictor.
        $this->app->singleton(RetentionSelector::class, fn ($app): RetentionSelector => new RetentionSelector(
            $app->make(RetentionStrategyRegistry::class),
        ));

        // Notifications fan out to every tagged channel. The free core ships only
        // the mail channel; the Pro package tags more (Slack). The notifier reads
        // the tagged set lazily, so a channel Pro registers is picked up whatever
        // the provider order.
        $this->app->tag(MailChannel::class, self::NOTIFICATION_CHANNELS);

        $this->app->singleton(BackupNotifier::class, function ($app): BackupNotifier {
            /** @var iterable<NotificationChannel> $channels */
            $channels = $app->tagged(self::NOTIFICATION_CHANNELS);

            return new BackupNotifier($app->make(Repository::class), $channels);
        });
    }

    private function registerManagers(): void
    {
        $this->app->singleton(DatabaseManager::class, function ($app): DatabaseManager {
            $config = $app->make(Repository::class);

            return new DatabaseManager(
                $app->make(BinaryLocator::class),
                $this->toInt($config->get('backup-manager.timeout.database')),
            );
        });

        $this->app->singleton(CompressorManager::class, function ($app): CompressorManager {
            $config = $app->make(Repository::class);

            return new CompressorManager(
                $app->make(BinaryLocator::class),
                (int) $config->get('backup-manager.compression.level', 6),
                $this->toInt($config->get('backup-manager.timeout.process')),
            );
        });

        $this->app->singleton(EncryptorManager::class, function ($app): EncryptorManager {
            $config = $app->make(Repository::class);
            $maxMb = $config->get('backup-manager.encryption.gcm_max_megabytes');

            return new EncryptorManager(
                $config->get('backup-manager.encryption.key'),
                $maxMb === null ? null : (int) $maxMb * 1024 * 1024,
            );
        });
    }

    private function registerBackupManager(): void
    {
        $this->app->singleton(BackupManager::class, function ($app): BackupManager {
            return new BackupManager(
                config: $app->make(Repository::class),
                resolver: $app->make(ProfileResolver::class),
                environment: $app->make(EnvironmentProbe::class),
                pipeline: new Pipeline($app),
                events: $app->make(Dispatcher::class),
                // Self-contained archive: assemble one tar, compress, encrypt, store.
                archiveStages: [
                    $app->make(PrepareWorkspace::class),
                    $app->make(DumpDatabases::class),
                    $app->make(CollectSourceFiles::class),
                    $app->make(BuildArchive::class),
                    $app->make(CompressArchive::class),
                    $app->make(EncryptArchive::class),
                    $app->make(BuildManifest::class),
                    new StoreBackup($app->make(FilesystemFactory::class)),
                    new CleanupWorkspace,
                ],
                // Incremental snapshots come from the snapshot driver.
                snapshots: $app->make(SnapshotDriver::class),
            );
        });
    }

    private function workingDirectory(Repository $config): string
    {
        return (string) $config->get('backup-manager.working_directory', storage_path('app/backup-manager/temp'));
    }

    private function registerSchedule(): void
    {
        $config = $this->app->make(Repository::class);

        if (! $config->get('backup-manager.schedule.enabled', false)) {
            return;
        }

        $this->app->booted(function () use ($config): void {
            $schedule = $this->app->make(Schedule::class);

            foreach ([
                'backup:run' => $config->get('backup-manager.schedule.backup'),
                'backup:clean' => $config->get('backup-manager.schedule.cleanup'),
            ] as $command => $expression) {
                if (is_string($expression) && $expression !== '') {
                    // withoutOverlapping guards against a slow backup colliding
                    // with the next scheduled run (or with retention/monitoring).
                    $schedule->command($command)->cron($expression)->withoutOverlapping();
                }
            }
        });
    }

    private function toInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
