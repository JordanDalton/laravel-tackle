<?php

use Illuminate\Support\Facades\Http;
use Tackle\Support\ConversationCache;

beforeEach(fn () => ConversationCache::disarm());
afterEach(fn () => ConversationCache::disarm());

it('rewrites a real outbound request through the application http client', function () {
    // The unit tests prove mark() and handle(); this proves the service
    // provider actually installed the middleware. Without it every other test
    // here would still pass while the feature did nothing in production.
    Http::fake(['*' => Http::response(['ok' => true])]);

    ConversationCache::arm();

    Http::post('https://api.anthropic.com/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'messages' => [['role' => 'user', 'content' => 'Fix the failing test']],
    ]);

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return ($body['messages'][0]['content'][0]['cache_control'] ?? null) === ['type' => 'ephemeral'];
    });
});

it('leaves an unarmed request alone through the same client', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    Http::post('https://api.anthropic.com/v1/messages', [
        'messages' => [['role' => 'user', 'content' => 'Not from Tackle']],
    ]);

    Http::assertSent(fn ($request) => json_decode($request->body(), true)['messages'][0]['content'] === 'Not from Tackle');
});
