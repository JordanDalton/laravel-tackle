<?php

use Illuminate\Support\Facades\Process;
use Tackle\Support\WorktreeManager;

it('is not active before create is called', function () {
    $manager = new WorktreeManager();

    expect($manager->active())->toBeFalse();
    expect($manager->path())->toBe(base_path());
});

it('becomes active after create and returns a temp path', function () {
    Process::fake([
        'git worktree add*' => Process::result(''),
    ]);

    $manager = new WorktreeManager();
    $path    = $manager->create();

    expect($manager->active())->toBeTrue();
    expect($path)->toStartWith(sys_get_temp_dir() . '/tackle-worktree-');
    expect($manager->path())->toBe($path);
});

it('is no longer active after cleanup', function () {
    Process::fake([
        'git worktree add*'    => Process::result(''),
        'git worktree remove*' => Process::result(''),
    ]);

    $manager = new WorktreeManager();
    $manager->create();
    $manager->cleanup();

    expect($manager->active())->toBeFalse();
    expect($manager->path())->toBe(base_path());
});

it('cleanup is a no-op when not active', function () {
    Process::fake();

    $manager = new WorktreeManager();
    $manager->cleanup(); // should not throw

    Process::assertNothingRan();
    expect($manager->active())->toBeFalse();
});

it('throws when git worktree add fails', function () {
    Process::fake([
        'git worktree add*' => Process::result('', 'fatal: not a git repo', 1),
    ]);

    $manager = new WorktreeManager();

    expect(fn () => $manager->create())->toThrow(RuntimeException::class, 'Failed to create worktree');
    expect($manager->active())->toBeFalse();
});
