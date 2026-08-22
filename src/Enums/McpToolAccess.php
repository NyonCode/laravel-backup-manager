<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Enums;

/**
 * How much an MCP tool is allowed to touch.
 *
 * The server gates tools by this level rather than by name, so a tool an add-on
 * registers is subject to the same permissions as the built-in ones. The levels
 * are ordered: a stricter permission never implies a looser one.
 */
enum McpToolAccess: string
{
    /** Reads configuration or storage; changes nothing. Always available. */
    case Read = 'read';

    /** Creates or reads backups (run, verify). Writes data, destroys none. */
    case Operate = 'operate';

    /** Edits the application's `.env` / `config/backup-manager.php`. */
    case Configure = 'configure';

    /** Can delete backups or overwrite application state. */
    case Destructive = 'destructive';

    public function isReadOnly(): bool
    {
        return $this === self::Read;
    }

    public function isDestructive(): bool
    {
        return $this === self::Destructive;
    }

    /**
     * The config flag that must be enabled for this level, if any.
     */
    public function requiredFlag(): ?string
    {
        return match ($this) {
            self::Configure => 'backup-manager.mcp.allow_writes',
            self::Destructive => 'backup-manager.mcp.allow_destructive',
            default => null,
        };
    }
}
