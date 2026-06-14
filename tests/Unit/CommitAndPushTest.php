<?php

use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Tools\CommitAndPush;

function makeCommitAndPushTool(): CommitAndPush
{
    return new CommitAndPush(app(PathGuard::class));
}

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

it('stages, commits, and pushes when there are changes', function () {
    Process::fake([
        'git status --porcelain' => Process::result(' M app/Foo.php'),
        'git add -A'             => Process::result(''),
        'git commit*'            => Process::result('[main abc1234] Add comment'),
        'git push'               => Process::result(''),
    ]);

    $result = makeCommitAndPushTool()->handle(new Request(['message' => 'Add comment above change']));

    expect($result)->toBe('Changes committed and pushed to the existing PR branch.');
    Process::assertRan('git add -A');
    Process::assertRan('git push');
});

it('checks out the branch before committing when branch is provided', function () {
    Process::fake([
        'git status --porcelain'   => Process::result(' M app/Foo.php'),
        'git checkout*'            => Process::result(''),
        'git add -A'               => Process::result(''),
        'git commit*'              => Process::result('[tackle/pr-6 abc1234] Add comment'),
        'git push origin*'         => Process::result(''),
    ]);

    $result = makeCommitAndPushTool()->handle(new Request([
        'message' => 'Add comment above change',
        'branch'  => 'tackle/issue-6-return-dalton',
    ]));

    expect($result)->toBe('Changes committed and pushed to the existing PR branch.');
    Process::assertRan('git checkout \'tackle/issue-6-return-dalton\'');
    Process::assertRan('git push origin \'tackle/issue-6-return-dalton\'');
});

it('returns error when branch checkout fails', function () {
    Process::fake([
        'git status --porcelain' => Process::result(' M app/Foo.php'),
        'git checkout*'          => Process::result('', 'error: pathspec not found', 1),
    ]);

    $result = makeCommitAndPushTool()->handle(new Request([
        'message' => 'Fix',
        'branch'  => 'tackle/nonexistent',
    ]));

    expect($result)->toStartWith('Failed to switch to branch:');
    Process::assertNotRan('git add -A');
});

it('returns error when commit fails', function () {
    Process::fake([
        'git status --porcelain' => Process::result(' M app/Foo.php'),
        'git add -A'             => Process::result(''),
        'git commit*'            => Process::result('', 'nothing to commit', 1),
    ]);

    $result = makeCommitAndPushTool()->handle(new Request(['message' => 'Fix']));

    expect($result)->toStartWith('Commit failed:');
    Process::assertNotRan('git push');
});

it('returns error when push fails', function () {
    Process::fake([
        'git status --porcelain' => Process::result(' M app/Foo.php'),
        'git add -A'             => Process::result(''),
        'git commit*'            => Process::result('[main abc1234] Fix'),
        'git push'               => Process::result('', 'error: remote rejected', 1),
    ]);

    $result = makeCommitAndPushTool()->handle(new Request(['message' => 'Fix']));

    expect($result)->toStartWith('Push failed:');
});
