<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp;

/**
 * The outcome of a tool call, in the shape MCP clients expect.
 *
 * A failure is *not* a protocol error: the model has to see what went wrong to
 * correct itself, so failures travel back as a normal result carrying
 * `isError: true` (as the specification requires) rather than a JSON-RPC error.
 */
final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public string $summary,
        public array $data,
        public bool $isError,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(string $summary, array $data = []): self
    {
        return new self($summary, $data, false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function failed(string $summary, array $data = []): self
    {
        return new self($summary, $data, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // Text content first: every client renders it, and the JSON body is
            // what the model actually reasons over.
            'content' => [['type' => 'text', 'text' => $this->text()]],
            'structuredContent' => $this->data === [] ? (object) [] : $this->data,
            'isError' => $this->isError,
        ];
    }

    private function text(): string
    {
        if ($this->data === []) {
            return $this->summary;
        }

        $json = json_encode(
            $this->data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $this->summary."\n\n".($json === false ? '{}' : $json);
    }
}
