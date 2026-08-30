<?php

use Illuminate\Support\Facades\Route;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Tools\ListRoutes;

function listRoutes(array $params = []): string
{
    return (new ListRoutes(new PathGuard(base_path())))->handle(new Request($params));
}

// The previous tests asserted only that the result was a string. It was — a
// blank "Could not retrieve routes:" on every filtered call, because the tool
// passed `route:list` a --filter option that command does not have.

beforeEach(function () {
    Route::get('/api/ping', fn () => 'pong')->name('api.ping');
    Route::post('/orders', 'App\Http\Controllers\OrderController@store')->name('orders.store');
    Route::get('/health', fn () => 'ok');
});

it('lists routes with method, uri, name and action', function () {
    expect(listRoutes())
        ->toContain('GET')->toContain('api/ping')->toContain('api.ping')
        ->toContain('POST')->toContain('orders')->toContain('OrderController@store');
});

it('filters by uri', function () {
    expect(listRoutes(['filter' => 'api']))
        ->toContain('api/ping')
        ->not->toContain('orders');
});

it('filters by name and by action', function () {
    expect(listRoutes(['filter' => 'orders.store']))->toContain('orders')
        ->and(listRoutes(['filter' => 'OrderController']))->toContain('orders')->not->toContain('api/ping');
});

it('filters by method, case-insensitively', function () {
    expect(listRoutes(['method' => 'post']))
        ->toContain('orders')
        ->not->toContain('api/ping');
});

it('says what did not match instead of failing blank', function () {
    expect(listRoutes(['filter' => 'nonexistent-route-xyz']))->toBe("No routes matched 'nonexistent-route-xyz'.");
});

it('does not list HEAD alongside GET', function () {
    expect(listRoutes(['filter' => 'health']))->not->toContain('HEAD');
});
