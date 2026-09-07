<?php

use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Tools\CommitAndPush;

function makeCommitAndPushTool(): CommitAndPush
{
    return new CommitAndPush(app(PathGuard::class));
}

function commitProcessCommand(mixed $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
}

/** @param  array<string, mixed>  $results */
function fakeCommitProcesses(array $results = []): void
{
    Process::fake(function ($process) use ($results) {
        $command = commitProcessCommand($process);

        foreach (['status', 'fetch', 'reset', 'diff', 'add', 'identity', 'commit', 'push'] as $operation) {
            $matches = match ($operation) {
                'status' => str_contains($command, 'status --porcelain'),
                'fetch' => str_contains($command, 'git fetch origin'),
                'reset' => str_contains($command, 'git reset --mixed'),
                'diff' => str_contains($command, ' diff HEAD'),
                'add' => str_contains($command, ' add --'),
                'identity' => str_contains($command, 'git var GIT_AUTHOR_IDENT'),
                'commit' => str_contains($command, ' commit --only'),
                'push' => str_contains($command, 'git push'),
            };

            if ($matches) {
                return $results[$operation] ?? match ($operation) {
                    'status' => Process::result(' M app/Foo.php'),
                    'identity' => Process::result('Tackle Agent <tackle@example.com>'),
                    default => Process::result(''),
                };
            }
        }

        return Process::result('');
    });
}

function commitRequest(array $attributes = []): Request
{
    return new Request([
        'message' => 'Fix the thing',
        'files' => ['app/Foo.php'],
        ...$attributes,
    ]);
}

beforeEach(fn () => CommitAndPush::$confirmOverride = null);
afterEach(fn () => CommitAndPush::$confirmOverride = null);

it('returns error when message is missing', function () {
    $result = makeCommitAndPushTool()->handle(new Request([]));

    expect($result)->toBe('message is required.');
});

it('requires an explicit list of files', function () {
    $result = makeCommitAndPushTool()->handle(new Request(['message' => 'Update readme']));

    expect($result)->toContain('files is required');
});

it('returns early when selected files have no changes', function () {
    fakeCommitProcesses(['status' => Process::result('')]);

    $result = makeCommitAndPushTool()->handle(commitRequest());

    expect($result)->toBe('None of the selected files has changes to commit.');
    Process::assertRan(fn ($process) => str_contains(commitProcessCommand($process), 'status --porcelain'));
    Process::assertNotRan(fn ($process) => str_contains(commitProcessCommand($process), ' add --'));
});

it('stages, commits only selected files, and pushes when user confirms', function () {
    CommitAndPush::$confirmOverride = true;
    fakeCommitProcesses();

    $result = makeCommitAndPushTool()->handle(commitRequest(['message' => 'Add comment above change']));

    expect($result)->toBe('Changes committed and pushed to the existing PR branch.');
    Process::assertRan(fn ($process) => str_contains(commitProcessCommand($process), 'add -- app/Foo.php'));
    Process::assertRan(fn ($process) => str_contains(commitProcessCommand($process), 'commit --only -m Add comment above change -- app/Foo.php'));
    Process::assertNotRan(fn ($process) => commitProcessCommand($process) === 'git add -A');
    Process::assertRan(fn ($process) => commitProcessCommand($process) === 'git push');
});

it('returns cancelled when user declines the diff preview', function () {
    CommitAndPush::$confirmOverride = false;
    fakeCommitProcesses();

    $result = makeCommitAndPushTool()->handle(commitRequest());

    expect($result)->toBe('Cancelled by user.');
    Process::assertNotRan(fn ($process) => str_contains(commitProcessCommand($process), ' add --'));
    Process::assertNotRan(fn ($process) => str_contains(commitProcessCommand($process), ' commit --only'));
});

it('fetches and resets to remote tip before committing when branch is provided', function () {
    CommitAndPush::$confirmOverride = true;
    fakeCommitProcesses();

    $result = makeCommitAndPushTool()->handle(commitRequest([
        'message' => 'Add comment above change',
        'branch' => 'tackle/issue-6-return-dalton',
    ]));

    expect($result)->toBe('Changes committed and pushed to the existing PR branch.');
    Process::assertNotRan(fn ($process) => str_contains(commitProcessCommand($process), 'git checkout'));
    Process::assertRan(fn ($process) => commitProcessCommand($process) === "git fetch origin 'tackle/issue-6-return-dalton'");
    Process::assertRan(fn ($process) => commitProcessCommand($process) === 'git reset --mixed FETCH_HEAD');
    Process::assertRan(fn ($process) => commitProcessCommand($process) === "git push origin HEAD:'tackle/issue-6-return-dalton'");
});

it('uses a safe bot identity when git has no configured author', function () {
    CommitAndPush::$confirmOverride = true;
    fakeCommitProcesses(['identity' => Process::result('', 'Author identity unknown', 1)]);

    $result = makeCommitAndPushTool()->handle(commitRequest());

    expect($result)->toBe('Changes committed and pushed to the existing PR branch.');
    Process::assertRan(fn ($process) => str_contains(
        commitProcessCommand($process),
        'git --literal-pathspecs -c user.name=Tackle Agent -c user.email=tackle-agent@users.noreply.github.com commit --only',
    ));
});

it('returns error when commit fails', function () {
    CommitAndPush::$confirmOverride = true;
    fakeCommitProcesses(['commit' => Process::result('', 'nothing to commit', 1)]);

    $result = makeCommitAndPushTool()->handle(commitRequest());

    expect($result)->toStartWith('Commit failed:');
    Process::assertNotRan(fn ($process) => str_contains(commitProcessCommand($process), 'git push'));
});

it('returns error when push fails', function () {
    CommitAndPush::$confirmOverride = true;
    fakeCommitProcesses(['push' => Process::result('', 'error: remote rejected', 1)]);

    $result = makeCommitAndPushTool()->handle(commitRequest());

    expect($result)->toStartWith('Push failed:');
});
