<?php

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Tackle\Agents\DefaultCodingAgent;
use Tackle\Agents\SummarizerAgent;
use Tackle\Support\ConversationCompactor;

function agentWithHistory(int $turns): DefaultCodingAgent
{
    $agent = app(DefaultCodingAgent::class);

    $messages = [];
    for ($i = 1; $i <= $turns; $i++) {
        $messages[] = new UserMessage("Task {$i}: do the thing number {$i}");
        $messages[] = new AssistantMessage("Done with thing {$i}.");
    }
    $agent->replaceConversation($messages);

    return $agent;
}

function fakeSummarizer(string $summary = 'Earlier: many things were done.'): SummarizerAgent
{
    $mock = Mockery::mock(SummarizerAgent::class);
    $mock->shouldReceive('summarize')->andReturn($summary);

    return $mock;
}

it('reports conversation size and supports the default agent', function () {
    $agent = agentWithHistory(2);

    expect($agent->conversationSize())->toBeGreaterThan(0)
        ->and((new ConversationCompactor(fakeSummarizer()))->supports($agent))->toBeTrue();
});

it('compacts older messages into a summary and keeps recent ones', function () {
    config()->set('tackle.compaction.keep_recent', 4);

    $agent = agentWithHistory(8); // 16 messages

    $compacted = (new ConversationCompactor(fakeSummarizer()))->compact($agent);
    $messages = collect($agent->messages())->values();

    expect($compacted)->toBeTrue()
        ->and($messages)->toHaveCount(6) // summary pair + 4 recent
        ->and((string) $messages[0]->content)->toContain('Earlier: many things were done.')
        ->and((string) $messages->last()->content)->toBe('Done with thing 8.');
});

it('does not compact short conversations', function () {
    config()->set('tackle.compaction.keep_recent', 4);

    $agent = agentWithHistory(2);

    expect((new ConversationCompactor(fakeSummarizer()))->compact($agent))->toBeFalse();
});

it('shouldCompact respects the threshold config', function () {
    $agent = agentWithHistory(3);
    $compactor = new ConversationCompactor(fakeSummarizer());

    config()->set('tackle.compaction.threshold_chars', 1);
    expect($compactor->shouldCompact($agent))->toBeTrue();

    config()->set('tackle.compaction.threshold_chars', 1_000_000);
    expect($compactor->shouldCompact($agent))->toBeFalse();
});

it('leaves the conversation untouched when the summarizer fails', function () {
    $summarizer = Mockery::mock(SummarizerAgent::class);
    $summarizer->shouldReceive('summarize')->andThrow(new RuntimeException('provider down'));

    $agent = agentWithHistory(8);
    $before = collect($agent->messages())->count();

    expect((new ConversationCompactor($summarizer))->compact($agent))->toBeFalse()
        ->and(collect($agent->messages()))->toHaveCount($before);
});

it('clears history with forgetConversation', function () {
    $agent = agentWithHistory(3);
    $agent->forgetConversation();

    expect(iterator_to_array($agent->messages()))->toBe([])
        ->and($agent->conversationSize())->toBe(0);
});
