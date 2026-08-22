<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Tackle\Agents\LeanCodingAgent;
use Tackle\Contracts\CodingAgent;
use Tackle\Tests\Fakes\FakeCodingAgent;

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
    $doc = json_decode(substr(Artisan::output(), strpos(Artisan::output(), '{')), true);

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
