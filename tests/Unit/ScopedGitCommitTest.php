<?php

use Tackle\Support\PathGuard;
use Tackle\Support\ScopedGitCommit;

it('commits only selected files and leaves unrelated staged changes alone', function () {
    $dir = sys_get_temp_dir().'/tackle-scoped-commit-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/selected.txt', "original\n");
    file_put_contents($dir.'/unrelated.txt', "original\n");
    shell_exec('git -C '.escapeshellarg($dir).' init -q . && git -C '.escapeshellarg($dir)
        .' add -A && git -C '.escapeshellarg($dir).' -c user.email=t@t -c user.name=t commit -qm init');

    file_put_contents($dir.'/selected.txt', "selected change\n");
    file_put_contents($dir.'/unrelated.txt', "unrelated change\n");
    shell_exec('git -C '.escapeshellarg($dir).' add unrelated.txt');

    $git = new ScopedGitCommit(new PathGuard($dir));
    $files = $git->files(['selected.txt']);

    expect($git->stage($files)->successful())->toBeTrue()
        ->and($git->commit('Commit selected file', $files)->successful())->toBeTrue();

    $committed = trim((string) shell_exec('git -C '.escapeshellarg($dir).' diff-tree --no-commit-id --name-only -r HEAD'));
    $stillStaged = trim((string) shell_exec('git -C '.escapeshellarg($dir).' diff --cached --name-only'));

    expect($committed)->toBe('selected.txt')
        ->and($stillStaged)->toBe('unrelated.txt');

    shell_exec('rm -rf '.escapeshellarg($dir));
});

it('rejects protected paths and directories', function () {
    $dir = sys_get_temp_dir().'/tackle-scoped-commit-'.bin2hex(random_bytes(4));
    mkdir($dir.'/app', 0755, true);
    mkdir($dir.'/storage', 0755, true);
    $git = new ScopedGitCommit(new PathGuard($dir));

    expect(fn () => $git->files(['storage/logs/laravel.log']))
        ->toThrow(InvalidArgumentException::class, 'protected pattern')
        ->and(fn () => $git->files(['app']))
        ->toThrow(InvalidArgumentException::class, 'directory');

    shell_exec('rm -rf '.escapeshellarg($dir));
});
