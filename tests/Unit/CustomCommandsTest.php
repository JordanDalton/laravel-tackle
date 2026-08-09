<?php

use Tackle\Support\CustomCommands;

function commandsDir(): string
{
    return config('tackle.workspace').'/.tackle/commands';
}

beforeEach(function () {
    @mkdir(commandsDir(), 0755, true);

    foreach (glob(commandsDir().'/*.md') ?: [] as $file) {
        @unlink($file);
    }
});

it('parses slash commands into name and arguments', function () {
    expect(CustomCommands::parse('/deploy-check staging now'))->toBe(['deploy-check', 'staging now'])
        ->and(CustomCommands::parse('/plan add a slug field'))->toBe(['plan', 'add a slug field'])
        ->and(CustomCommands::parse('/compact'))->toBe(['compact', ''])
        ->and(CustomCommands::parse('  /help  '))->toBe(['help', '']);
});

it('does not parse non-commands', function () {
    expect(CustomCommands::parse('fix the bug'))->toBeNull()
        ->and(CustomCommands::parse('/'))->toBeNull()
        ->and(CustomCommands::parse('/not a command !'))->toBe(['not', 'a command !'])
        ->and(CustomCommands::parse('a/b path'))->toBeNull();
});

it('lists and renders project commands with $ARGUMENTS substitution', function () {
    file_put_contents(commandsDir().'/deploy-check.md', 'Check the deploy of $ARGUMENTS and report.');

    $commands = app(CustomCommands::class);

    expect($commands->all())->toHaveKey('deploy-check')
        ->and($commands->has('deploy-check'))->toBeTrue()
        ->and($commands->render('deploy-check', 'staging'))->toBe('Check the deploy of staging and report.');
});

it('appends arguments when the template has no placeholder', function () {
    file_put_contents(commandsDir().'/review-module.md', 'Review the module for issues.');

    $commands = app(CustomCommands::class);

    expect($commands->render('review-module', 'billing'))->toBe("Review the module for issues.\n\nbilling")
        ->and($commands->render('review-module'))->toBe('Review the module for issues.');
});

it('returns null for unknown commands', function () {
    expect(app(CustomCommands::class)->render('nope'))->toBeNull();
});
