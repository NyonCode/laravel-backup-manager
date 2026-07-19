<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Exceptions;

use RuntimeException;

/**
 * Base exception for every failure raised by the backup manager.
 *
 * Catch this type to handle any package-level error uniformly.
 */
class BackupManagerException extends RuntimeException {}
