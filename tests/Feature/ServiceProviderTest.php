<?php

declare(strict_types=1);

it('boots the package and loads its configuration', function (): void {
    expect(config('backup-manager'))->toBeArray()
        ->and(config('backup-manager.default'))->toBe('default')
        ->and(config('backup-manager.profiles.default.type'))->toBe('full');
});
