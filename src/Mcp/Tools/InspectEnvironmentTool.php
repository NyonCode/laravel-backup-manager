<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Tools;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Nyoncode\BackupManager\Mcp\ToolResult;
use Nyoncode\BackupManager\Support\BinaryLocator;
use Nyoncode\BackupManager\Support\Bytes;

/**
 * What this host can actually do: which disks and database connections exist,
 * which dump and compression tools are installed, and whether there is room to
 * assemble an archive.
 *
 * Credentials are never included — a disk is reported by driver and location,
 * a database by driver, name and host. Nothing here needs a password to be
 * useful, so nothing here carries one.
 */
final class InspectEnvironmentTool extends Tool
{
    private const BINARIES = ['mysqldump', 'mysql', 'mariadb-dump', 'mariadb', 'pg_dump', 'psql', 'zstd', 'tar'];

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly BinaryLocator $binaries,
    ) {}

    public function name(): string
    {
        return 'backup_inspect_environment';
    }

    public function title(): string
    {
        return 'Inspect the environment';
    }

    public function description(): string
    {
        return 'The filesystem disks, database connections, dump/compression binaries and PHP extensions '
            .'available on this host, plus free space in the working directory. Use it to pick destinations '
            .'and connections that really exist before configuring anything. Read-only; no credentials are returned.';
    }

    public function handle(array $arguments): ToolResult
    {
        $workingDirectory = (string) $this->config->get('backup-manager.working_directory', '');
        $existing = $workingDirectory;

        while ($existing !== '' && ! is_dir($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }

        $free = $existing !== '' ? @disk_free_space($existing) : false;

        return ToolResult::ok(
            sprintf(
                '%d disk(s), %d database connection(s); %s.',
                count($this->disks()),
                count($this->connections()),
                is_float($free) ? Bytes::human((int) $free).' free for archives' : 'free space unknown',
            ),
            [
                'application' => [
                    'name' => $this->config->get('app.name'),
                    'environment' => $this->app->environment(),
                    'laravel' => $this->app->version(),
                    'php' => PHP_VERSION,
                    'operating_system' => php_uname('s').' '.php_uname('r'),
                ],
                'disks' => $this->disks(),
                'database_connections' => $this->connections(),
                'binaries' => $this->binaryPaths(),
                'php_extensions' => [
                    'openssl' => extension_loaded('openssl'),
                    'zlib' => extension_loaded('zlib'),
                    'bz2' => extension_loaded('bz2'),
                ],
                'working_directory' => [
                    'path' => $workingDirectory,
                    'exists' => is_dir($workingDirectory),
                    'writable' => $existing !== '' && is_writable($existing),
                    'free_bytes' => is_float($free) ? (int) $free : null,
                    'free_human' => is_float($free) ? Bytes::human((int) $free) : null,
                ],
            ],
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function disks(): array
    {
        $disks = [];
        /** @var array<string, mixed> $configured */
        $configured = (array) $this->config->get('filesystems.disks', []);

        foreach ($configured as $name => $disk) {
            $disk = (array) $disk;
            $driver = (string) ($disk['driver'] ?? 'unknown');

            $disks[(string) $name] = array_filter([
                'driver' => $driver,
                'root' => $driver === 'local' ? ($disk['root'] ?? null) : null,
                'bucket' => $disk['bucket'] ?? null,
                'region' => $disk['region'] ?? null,
                'off_site' => $driver !== 'local',
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $disks;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function connections(): array
    {
        $connections = [];
        /** @var array<string, mixed> $configured */
        $configured = (array) $this->config->get('database.connections', []);
        $default = (string) $this->config->get('database.default', '');

        foreach ($configured as $name => $connection) {
            $connection = (array) $connection;

            $connections[(string) $name] = array_filter([
                'driver' => $connection['driver'] ?? null,
                'database' => $connection['database'] ?? null,
                'host' => $connection['host'] ?? null,
                'port' => $connection['port'] ?? null,
                'is_default' => ((string) $name === $default) ?: null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $connections;
    }

    /**
     * @return array<string, string|null>
     */
    private function binaryPaths(): array
    {
        $paths = [];

        foreach (self::BINARIES as $binary) {
            $paths[$binary] = $this->binaries->has($binary) ? $this->binaries->locate($binary) : null;
        }

        return $paths;
    }
}
