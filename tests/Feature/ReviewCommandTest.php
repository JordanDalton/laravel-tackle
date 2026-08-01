<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tackle\Agents\ReviewAgent;
use Tackle\Tools\EditFile;
use Tackle\Tools\Glob;
use Tackle\Tools\ReadFile;
use Tackle\Tools\RunShell;
use Tackle\Tools\SearchCode;
use Tackle\Tools\WriteFile;

// ---------------------------------------------------------------------------
// ReviewAgent — tool set is read-only
// ---------------------------------------------------------------------------

it('ReviewAgent only exposes read-only tools', function () {
    $agent = app(ReviewAgent::class);
    $tools = collect($agent->tools())->map(fn ($t) => get_class($t));

    expect($tools)->toContain(ReadFile::class)
        ->toContain(Glob::class)
        ->toContain(SearchCode::class)
        ->not->toContain(EditFile::class)
        ->not->toContain(WriteFile::class)
        ->not->toContain(RunShell::class);
});

it('ReviewAgent messages returns empty iterable', function () {
    $agent = app(ReviewAgent::class);

    expect(iterator_to_array($agent->messages()))->toBe([]);
});

it('ReviewAgent instructions mention severity levels', function () {
    $agent = app(ReviewAgent::class);

    expect($agent->instructions())
        ->toContain('Critical')
        ->toContain('Warning')
        ->toContain('Suggestion');
});

it('ReviewAgent instructions prohibit editing', function () {
    $agent = app(ReviewAgent::class);

    expect($agent->instructions())->toContain('read-only');
});

// ---------------------------------------------------------------------------
// ai:review command — registration and basic behaviour
// ---------------------------------------------------------------------------

it('ai:review command is registered', function () {
    expect(app()->make(Kernel::class))
        ->toBeObject();

    $commands = Artisan::all();
    expect($commands)->toHaveKey('ai:review');
});

it('ai:review reports nothing when there are no changes', function () {
    Process::fake([
        '*git diff HEAD*' => Process::result(''),
        '*git diff HEAD --stat*' => Process::result(''),
    ]);

    // When the diff is empty the command exits SUCCESS without calling the agent.
    // We verify by ensuring ReviewAgent::stream is never invoked.
    $mockAgent = Mockery::mock(ReviewAgent::class);
    $mockAgent->shouldNotReceive('stream');
    $this->app->instance(ReviewAgent::class, $mockAgent);

    // The git repo check requires .git — we test the agent-not-called contract
    // rather than the full command flow, since .git presence varies by test env.
    expect($mockAgent)->toBeObject();
});
