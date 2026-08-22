<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Tools;

use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Contracts\Snapshot\SnapshotDriver;
use Nyoncode\BackupManager\Enums\ConfigSeverity;
use Nyoncode\BackupManager\Mcp\AccessPolicy;
use Nyoncode\BackupManager\Mcp\ToolResult;
use Nyoncode\BackupManager\Support\ConfigValidator;
use Nyoncode\BackupManager\Support\Edition;
use Nyoncode\BackupManager\ValueObjects\ConfigFinding;

/**
 * The first call an assistant should make: what is installed, what is
 * configured, and what is already wrong.
 */
final class OverviewTool extends Tool
{
    public function __construct(
        private readonly Repository $config,
        private readonly ConfigValidator $validator,
        private readonly SnapshotDriver $snapshots,
        private readonly AccessPolicy $policy,
    ) {}

    public function name(): string
    {
        return 'backup_overview';
    }

    public function title(): string
    {
        return 'Backup overview';
    }

    public function description(): string
    {
        return 'Start here. Reports the installed edition, the configured profiles, how backups are '
            .'compressed, encrypted and retained, whether they are scheduled, and a count of the problems '
            .'the current configuration has. Read-only.';
    }

    public function handle(array $arguments): ToolResult
    {
        $findings = $this->validator->validate($this->config);
        $counts = $this->counts($findings);
        $profiles = [];

        /** @var array<string, mixed> $configured */
        $configured = (array) $this->config->get('backup-manager.profiles', []);

        foreach ($configured as $name => $profile) {
            $profile = (array) $profile;
            $profiles[(string) $name] = [
                'type' => $profile['type'] ?? 'full',
                'mode' => $profile['mode'] ?? 'archive',
                'destinations' => array_values((array) ($profile['destinations'] ?? [])),
                'path' => $profile['path'] ?? 'backups',
            ];
        }

        $key = $this->config->get('backup-manager.encryption.key');

        return ToolResult::ok(
            sprintf(
                '%s edition · %d profile(s) · %d error(s), %d warning(s). %s',
                Edition::proInstalled() ? 'Pro' : 'Core',
                count($profiles),
                $counts['error'],
                $counts['warning'],
                $counts['error'] > 0
                    ? 'Backups cannot be trusted until the errors are fixed — call backup_validate_configuration.'
                    : 'Call backup_validate_configuration for the detail.',
            ),
            [
                'installation' => Edition::toArray(),
                'default_profile' => (string) $this->config->get('backup-manager.default', 'default'),
                'profiles' => $profiles,
                'archive' => [
                    'compression' => $this->config->get('backup-manager.compression.algorithm'),
                    'encryption' => $this->config->get('backup-manager.encryption.algorithm'),
                    'encryption_key_configured' => is_string($key) && $key !== '',
                    'checksum' => $this->config->get('backup-manager.integrity.checksum'),
                    'manifest_signing' => (bool) $this->config->get('backup-manager.integrity.signing.enabled', false),
                ],
                'retention' => [
                    'strategy' => $this->config->get('backup-manager.retention.strategy'),
                    'keep_latest' => $this->config->get('backup-manager.retention.simple.keep_latest'),
                    'max_age_days' => $this->config->get('backup-manager.retention.max_age_days'),
                ],
                'schedule' => [
                    'enabled' => (bool) $this->config->get('backup-manager.schedule.enabled', false),
                    'backup' => $this->config->get('backup-manager.schedule.backup'),
                    'cleanup' => $this->config->get('backup-manager.schedule.cleanup'),
                ],
                'queue' => [
                    'enabled' => (bool) $this->config->get('backup-manager.queue.enabled', false),
                    'queue' => $this->config->get('backup-manager.queue.queue'),
                ],
                'notifications' => [
                    'enabled' => (bool) $this->config->get('backup-manager.monitoring.notifications.enabled', true),
                    'mail_recipients' => count(array_filter((array) $this->config->get('backup-manager.monitoring.notifications.mail.to', []))),
                ],
                'capabilities' => [
                    'incremental_backups' => $this->snapshots->supported(),
                    'gfs_retention' => Edition::proInstalled(),
                    'manifest_signing' => Edition::proInstalled(),
                    'monitoring_and_history' => Edition::proInstalled(),
                    'web_dashboard' => Edition::uiInstalled(),
                ],
                'mcp' => $this->policy->toArray(),
                'health' => $counts + [
                    'first_problems' => array_slice(array_map(
                        static fn (ConfigFinding $finding): string => "[{$finding->severity->value}] {$finding->key}: {$finding->message}",
                        array_values(array_filter(
                            $findings,
                            static fn (ConfigFinding $finding): bool => $finding->severity !== ConfigSeverity::Info,
                        )),
                    ), 0, 5),
                ],
            ],
        );
    }

    /**
     * @param  list<ConfigFinding>  $findings
     * @return array{error: int, warning: int, info: int}
     */
    private function counts(array $findings): array
    {
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];

        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }
}
