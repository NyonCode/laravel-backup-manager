<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Enums;

/**
 * How badly a configuration finding matters.
 */
enum ConfigSeverity: string
{
    /** The configuration will fail, or has already silently stopped protecting data. */
    case Error = 'error';

    /** It works, but the setup is weaker than it looks. */
    case Warning = 'warning';

    /** Worth knowing; nothing is wrong. */
    case Info = 'info';
}
