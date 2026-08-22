<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Tools;

use Nyoncode\BackupManager\Enums\McpToolAccess;
use Nyoncode\BackupManager\Managers\VerifyManager;
use Nyoncode\BackupManager\Mcp\ToolResult;
use Nyoncode\BackupManager\Support\BackupFinder;
use Nyoncode\BackupManager\Support\Bytes;
use Nyoncode\BackupManager\Support\ProfileResolver;

/**
 * Checks a stored backup against its manifest, without restoring it.
 */
final class VerifyBackupTool extends Tool
{
    public function __construct(
        private readonly ProfileResolver $resolver,
        private readonly BackupFinder $finder,
        private readonly VerifyManager $verifier,
    ) {}

    public function name(): string
    {
        return 'backup_verify';
    }

    public function title(): string
    {
        return 'Verify a backup';
    }

    public function description(): string
    {
        return 'Verifies a stored backup against its manifest — archive checksum, and the manifest '
            .'signature when signing is in use. Defaults to the newest backup of the profile. Reads the '
            .'archive; changes nothing.';
    }

    public function access(): McpToolAccess
    {
        return McpToolAccess::Operate;
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'profile' => $this->profileProperty(),
            'disk' => [
                'type' => 'string',
                'description' => 'Destination disk. Defaults to the profile\'s first destination.',
            ],
            'backup' => [
                'type' => 'string',
                'description' => 'Backup file name. Defaults to the newest backup.',
            ],
        ]);
    }

    public function handle(array $arguments): ToolResult
    {
        $definition = $this->resolver->resolve($this->profile($arguments));
        $disk = is_string($arguments['disk'] ?? null) && $arguments['disk'] !== ''
            ? $arguments['disk']
            : ($definition->destinations[0] ?? 'local');
        $name = is_string($arguments['backup'] ?? null) && $arguments['backup'] !== '' ? $arguments['backup'] : null;

        $backup = $this->finder->find($disk, $definition->path, $name);

        if ($backup === null) {
            return ToolResult::failed(sprintf(
                'No %s found on disk [%s] under [%s].',
                $name === null ? 'backup' : "backup named [{$name}]",
                $disk,
                $definition->path,
            ));
        }

        $result = $this->verifier->verify($backup);
        $manifest = $result->manifest;

        $data = [
            'backup' => $backup->name,
            'disk' => $disk,
            'mode' => $backup->mode->value,
            'created_at' => $backup->createdAt->format(DATE_ATOM),
            'size_human' => Bytes::human($backup->size),
            'checksum_valid' => $result->checksumValid,
            'signature_valid' => $result->signatureValid,
            'passed' => $result->passed(),
            'databases' => count($manifest->databases),
            'files' => $manifest->fileCount(),
            'encryption' => $manifest->encryption->value,
        ];

        return $result->passed()
            ? ToolResult::ok(sprintf('[%s] verified: the stored archive matches its manifest.', $backup->name), $data)
            : ToolResult::failed(
                sprintf('[%s] FAILED verification — treat this backup as unusable and investigate before relying on it.', $backup->name),
                $data,
            );
    }
}
