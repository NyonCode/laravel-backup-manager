<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp;

use Composer\InstalledVersions;
use Nyoncode\BackupManager\Contracts\Mcp\McpTool;
use Throwable;

/**
 * A transport-agnostic Model Context Protocol server for the backup manager.
 *
 * It speaks JSON-RPC 2.0: hand it one decoded message (or a batch) and it
 * returns the reply to write back, or null for a notification. Keeping the
 * protocol here — rather than in the console command — is what makes the whole
 * surface testable without spawning a process.
 *
 * Deliberately dependency-free: an assistant helping a user set up their first
 * backup should not have to install a second package first.
 */
final class Server
{
    public const NAME = 'backup-manager';

    /**
     * Protocol revisions this server implements, newest first. The client's
     * requested revision wins when we support it; otherwise we answer with our
     * newest and let the client decide.
     *
     * @var list<string>
     */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    private const PARSE_ERROR = -32700;

    private const INVALID_REQUEST = -32600;

    private const METHOD_NOT_FOUND = -32601;

    private const INVALID_PARAMS = -32602;

    private const INTERNAL_ERROR = -32603;

    /**
     * @param  list<McpTool>  $tools
     * @param  list<McpResource>  $resources
     * @param  list<McpPrompt>  $prompts
     */
    public function __construct(
        private readonly array $tools,
        private readonly array $resources,
        private readonly array $prompts,
        private readonly AccessPolicy $policy,
        private readonly string $instructions,
        private readonly ArgumentValidator $validator = new ArgumentValidator,
    ) {}

    /**
     * Handle one raw JSON-RPC frame; returns the frame to write back, or null
     * when the message needs no reply.
     */
    public function handle(string $payload): ?string
    {
        $payload = trim($payload);

        if ($payload === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return $this->encode($this->error(null, self::PARSE_ERROR, 'Invalid JSON payload.'));
        }

        // JSON-RPC batching: supported for the revisions that allow it, and
        // harmless for the ones that dropped it.
        if (array_is_list($decoded)) {
            $replies = [];

            foreach ($decoded as $message) {
                $reply = is_array($message)
                    ? $this->dispatch($message)
                    : $this->error(null, self::INVALID_REQUEST, 'Batch entries must be objects.');

                if ($reply !== null) {
                    $replies[] = $reply;
                }
            }

            return $replies === [] ? null : $this->encode($replies);
        }

        $reply = $this->dispatch($decoded);

        return $reply === null ? null : $this->encode($reply);
    }

    /**
     * The tools this installation exposes, after the access policy has had its say.
     *
     * @return list<McpTool>
     */
    public function tools(): array
    {
        return array_values(array_filter(
            $this->tools,
            fn (McpTool $tool): bool => $this->policy->permits($tool->access()),
        ));
    }

    /**
     * @param  array<mixed>  $message
     * @return array<string, mixed>|null
     */
    private function dispatch(array $message): ?array
    {
        /** @var mixed $id */
        $id = $message['id'] ?? null;
        $isNotification = ! array_key_exists('id', $message) || $id === null;
        $method = $message['method'] ?? null;

        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($method)) {
            return $isNotification ? null : $this->error($id, self::INVALID_REQUEST, 'Malformed JSON-RPC request.');
        }

        /** @var array<string, mixed> $params */
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        // Notifications never get a reply, whatever they carry.
        if ($isNotification) {
            return null;
        }

        try {
            return match ($method) {
                'initialize' => $this->result($id, $this->initialize($params)),
                'ping' => $this->result($id, (object) []),
                'tools/list' => $this->result($id, ['tools' => $this->toolDescriptors()]),
                'tools/call' => $this->callTool($id, $params),
                'resources/list' => $this->result($id, ['resources' => array_map(
                    static fn (McpResource $resource): array => $resource->toArray(),
                    $this->resources,
                )]),
                'resources/templates/list' => $this->result($id, ['resourceTemplates' => []]),
                'resources/read' => $this->readResource($id, $params),
                'prompts/list' => $this->result($id, ['prompts' => array_map(
                    static fn (McpPrompt $prompt): array => $prompt->toArray(),
                    $this->prompts,
                )]),
                'prompts/get' => $this->getPrompt($id, $params),
                default => $this->error($id, self::METHOD_NOT_FOUND, "Unknown method [{$method}]."),
            };
        } catch (Throwable $exception) {
            return $this->error($id, self::INTERNAL_ERROR, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => is_string($requested) && in_array($requested, self::PROTOCOL_VERSIONS, true)
                ? $requested
                : self::PROTOCOL_VERSIONS[0],
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['listChanged' => false, 'subscribe' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => self::NAME,
                'title' => 'Backup Manager',
                'version' => self::version(),
            ],
            'instructions' => $this->instructions,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolDescriptors(): array
    {
        return array_map(static fn (McpTool $tool): array => [
            'name' => $tool->name(),
            'title' => $tool->title(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            'annotations' => [
                'title' => $tool->title(),
                'readOnlyHint' => $tool->access()->isReadOnly(),
                'destructiveHint' => $tool->access()->isDestructive(),
                'openWorldHint' => false,
            ],
        ], $this->tools());
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;

        if (! is_string($name)) {
            return $this->error($id, self::INVALID_PARAMS, 'A tool name is required.');
        }

        $tool = $this->find($name);

        if ($tool === null) {
            $available = implode(', ', array_map(static fn (McpTool $t): string => $t->name(), $this->tools()));

            return $this->error($id, self::INVALID_PARAMS, "Unknown tool [{$name}]. Available tools: {$available}.");
        }

        // The tool exists but may not be exposed here: answering with the reason
        // (rather than "unknown tool") tells the model, and the user reading the
        // transcript, exactly which flag would enable it.
        $denial = $this->policy->denialReason($tool->access());

        if ($denial !== null) {
            return $this->result($id, ToolResult::failed($denial, [
                'tool' => $name,
                'required_access' => $tool->access()->value,
            ])->toArray());
        }

        /** @var array<string, mixed> $arguments */
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $schema = $tool->inputSchema();
        $errors = $this->validator->validate($schema, $arguments);

        if ($errors !== []) {
            return $this->error($id, self::INVALID_PARAMS, implode(' ', $errors));
        }

        try {
            $result = $tool->handle($this->validator->withDefaults($schema, $arguments));
        } catch (Throwable $exception) {
            // A failing tool is a result the model must read, not a transport
            // fault: report the message, never a stack trace.
            $result = ToolResult::failed(
                sprintf('%s failed: %s', $name, $exception->getMessage()),
                ['exception' => $exception::class],
            );
        }

        return $this->result($id, $result->toArray());
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function readResource(mixed $id, array $params): array
    {
        $uri = $params['uri'] ?? null;

        foreach ($this->resources as $resource) {
            if ($resource->uri === $uri) {
                return $this->result($id, ['contents' => [[
                    'uri' => $resource->uri,
                    'name' => $resource->name,
                    'mimeType' => $resource->mimeType,
                    'text' => $resource->read(),
                ]]]);
            }
        }

        return $this->error($id, self::INVALID_PARAMS, sprintf('Unknown resource [%s].', is_string($uri) ? $uri : 'null'));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function getPrompt(mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;

        foreach ($this->prompts as $prompt) {
            if ($prompt->name === $name) {
                return $this->result($id, [
                    'description' => $prompt->description,
                    'messages' => $prompt->messages(),
                ]);
            }
        }

        return $this->error($id, self::INVALID_PARAMS, sprintf('Unknown prompt [%s].', is_string($name) ? $name : 'null'));
    }

    private function find(string $name): ?McpTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function encode(mixed $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if ($json !== false) {
            return $json;
        }

        return (string) json_encode($this->error(null, self::INTERNAL_ERROR, 'The response could not be encoded.'));
    }

    private static function version(): string
    {
        if (class_exists(InstalledVersions::class)) {
            try {
                return InstalledVersions::getPrettyVersion('nyoncode/laravel-backup-manager') ?? 'dev';
            } catch (Throwable) {
                return 'dev';
            }
        }

        return 'dev';
    }
}
