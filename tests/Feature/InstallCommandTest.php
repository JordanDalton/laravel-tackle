<?php

use Illuminate\Support\Composer;

beforeEach(function () {
    // Create a blank .env for tests
    file_put_contents(base_path('.env'), '');
});

afterEach(function () {
    @unlink(base_path('.env'));
});

it('runs successfully', function () {
    $this->artisan('tackle:install')->assertSuccessful();
});

it('outputs a done message', function () {
    $this->artisan('tackle:install')
        ->expectsOutputToContain('Done!');
});

it('appends AI_CODE_HEALING_ENABLED to .env', function () {
    $this->artisan('tackle:install')->assertSuccessful();

    expect(file_get_contents(base_path('.env')))
        ->toContain('AI_CODE_HEALING_ENABLED=false');
});

it('does not duplicate env vars when run twice', function () {
    $this->artisan('tackle:install')->assertSuccessful();
    $this->artisan('tackle:install')->assertSuccessful();

    $contents = file_get_contents(base_path('.env'));
    $count = substr_count($contents, 'AI_CODE_HEALING_ENABLED');

    expect($count)->toBe(1);
});

it('skips .env modification when file does not exist', function () {
    @unlink(base_path('.env'));

    $this->artisan('tackle:install')->assertSuccessful();

    expect(file_exists(base_path('.env')))->toBeFalse();
});

it('does not output migration confirmation unless --migrate is passed', function () {
    $this->artisan('tackle:install')
        ->assertSuccessful()
        ->expectsOutputToContain('Migrations published')
        ->doesntExpectOutputToContain('Migrations run');
});

it('installs tackle-remote via composer as a dev dependency', function () {
    $this->mock(Composer::class, function ($mock) {
        $mock->shouldReceive('setWorkingPath')->once()->with(base_path())->andReturnSelf();
        $mock->shouldReceive('requirePackages')
            ->once()
            ->withArgs(fn ($packages, $dev) => $packages === ['jordandalton/laravel-tackle-remote'] && $dev === true)
            ->andReturnTrue();
    });

    $this->artisan('tackle:install', ['component' => 'remote'])->assertSuccessful();
});

it('installs tackle-remote into require with --no-dev', function () {
    $this->mock(Composer::class, function ($mock) {
        $mock->shouldReceive('setWorkingPath')->andReturnSelf();
        $mock->shouldReceive('requirePackages')
            ->once()
            ->withArgs(fn ($packages, $dev) => $dev === false)
            ->andReturnTrue();
    });

    $this->artisan('tackle:install', ['component' => 'remote', '--no-dev' => true])->assertSuccessful();
});

it('fails loudly when composer require fails', function () {
    $this->mock(Composer::class, function ($mock) {
        $mock->shouldReceive('setWorkingPath')->andReturnSelf();
        $mock->shouldReceive('requirePackages')->once()->andReturnFalse();
    });

    $this->artisan('tackle:install', ['component' => 'remote'])->assertFailed();
});

it('installs tackle-codex via composer as a dev dependency', function () {
    $this->mock(Composer::class, function ($mock) {
        $mock->shouldReceive('setWorkingPath')->once()->with(base_path())->andReturnSelf();
        $mock->shouldReceive('requirePackages')
            ->once()
            ->withArgs(fn ($packages, $dev) => $packages === ['jordandalton/tackle-codex'] && $dev === true)
            ->andReturnTrue();
    });

    $this->artisan('tackle:install', ['component' => 'codex'])
        ->assertSuccessful()
        ->expectsOutputToContain('AI_CODE_PROVIDER=codex');
});

it('installs tackle-codex into require with --no-dev', function () {
    $this->mock(Composer::class, function ($mock) {
        $mock->shouldReceive('setWorkingPath')->andReturnSelf();
        $mock->shouldReceive('requirePackages')
            ->once()
            ->withArgs(fn ($packages, $dev) => $dev === false)
            ->andReturnTrue();
    });

    $this->artisan('tackle:install', ['component' => 'codex', '--no-dev' => true])->assertSuccessful();
});

it('fails loudly when the tackle-codex require fails', function () {
    $this->mock(Composer::class, function ($mock) {
        $mock->shouldReceive('setWorkingPath')->andReturnSelf();
        $mock->shouldReceive('requirePackages')->once()->andReturnFalse();
    });

    $this->artisan('tackle:install', ['component' => 'codex'])->assertFailed();
});

it('installs tackle-grok via composer as a dev dependency', function () {
    $this->mock(Composer::class, function ($mock) {
        $mock->shouldReceive('setWorkingPath')->once()->with(base_path())->andReturnSelf();
        $mock->shouldReceive('requirePackages')
            ->once()
            ->withArgs(fn ($packages, $dev) => $packages === ['jordandalton/tackle-grok'] && $dev === true)
            ->andReturnTrue();
    });

    $this->artisan('tackle:install', ['component' => 'grok'])
        ->assertSuccessful()
        ->expectsOutputToContain('AI_CODE_PROVIDER=grok');
});

it('installs tackle-grok into require with --no-dev', function () {
    $this->mock(Composer::class, function ($mock) {
        $mock->shouldReceive('setWorkingPath')->andReturnSelf();
        $mock->shouldReceive('requirePackages')
            ->once()
            ->withArgs(fn ($packages, $dev) => $dev === false)
            ->andReturnTrue();
    });

    $this->artisan('tackle:install', ['component' => 'grok', '--no-dev' => true])->assertSuccessful();
});

it('fails loudly when the tackle-grok require fails', function () {
    $this->mock(Composer::class, function ($mock) {
        $mock->shouldReceive('setWorkingPath')->andReturnSelf();
        $mock->shouldReceive('requirePackages')->once()->andReturnFalse();
    });

    $this->artisan('tackle:install', ['component' => 'grok'])->assertFailed();
});

it('scaffolds the tackle-review workflow without overwriting', function () {
    $path = base_path('.github/workflows/tackle-review.yml');
    @unlink($path);

    $this->artisan('tackle:install', ['component' => 'review'])->assertSuccessful();

    expect(file_get_contents($path))
        ->toContain('JordanDalton/tackle-review@v1')
        ->toContain('pull-requests: write');

    file_put_contents($path, 'custom: workflow');
    $this->artisan('tackle:install', ['component' => 'review'])->assertSuccessful();

    expect(file_get_contents($path))->toBe('custom: workflow');

    @unlink($path);
});

it('rejects unknown components with the available list', function () {
    $this->artisan('tackle:install', ['component' => 'nope'])->assertFailed();
});

it('scaffolds the tackle-eval nightly workflow without overwriting', function () {
    $path = base_path('.github/workflows/tackle-eval.yml');
    @unlink($path);

    $this->artisan('tackle:install', ['component' => 'eval-ci'])->assertSuccessful();

    expect(file_get_contents($path))
        ->toContain('name: Tackle Eval')
        ->toContain('php artisan ai:eval --json')
        ->toContain('cron:');

    file_put_contents($path, 'custom: workflow');
    $this->artisan('tackle:install', ['component' => 'eval-ci'])->assertSuccessful();

    expect(file_get_contents($path))->toBe('custom: workflow');

    @unlink($path);
});
