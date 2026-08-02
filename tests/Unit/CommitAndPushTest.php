<?php

use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Tools\CommitAndPush;

function makeCommitAndPushTool(): CommitAndPush
{
    return new CommitAndPush(app(PathGuard::class));
}

beforeEach(fn () => CommitAndPush::$confirmOverride = null);
afterEach(fn () => CommitAndPush::$confirmOverride = null);

it('returns error when message is missing', function () {
    $result = makeCommitAndPushTool()->handle(new Request([]));

    expect($result)->toBe('message is required.');
});

it('returns early when there are no changes', function () {
    Process::fake([
        'git status --porcelain' => Process::result(''),
    ]);

    $result = makeCommitAndPushTool()->handle(new Request(['message' => 'Update readme']));

    expect($result)->toBe('No changes to commit.');
    Process::assertRan('git status --porcelain');
    Process::assertNotRan('git add -A');
});

it('stages, commits, and pushes when user confirms', function () {
    CommitAndPush::$confirmOverride = true;

    Process::fake([
        'git status --porcelain' => Process::result(' M app/Foo.php'),
        'git diff*' => Process::result('diff --git a/app/Foo.php'),
        'git add -A' => Process::result(''),
        'git commit*' => Process::result('[main abc1234] Add comment'),
        'git push' => Process::result(''),
    ]);

    $result = makeCommitAndPushTool()->handle(new Request(['message' => 'Add comment above change']));

    expect($result)->toBe('Changes committed and pushed to the existing PR branch.');
    Process::assertRan('git add -A');
    Process::assertRan('git push');
});

it('returns cancelled when user declines the diff preview', function () {
    CommitAndPush::$confirmOverride = false;

    Process::fake([
        'git status --porcelain' => Process::result(' M app/Foo.php'),
        'git diff*' => Process::result('diff --git a/app/Foo.php'),
    ]);

    $result = makeCommitAndPushTool()->handle(new Request(['message' => 'Fix']));

    expect($result)->toBe('Cancelled by user.');
    Process::assertNotRan('git add -A');
    Process::assertNotRan('git commit*');
});

it('fetches and resets to remote tip before committing when branch is provided', function () {
    CommitAndPush::$confirmOverride = true;

    Process::fake([
        'git status --porcelain' => Process::result(' M app/Foo.php'),
        'git fetch origin*' => Process::result(''),
        'git reset*' => Process::result(''),
        'git diff*' => Process::result('diff --git a/app/Foo.php'),
        'git add -A' => Process::result(''),
        'git commit*' => Process::result('[detached HEAD abc1234] Add comment'),
        'git push origin*' => Process::result(''),
    ]);

    $result = makeCommitAndPushTool()->handle(new Request([
        'message' => 'Add comment above change',
        'branch' => 'tackle/issue-6-return-dalton',
    ]));

    expect($result)->toBe('Changes committed and pushed to the existing PR branch.');
    Process::assertNotRan('git checkout*');
    Process::assertRan("git fetch origin 'tackle/issue-6-return-dalton'");
    Process::assertRan('git reset --mixed FETCH_HEAD');
    Process::assertRan("git push origin HEAD:'tackle/issue-6-return-dalton'");
});

it('returns error when commit fails', function () {
    CommitAndPush::$confirmOverride = true;

    Process::fake([
        'git status --porcelain' => Process::result(' M app/Foo.php'),
        'git diff*' => Process::result('diff --git a/app/Foo.php'),
        'git add -A' => Process::result(''),
        'git commit*' => Process::result('', 'nothing to commit', 1),
    ]);

    $result = makeCommitAndPushTool()->handle(new Request(['message' => 'Fix']));

    expect($result)->toStartWith('Commit failed:');
    Process::assertNotRan('git push');
});

it('returns error when push fails', function () {
    CommitAndPush::$confirmOverride = true;

    Process::fake([
        'git status --porcelain' => Process::result(' M app/Foo.php'),
        'git diff*' => Process::result('diff --git a/app/Foo.php'),
        'git add -A' => Process::result(''),
        'git commit*' => Process::result('[main abc1234] Fix'),
        'git push' => Process::result('', 'error: remote rejected', 1),
    ]);

    $result = makeCommitAndPushTool()->handle(new Request(['message' => 'Fix']));

    expect($result)->toStartWith('Push failed:');
});
