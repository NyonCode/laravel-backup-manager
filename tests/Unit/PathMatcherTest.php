<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Support\PathMatcher;

beforeEach(function (): void {
    $this->matcher = new PathMatcher;
});

it('excludes by exact directory prefix', function (): void {
    expect($this->matcher->isExcluded('/app/vendor/pkg/file.php', ['/app/vendor']))->toBeTrue()
        ->and($this->matcher->isExcluded('/app/src/file.php', ['/app/vendor']))->toBeFalse();
});

it('does not treat a prefix as a substring (no false positives)', function (): void {
    // '/app/vendor' must NOT match '/app/vendor-tools' — different directory.
    expect($this->matcher->isExcluded('/app/vendor-tools/x.php', ['/app/vendor']))->toBeFalse();
});

it('matches the exact path itself', function (): void {
    expect($this->matcher->isExcluded('/app/.env', ['/app/.env']))->toBeTrue();
});

it('handles trailing slashes in patterns', function (): void {
    expect($this->matcher->isExcluded('/app/storage/logs/laravel.log', ['/app/storage/']))->toBeTrue();
});

it('excludes by glob on the full path', function (): void {
    expect($this->matcher->isExcluded('/app/storage/logs/laravel.log', ['*/logs/*']))->toBeTrue();
});

it('excludes by glob on the basename', function (): void {
    expect($this->matcher->isExcluded('/deep/nested/app.log', ['*.log']))->toBeTrue()
        ->and($this->matcher->isExcluded('/deep/nested/app.php', ['*.log']))->toBeFalse();
});

it('ignores empty patterns', function (): void {
    expect($this->matcher->isExcluded('/app/file.php', ['', '/nope']))->toBeFalse();
});

it('matches when any pattern in the set matches', function (): void {
    expect($this->matcher->isExcluded('/app/node_modules/x', ['/app/vendor', '/app/node_modules']))->toBeTrue();
});
