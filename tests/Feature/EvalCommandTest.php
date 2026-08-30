<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Tackle\Agents\CachingCodingAgent;
use Tackle\Agents\LeanCodingAgent;
use Tackle\Contracts\CodingAgent;
use Tackle\Support\ConversationCache;
use Tackle\Tests\Fakes\FakeCodingAgent;

/**
 * The JSON document ai:eval wrote to stdout.
 *
 * Progress now goes to stderr, and under Artisan::call both streams share one
 * buffer — so the human-readable lines are trimmed off the front here, exactly
 * as ai:run's test helper does.
 */
function evalJson(): ?array
{
    $output = Artisan::output();
    $start = strpos($output, '{');

    return $start === false ? null : json_decode(substr($output, $start), true);
}

it('ai:eval is registered', function () {
    expect(Artisan::all())->toHaveKey('ai:eval');
});

it('reports unknown case ids cleanly', function () {
    $this->artisan('ai:eval', ['--case' => ['does-not-exist']])
        ->expectsOutputToContain('No matching eval cases')
        ->assertExitCode(1);
});

it('runs a case with a no-op agent and reports it not-fixed as JSON', function () {
    // A fresh agent per resolution that makes no edits and reports small usage.
    app()->bind(CodingAgent::class, fn () => new FakeCodingAgent([
        new StreamEnd('e', 'stop', new Usage(1000, 50), 0),
    ]));

    $exit = Artisan::call('ai:eval', ['--case' => ['div-by-zero'], '--json' => true, '--budget' => '0.50']);
    $out = Artisan::output();
    $doc = json_decode(substr($out, strpos($out, '{')), true);

    expect($exit)->toBe(0) // not-fixed is not a false-fix/error, so exit 0
        ->and($doc['total'])->toBe(1)
        ->and($doc['fixed'])->toBe(0)
        ->and($doc['cases'][0]['id'])->toBe('div-by-zero')
        ->and($doc['cases'][0]['status'])->toBe('not-fixed')
        ->and($doc['input_tokens'])->toBe(1000);
});

it('rejects an --agent that is not a CodingAgent', function () {
    $this->artisan('ai:eval', ['--case' => ['div-by-zero'], '--agent' => 'stdClass', '--json' => true])
        ->assertExitCode(1);
});

it('rejects an unknown --agent class', function () {
    $this->artisan('ai:eval', ['--case' => ['div-by-zero'], '--agent' => 'App\\Nope\\Missing', '--json' => true])
        ->assertExitCode(1);
});

it('benchmarks the agent class given to --agent', function () {
    // A no-op CodingAgent class the runner will instantiate via the container.
    app()->bind(EvalFakeAgent::class, fn () => new EvalFakeAgent);

    $exit = Artisan::call('ai:eval', ['--case' => ['div-by-zero'], '--agent' => EvalFakeAgent::class, '--json' => true]);
    // Once: Artisan::output() drains the buffer, so a second call returns ''.
    $doc = evalJson();

    expect($exit)->toBe(0)
        ->and($doc['total'])->toBe(1)
        ->and($doc['cases'][0]['status'])->toBe('not-fixed'); // no-op agent makes no edit
});

class EvalFakeAgent extends FakeCodingAgent
{
    public function __construct()
    {
        parent::__construct([
            new StreamEnd('e', 'stop', new Usage(500, 25), 0),
        ]);
    }
}

it('exposes only the fix-task tools on the lean agent', function () {
    config()->set('tackle.workspace', sys_get_temp_dir());
    $names = collect(app(LeanCodingAgent::class)->tools())
        ->map(fn ($t) => is_callable([$t, 'name']) ? $t->name() : class_basename($t));

    expect($names->all())->toEqualCanonicalizing(LeanCodingAgent::KEEP)
        ->and($names)->not->toContain('ReadSentryIssue', 'CreatePullRequest', 'ListRoutes');
});

it('resolves the --agent=lean shorthand', function () {
    app()->bind(LeanCodingAgent::class, fn () => new EvalFakeAgent);

    $exit = Artisan::call('ai:eval', ['--case' => ['div-by-zero'], '--agent' => 'lean', '--json' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('"total": 1');
});

it('emits an anthropic system cache breakpoint from the caching agent', function () {
    config()->set('tackle.workspace', sys_get_temp_dir());
    $agent = app(CachingCodingAgent::class);

    $opts = $agent->providerOptions(Lab::Anthropic);
    expect($opts)->toHaveKey('system')
        ->and($opts['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($opts['system'][0]['text'])->toContain('Laravel');

    // No caching directives for other providers.
    expect($agent->providerOptions('openai'))->toBe([]);
});

it('caches by default on the standard agent and respects tackle.prompt_cache', function () {
    config()->set('tackle.workspace', sys_get_temp_dir());

    config()->set('tackle.prompt_cache', true);
    $on = app(CodingAgent::class);
    expect($on)->toBeInstanceOf(HasProviderOptions::class)
        ->and($on->providerOptions(Lab::Anthropic))->toHaveKey('system');

    config()->set('tackle.prompt_cache', false);
    expect(app(CodingAgent::class)->providerOptions(Lab::Anthropic))->toBe([]);
});

it('arms conversation caching alongside the system breakpoint', function () {
    // The system block covers instructions and tool schemas — the fixed part.
    // The conversation is the part that grows, and laravel/ai owns those
    // messages, so the trait arms the outbound rewrite that marks them.
    ConversationCache::disarm();
    config()->set('tackle.workspace', sys_get_temp_dir());

    app(CachingCodingAgent::class)->providerOptions(Lab::Anthropic);

    expect(ConversationCache::armed())->toBeTrue();

    ConversationCache::disarm();
});

it('does not arm conversation caching for a non-anthropic provider', function () {
    ConversationCache::disarm();
    config()->set('tackle.workspace', sys_get_temp_dir());

    app(CachingCodingAgent::class)->providerOptions('openai');

    expect(ConversationCache::armed())->toBeFalse();
});

it('does not arm conversation caching when prompt caching is off', function () {
    ConversationCache::disarm();
    config()->set('tackle.workspace', sys_get_temp_dir());
    config()->set('tackle.prompt_cache', false);

    app(CachingCodingAgent::class)->providerOptions(Lab::Anthropic);

    expect(ConversationCache::armed())->toBeFalse();
});

it('surfaces a model/stream failure as an error, not a silent not-fixed', function () {
    // An agent whose stream throws before any tokens are recorded (bad model,
    // auth, network) must show as an error — the failure that produced 0-token
    // "not-fixed" rows.
    app()->bind(CodingAgent::class, fn () => new class extends FakeCodingAgent
    {
        public function __construct()
        {
            parent::__construct([], new RuntimeException('model: unknown model "claude-nope"'));
        }
    });

    $exit = Artisan::call('ai:eval', ['--case' => ['div-by-zero'], '--json' => true]);
    // Once: Artisan::output() drains the buffer, so a second call returns ''.
    $doc = evalJson();

    expect($exit)->toBe(1) // errors → non-zero
        ->and($doc['errors'])->toBe(1)
        ->and($doc['cases'][0]['status'])->toBe('error')
        ->and($doc['cases'][0]['error'])->toContain('unknown model');
});

it('reports per-case progress on stderr in JSON mode', function () {
    // A suite runs for minutes. --json used to print nothing at all until the
    // final document, which is indistinguishable from a hang — and it is the
    // one command here that spends real money while it does it.
    app()->bind(CodingAgent::class, fn () => new EvalFakeAgent);

    Artisan::call('ai:eval', ['--case' => ['div-by-zero'], '--json' => true]);

    $output = Artisan::output();

    // Both streams share one buffer under Artisan::call, so this asserts the
    // progress was written at all, and that it lands ahead of the document.
    expect($output)->toContain('running div-by-zero')
        ->and(strpos($output, 'running div-by-zero'))->toBeLessThan(strpos($output, '{'))
        // stdout is still exactly one parseable document.
        ->and(json_decode(substr($output, strpos($output, '{')), true))->toHaveKey('total');
});

it('runs every case --repeat times', function () {
    app()->bind(CodingAgent::class, fn () => new EvalFakeAgent);

    Artisan::call('ai:eval', ['--case' => ['div-by-zero'], '--repeat' => 3, '--json' => true]);

    $doc = evalJson();

    expect($doc['total'])->toBe(3)
        ->and($doc['by_case']['div-by-zero']['runs'])->toBe(3);
});

it('rejects a non-positive --repeat', function () {
    expect(Artisan::call('ai:eval', ['--case' => ['div-by-zero'], '--repeat' => 0]))->not->toBe(0);
});
