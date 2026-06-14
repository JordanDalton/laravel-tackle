<?php

use Illuminate\Support\Facades\Artisan;

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
