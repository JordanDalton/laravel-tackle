<?php

use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Tools\ListRoutes;

function listRoutes(array $params = []): string
{
    return (new ListRoutes(new PathGuard(base_path())))->handle(new Request($params));
}

/** Process::assertRan hands over the command as given — an array for these tools. */
function commandLine(object $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
}

function fakeRouteList(): void
{
    Process::fake(['*route:list*' => Process::result(json_encode([
        ['method' => 'GET|HEAD', 'uri' => 'api/ping', 'name' => 'api.ping', 'action' => 'App\Http\Controllers\PingController'],
        ['method' => 'POST', 'uri' => 'orders', 'name' => 'orders.store', 'action' => 'App\Http\Controllers\OrderController@store'],
        ['method' => 'GET|HEAD', 'uri' => 'health', 'name' => null, 'action' => 'Closure'],
    ]))]);
}

// The tests this file replaces asserted only that the result was a string.
// It was: a blank "Could not retrieve routes:" on every filtered call, because
// the tool passed route:list a --filter option that command does not have.

it('never passes route:list a --filter option', function () {
    fakeRouteList();

    listRoutes(['filter' => 'api', 'method' => 'get']);

    Process::assertRan(fn ($process) => str_contains(commandLine($process), 'route:list --json')
        && ! str_contains(commandLine($process), '--filter'));
});

it('boots a fresh process so routes written during the run are seen', function () {
    // Reading the booted router in-process reported "no routes matched" for a
    // route file the agent had written two steps earlier.
    fakeRouteList();

    listRoutes();

    Process::assertRan(fn ($process) => str_starts_with(commandLine($process), 'php artisan route:list'));
});

it('filters one term across uri, name and action', function () {
    fakeRouteList();

    expect(listRoutes(['filter' => 'api']))->toContain('api/ping')->not->toContain('orders')
        ->and(listRoutes(['filter' => 'orders.store']))->toContain('orders')
        ->and(listRoutes(['filter' => 'OrderController']))->toContain('orders')->not->toContain('api/ping');
});

it('filters by method, case-insensitively, and drops HEAD', function () {
    fakeRouteList();

    expect(listRoutes(['method' => 'post']))->toContain('orders')->not->toContain('api/ping')
        ->and(listRoutes(['filter' => 'health']))->not->toContain('HEAD');
});

it('says what did not match instead of failing blank', function () {
    fakeRouteList();

    expect(listRoutes(['filter' => 'nonexistent-route-xyz']))->toBe("No routes matched 'nonexistent-route-xyz'.");
});

it('reports what artisan printed to stdout when route:list fails', function () {
    Process::fake(['*' => Process::result(output: '  The "--bogus" option does not exist.', errorOutput: '', exitCode: 1)]);

    expect(listRoutes())->toContain('Could not retrieve routes:')->toContain('--bogus');
});
