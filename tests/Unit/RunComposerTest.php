<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\PathGuard;
use Tackle\Tools\RunComposer;

function makeRunComposerTool(?InteractionPolicy $interaction = null): RunComposer
{
    return new RunComposer(app(PathGuard::class), $interaction ?? nonInteractivePolicy());
}

function nonInteractivePolicy(bool $interactive = false, bool $answer = false): InteractionPolicy
{
    return new class($interactive, $answer) implements InteractionPolicy
    {
        public function __construct(private bool $interactive, private bool $answer) {}

        public function confirm(string $label, bool $default = true, ?string $hint = null): bool
        {
            return $this->answer;
        }

        public function choose(string $question, array $options, bool $multiple = false): string
        {
            return '';
        }

        public function isInteractive(): bool
        {
            return $this->interactive;
        }

        public function deniedCount(): int
        {
            return 0;
        }
    };
}

it('refuses subcommands outside the allowlist', function () {
    Process::fake();

    $result = makeRunComposerTool()->handle(new Request(['subcommand' => 'config']));

    expect($result)->toContain("'config' is not permitted");
    Process::assertNothingRan();
});

it('refuses run-script outright', function () {
    Process::fake();

    $result = makeRunComposerTool()->handle(new Request(['subcommand' => 'run-script']));

    expect($result)->toContain('not permitted');
    Process::assertNothingRan();
});

it('refuses flags that escape the workspace', function (string $flag) {
    Process::fake();

    $result = makeRunComposerTool()->handle(new Request([
        'subcommand' => 'show',
        'args' => $flag,
    ]));

    expect($result)->toContain('not permitted');
    Process::assertNothingRan();
})->with(['--working-dir=/etc', '--global', '-g', '-d']);

it('runs read-only subcommands without --no-scripts', function () {
    Process::fake();

    makeRunComposerTool()->handle(new Request([
        'subcommand' => 'why-not',
        'args' => 'laravel/framework ^12.0',
    ]));

    Process::assertRan(function (PendingProcess $process) {
        return $process->command === ['composer', 'why-not', 'laravel/framework', '^12.0', '--no-interaction', '--no-ansi'];
    });
});

it('forces --no-scripts and --no-interaction on mutations', function () {
    Process::fake();

    $result = makeRunComposerTool()->handle(new Request([
        'subcommand' => 'update',
        'args' => 'laravel/framework --with-all-dependencies',
    ]));

    Process::assertRan(function (PendingProcess $process) {
        return $process->command === [
            'composer', 'update', 'laravel/framework', '--with-all-dependencies',
            '--no-interaction', '--no-ansi', '--no-scripts',
        ];
    });

    expect($result)->toContain('Lifecycle scripts were suppressed');
});

it('keeps scripts suppressed when allow_scripts is requested with nobody to approve', function () {
    Process::fake();

    $result = makeRunComposerTool(nonInteractivePolicy(interactive: false))->handle(new Request([
        'subcommand' => 'install',
        'allow_scripts' => true,
    ]));

    Process::assertRan(fn (PendingProcess $process) => in_array('--no-scripts', $process->command, strict: true));
    expect($result)->toContain('Lifecycle scripts were suppressed');
});

it('keeps scripts suppressed when the user declines', function () {
    Process::fake();

    makeRunComposerTool(nonInteractivePolicy(interactive: true, answer: false))->handle(new Request([
        'subcommand' => 'install',
        'allow_scripts' => true,
    ]));

    Process::assertRan(fn (PendingProcess $process) => in_array('--no-scripts', $process->command, strict: true));
});

it('runs scripts only after the user approves', function () {
    Process::fake();

    $result = makeRunComposerTool(nonInteractivePolicy(interactive: true, answer: true))->handle(new Request([
        'subcommand' => 'install',
        'allow_scripts' => true,
    ]));

    Process::assertRan(fn (PendingProcess $process) => ! in_array('--no-scripts', $process->command, strict: true));
    expect($result)->not->toContain('Lifecycle scripts were suppressed');
});

it('reports the exit code when composer fails', function () {
    Process::fake(fn () => Process::result(output: '', errorOutput: 'Your requirements could not be resolved to an installable set of packages.', exitCode: 2));

    $result = makeRunComposerTool()->handle(new Request([
        'subcommand' => 'update',
        'args' => 'laravel/framework',
    ]));

    expect($result)
        ->toContain('Command failed (exit 2)')
        ->toContain('could not be resolved');
});
