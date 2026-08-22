<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp;

use Closure;

/**
 * A document the server exposes for a client to read on demand.
 *
 * Resources are read lazily: the closure runs when a client asks for the URI,
 * never at boot, so listing them costs nothing.
 */
final readonly class McpResource
{
    /**
     * @param  Closure(): string  $reader
     */
    public function __construct(
        public string $uri,
        public string $name,
        public string $title,
        public string $description,
        public string $mimeType,
        private Closure $reader,
    ) {}

    public function read(): string
    {
        return ($this->reader)();
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'uri' => $this->uri,
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'mimeType' => $this->mimeType,
        ];
    }
}
