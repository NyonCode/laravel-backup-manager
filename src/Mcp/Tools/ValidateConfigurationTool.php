<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Tools;

use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Enums\ConfigSeverity;
use Nyoncode\BackupManager\Mcp\ToolResult;
use Nyoncode\BackupManager\Support\ConfigValidator;
use Nyoncode\BackupManager\ValueObjects\ConfigFinding;

/**
 * Checks the live configuration against everything that makes a backup fail —
 * or quietly stop protecting anything.
 */
final class ValidateConfigurationTool extends Tool
{
    public function __construct(
        private readonly Repository $config,
        private readonly ConfigValidator $validator,
    ) {}

    public function name(): string
    {
        return 'backup_validate_configuration';
    }

    public function title(): string
    {
        return 'Validate the configuration';
    }

    public function description(): string
    {
        return 'Reports every problem with the current backup configuration: missing disks or dump '
            .'binaries, an encryption key that is not set, a retention policy that would delete '
            .'everything, capabilities that need Pro, no off-site copy, no schedule. Each finding names the '
            .'key and how to fix it. Read-only.';
    }

    public function handle(array $arguments): ToolResult
    {
        $findings = $this->validator->validate($this->config);

        return ToolResult::ok($this->summarise($findings), self::report($findings));
    }

    /**
     * @param  list<ConfigFinding>  $findings
     * @return array<string, mixed>
     */
    public static function report(array $findings): array
    {
        $grouped = ['error' => [], 'warning' => [], 'info' => []];

        foreach ($findings as $finding) {
            $grouped[$finding->severity->value][] = $finding->toArray();
        }

        return [
            'healthy' => $grouped['error'] === [],
            'counts' => array_map('count', $grouped),
            'errors' => $grouped['error'],
            'warnings' => $grouped['warning'],
            'info' => $grouped['info'],
        ];
    }

    /**
     * @param  list<ConfigFinding>  $findings
     */
    private function summarise(array $findings): string
    {
        $errors = count(array_filter($findings, static fn (ConfigFinding $f): bool => $f->severity === ConfigSeverity::Error));
        $warnings = count(array_filter($findings, static fn (ConfigFinding $f): bool => $f->severity === ConfigSeverity::Warning));

        if ($errors > 0) {
            return sprintf('%d error(s) and %d warning(s): backups will fail or protect nothing until the errors are fixed.', $errors, $warnings);
        }

        return $warnings > 0
            ? sprintf('No errors, %d warning(s): backups will run, but the setup is weaker than it looks.', $warnings)
            : 'The configuration is sound.';
    }
}
