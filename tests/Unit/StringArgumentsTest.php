<?php

use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\CommandGuard;
use Tackle\Support\PathGuard;
use Tackle\Tools\GitDiff;
use Tackle\Tools\RunLarastan;
use Tackle\Tools\RunTests;

// Request::string() returns a Stringable, which is !== '' and truthy even
// when empty. Every tool below shipped an empty argument because of it.

it('runs the whole suite when no filter is given, not --filter=""', function () {
    // --filter='' matched nothing: "No tests found", and the agent fell back
    // to `artisan test --compact` and dumped the entire suite into context.
    config()->set('tackle.artisan_allowlist', ['test']);
    Process::fake(['*' => Process::result('Tests: 3 passed')]);

    (new RunTests(new PathGuard(base_path()), app(CommandGuard::class)))->handle(new Request([]));

    Process::assertRan(fn ($process) => ! str_contains($process->command, '--filter'));
});

it('diffs against HEAD when no commit is given, not against "^"', function () {
    Process::fake(['*' => Process::result('')]);

    (new GitDiff(new PathGuard(base_path())))->handle(new Request(['stat' => true]));

    Process::assertRan(fn ($process) => str_ends_with(trim(commandLine($process)), 'HEAD')
        && ! str_contains(commandLine($process), '^'));
});

it('does not set a memory limit unless one was asked for', function () {
    $workspace = sys_get_temp_dir().'/tackle-larastan-'.bin2hex(random_bytes(4));
    mkdir($workspace.'/vendor/bin', 0755, true);
    touch($workspace.'/vendor/bin/phpstan');
    Process::fake(['*' => Process::result('[OK] No errors')]);

    (new RunLarastan(new PathGuard($workspace)))->handle(new Request([]));

    Process::assertRan(fn ($process) => ! str_contains($process->command, 'memory_limit'));

    shell_exec('rm -rf '.escapeshellarg($workspace));
});
