<?php

use GuzzleHttp\Psr7\Request;
use Tackle\Support\ConversationCache;

afterEach(fn () => ConversationCache::disarm());

/** Mark a body given as JSON and hand back the decoded result. */
function marked(string $json): ?array
{
    $out = ConversationCache::mark($json);

    return $out === null ? null : json_decode($out, true);
}

// ---------------------------------------------------------------------------
// Round-tripping the body — the part that broke in production
// ---------------------------------------------------------------------------

it('preserves empty objects when it rewrites the body', function () {
    // Decoding to associative arrays cannot tell {} from [], so a tool schema
    // with no parameters came back as "properties":[] and Anthropic rejected
    // the request with a 400 on every step, before the first token.
    $json = '{"model":"claude-sonnet-4-6",'
        .'"tools":[{"name":"RunTests","input_schema":{"type":"object","properties":{},"required":[]}}],'
        .'"messages":[{"role":"user","content":"go"}]}';

    expect(ConversationCache::mark($json))->toContain('"properties":{}')
        ->not->toContain('"properties":[]');
});

it('preserves an empty tool_use input object inside the conversation', function () {
    $json = '{"messages":['
        .'{"role":"assistant","content":[{"type":"tool_use","name":"RunTests","input":{}}]},'
        .'{"role":"user","content":[{"type":"tool_result","content":"ok"}]}]}';

    expect(ConversationCache::mark($json))->toContain('"input":{}');
});

it('leaves genuinely empty lists as lists', function () {
    $json = '{"messages":[{"role":"user","content":"go"}],"stop_sequences":[]}';

    expect(ConversationCache::mark($json))->toContain('"stop_sequences":[]');
});

it('writes the breakpoint as an object, not a list', function () {
    $out = ConversationCache::mark('{"messages":[{"role":"user","content":"go"}]}');

    expect($out)->toContain('"cache_control":{"type":"ephemeral"}');
});

// ---------------------------------------------------------------------------
// Where the breakpoint lands
// ---------------------------------------------------------------------------

it('marks the final content block of the message list', function () {
    $body = marked(json_encode([
        'model' => 'claude-sonnet-4-6',
        'messages' => [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Fix the bug']]],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'name' => 'ReadFile']]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'content' => '<?php ...']]],
        ],
    ]));

    expect($body['messages'][2]['content'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        // Only the last one: Anthropic caches the cumulative prefix, so a
        // single trailing breakpoint already covers everything before it.
        ->and($body['messages'][0]['content'][0])->not->toHaveKey('cache_control')
        ->and($body['messages'][1]['content'][0])->not->toHaveKey('cache_control');
});

it('marks the last block when a message carries several', function () {
    $body = marked(json_encode(['messages' => [
        ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'content' => 'first'],
            ['type' => 'tool_result', 'content' => 'second'],
        ]],
    ]]));

    expect($body['messages'][0]['content'][0])->not->toHaveKey('cache_control')
        ->and($body['messages'][0]['content'][1]['cache_control'])->toBe(['type' => 'ephemeral']);
});

it('expands string content into a text block so it can carry a breakpoint', function () {
    // The API treats a bare string as a single text block, so this changes
    // nothing the model sees.
    $body = marked('{"messages":[{"role":"user","content":"Fix the failing test"}]}');

    expect($body['messages'][0]['content'])->toBe([[
        'type' => 'text',
        'text' => 'Fix the failing test',
        'cache_control' => ['type' => 'ephemeral'],
    ]]);
});

it('walks back past a trailing message with no content to mark', function () {
    $body = marked(json_encode(['messages' => [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
        ['role' => 'assistant', 'content' => []],
    ]]));

    expect($body['messages'][0]['content'][0]['cache_control'])->toBe(['type' => 'ephemeral']);
});

it('preserves the system breakpoint the instructions trait placed', function () {
    $body = marked(json_encode([
        'system' => [['type' => 'text', 'text' => 'You are...', 'cache_control' => ['type' => 'ephemeral']]],
        'messages' => [['role' => 'user', 'content' => 'go']],
    ]));

    // Two breakpoints total, well inside the limit of four.
    expect($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['messages'][0]['content'][0]['cache_control'])->toBe(['type' => 'ephemeral']);
});

// ---------------------------------------------------------------------------
// Leaving things alone
// ---------------------------------------------------------------------------

it('leaves a conversation that already carries breakpoints untouched', function () {
    // Four is the Anthropic limit; adding to someone else's deliberate
    // placement risks exceeding it and overrides their intent.
    $json = json_encode(['messages' => [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'a', 'cache_control' => ['type' => 'ephemeral']]]],
        ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'b']]],
    ]]);

    expect(ConversationCache::mark($json))->toBeNull();
});

it('leaves an empty message list untouched', function () {
    expect(ConversationCache::mark('{"messages":[]}'))->toBeNull();
});

it('leaves a body with no messages untouched', function () {
    expect(ConversationCache::mark('{"model":"x"}'))->toBeNull();
});

it('leaves whitespace-only string content untouched', function () {
    expect(ConversationCache::mark('{"messages":[{"role":"user","content":"   "}]}'))->toBeNull();
});

it('leaves an unparseable body untouched', function () {
    expect(ConversationCache::mark('not json'))->toBeNull()
        ->and(ConversationCache::mark(''))->toBeNull();
});

// ---------------------------------------------------------------------------
// Arming — what stops this touching an application's own Anthropic traffic
// ---------------------------------------------------------------------------

function anthropicRequest(string $body): Request
{
    return new Request('POST', 'https://api.anthropic.com/v1/messages', [], $body);
}

it('passes an unarmed request through untouched', function () {
    $request = anthropicRequest('{"messages":[{"role":"user","content":"hi"}]}');

    expect((string) ConversationCache::handle($request)->getBody())
        ->toBe((string) $request->getBody());
});

it('rewrites an armed request', function () {
    ConversationCache::arm();

    $body = json_decode((string) ConversationCache::handle(
        anthropicRequest('{"messages":[{"role":"user","content":"hi"}]}')
    )->getBody(), true);

    expect($body['messages'][0]['content'][0]['cache_control'])->toBe(['type' => 'ephemeral']);
});

it('consumes the arming so it covers exactly one request', function () {
    ConversationCache::arm();

    ConversationCache::handle(anthropicRequest('{"messages":[{"role":"user","content":"hi"}]}'));

    expect(ConversationCache::armed())->toBeFalse();
});

it('consumes the arming even when the request is not one it rewrites', function () {
    // A body built but never posted must not leak the arming onto whatever
    // the application sends next.
    ConversationCache::arm();

    ConversationCache::handle(new Request('POST', 'https://api.anthropic.com/v1/models'));

    expect(ConversationCache::armed())->toBeFalse();
});

it('leaves a non-messages endpoint untouched', function () {
    ConversationCache::arm();

    $request = new Request('GET', 'https://api.anthropic.com/v1/models');

    expect((string) ConversationCache::handle($request)->getBody())->toBe('');
});

it('passes an unparseable body through rather than corrupting the request', function () {
    ConversationCache::arm();

    $request = anthropicRequest('not json');

    expect((string) ConversationCache::handle($request)->getBody())->toBe('not json');
});

it('corrects Content-Length when it rewrites the body', function () {
    ConversationCache::arm();

    $rewritten = ConversationCache::handle(anthropicRequest('{"messages":[{"role":"user","content":"hi"}]}'));

    expect((int) $rewritten->getHeaderLine('Content-Length'))
        ->toBe(strlen((string) $rewritten->getBody()));
});
