<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Tools;

use Nyoncode\BackupManager\Mcp\Support\SetupGuide;
use Nyoncode\BackupManager\Mcp\ToolResult;

/**
 * The order of operations for setting backups up, plus the judgement calls that
 * decide whether the result is actually restorable.
 */
final class SetupGuideTool extends Tool
{
    public function __construct(private readonly SetupGuide $guide) {}

    public function name(): string
    {
        return 'backup_setup_guide';
    }

    public function title(): string
    {
        return 'Setup guide';
    }

    public function description(): string
    {
        return 'How to set up backups with this package: the order of tool calls, the decisions that '
            .'matter (scope, off-site destination, encryption key handling, retention, scheduling) and '
            .'ready-made configuration recipes. Read this before proposing a configuration.';
    }

    public function handle(array $arguments): ToolResult
    {
        return ToolResult::ok($this->guide->render());
    }
}
