<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use RuntimeException;

/**
 * Writes credentials into a Composer `auth.json`, merging with any existing
 * content. Keeping this out of `composer config --auth` means a licence token
 * never lands on the process argv where other local users could read it from
 * `ps`/`/proc`. The file is restricted to the owner (mode `0600`) since it
 * holds a secret.
 */
final readonly class ComposerAuthFile
{
    public function __construct(private string $path) {}

    /**
     * Set the http-basic username/password for $host, preserving every other
     * host and top-level key already in the file.
     */
    public function putHttpBasic(string $host, string $username, string $password): void
    {
        $auth = $this->read();

        if (! isset($auth['http-basic']) || ! is_array($auth['http-basic'])) {
            $auth['http-basic'] = [];
        }

        $auth['http-basic'][$host] = ['username' => $username, 'password' => $password];

        $this->write($auth);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        // A malformed or non-object auth.json is treated as empty rather than
        // aborting: we start fresh instead of refusing to record credentials.
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $auth
     */
    private function write(array $auth): void
    {
        $json = json_encode($auth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false || file_put_contents($this->path, $json.PHP_EOL) === false) {
            throw new RuntimeException("unable to write {$this->path}");
        }

        // Credentials file: restrict to the owner where the platform supports it.
        @chmod($this->path, 0600);
    }
}
