<?php

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Tackle\Support\SessionStore;

beforeEach(function () {
    (new SessionStore)->forget('test-session');
});

it('is enabled for memory=file and disabled for memory=none', function () {
    config()->set('tackle.memory', 'file');
    expect((new SessionStore)->enabled())->toBeTrue();

    config()->set('tackle.memory', 'none');
    expect((new SessionStore)->enabled())->toBeFalse();
});

it('round-trips a transcript preserving roles, content, and order', function () {
    $store = new SessionStore;

    $store->save('test-session', [
        new UserMessage('Add a slug field'),
        new AssistantMessage('Done — migration and model updated.'),
        new UserMessage('Now add tests'),
    ]);

    $loaded = $store->load('test-session');

    expect($loaded)->toHaveCount(3)
        ->and($loaded[0])->toBeInstanceOf(UserMessage::class)
        ->and($loaded[0]->content)->toBe('Add a slug field')
        ->and($loaded[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($loaded[1]->content)->toBe('Done — migration and model updated.')
        ->and($loaded[2]->content)->toBe('Now add tests');
});

it('returns an empty transcript for unknown sessions', function () {
    expect((new SessionStore)->load('never-existed'))->toBe([]);
});

it('forgets a session', function () {
    $store = new SessionStore;
    $store->save('test-session', [new UserMessage('hello')]);
    $store->forget('test-session');

    expect($store->load('test-session'))->toBe([]);
});

it('sanitizes session names into safe filenames', function () {
    $store = new SessionStore;

    expect($store->path('../../etc/passwd'))->toEndWith('ai-code/etc-passwd.json')
        ->and($store->path('my feature!'))->toEndWith('ai-code/my-feature.json')
        ->and($store->path(''))->toEndWith('ai-code/default.json');
});

it('survives corrupt session files', function () {
    $store = new SessionStore;
    @mkdir(dirname($store->path('corrupt')), 0755, true);
    file_put_contents($store->path('corrupt'), '{not json');

    expect($store->load('corrupt'))->toBe([]);

    $store->forget('corrupt');
});
