<?php

use Illuminate\Process\PendingProcess;
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
