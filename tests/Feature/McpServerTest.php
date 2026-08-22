<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Contracts\Mcp\McpTool;
use Nyoncode\BackupManager\Mcp\Server;

it('completes the initialize handshake, echoing a protocol revision it supports', function (): void {
    $result = mcpRequest('initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => [],
        'clientInfo' => ['name' => 'pest', 'version' => '1.0'],
    ])['result'];

    expect($result['protocolVersion'])->toBe('2025-06-18')
        ->and($result['serverInfo']['name'])->toBe('backup-manager')
        ->and($result['capabilities'])->toHaveKeys(['tools', 'resources', 'prompts'])
        ->and($result['instructions'])->toContain('backup_setup_guide');
});

it('answers an unknown protocol revision with its own newest', function (): void {
    $result = mcpRequest('initialize', ['protocolVersion' => '1999-01-01'])['result'];

    expect($result['protocolVersion'])->toBe(Server::PROTOCOL_VERSIONS[0]);
});

it('lists its tools with a schema and honest annotations', function (): void {
    $tools = mcpRequest('tools/list')['result']['tools'];
    $names = array_column($tools, 'name');

    expect($names)->toContain('backup_overview', 'backup_configure', 'backup_run', 'backup_verify')
        ->and($names)->not->toContain('backup_restore');

    foreach ($tools as $tool) {
        expect($tool['inputSchema']['type'])->toBe('object')
            ->and($tool['description'])->not->toBeEmpty()
            ->and($tool['annotations'])->toHaveKeys(['readOnlyHint', 'destructiveHint']);
    }

    $overview = $tools[array_search('backup_overview', $names, true)];
    expect($overview['annotations']['readOnlyHint'])->toBeTrue();
});

it('answers ping', function (): void {
    expect(mcpRequest('ping'))->toHaveKey('result');
});

it('rejects an unknown method and an unknown tool', function (): void {
    expect(mcpRequest('nope/nope')['error']['code'])->toBe(-32601)
        ->and(mcpRequest('tools/call', ['name' => 'backup_nope'])['error']['code'])->toBe(-32602)
        ->and(mcpRequest('tools/call', ['name' => 'backup_nope'])['error']['message'])->toContain('Available tools');
});

it('validates tool arguments before running anything', function (): void {
    $error = mcpRequest('tools/call', [
        'name' => 'backup_list_backups',
        'arguments' => ['limit' => 'ten'],
    ])['error'];

    expect($error['code'])->toBe(-32602)
        ->and($error['message'])->toContain('limit');

    $unknown = mcpRequest('tools/call', [
        'name' => 'backup_list_backups',
        'arguments' => ['profil' => 'x'],
    ])['error'];

    expect($unknown['message'])->toContain('Unknown argument [profil]');
});

it('says nothing to a notification', function (): void {
    expect(mcpRequest('notifications/initialized', [], id: null))->toBeNull();
});

it('reports malformed input as a protocol error', function (): void {
    $server = app(Server::class);

    expect(json_decode((string) $server->handle('{not json'), true)['error']['code'])->toBe(-32700)
        ->and($server->handle('   '))->toBeNull();
});

it('answers a batch with one reply per request', function (): void {
    $reply = app(Server::class)->handle((string) json_encode([
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
    ]));

    $decoded = json_decode((string) $reply, true);

    expect($decoded)->toHaveCount(2)
        ->and(array_column($decoded, 'id'))->toBe([1, 2]);
});

it('exposes the guide and the configuration as readable resources', function (): void {
    $resources = mcpRequest('resources/list')['result']['resources'];

    expect(array_column($resources, 'uri'))->toBe([
        'backup-manager://guide/setup',
        'backup-manager://config/reference',
        'backup-manager://config/current',
    ]);

    $guide = mcpRequest('resources/read', ['uri' => 'backup-manager://guide/setup'])['result']['contents'][0];

    expect($guide['text'])->toContain('Setting up backups')
        ->and(mcpRequest('resources/read', ['uri' => 'backup-manager://nope'])['error']['code'])->toBe(-32602);
});

it('redacts credentials from the configuration resource', function (): void {
    config()->set('backup-manager.encryption.key', 'base64:'.base64_encode(random_bytes(32)));

    $contents = mcpRequest('resources/read', ['uri' => 'backup-manager://config/current'])['result']['contents'][0]['text'];
    $decoded = json_decode($contents, true);

    expect($decoded['encryption']['key'])->toBe('********')
        ->and($contents)->not->toContain('base64:');
});

it('offers a setup prompt', function (): void {
    expect(array_column(mcpRequest('prompts/list')['result']['prompts'], 'name'))->toBe(['set-up-backups'])
        ->and(mcpRequest('prompts/get', ['name' => 'set-up-backups'])['result']['messages'][0]['content']['text'])
        ->toContain('Set up backups for this Laravel application.');
});

it('hides configuration writes when the installation forbids them', function (): void {
    config()->set('backup-manager.mcp.allow_writes', false);

    $names = array_map(static fn (McpTool $tool): string => $tool->name(), app(Server::class)->tools());

    expect($names)->not->toContain('backup_configure')
        ->and($names)->toContain('backup_overview');

    // Calling it anyway explains itself instead of pretending the tool does not exist.
    $result = mcpTool('backup_configure', ['config' => []]);

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('BACKUP_MCP_ALLOW_WRITES');
});

it('never exposes destructive tools by default', function (): void {
    $names = array_map(static fn (McpTool $tool): string => $tool->name(), app(Server::class)->tools());

    expect($names)->toContain('backup_preview_retention')
        ->and($names)->not->toContain('backup_apply_retention');

    $result = mcpTool('backup_apply_retention', ['dry_run' => false]);

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('BACKUP_MCP_ALLOW_DESTRUCTIVE');
});
