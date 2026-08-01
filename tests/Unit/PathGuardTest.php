<?php

use Tackle\Support\PathGuard;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/tackle-tests';
    @mkdir($this->workspace, 0755, true);

    config()->set('tackle.workspace', $this->workspace);
    config()->set('tackle.protected_paths', ['.env', '.env.*', 'storage/*', 'vendor/*', '.git/*']);
});

afterEach(function () {
    // Clean up test workspace.
    $files = glob($this->workspace.'/{,.}*', GLOB_BRACE) ?: [];
    foreach ($files as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
});

it('allows reads within workspace', function () {
    $file = $this->workspace.'/app/Foo.php';
    @mkdir(dirname($file), 0755, true);
    file_put_contents($file, '<?php');

    $guard = new PathGuard;
    expect($guard->checkRead('app/Foo.php'))->toBeNull();
});

it('refuses to read .env files', function () {
    file_put_contents($this->workspace.'/.env', 'APP_KEY=secret');

    $guard = new PathGuard;
    $result = $guard->checkRead('.env');

    expect($result)->toBeString()->toContain('.env');
});

it('refuses to read .env.production', function () {
    file_put_contents($this->workspace.'/.env.production', 'APP_KEY=secret');

    $guard = new PathGuard;
    $result = $guard->checkRead('.env.production');

    expect($result)->toBeString()->toContain('protected');
});

it('refuses to read files inside vendor/', function () {
    @mkdir($this->workspace.'/vendor/laravel', 0755, true);
    file_put_contents($this->workspace.'/vendor/laravel/framework.php', '<?php');

    $guard = new PathGuard;
    $result = $guard->checkRead('vendor/laravel/framework.php');

    expect($result)->toBeString()->toContain('protected');
});

it('refuses to read files inside storage/', function () {
    @mkdir($this->workspace.'/storage/logs', 0755, true);
    file_put_contents($this->workspace.'/storage/logs/laravel.log', 'log');

    $guard = new PathGuard;
    $result = $guard->checkRead('storage/logs/laravel.log');

    expect($result)->toBeString()->toContain('protected');
});

it('refuses paths outside workspace root', function () {
    $guard = new PathGuard;
    $result = $guard->checkRead('/etc/passwd');

    expect($result)->toBeString()->toContain('outside the workspace');
});

it('refuses path traversal attempts', function () {
    @mkdir($this->workspace.'/app', 0755, true);
    file_put_contents($this->workspace.'/app/test.php', '<?php');

    $guard = new PathGuard;
    $result = $guard->checkRead('app/../../etc/passwd');

    expect($result)->toBeString()->toContain('outside the workspace');
});

it('marks vendor paths as protected', function () {
    $guard = new PathGuard;

    expect($guard->isProtected('vendor/autoload.php'))->toBeTrue();
    expect($guard->isProtected('app/Models/User.php'))->toBeFalse();
});
