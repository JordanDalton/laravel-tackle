<?php

use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Tools\GitDiff;

function makeGitDiffRequest(array $params = []): Request
{
    return new Request($params);
}

it('returns no differences when nothing has changed', function () {
    $guard  = new PathGuard(base_path());
    $result = (new GitDiff($guard))->handle(makeGitDiffRequest());

    expect($result)->toBeString();
});

it('handles staged flag', function () {
    $guard  = new PathGuard(base_path());
    $result = (new GitDiff($guard))->handle(makeGitDiffRequest(['staged' => true]));

    expect($result)->toBeString();
});

it('handles stat flag', function () {
    $guard  = new PathGuard(base_path());
    $result = (new GitDiff($guard))->handle(makeGitDiffRequest(['stat' => true]));

    expect($result)->toBeString();
});
