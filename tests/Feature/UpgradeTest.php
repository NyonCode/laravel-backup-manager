<?php

declare(strict_types=1);

/**
 * The free core ships a `backup:upgrade` command that activates a Pro licence
 * and installs the Pro package. The dry run exercises everything except the
 * actual composer calls, so no network or process work happens here.
 */
it('registers the upgrade command', function (): void {
    expect(array_keys(app('Illuminate\Contracts\Console\Kernel')->all()))
        ->toContain('backup:upgrade');
});

it('previews the composer steps on a dry run, with the token masked', function (): void {
    // Each expectsOutputToContain targets a distinct line (the matcher consumes
    // matched lines), so the masked-token check gets the auth line to itself.
    $this->artisan('backup:upgrade', [
        'token' => 'SECRET-TOKEN-1234',
        '--repository' => 'https://packages.example.test',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('repositories.nyoncode')
        ->expectsOutputToContain('composer require nyoncode/laravel-backup-manager-pro')
        ->expectsOutputToContain('****1234')               // token masked on the auth line
        ->doesntExpectOutputToContain('SECRET-TOKEN-1234') // never printed raw
        ->assertExitCode(0);
});

it('rejects an empty token', function (): void {
    $this->artisan('backup:upgrade', ['token' => '', '--dry-run' => true])
        ->assertExitCode(2); // Command::INVALID
});

it('refuses to send the licence token over plain http', function (): void {
    $this->artisan('backup:upgrade', [
        'token' => 'SECRET-TOKEN-1234',
        '--repository' => 'http://packages.example.test',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Refusing to send the licence token over plain HTTP')
        ->assertExitCode(2);
});

it('warns that a token passed as an argument is in the shell history', function (): void {
    $this->artisan('backup:upgrade', [
        'token' => 'SECRET-TOKEN-1234',
        '--repository' => 'https://packages.example.test',
        '--dry-run' => true,
    ])->expectsOutputToContain('shell history')->assertSuccessful();
});
