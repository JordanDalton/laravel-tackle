<?php

use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Tools\RunTests;

afterEach(function () {
    @unlink(base_path('.env.testing'));
});

it('refuses to run in production without .env.testing', function () {
    app()->detectEnvironment(fn () => 'production');
    @unlink(base_path('.env.testing'));

    $guard  = new PathGuard(base_path());
    $result = (new RunTests($guard))->handle(new Request([]));

    expect($result)->toContain('RunTests is disabled');

    app()->detectEnvironment(fn () => 'testing');
});

it('allows running in production when .env.testing exists', function () {
    app()->detectEnvironment(fn () => 'production');
    file_put_contents(base_path('.env.testing'), 'APP_ENV=testing');

    $guard  = new PathGuard(base_path());
    $result = (new RunTests($guard))->handle(new Request([]));

    expect($result)->not->toContain('RunTests is disabled');

    app()->detectEnvironment(fn () => 'testing');
});

it('runs normally in non-production environments', function () {
    $guard  = new PathGuard(base_path());
    $result = (new RunTests($guard))->handle(new Request([]));

    expect($result)->toBeString()->not->toContain('RunTests is disabled');
});
