<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Actions\Files\CollectFiles;
use Nyoncode\BackupManager\DTOs\FileSource;
use Nyoncode\BackupManager\Support\ArchivePath;
use Nyoncode\BackupManager\Support\PathMatcher;
use Nyoncode\BackupManager\ValueObjects\CollectedFile;

function makeTree(): string
{
    $root = sys_get_temp_dir().'/bm-'.uniqid();
    mkdir("{$root}/app", 0777, true);
    mkdir("{$root}/vendor/pkg", 0777, true);
    mkdir("{$root}/storage/logs", 0777, true);
    file_put_contents("{$root}/app/User.php", 'class User {}');
    file_put_contents("{$root}/.env", 'APP_KEY=secret');
    file_put_contents("{$root}/vendor/pkg/huge.php", 'vendor');
    file_put_contents("{$root}/storage/logs/laravel.log", 'log');

    return $root;
}

/** @return list<string> */
function paths(array $files): array
{
    return array_map(static fn (CollectedFile $file): string => $file->archivePath, $files);
}

it('collects files and mirrors their absolute path under files/', function (): void {
    $root = makeTree();
    $files = (new CollectFiles(new PathMatcher))->execute(new FileSource([$root]));

    $paths = paths($files);

    // Build the expected entry the same way the collector does, so the check
    // holds on both POSIX and Windows (drive-letter) paths.
    expect($paths)->toContain(ArchivePath::fromAbsolute("{$root}/app/User.php"))
        ->and($paths)->toContain(ArchivePath::fromAbsolute("{$root}/.env"));
});

it('excludes paths by prefix', function (): void {
    $root = makeTree();
    $files = (new CollectFiles(new PathMatcher))->execute(
        new FileSource([$root], excludePatterns: ["{$root}/vendor", "{$root}/storage"]),
    );

    foreach (paths($files) as $path) {
        expect($path)->not->toContain('/vendor/')
            ->and($path)->not->toContain('/storage/');
    }
});

it('excludes files by glob pattern', function (): void {
    $root = makeTree();
    $files = (new CollectFiles(new PathMatcher))->execute(
        new FileSource([$root], excludePatterns: ['*.log']),
    );

    foreach (paths($files) as $path) {
        expect($path)->not->toEndWith('.log');
    }
});

it('can omit hidden files', function (): void {
    $root = makeTree();
    $files = (new CollectFiles(new PathMatcher))->execute(
        new FileSource([$root], includeHidden: false),
    );

    foreach (paths($files) as $path) {
        expect($path)->not->toEndWith('/.env');
    }
});
