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

// ---------------------------------------------------------------------------
// Creating and removing commands from inside a session
// ---------------------------------------------------------------------------

it('saves a command and makes it immediately available', function () {
    $commands = app(CustomCommands::class);

    $path = $commands->save('audit', 'Audit the queue configuration.');

    // No restart, no cache to bust: all() globs the directory every call.
    // (The path is compared loosely: PathGuard resolves symlinks in the
    // workspace root, the test helper does not.)
    expect($path)->toEndWith('/.tackle/commands/audit.md')
        ->and(is_file($path))->toBeTrue()
        ->and($commands->has('audit'))->toBeTrue()
        ->and($commands->render('audit'))->toBe('Audit the queue configuration.');
});

it('creates the commands directory on first save', function () {
    foreach (glob(commandsDir().'/*.md') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir(commandsDir());

    expect(is_dir(commandsDir()))->toBeFalse();

    app(CustomCommands::class)->save('first', 'Do the thing.');

    expect(is_dir(commandsDir()))->toBeTrue();
});

it('does not hide saved commands from git', function () {
    app(CustomCommands::class)->save('shared', 'A prompt the team shares.');

    // The opposite of Tackle's state directories: these are meant to be
    // committed and reviewed, so nothing gitignores them.
    expect(is_file(commandsDir().'/.gitignore'))->toBeFalse();
});

it('overwrites an existing command in place', function () {
    $commands = app(CustomCommands::class);

    $commands->save('audit', 'First version.');
    $commands->save('audit', 'Second version.');

    expect($commands->render('audit'))->toBe('Second version.')
        ->and($commands->all())->toHaveCount(1);
});

it('deletes a command, and reports when there was nothing to delete', function () {
    $commands = app(CustomCommands::class);
    $commands->save('temporary', 'Throwaway.');

    expect($commands->delete('temporary'))->toBeTrue()
        ->and($commands->has('temporary'))->toBeFalse()
        ->and($commands->delete('temporary'))->toBeFalse();
});

it('accepts only names that survive being both a filename and a slash command', function () {
    expect(CustomCommands::validName('deploy-check'))->toBeTrue()
        ->and(CustomCommands::validName('audit_queue'))->toBeTrue()
        ->and(CustomCommands::validName('../escape'))->toBeFalse()
        ->and(CustomCommands::validName('with space'))->toBeFalse()
        ->and(CustomCommands::validName(''))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Which prompt /save keeps
// ---------------------------------------------------------------------------

it('keeps the last real prompt, skipping the slash commands after it', function () {
    $history = ['find the N+1 in the orders page', '/compact', '/save audit'];

    expect(CustomCommands::lastPrompt($history))->toBe('find the N+1 in the orders page');
});

it('treats a /plan task as the prompt', function () {
    // /plan <task> is a prompt wearing a slash command — and usually the one
    // worth keeping, since you thought it was worth planning.
    expect(CustomCommands::lastPrompt(['/plan add a slug field to posts', '/save slugs']))
        ->toBe('add a slug field to posts');
});

it('does not keep a custom command invocation', function () {
    // Saving /audit would store a pointer to another command, not a prompt.
    expect(CustomCommands::lastPrompt(['review the queue', '/audit staging', '/save x']))
        ->toBe('review the queue');
});

it('has nothing to keep before the first prompt', function () {
    expect(CustomCommands::lastPrompt([]))->toBeNull()
        ->and(CustomCommands::lastPrompt(['/help', '/save x']))->toBeNull()
        ->and(CustomCommands::lastPrompt(['/plan', '/save x']))->toBeNull();
});
