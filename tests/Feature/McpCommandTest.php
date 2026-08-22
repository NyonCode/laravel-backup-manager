<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Contracts\Mcp\McpTool;
use Nyoncode\BackupManager\Mcp\Server;
use Nyoncode\BackupManager\Mcp\StdioTransport;

it('registers the mcp command', function (): void {
    expect(array_keys(app('Illuminate\Contracts\Console\Kernel')->all()))->toContain('backup:mcp');
});

it('refuses to serve when the package disables it', function (): void {
    config()->set('backup-manager.mcp.enabled', false);

    $this->artisan('backup:mcp')->assertExitCode(1);
});

it('refuses to serve in an environment that is not allowed', function (): void {
    config()->set('backup-manager.mcp.enabled', true);
    config()->set('backup-manager.mcp.environments', ['local']);

    // Testbench runs as `testing`, which the default allow-list excludes.
    $this->artisan('backup:mcp')->assertExitCode(1);
});

it('lets a flag narrow what the session may do', function (): void {
    config()->set('backup-manager.mcp.allow_writes', true);
    config()->set('backup-manager.mcp.allow_destructive', true);

    // The command applies its flags to configuration before the server is built.
    $this->artisan('backup:mcp', ['--read-only' => true, '--allow-destructive' => true])->assertExitCode(1);

    expect(config('backup-manager.mcp.allow_writes'))->toBeFalse()
        ->and(config('backup-manager.mcp.allow_destructive'))->toBeFalse();
});

it('serves a whole session over a pair of streams', function (): void {
    $input = fopen('php://memory', 'r+');
    $output = fopen('php://memory', 'r+');

    foreach ([
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => 'backup_overview',
            'arguments' => (object) [],
        ]],
    ] as $message) {
        fwrite($input, json_encode($message)."\n");
    }

    rewind($input);

    (new StdioTransport(app(Server::class)))->run($input, $output);

    rewind($output);
    $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($output))));

    // Two requests, one notification: exactly two frames come back.
    expect($lines)->toHaveCount(2);

    $handshake = json_decode($lines[0], true);
    $overview = json_decode($lines[1], true);

    expect($handshake['result']['serverInfo']['name'])->toBe('backup-manager')
        ->and($overview['id'])->toBe(2)
        ->and($overview['result']['isError'])->toBeFalse()
        ->and($overview['result']['structuredContent'])->toHaveKey('installation');

    fclose($input);
    fclose($output);
});

it('exposes every tool as its declared access level', function (): void {
    config()->set('backup-manager.mcp.allow_writes', true);
    config()->set('backup-manager.mcp.allow_destructive', true);

    $levels = [];

    foreach (app(Server::class)->tools() as $tool) {
        $levels[$tool->name()] = $tool->access()->value;
    }

    expect($levels)->toBe([
        'backup_overview' => 'read',
        'backup_setup_guide' => 'read',
        'backup_config_reference' => 'read',
        'backup_list_profiles' => 'read',
        'backup_inspect_environment' => 'read',
        'backup_validate_configuration' => 'read',
        'backup_configure' => 'configure',
        'backup_list_backups' => 'read',
        'backup_run' => 'operate',
        'backup_verify' => 'operate',
        'backup_preview_retention' => 'read',
        'backup_apply_retention' => 'destructive',
    ]);
});

it('gives every tool a unique name and a usable schema', function (): void {
    config()->set('backup-manager.mcp.allow_writes', true);
    config()->set('backup-manager.mcp.allow_destructive', true);

    $names = array_map(static fn (McpTool $tool): string => $tool->name(), app(Server::class)->tools());

    expect($names)->toBe(array_unique($names));

    foreach (app(Server::class)->tools() as $tool) {
        expect($tool->name())->toMatch('/^backup_[a-z_]+$/')
            ->and($tool->inputSchema())->toHaveKey('type', 'object')
            ->and(strlen($tool->description()))->toBeGreaterThan(40);
    }
});
