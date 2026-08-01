<?php

use Laravel\Ai\Tools\Request;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\AutoApproveInteraction;
use Tackle\Support\CommandGuard;
use Tackle\Support\DenyInteraction;
use Tackle\Support\PathGuard;
use Tackle\Support\TerminalInteraction;
use Tackle\Tools\AskUser;
use Tackle\Tools\ConfirmAction;
use Tackle\Tools\RunArtisan;
use Tackle\Tools\RunShell;

it('binds the terminal policy by default so interactive commands are unchanged', function () {
    expect(app(InteractionPolicy::class))->toBeInstanceOf(TerminalInteraction::class);
});

it('denies every confirmation and counts them', function () {
    $policy = new DenyInteraction;

    expect($policy->confirm('Proceed?', default: true))->toBeFalse()
        ->and($policy->confirm('Really?', default: true))->toBeFalse()
        ->and($policy->deniedCount())->toBe(2)
        ->and($policy->isInteractive())->toBeFalse();
});

it('approves every confirmation under auto-approve without counting denials', function () {
    $policy = new AutoApproveInteraction;

    expect($policy->confirm('Proceed?', default: false))->toBeTrue()
        ->and($policy->deniedCount())->toBe(0)
        ->and($policy->isInteractive())->toBeFalse();
});

it('directs the agent to decide for itself rather than refusing a selection', function () {
    foreach ([new DenyInteraction, new AutoApproveInteraction] as $policy) {
        $result = $policy->choose('Which approach?', ['Repository', 'Service class']);

        expect($result)->toContain('No interactive user is available')
            ->and($result)->toContain('Which approach?')
            ->and($result)->toContain('Repository')
            ->and($result)->toContain('Service class')
            ->and($result)->toContain('Select the option you judge best');
    }
});

it('AskUser returns the decide-for-yourself directive with no terminal', function () {
    $result = (new AskUser(new DenyInteraction))->handle(new Request([
        'question' => 'Which approach?',
        'options' => ['Repository', 'Service class'],
    ]));

    expect($result)->toContain('No interactive user is available');
});

it('AskUser still reports when no options were given', function () {
    $result = (new AskUser(new DenyInteraction))->handle(new Request([
        'question' => 'Which approach?',
        'options' => [],
    ]));

    expect($result)->toBe('No options were provided.');
});

it('ConfirmAction cancels with no terminal and confirms under --yes', function () {
    expect((new ConfirmAction(new DenyInteraction))->handle(new Request(['action' => 'Drop the table?'])))
        ->toBe('cancelled')
        ->and((new ConfirmAction(new AutoApproveInteraction))->handle(new Request(['action' => 'Drop the table?'])))
        ->toBe('confirmed');
});

it('RunShell refuses approve-mode commands with no terminal and explains why', function () {
    config(['tackle.shell' => 'approve']);

    $result = (new RunShell(app(PathGuard::class), new CommandGuard, new DenyInteraction))
        ->handle(new Request(['command' => 'echo hello']));

    expect($result)->toContain('no interactive user is available')
        ->and($result)->toContain('shell=allowlist')
        ->and($result)->not->toContain('User denied');
});

it('RunShell executes approve-mode commands under --yes', function () {
    config(['tackle.shell' => 'approve']);

    $result = (new RunShell(app(PathGuard::class), new CommandGuard, new AutoApproveInteraction))
        ->handle(new Request(['command' => 'echo approved-by-flag']));

    expect($result)->toContain('approved-by-flag');
});

it('RunArtisan cancels destructive commands with no terminal', function () {
    config([
        'tackle.artisan_destructive' => ['migrate:fresh'],
        'tackle.artisan_allowlist' => ['migrate:*'],
    ]);

    $result = (new RunArtisan(app(PathGuard::class), new CommandGuard, new DenyInteraction))
        ->handle(new Request(['command' => 'migrate:fresh']));

    expect($result)->toBe('Cancelled by user.');
});
