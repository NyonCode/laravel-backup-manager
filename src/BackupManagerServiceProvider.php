<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
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
use Nyoncode\BackupManager\Commands\BackupMcpCommand;
use Nyoncode\BackupManager\Commands\BackupRestoreCommand;
use Nyoncode\BackupManager\Commands\BackupRunCommand;
use Nyoncode\BackupManager\Commands\BackupUpgradeCommand;
use Nyoncode\BackupManager\Commands\BackupVerifyCommand;
use Nyoncode\BackupManager\Contracts\Archive\Archiver;
use Nyoncode\BackupManager\Contracts\Integrity\Signer;
use Nyoncode\BackupManager\Contracts\Mcp\McpTool;
use Nyoncode\BackupManager\Contracts\Notification\NotificationChannel;
use Nyoncode\BackupManager\Contracts\Snapshot\SnapshotDriver;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Exceptions\IntegrityException;
use Nyoncode\BackupManager\Listeners\LogsBackupActivity;
use Nyoncode\BackupManager\Listeners\SendBackupNotifications;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Managers\CleanupManager;
use Nyoncode\BackupManager\Managers\CompressorManager;
use Nyoncode\BackupManager\Managers\DatabaseManager;
use Nyoncode\BackupManager\Managers\EncryptorManager;
use Nyoncode\BackupManager\Mcp\AccessPolicy;
use Nyoncode\BackupManager\Mcp\McpPrompt;
use Nyoncode\BackupManager\Mcp\McpResource;
use Nyoncode\BackupManager\Mcp\Server as McpServer;
use Nyoncode\BackupManager\Mcp\Support\SetupGuide;
use Nyoncode\BackupManager\Mcp\Tools\ConfigReferenceTool;
use Nyoncode\BackupManager\Mcp\Tools\ConfigureTool;
use Nyoncode\BackupManager\Mcp\Tools\InspectEnvironmentTool;
use Nyoncode\BackupManager\Mcp\Tools\ListBackupsTool;
use Nyoncode\BackupManager\Mcp\Tools\ListProfilesTool;
use Nyoncode\BackupManager\Mcp\Tools\OverviewTool;
use Nyoncode\BackupManager\Mcp\Tools\RetentionTool;
use Nyoncode\BackupManager\Mcp\Tools\RunBackupTool;
use Nyoncode\BackupManager\Mcp\Tools\SetupGuideTool;
use Nyoncode\BackupManager\Mcp\Tools\ValidateConfigurationTool;
use Nyoncode\BackupManager\Mcp\Tools\VerifyBackupTool;
use Nyoncode\BackupManager\Notifications\MailChannel;
use Nyoncode\BackupManager\Retention\SimpleStrategy;
use Nyoncode\BackupManager\Services\BackupNotifier;
use Nyoncode\BackupManager\Snapshot\NullSnapshotDriver;
use Nyoncode\BackupManager\Storage\DiskBackupRepository;
use Nyoncode\BackupManager\Support\BinaryLocator;
use Nyoncode\BackupManager\Support\ConfigReference;
use Nyoncode\BackupManager\Support\ConfigValidator;
use Nyoncode\BackupManager\Support\ConfigWriter;
use Nyoncode\BackupManager\Support\EnvFile;
use Nyoncode\BackupManager\Support\EnvironmentProbe;
use Nyoncode\BackupManager\Support\PhpConfigFile;
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

    /**
     * Container tag collecting the tools the MCP server exposes. An add-on
     * registers a tool by tagging an implementation of the McpTool contract
     * onto this name; the server picks it up without knowing about it.
     */
    public const MCP_TOOLS = 'backup-manager.mcp-tools';

    /**
     * Aliases for the two registrations of the retention tool: the same class
     * serves a read-only preview and the destructive apply.
     */
    private const RETENTION_PREVIEW = 'backup-manager.mcp.retention-preview';

    private const RETENTION_APPLY = 'backup-manager.mcp.retention-apply';

    public function configure(Packager $packager): void
    {
        $packager
            ->name('Backup Manager')
            ->hasShortName('backup-manager')
            ->hasConfig()
            ->hasAbout()
            // Wire the container after the package is registered — the toolkit
            // fires this once config has been merged, so bindings can safely
            // read configuration without overriding register() itself.
            ->registeredPackage(function (): void {
                $this->registerServices();
                $this->registerManagers();
                $this->registerBackupManager();
                $this->registerMcpServer();
            })
            // Subscribe the activity/notification listeners and register the
            // schedule once the package has fully booted.
            ->bootedPackage(function (): void {
                $events = $this->app->make(Dispatcher::class);
                $events->subscribe(LogsBackupActivity::class);
                $events->subscribe(SendBackupNotifications::class);

                $this->registerSchedule();
            });
    }

    /**
     * Rows added to this package's section of `php artisan about`.
     *
     * Every value is a closure so it is evaluated lazily when the command runs
     * (never at boot) and reflects the live container: the snapshot row reports
     * whether the Pro engine has overridden the core's null driver.
     *
     * @return array<string, \Closure>
     */
    public function aboutData(): array
    {
        return [
            'Default profile' => fn (): string => (string) config('backup-manager.default', 'default'),
            'Encryption' => fn (): string => config('backup-manager.encryption.key') ? 'enabled' : 'disabled',
            'Manifest signing' => fn (): string => config('backup-manager.integrity.signing.enabled') ? 'enabled' : 'disabled',
            'Incremental snapshots' => fn (): string => $this->app->make(SnapshotDriver::class) instanceof NullSnapshotDriver
                ? 'core (read-only)'
                : 'pro',
            'Scheduling' => fn (): string => config('backup-manager.schedule.enabled') ? 'enabled' : 'disabled',
            'MCP server' => fn (): string => config('backup-manager.mcp.enabled')
                ? 'backup:mcp'.(config('backup-manager.mcp.allow_writes') ? ' (writes allowed)' : ' (read-only)')
                : 'disabled',
        ];
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
            BackupMcpCommand::class,
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
        // package (nyoncode/laravel-backup-manager-pro) overrides this binding with the
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

    /**
     * Wire the Model Context Protocol server.
     *
     * The tools are collected through a container tag, so the Pro package — or
     * an application — can contribute one without this provider knowing about
     * it. Nothing here is resolved until `backup:mcp` runs, so an installation
     * that never talks to an assistant pays nothing for the feature.
     */
    private function registerMcpServer(): void
    {
        $this->app->singleton(ConfigReference::class);
        $this->app->singleton(SetupGuide::class);

        $this->app->singleton(ConfigValidator::class, fn ($app): ConfigValidator => new ConfigValidator(
            $app->make(BinaryLocator::class),
            $app->make(SnapshotDriver::class),
            config_path('backup-manager.php'),
            base_path('.env'),
        ));

        $this->app->singleton(ConfigWriter::class, fn ($app): ConfigWriter => new ConfigWriter(
            $app->make(ConfigReference::class),
            new PhpConfigFile(config_path('backup-manager.php')),
            new EnvFile(base_path('.env')),
            dirname(__DIR__).'/config/backup-manager.php',
            storage_path('app/backup-manager/config-backups'),
        ));

        $this->app->singleton(AccessPolicy::class, fn ($app): AccessPolicy => AccessPolicy::fromConfig(
            $app->make(Repository::class),
            (string) $this->app->environment(),
        ));

        // One class, two registrations: a preview every assistant may call, and
        // the destructive apply that stays hidden unless explicitly allowed.
        $this->app->singleton(self::RETENTION_PREVIEW, fn ($app): RetentionTool => $this->retentionTool($app, false));
        $this->app->singleton(self::RETENTION_APPLY, fn ($app): RetentionTool => $this->retentionTool($app, true));

        $this->app->tag([
            OverviewTool::class,
            SetupGuideTool::class,
            ConfigReferenceTool::class,
            ListProfilesTool::class,
            InspectEnvironmentTool::class,
            ValidateConfigurationTool::class,
            ConfigureTool::class,
            ListBackupsTool::class,
            RunBackupTool::class,
            VerifyBackupTool::class,
            self::RETENTION_PREVIEW,
            self::RETENTION_APPLY,
        ], self::MCP_TOOLS);

        $this->app->singleton(McpServer::class, fn ($app): McpServer => new McpServer(
            tools: $this->mcpTools($app),
            resources: $this->mcpResources($app),
            prompts: $this->mcpPrompts($app),
            policy: $app->make(AccessPolicy::class),
            instructions: $this->mcpInstructions(),
        ));
    }

    private function retentionTool(Container $app, bool $destructive): RetentionTool
    {
        return new RetentionTool(
            $app->make(ProfileResolver::class),
            $app->make(CleanupManager::class),
            $destructive,
        );
    }

    /**
     * @return list<McpTool>
     */
    private function mcpTools(Container $app): array
    {
        $tools = [];

        foreach ($app->tagged(self::MCP_TOOLS) as $tool) {
            if ($tool instanceof McpTool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * @return list<McpResource>
     */
    private function mcpResources(Container $app): array
    {
        return [
            new McpResource(
                uri: 'backup-manager://guide/setup',
                name: 'setup-guide',
                title: 'Backup setup guide',
                description: 'How to set backups up with this package, in the order the work should be done.',
                mimeType: 'text/markdown',
                reader: fn (): string => $app->make(SetupGuide::class)->render(),
            ),
            new McpResource(
                uri: 'backup-manager://config/reference',
                name: 'config-reference',
                title: 'Configuration reference',
                description: 'Every configuration key, with its type, allowed values and environment variable.',
                mimeType: 'application/json',
                reader: fn (): string => $this->json($app->make(ConfigReference::class)->toArray()),
            ),
            new McpResource(
                uri: 'backup-manager://config/current',
                name: 'current-config',
                title: 'Current configuration',
                description: 'The live backup-manager configuration, with credentials removed.',
                mimeType: 'application/json',
                reader: fn (): string => $this->json($this->redactedConfig($app)),
            ),
        ];
    }

    /**
     * @return list<McpPrompt>
     */
    private function mcpPrompts(Container $app): array
    {
        return [
            new McpPrompt(
                name: 'set-up-backups',
                title: 'Set up backups',
                description: 'Walk through configuring, running and verifying a backup for this application.',
                renderer: fn (): string => "Set up backups for this Laravel application.\n\n"
                    .$app->make(SetupGuide::class)->render(),
            ),
        ];
    }

    private function mcpInstructions(): string
    {
        return 'Backup Manager backs up this Laravel application\'s databases and files. '
            .'When the task is to set backups up, call backup_setup_guide first, then backup_overview and '
            .'backup_inspect_environment before proposing anything — never invent a disk, a database '
            .'connection or a configuration key. Configuration is written with backup_configure, which is a '
            .'dry run unless told otherwise. Prove the result with backup_run followed by backup_verify. '
            .'Restoring a backup is deliberately not exposed here: it overwrites a live application and '
            .'belongs to a person running php artisan backup:restore.';
    }

    /**
     * The live configuration with every credential removed — a resource is
     * read by a model and may end up in a transcript.
     *
     * @return array<string, mixed>
     */
    private function redactedConfig(Container $app): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) $app->make(Repository::class)->get('backup-manager', []);

        foreach ($app->make(ConfigReference::class)->options() as $option) {
            if (! $option->secret || str_contains($option->key, '*')) {
                continue;
            }

            $value = data_get($config, $option->key);

            if ($value !== null && $value !== '') {
                data_set($config, $option->key, '********');
            }
        }

        return $config;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
