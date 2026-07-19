<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Tests\Integration;

/**
 * Helpers for the database integration tests.
 *
 * Connection settings come from `BM_*` environment variables, each with a
 * sensible default. A test is skipped automatically when its database is not
 * reachable, so the suite stays green on a machine without those services.
 */
final class DatabaseTestKit
{
    /**
     * @return array<string, mixed>
     */
    public static function mysql(): array
    {
        return [
            'driver' => 'mysql',
            'host' => getenv('BM_MYSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('BM_MYSQL_PORT') ?: 33306),
            'database' => getenv('BM_MYSQL_DATABASE') ?: 'backup_manager_test',
            'username' => getenv('BM_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('BM_MYSQL_PASSWORD') ?: 'root',
            'charset' => 'utf8mb4',
            'prefix' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mariadb(): array
    {
        return [
            'driver' => 'mariadb',
            'host' => getenv('BM_MARIADB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('BM_MARIADB_PORT') ?: 33307),
            'database' => getenv('BM_MARIADB_DATABASE') ?: 'backup_manager_test',
            'username' => getenv('BM_MARIADB_USERNAME') ?: 'root',
            'password' => getenv('BM_MARIADB_PASSWORD') ?: 'root',
            'charset' => 'utf8mb4',
            'prefix' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function postgres(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => getenv('BM_PGSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('BM_PGSQL_PORT') ?: 55432),
            'database' => getenv('BM_PGSQL_DATABASE') ?: 'backup_manager_test',
            'username' => getenv('BM_PGSQL_USERNAME') ?: 'postgres',
            'password' => getenv('BM_PGSQL_PASSWORD') ?: 'postgres',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    /**
     * S3-compatible (MinIO) disk configuration for the remote-storage test.
     *
     * @return array<string, mixed>
     */
    public static function s3(): array
    {
        $host = getenv('BM_S3_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('BM_S3_PORT') ?: 9100);

        return [
            'driver' => 's3',
            'host' => $host,
            'port' => $port,
            'key' => getenv('BM_S3_KEY') ?: 'minioadmin',
            'secret' => getenv('BM_S3_SECRET') ?: 'minioadmin',
            'region' => getenv('BM_S3_REGION') ?: 'us-east-1',
            'bucket' => getenv('BM_S3_BUCKET') ?: 'backup-manager-test',
            'endpoint' => getenv('BM_S3_ENDPOINT') ?: "http://{$host}:{$port}",
            'use_path_style_endpoint' => true,
            'throw' => true,
        ];
    }

    /**
     * Explicitly configured binary paths (used locally where pg tools are
     * keg-only; empty on CI where they are on PATH).
     *
     * @return array<string, string|null>
     */
    public static function binaries(): array
    {
        return array_filter([
            'mysqldump' => getenv('BM_MYSQLDUMP_PATH') ?: null,
            'mysql' => getenv('BM_MYSQL_PATH') ?: null,
            'pg_dump' => getenv('BM_PGDUMP_PATH') ?: null,
            'psql' => getenv('BM_PSQL_PATH') ?: null,
        ]);
    }

    /**
     * Whether a TCP service is accepting connections at host:port.
     *
     * @param  array<string, mixed>  $config
     */
    public static function reachable(array $config): bool
    {
        $connection = @fsockopen((string) $config['host'], (int) $config['port'], $errno, $errstr, 1.0);

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }
}
