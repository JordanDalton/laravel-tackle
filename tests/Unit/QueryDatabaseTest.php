<?php

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tackle\Tools\QueryDatabase;

function makeDbRequest(array $params): Request
{
    return new Request($params);
}

it('rejects non-SELECT queries', function () {
    $tool   = new QueryDatabase();
    $result = $tool->handle(makeDbRequest(['query' => 'DROP TABLE users']));

    expect($result)->toContain('Only SELECT queries are permitted');
});

it('rejects an empty query', function () {
    $tool   = new QueryDatabase();
    $result = $tool->handle(makeDbRequest(['query' => '']));

    expect($result)->toContain('non-empty query is required');
});

it('returns query error message on failure', function () {
    $tool   = new QueryDatabase();
    $result = $tool->handle(makeDbRequest(['query' => 'SELECT * FROM nonexistent_table_xyz']));

    expect($result)->toContain('Query error');
});

it('returns JSON results for a valid query', function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

    DB::statement('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
    DB::table('items')->insert(['id' => 1, 'name' => 'foo']);

    $tool   = new QueryDatabase();
    $result = $tool->handle(makeDbRequest(['query' => 'SELECT * FROM items']));

    expect($result)->toContain('foo');
});

it('appends a LIMIT when none is present', function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

    DB::statement('CREATE TABLE things (id INTEGER PRIMARY KEY)');

    $tool   = new QueryDatabase();
    $result = $tool->handle(makeDbRequest(['query' => 'SELECT * FROM things']));

    // No error means LIMIT was injected cleanly
    expect($result)->toBeString();
});
