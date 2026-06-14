<?php

use Laravel\Ai\Tools\Request;
use Tackle\Tools\ReadLog;

beforeEach(function () {
    @mkdir(storage_path('logs'), 0755, true);
});

afterEach(function () {
    @unlink(storage_path('logs/laravel.log'));
});

function makeLogRequest(array $params = []): Request
{
    return new Request($params);
}

it('returns a message when the log file does not exist', function () {
    @unlink(storage_path('logs/laravel.log'));

    $result = (new ReadLog())->handle(makeLogRequest());

    expect($result)->toContain('not found');
});

it('returns the last N lines of the log', function () {
    $lines = array_map(fn ($i) => "[2026-01-01] local.INFO: Line {$i}", range(1, 100));
    file_put_contents(storage_path('logs/laravel.log'), implode("\n", $lines));

    $result = (new ReadLog())->handle(makeLogRequest(['lines' => 10]));

    expect($result)->toContain('Line 100')
        ->not->toContain('Line 1 ');
});

it('filters lines by a search string', function () {
    file_put_contents(storage_path('logs/laravel.log'), implode("\n", [
        '[2026-01-01] local.ERROR: Something broke',
        '[2026-01-01] local.INFO: All good',
        '[2026-01-01] local.ERROR: Another error',
    ]));

    $result = (new ReadLog())->handle(makeLogRequest(['filter' => 'ERROR']));

    expect($result)->toContain('Something broke')
        ->toContain('Another error')
        ->not->toContain('All good');
});

it('returns a message when filter matches nothing', function () {
    file_put_contents(storage_path('logs/laravel.log'), '[2026-01-01] local.INFO: fine');

    $result = (new ReadLog())->handle(makeLogRequest(['filter' => 'CRITICAL']));

    expect($result)->toContain('No log lines matching');
});
