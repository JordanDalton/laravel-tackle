<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tackle\Upgrade\Auditor;

function fakeOutdatedJson(): string
{
    return json_encode(['installed' => [
        ['name' => 'laravel/framework', 'version' => 'v11.44.0', 'latest' => 'v12.21.0', 'latest-status' => 'update-possible', 'description' => 'The Laravel Framework.'],
        ['name' => 'pestphp/pest', 'version' => 'v3.7.0', 'latest' => 'v3.8.2', 'latest-status' => 'semver-safe-update', 'description' => 'Testing framework.'],
        ['name' => 'acme/dev-only', 'version' => 'dev-main', 'latest' => 'dev-main', 'latest-status' => 'up-to-date', 'description' => ''],
    ]]);
}

it('reports only direct dependencies with a new major', function () {
    Process::fake(fn () => Process::result(fakeOutdatedJson()));

    $majors = (new Auditor(sys_get_temp_dir()))->majors();

    expect($majors)->toHaveCount(1)
        ->and($majors[0]['name'])->toBe('laravel/framework')
        ->and($majors[0]['version'])->toBe('v11.44.0')
        ->and($majors[0]['latest'])->toBe('v12.21.0');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'composer', 'outdated', '--direct', '--format=json', '--no-interaction', '--no-ansi',
    ]);
});

it('returns an empty list when everything is on the latest major', function () {
    Process::fake(fn () => Process::result(json_encode(['installed' => []])));

    expect((new Auditor(sys_get_temp_dir()))->majors())->toBe([]);
});

it('throws when composer outdated fails', function () {
    Process::fake(fn () => Process::result(output: '', errorOutput: 'composer.json not found', exitCode: 1));

    (new Auditor(sys_get_temp_dir()))->majors();
})->throws(RuntimeException::class, 'composer outdated failed');

it('derives a why-not constraint from a target version', function () {
    expect(Auditor::constraintFor('v12.21.0'))->toBe('^12.0')
        ->and(Auditor::constraintFor('3.1.4'))->toBe('^3.0')
        ->and(Auditor::constraintFor('dev-main'))->toBe('dev-main');
});

it('builds a prompt context with the audit overview and blockers', function () {
    Process::fake(fn () => Process::result('blocked by pest-plugin-laravel'));

    $majors = [
        ['name' => 'pestphp/pest', 'version' => 'v4.7.8', 'latest' => 'v5.1.1', 'description' => ''],
    ];

    $context = (new Auditor(sys_get_temp_dir()))->promptContext('pestphp/pest', ['pestphp/pest'], $majors);

    expect($context)
        ->toContain('pestphp/pest: v4.7.8 installed, v5.1.1 available')
        ->toContain('composer why-not pestphp/pest ^5.0')
        ->toContain('blocked by pest-plugin-laravel')
        ->not->toContain('Scope: this session');
});

it('fences the scope when other packages are queued in the batch', function () {
    Process::fake(fn () => Process::result(''));

    $context = (new Auditor(sys_get_temp_dir()))->promptContext(
        'pestphp/pest',
        ['pestphp/pest', 'spatie/laravel-permission', 'league/flysystem'],
        [],
    );

    expect($context)
        ->toContain('this session upgrades ONLY pestphp/pest')
        ->toContain('spatie/laravel-permission, league/flysystem')
        ->toContain('did not appear in the major-upgrade audit');
});

it('passes package and constraint through to composer why-not', function () {
    Process::fake(fn () => Process::result('laravel/framework 11.44.0 requires php (^8.2)'));

    $output = (new Auditor(sys_get_temp_dir()))->whyNot('laravel/framework', '^12.0');

    expect($output)->toContain('requires php');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'composer', 'why-not', 'laravel/framework', '^12.0', '--no-interaction', '--no-ansi',
    ]);
});
