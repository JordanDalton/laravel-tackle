<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Tackle\Mcp\McpServer;

class FakeEchoTool implements Tool
{
    public function description(): string
    {
        return 'Echoes back the provided message.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()->description('The message to echo.')->required(),
            'shout' => $schema->boolean()->description('Uppercase the result.'),
        ];
    }

    public function handle(Request $request): string
    {
        $message = $request->string('message', '');

        return $request->boolean('shout') ? strtoupper($message) : (string) $message;
    }
}

class FakeExplodingTool implements Tool
{
    public function description(): string
    {
        return 'Always throws.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        throw new RuntimeException('kaboom');
    }
}

function mcpServer(): McpServer
{
    return new McpServer([new FakeEchoTool, new FakeExplodingTool], 'laravel-tackle', '1.0.0');
}

it('responds to initialize with server info and tools capability', function () {
    $response = mcpServer()->handleMessage([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-03-26'],
    ]);

    expect($response['id'])->toBe(1)
        ->and($response['result']['protocolVersion'])->toBe('2025-03-26')
        ->and($response['result']['serverInfo']['name'])->toBe('laravel-tackle')
        ->and($response['result']['serverInfo']['version'])->toBe('1.0.0')
        ->and($response['result']['capabilities'])->toHaveKey('tools');
});

it('defaults the protocol version when the client sends none', function () {
    $response = mcpServer()->handleMessage([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [],
    ]);

    expect($response['result']['protocolVersion'])->toBe(McpServer::PROTOCOL_VERSION);
});

it('ignores notifications', function () {
    $response = mcpServer()->handleMessage([
        'jsonrpc' => '2.0', 'method' => 'notifications/initialized',
    ]);

    expect($response)->toBeNull();
});

it('lists tools with serialized JSON schemas', function () {
    $response = mcpServer()->handleMessage([
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list',
    ]);

    $tools = collect($response['result']['tools']);

    expect($tools)->toHaveCount(2);

    $echo = $tools->firstWhere('name', 'FakeEchoTool');

    expect($echo['description'])->toBe('Echoes back the provided message.')
        ->and($echo['inputSchema']['type'])->toBe('object')
        ->and($echo['inputSchema']['properties']['message']['type'])->toBe('string')
        ->and($echo['inputSchema']['properties']['shout']['type'])->toBe('boolean')
        ->and($echo['inputSchema']['required'])->toBe(['message']);
});

it('produces a minimal object schema for tools without parameters', function () {
    $response = mcpServer()->handleMessage([
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list',
    ]);

    $exploding = collect($response['result']['tools'])->firstWhere('name', 'FakeExplodingTool');

    expect($exploding['inputSchema'])->toBe(['type' => 'object']);
});

it('calls a tool and returns its text output', function () {
    $response = mcpServer()->handleMessage([
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => ['name' => 'FakeEchoTool', 'arguments' => ['message' => 'hello', 'shout' => true]],
    ]);

    expect($response['result']['isError'])->toBeFalse()
        ->and($response['result']['content'][0])->toBe(['type' => 'text', 'text' => 'HELLO']);
});

it('reports tool exceptions in-band with isError', function () {
    $response = mcpServer()->handleMessage([
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/call',
        'params' => ['name' => 'FakeExplodingTool', 'arguments' => []],
    ]);

    expect($response['result']['isError'])->toBeTrue()
        ->and($response['result']['content'][0]['text'])->toContain('kaboom');
});

it('returns an invalid-params error for unknown tools', function () {
    $response = mcpServer()->handleMessage([
        'jsonrpc' => '2.0',
        'id' => 5,
        'method' => 'tools/call',
        'params' => ['name' => 'NoSuchTool', 'arguments' => []],
    ]);

    expect($response['error']['code'])->toBe(-32602)
        ->and($response['error']['message'])->toContain('NoSuchTool');
});

it('returns method-not-found for unknown methods', function () {
    $response = mcpServer()->handleMessage([
        'jsonrpc' => '2.0', 'id' => 6, 'method' => 'resources/list',
    ]);

    expect($response['error']['code'])->toBe(-32601);
});

it('answers ping with an empty result', function () {
    $response = mcpServer()->handleMessage([
        'jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping',
    ]);

    expect($response)->toHaveKey('result')
        ->and($response)->not->toHaveKey('error');
});

it('runs a full stdio session over streams', function () {
    $input = fopen('php://memory', 'r+');
    fwrite($input, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []])."\n");
    fwrite($input, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])."\n");
    fwrite($input, "not-json\n");
    fwrite($input, json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'FakeEchoTool', 'arguments' => ['message' => 'hi']],
    ])."\n");
    rewind($input);

    $output = fopen('php://memory', 'r+');

    mcpServer()->run($input, $output);

    rewind($output);
    $lines = array_values(array_filter(array_map('trim', explode("\n", stream_get_contents($output)))));

    // initialize response, parse error, tools/call response — notification is silent.
    expect($lines)->toHaveCount(3);

    $parseError = json_decode($lines[1], true);
    $callResult = json_decode($lines[2], true);

    expect($parseError['error']['code'])->toBe(-32700)
        ->and($callResult['result']['content'][0]['text'])->toBe('hi');
});
