<?php

use Tackle\Support\CommandGuard;

// ---------------------------------------------------------------------------
// check() — allowlist matching (flat list, backward compatible)
// ---------------------------------------------------------------------------

it('allows commands matching the allowlist', function () {
    $guard = new CommandGuard;
    $allowlist = ['composer', 'npm', 'php artisan'];

    expect($guard->check('composer install', $allowlist))->toBeNull();
    expect($guard->check('npm run build', $allowlist))->toBeNull();
    expect($guard->check('php artisan migrate', $allowlist))->toBeNull();
});

it('refuses commands not in the allowlist', function () {
    $guard = new CommandGuard;
    $result = $guard->check('rm -rf /', ['composer', 'npm']);

    expect($result)->toBeString()->toContain('not in the allowlist');
});

it('allows glob patterns in the allowlist', function () {
    $guard = new CommandGuard;
    $allowlist = ['make:*', 'route:list', 'migrate'];

    expect($guard->check('make:model Post', $allowlist))->toBeNull();
    expect($guard->check('make:controller UserController', $allowlist))->toBeNull();
    expect($guard->check('route:list', $allowlist))->toBeNull();
});

it('refuses artisan commands not in artisan allowlist', function () {
    $guard = new CommandGuard;
    $result = $guard->check('config:clear', ['make:*', 'route:list', 'migrate']);

    expect($result)->toBeString()->toContain('not in the allowlist');
});

// ---------------------------------------------------------------------------
// matches()
// ---------------------------------------------------------------------------

it('matches exact commands', function () {
    $guard = new CommandGuard;

    expect($guard->matches('migrate', ['migrate', 'db:seed']))->toBeTrue();
    expect($guard->matches('db:seed', ['migrate', 'db:seed']))->toBeTrue();
});

it('matches glob patterns', function () {
    $guard = new CommandGuard;

    expect($guard->matches('migrate:fresh', ['migrate:*']))->toBeTrue();
    expect($guard->matches('migrate:reset', ['migrate:*']))->toBeTrue();
    expect($guard->matches('make:model Post', ['make:*']))->toBeTrue();
});

it('returns false when no pattern matches', function () {
    $guard = new CommandGuard;

    expect($guard->matches('db:wipe', ['migrate:*', 'make:*']))->toBeFalse();
});

// ---------------------------------------------------------------------------
// resolveList() — env-aware resolution
// ---------------------------------------------------------------------------

it('returns a flat list unchanged', function () {
    $guard = new CommandGuard;
    $list = ['make:*', 'route:list', 'test'];

    expect($guard->resolveList($list))->toBe($list);
});

it('resolves the correct environment key', function () {
    $guard = new CommandGuard;

    $lists = [
        'local' => ['make:*', 'migrate:*', 'test'],
        'production' => ['route:list'],
    ];

    app()->detectEnvironment(fn () => 'local');
    expect($guard->resolveList($lists))->toBe(['make:*', 'migrate:*', 'test']);

    app()->detectEnvironment(fn () => 'production');
    expect($guard->resolveList($lists))->toBe(['route:list']);
});

it('falls back to wildcard key when env has no entry', function () {
    $guard = new CommandGuard;

    $lists = [
        'local' => ['make:*'],
        '*' => ['route:list'],
    ];

    app()->detectEnvironment(fn () => 'staging');
    expect($guard->resolveList($lists))->toBe(['route:list']);
});

it('returns empty array when env and wildcard are both absent', function () {
    $guard = new CommandGuard;

    $lists = [
        'local' => ['make:*'],
    ];

    app()->detectEnvironment(fn () => 'production');
    expect($guard->resolveList($lists))->toBe([]);
});
