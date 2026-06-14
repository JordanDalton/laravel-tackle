<?php

use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Tools\ListRoutes;

function makeRoutesRequest(array $params = []): Request
{
    return new Request($params);
}

it('returns a string result', function () {
    $guard  = new PathGuard(base_path());
    $result = (new ListRoutes($guard))->handle(makeRoutesRequest());

    expect($result)->toBeString();
});

it('accepts a filter parameter without error', function () {
    $guard  = new PathGuard(base_path());
    $result = (new ListRoutes($guard))->handle(makeRoutesRequest(['filter' => 'nonexistent-route-xyz']));

    expect($result)->toBeString();
});

it('accepts a method parameter without error', function () {
    $guard  = new PathGuard(base_path());
    $result = (new ListRoutes($guard))->handle(makeRoutesRequest(['method' => 'GET']));

    expect($result)->toBeString();
});
