<?php

use Illuminate\Support\Facades\Artisan;
use Tackle\Mcp\McpServer;
use Tackle\Tools\AskUser;
use Tackle\Tools\ConfirmAction;

it('registers the tackle:mcp command', function () {
    expect(Artisan::all())->toHaveKey('tackle:mcp');
});

it('exposes only non-interactive tools in the default config', function () {
    $tools = config('tackle.mcp.tools');

    expect($tools)->not->toBeEmpty()
        ->and($tools)->not->toContain(AskUser::class)
        ->and($tools)->not->toContain(ConfirmAction::class);
});

it('lists every default tool with a valid schema', function () {
    $tools = array_map(
        fn (string $class) => app($class),
        config('tackle.mcp.tools'),
    );

    $server = new McpServer($tools);

    $response = $server->handleMessage([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
    ]);

    $listed = $response['result']['tools'];

    expect($listed)->toHaveCount(count(config('tackle.mcp.tools')));

    foreach ($listed as $tool) {
        expect($tool['name'])->not->toBeEmpty()
            ->and($tool['description'])->not->toBeEmpty()
            ->and($tool['inputSchema']['type'])->toBe('object');

        // The whole tools/list payload must be JSON-encodable for the wire.
        expect(json_encode($tool))->toBeString();
    }
});
