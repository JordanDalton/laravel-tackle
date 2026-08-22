<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
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
