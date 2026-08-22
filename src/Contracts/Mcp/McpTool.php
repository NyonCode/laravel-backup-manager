<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Mcp;

use Nyoncode\BackupManager\BackupManagerServiceProvider;
use Nyoncode\BackupManager\Enums\McpToolAccess;
use Nyoncode\BackupManager\Mcp\ToolResult;

/**
 * Capability: one tool an AI assistant can call over the Model Context Protocol.
 *
 * Tools are collected from the container tag
 * {@see BackupManagerServiceProvider::MCP_TOOLS}, so an
 * add-on contributes one by tagging an implementation — the server itself never
 * changes. Every tool declares an {@see McpToolAccess} level; the server refuses
 * to expose a tool whose level is not permitted by configuration.
 */
interface McpTool
{
    /**
     * The wire name, unique per server (`backup_run`).
     */
    public function name(): string;

    /**
     * A short human readable title for MCP clients.
     */
    public function title(): string;

    /**
     * What the tool does — written for the model that decides whether to call it.
     */
    public function description(): string;

    /**
     * JSON Schema describing the arguments. Must be an object schema.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * How much this tool is allowed to touch.
     */
    public function access(): McpToolAccess;

    /**
     * Run the tool. Arguments have been validated against {@see inputSchema()}
     * and defaults have been filled in.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): ToolResult;
}
