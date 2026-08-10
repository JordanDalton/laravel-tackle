<?php

use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\CommandGuard;
use Tackle\Support\PathGuard;
use Tackle\Support\PermissionStore;
use Tackle\Tools\RunShell;

function permissionsPath(): string
{
    return config('tackle.workspace').'/.tackle/permissions.json';
}

beforeEach(function () {
    @unlink(permissionsPath());
});

it('starts empty and persists allowed commands', function () {
    $store = app(PermissionStore::class);

    expect($store->allows('npm run build'))->toBeFalse();

    $store->allow('npm run build');

    expect($store->allows('npm run build'))->toBeTrue()
        ->and(app(PermissionStore::class)->allows('npm run build'))->toBeTrue(); // fresh instance reads the file
});

it('matches exactly — no prefix creep', function () {
    $store = app(PermissionStore::class);
    $store->allow('git status');

    expect($store->allows('git status'))->toBeTrue()
        ->and($store->allows('git push --force'))->toBeFalse()
        ->and($store->allows('git status && rm -rf /'))->toBeFalse();
});

it('does not store duplicates or empty commands', function () {
    $store = app(PermissionStore::class);
    $store->allow('npm test');
    $store->allow('npm test');
    $store->allow('  ');

    expect($store->all())->toBe(['npm test']);
});

it('RunShell auto-runs always-allowed commands without prompting', function () {
    config()->set('tackle.shell', 'approve');
    Process::fake(['*' => Process::result("built\n")]);

    app(PermissionStore::class)->allow('npm run build');

    $interaction = Mockery::mock(InteractionPolicy::class);
    $interaction->shouldNotReceive('confirm');

    $tool = new RunShell(app(PathGuard::class), app(CommandGuard::class), $interaction);
    $result = $tool->handle(new Request(['command' => 'npm run build']));

    expect($result)->toContain('built');
});

it('RunShell persists the command when the user picks always', function () {
    config()->set('tackle.shell', 'approve');
    Process::fake(['*' => Process::result("ok\n")]);

    $interaction = new class implements InteractionPolicy
    {
        public function confirm(string $label, bool $default = true, ?string $hint = null): bool
        {
            return false;
        }

        public function confirmWithAlways(string $label, ?string $hint = null): string
        {
            return 'always';
        }

        public function choose(string $question, array $options, bool $multiple = false): string
        {
            return '';
        }

        public function isInteractive(): bool
        {
            return true;
        }

        public function deniedCount(): int
        {
            return 0;
        }
    };

    $tool = new RunShell(app(PathGuard::class), app(CommandGuard::class), $interaction);
    $result = $tool->handle(new Request(['command' => 'composer test']));

    expect($result)->toContain('ok')
        ->and(app(PermissionStore::class)->allows('composer test'))->toBeTrue();
});

it('RunShell still denies via plain confirm policies', function () {
    config()->set('tackle.shell', 'approve');

    $interaction = Mockery::mock(InteractionPolicy::class);
    $interaction->shouldReceive('confirm')->andReturn(false);
    $interaction->shouldReceive('isInteractive')->andReturn(true);

    $tool = new RunShell(app(PathGuard::class), app(CommandGuard::class), $interaction);
    $result = $tool->handle(new Request(['command' => 'rm -rf /tmp/x']));

    expect($result)->toContain('denied');
});
