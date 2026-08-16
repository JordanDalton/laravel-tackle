<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function fakeUpgradeComposer(array $majors): void
{
    Process::fake(function (PendingProcess $process) use ($majors) {
        if (in_array('outdated', $process->command, strict: true)) {
            return Process::result(json_encode(['installed' => $majors]));
        }

        if (in_array('why-not', $process->command, strict: true)) {
            return Process::result('laravel/framework is locked to version v11.44.0');
        }

        return Process::result('');
    });
}

it('prints available majors and their blockers with --audit', function () {
    fakeUpgradeComposer([
        ['name' => 'laravel/framework', 'version' => 'v11.44.0', 'latest' => 'v12.21.0', 'description' => 'The Laravel Framework.'],
    ]);

    $this->artisan('ai:upgrade', ['--audit' => true])
        ->expectsOutputToContain('Major upgrades available')
        ->expectsOutputToContain('v11.44.0 → v12.21.0')
        ->expectsOutputToContain('locked to version')
        ->assertExitCode(0);
});

it('reports a clean state with --audit when no majors are available', function () {
    fakeUpgradeComposer([]);

    $this->artisan('ai:upgrade', ['--audit' => true])
        ->expectsOutputToContain('latest major version')
        ->assertExitCode(0);
});

it('fails --audit cleanly when composer cannot run', function () {
    Process::fake(fn () => Process::result(output: '', errorOutput: 'composer.json not found', exitCode: 1));

    $this->artisan('ai:upgrade', ['--audit' => true])
        ->expectsOutputToContain('composer outdated failed')
        ->assertExitCode(1);
});

it('syncs the audit to a GitHub issue with --issue', function () {
    config()->set('tackle.github.token', 'test-token');
    config()->set('tackle.github.repo', 'acme/app');

    fakeUpgradeComposer([
        ['name' => 'pestphp/pest', 'version' => 'v4.7.8', 'latest' => 'v5.1.1', 'description' => ''],
    ]);

    Http::fake([
        'api.github.com/repos/acme/app/issues?*' => Http::response([]),
        'api.github.com/repos/acme/app/issues' => Http::response(['number' => 3, 'html_url' => 'https://github.com/acme/app/issues/3'], 201),
    ]);

    // --issue implies --audit; no TTY needed, so it is scheduler-safe.
    $this->artisan('ai:upgrade', ['--issue' => true])
        ->expectsOutputToContain('Created issue #3')
        ->assertExitCode(0);
});

it('fails --issue cleanly when GitHub is not configured', function () {
    config()->set('tackle.github.token', null);
    config()->set('tackle.github.repo', null);

    Process::fake(function (PendingProcess $process) {
        if (in_array('outdated', $process->command, strict: true)) {
            return Process::result(json_encode(['installed' => []]));
        }

        return Process::result(output: '', errorOutput: 'not logged in', exitCode: 1);
    });

    $this->artisan('ai:upgrade', ['--audit' => true, '--issue' => true])
        ->expectsOutputToContain('GitHub is not configured')
        ->assertExitCode(1);
});

it('requires a TTY for an interactive session', function () {
    fakeUpgradeComposer([]);

    $this->artisan('ai:upgrade', ['packages' => ['laravel/framework']])
        ->expectsOutputToContain('requires an interactive TTY')
        ->assertExitCode(1);
});

it('accepts multiple packages for a batch', function () {
    fakeUpgradeComposer([]);

    // The TTY gate fires before any session starts — this asserts the
    // variadic signature parses, not the interactive flow itself.
    $this->artisan('ai:upgrade', ['packages' => ['pestphp/pest', 'spatie/laravel-permission']])
        ->expectsOutputToContain('requires an interactive TTY')
        ->assertExitCode(1);
});
