<?php

use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request;
use Tackle\Agents\DefaultCodingAgent;
use Tackle\Support\BudgetTracker;
use Tackle\Tests\Fakes\FakeCodingAgent;
use Tackle\Tools\Delegate;

/**
 * A registrable subagent whose stream replays scripted events, so Delegate
 * can be tested without a provider. Configured per-test via static state.
 */
class DelegateTestSubagent extends FakeCodingAgent
{
    public static array $events = [];

    public static ?string $receivedPrompt = null;

    public function __construct()
    {
        parent::__construct(self::$events);
    }

    public function stream(
        mixed $prompt,
        array $attachments = [],
        mixed $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse {
        self::$receivedPrompt = is_string($prompt) ? $prompt : null;

        return parent::stream($prompt, $attachments, $provider, $model, $timeout);
    }
}

function delegateTextDelta(string $text): TextDelta
{
    return new TextDelta('e', 'm', $text, 0);
}

function delegateStreamEnd(int $in = 1000, int $out = 100): StreamEnd
{
    return new StreamEnd('e', 'stop', new Usage($in, $out), 0);
}

beforeEach(function () {
    DelegateTestSubagent::$events = [];
    DelegateTestSubagent::$receivedPrompt = null;

    config()->set('tackle.subagents', [
        'fake-explorer' => [
            'agent' => DelegateTestSubagent::class,
            'description' => 'A scripted explorer for tests.',
        ],
    ]);
});

it('lists configured subagents in its description', function () {
    $description = (string) app(Delegate::class)->description();

    expect($description)->toContain("'fake-explorer'")
        ->and($description)->toContain('A scripted explorer for tests.');
});

it('runs the subagent and returns its report', function () {
    DelegateTestSubagent::$events = [
        delegateTextDelta('The healer lives in '),
        delegateTextDelta('src/Healing.'),
        delegateStreamEnd(),
    ];

    $result = app(Delegate::class)->handle(new Request([
        'agent' => 'fake-explorer',
        'task' => 'Where does the healer live?',
    ]));

    expect($result)->toBe("Report from subagent 'fake-explorer':\n\nThe healer lives in src/Healing.")
        ->and(DelegateTestSubagent::$receivedPrompt)->toBe('Where does the healer live?');
});

it('refuses an unknown subagent and lists what is available', function () {
    $result = app(Delegate::class)->handle(new Request([
        'agent' => 'nope',
        'task' => 'anything',
    ]));

    expect($result)->toContain("Unknown subagent 'nope'")
        ->and($result)->toContain('fake-explorer');
});

it('refuses an empty task brief', function () {
    $result = app(Delegate::class)->handle(new Request([
        'agent' => 'fake-explorer',
        'task' => '  ',
    ]));

    expect($result)->toContain('non-empty task brief');
});

it('records subagent usage into the shared session budget', function () {
    DelegateTestSubagent::$events = [
        delegateTextDelta('report'),
        delegateStreamEnd(5000, 500),
    ];

    $budget = app(BudgetTracker::class);

    app(Delegate::class)->handle(new Request([
        'agent' => 'fake-explorer',
        'task' => 'count some tokens',
    ]));

    expect(app(BudgetTracker::class))->toBe($budget)
        ->and($budget->inputTokens())->toBe(5000)
        ->and($budget->outputTokens())->toBe(500);
});

it('stops the subagent when the session budget is exhausted', function () {
    config()->set('tackle.budget_usd', 0.0001);

    DelegateTestSubagent::$events = [
        delegateTextDelta('partial work'),
        delegateStreamEnd(1_000_000, 100_000),
    ];

    $result = app(Delegate::class)->handle(new Request([
        'agent' => 'fake-explorer',
        'task' => 'burn the budget',
    ]));

    expect($result)->toContain('budget')
        ->and($result)->toContain('Do not delegate again');
});

it('refuses nested delegation', function () {
    $nestedResult = null;

    DelegateTestSubagent::$events = [
        function () use (&$nestedResult) {
            $nestedResult = app(Delegate::class)->handle(new Request([
                'agent' => 'fake-explorer',
                'task' => 'delegate again',
            ]));
        },
        delegateTextDelta('outer report'),
        delegateStreamEnd(),
    ];

    $outer = app(Delegate::class)->handle(new Request([
        'agent' => 'fake-explorer',
        'task' => 'outer task',
    ]));

    expect($nestedResult)->toContain('subagents cannot delegate further')
        ->and($outer)->toContain('outer report');
});

it('releases the nesting guard after a subagent failure', function () {
    config()->set('tackle.subagents.broken', [
        'agent' => FakeCodingAgent::class,
        'description' => 'Constructed with a throw.',
    ]);
    app()->bind(FakeCodingAgent::class, fn () => new FakeCodingAgent([], new RuntimeException('boom')));

    $failed = app(Delegate::class)->handle(new Request([
        'agent' => 'broken',
        'task' => 'explode',
    ]));

    DelegateTestSubagent::$events = [delegateTextDelta('recovered'), delegateStreamEnd()];

    $next = app(Delegate::class)->handle(new Request([
        'agent' => 'fake-explorer',
        'task' => 'try again',
    ]));

    expect($failed)->toContain("Subagent 'broken' failed: boom")
        ->and($next)->toContain('recovered');
});

it('reports a subagent that finished silently', function () {
    DelegateTestSubagent::$events = [delegateStreamEnd()];

    $result = app(Delegate::class)->handle(new Request([
        'agent' => 'fake-explorer',
        'task' => 'say nothing',
    ]));

    expect($result)->toContain('finished without producing a report');
});

it('is in the default agent toolset when subagents are configured', function () {
    $tools = collect(app(DefaultCodingAgent::class)->tools())
        ->map(fn ($tool) => method_exists($tool, 'name') ? $tool->name() : class_basename($tool));

    expect($tools)->toContain('Delegate');
});

it('is absent from the toolset and instructions when no subagents are configured', function () {
    config()->set('tackle.subagents', []);

    $agent = app(DefaultCodingAgent::class);

    $tools = collect($agent->tools())
        ->map(fn ($tool) => method_exists($tool, 'name') ? $tool->name() : class_basename($tool));

    expect($tools)->not->toContain('Delegate')
        ->and($agent->instructions())->not->toContain('Use Delegate');
});
