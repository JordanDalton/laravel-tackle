<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Tackle\Commands\RunCommand;
use Tackle\Contracts\CodingAgent;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\AutoApproveInteraction;
use Tackle\Support\DenyInteraction;
use Tackle\Tests\Fakes\FakeCodingAgent;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function textDelta(string $text): TextDelta
{
    return new TextDelta('e', 'm', $text, 0);
}

function toolCallEvent(string $tool, array $args = []): ToolCall
{
    return new ToolCall('e', new Data\ToolCall('t', $tool, $args), 0);
}

function toolResultEvent(string $tool, string $result): ToolResult
{
    return new ToolResult('e', new Data\ToolResult('t', $tool, [], $result), true, null, 0);
}

function streamEnd(int $in = 1000, int $out = 100, int $cacheWrite = 0, int $cacheRead = 0): StreamEnd
{
    // Usage's constructor order is prompt, completion, cache-write, cache-read.
    return new StreamEnd('e', 'stop', new Usage($in, $out, $cacheWrite, $cacheRead), 0);
}

function fakeAgent(array $events): void
{
    app()->instance(CodingAgent::class, new FakeCodingAgent($events));
}

/**
 * Decode the JSON document ai:run wrote to stdout.
 *
 * Under Artisan::call both streams share one buffer, so the leading '#' notes
 * that would go to stderr on a real terminal are trimmed off here.
 */
function runJson(array $params = []): array
{
    $exit = artisan_run(array_merge(['--output' => 'json'], $params));
    $output = Artisan::output();
    $start = strpos($output, '{');

    return [
        $start === false ? null : json_decode(substr($output, $start), true),
        $exit,
    ];
}

function artisan_run(array $params = []): int
{
    return Artisan::call('ai:run', array_merge(['prompt' => 'do the thing'], $params));
}

// ---------------------------------------------------------------------------
// Interaction policy
// ---------------------------------------------------------------------------

it('denies confirmations by default because nobody is watching', function () {
    fakeAgent([textDelta('done'), streamEnd()]);

    artisan_run();

    expect(app(InteractionPolicy::class))->toBeInstanceOf(DenyInteraction::class);
});

it('auto-approves only when --yes is passed', function () {
    fakeAgent([textDelta('done'), streamEnd()]);

    artisan_run(['--yes' => true]);

    expect(app(InteractionPolicy::class))->toBeInstanceOf(AutoApproveInteraction::class);
});

// ---------------------------------------------------------------------------
// Exit codes
// ---------------------------------------------------------------------------

it('exits 0 on a clean run', function () {
    fakeAgent([textDelta('all done'), streamEnd()]);

    expect(artisan_run())->toBe(RunCommand::EXIT_OK);
});

it('exits 1 when the agent throws', function () {
    app()->instance(CodingAgent::class, new FakeCodingAgent([], throw: new RuntimeException('provider exploded')));

    [$json, $exit] = runJson();

    expect($exit)->toBe(RunCommand::EXIT_ERROR)
        ->and($json['outcome'])->toBe('error')
        ->and($json['error'])->toBe('provider exploded')
        ->and($json['ok'])->toBeFalse();
});

it('exits 2 and stops when the budget is exhausted', function () {
    config(['tackle.budget_usd' => 0.001]);

    fakeAgent([streamEnd(in: 1_000_000, out: 0), textDelta('should never be reached')]);

    [$json, $exit] = runJson();

    expect($exit)->toBe(RunCommand::EXIT_BUDGET)
        ->and($json['outcome'])->toBe('budget_exceeded')
        ->and($json['text'])->not->toContain('should never be reached');
});

it('exits 3 when a confirmation was denied and --fail-on-denied is set', function () {
    fakeAgent([
        fn () => app(InteractionPolicy::class)->confirm('Drop the table?'),
        streamEnd(),
    ]);

    [$json, $exit] = runJson(['--fail-on-denied' => true]);

    expect($exit)->toBe(RunCommand::EXIT_DENIED)
        ->and($json['outcome'])->toBe('interaction_denied')
        ->and($json['interactions_denied'])->toBe(1);
});

it('still exits 0 on a denied confirmation without --fail-on-denied', function () {
    fakeAgent([
        fn () => app(InteractionPolicy::class)->confirm('Drop the table?'),
        streamEnd(),
    ]);

    [$json, $exit] = runJson();

    expect($exit)->toBe(RunCommand::EXIT_OK)
        ->and($json['interactions_denied'])->toBe(1);
});

it('exits 4 when the step ceiling is hit', function () {
    fakeAgent([
        toolCallEvent('ReadFile', ['path' => 'a.php']),
        toolCallEvent('ReadFile', ['path' => 'b.php']),
        toolCallEvent('ReadFile', ['path' => 'c.php']),
        streamEnd(),
    ]);

    [$json, $exit] = runJson(['--max-steps' => 2]);

    expect($exit)->toBe(RunCommand::EXIT_MAX_STEPS)
        ->and($json['outcome'])->toBe('max_steps_reached')
        ->and($json['steps'])->toBe(3);
});

// ---------------------------------------------------------------------------
// Option validation
// ---------------------------------------------------------------------------

it('rejects an unknown output format', function () {
    fakeAgent([streamEnd()]);

    expect(artisan_run(['--output' => 'yaml']))->toBe(RunCommand::EXIT_ERROR);
    expect(Artisan::output())->toContain('Invalid --output');
});

it('rejects a non-numeric budget', function () {
    fakeAgent([streamEnd()]);

    expect(artisan_run(['--budget' => 'lots']))->toBe(RunCommand::EXIT_ERROR);
    expect(Artisan::output())->toContain('Invalid --budget');
});

it('rejects a zero or negative step ceiling', function () {
    fakeAgent([streamEnd()]);

    expect(artisan_run(['--max-steps' => '0']))->toBe(RunCommand::EXIT_ERROR);
    expect(Artisan::output())->toContain('Invalid --max-steps');
});

it('rejects an invalid shell mode', function () {
    fakeAgent([streamEnd()]);

    expect(artisan_run(['--shell' => 'maybe']))->toBe(RunCommand::EXIT_ERROR);
    expect(Artisan::output())->toContain('Invalid --shell');
});

it('applies the budget override before the tracker reads config', function () {
    fakeAgent([textDelta('ok'), streamEnd()]);

    [$json] = runJson(['--budget' => '5.50']);

    expect($json['budget_usd'])->toBe(5.5);
});

// ---------------------------------------------------------------------------
// JSON output
// ---------------------------------------------------------------------------

it('emits a single parseable JSON document with the run summary', function () {
    fakeAgent([
        textDelta('Looked at it. '),
        toolCallEvent('ReadFile', ['path' => 'app/Models/User.php']),
        toolResultEvent('ReadFile', '<?php class User {}'),
        textDelta('All good.'),
        streamEnd(in: 2000, out: 300),
    ]);

    [$json, $exit] = runJson();

    expect($exit)->toBe(RunCommand::EXIT_OK)
        ->and($json)->toBeArray()
        ->and($json['ok'])->toBeTrue()
        ->and($json['outcome'])->toBe('completed')
        // Prose either side of a tool call is two turns, so it is separated
        // rather than run together — this used to assert the concatenation.
        ->and($json['text'])->toBe("Looked at it.\n\nAll good.")
        ->and($json['steps'])->toBe(1)
        ->and($json['interactions_denied'])->toBe(0)
        ->and($json['usage']['input_tokens'])->toBe(2000)
        ->and($json['usage']['output_tokens'])->toBe(300)
        ->and($json['usage']['cache_read_tokens'])->toBe(0)
        ->and($json['usage']['cache_write_tokens'])->toBe(0)
        ->and($json['events'])->toHaveCount(2)
        ->and($json['events'][0]['type'])->toBe('tool_call')
        ->and($json['events'][0]['tool'])->toBe('ReadFile')
        ->and($json['events'][0]['args']['path'])->toBe('app/Models/User.php');
});

it('reports the cache breakdown so a run\'s real token cost can be read', function () {
    // Two steps: the first writes the cacheable prefix, the second reads it
    // back. Reporting only input_tokens made these two runs indistinguishable
    // from one that re-bought the whole context at full price.
    fakeAgent([
        toolCallEvent('ReadFile', ['path' => 'app/Models/User.php']),
        toolResultEvent('ReadFile', '<?php class User {}'),
        streamEnd(in: 400, out: 120, cacheWrite: 7800),
        streamEnd(in: 900, out: 200, cacheRead: 7800),
    ]);

    [$json] = runJson();

    expect($json['usage']['input_tokens'])->toBe(1300)
        ->and($json['usage']['cache_write_tokens'])->toBe(7800)
        ->and($json['usage']['cache_read_tokens'])->toBe(7800)
        // 7800 of 16,900 input tokens came from cache.
        ->and($json['usage']['cache_hit_rate'])->toBe(0.4615);
});

it('keeps stdout parseable when a tool result contains angle brackets', function () {
    fakeAgent([
        toolResultEvent('ReadFile', '<?php echo "5 < 6 && 7 > 2"; ?>'),
        streamEnd(),
    ]);

    [$json] = runJson();

    expect($json)->toBeArray()
        ->and($json['events'][0]['result'])->toContain('5 < 6 && 7 > 2');
});

it('reports the pull request URL when the agent opens one', function () {
    fakeAgent([
        toolResultEvent('CreatePullRequest', 'Opened https://github.com/acme/app/pull/42 successfully.'),
        streamEnd(),
    ]);

    [$json] = runJson();

    expect($json['pr_url'])->toBe('https://github.com/acme/app/pull/42');
});

it('leaves the pull request URL null when none was opened', function () {
    fakeAgent([textDelta('nothing to do'), streamEnd()]);

    [$json] = runJson();

    expect($json['pr_url'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Text output
// ---------------------------------------------------------------------------

it('writes a plain text log with no ANSI escapes', function () {
    fakeAgent([
        textDelta('Reading things.'),
        toolCallEvent('ReadFile', ['path' => 'app/Models/User.php']),
        streamEnd(),
    ]);

    artisan_run();
    $output = Artisan::output();

    expect($output)->toContain('reading app/Models/User.php')
        ->and($output)->toContain('Reading things.')
        ->and($output)->toContain('# outcome: completed')
        ->and($output)->not->toContain("\033[");
});

// ---------------------------------------------------------------------------
// Usage on a run that does not finish cleanly
// ---------------------------------------------------------------------------

it('keeps the token counts when the step ceiling is reached', function () {
    // Usage arrives in a single StreamEnd at the end of the whole loop, so
    // tearing the loop down by throwing through it reported $0.0000 for a run
    // that had just spent 41 steps of Sonnet. The loop now stops itself and
    // still reports.
    fakeAgent([
        toolCallEvent('ReadFile', ['path' => 'a.php']),
        toolResultEvent('ReadFile', 'ok'),
        new StreamEnd('e', 'tool_calls', new Usage(52_000, 3_100, 4_000, 40_000), 0),
    ]);

    [$json, $exit] = runJson();

    expect($exit)->toBe(RunCommand::EXIT_MAX_STEPS)
        ->and($json['outcome'])->toBe('max_steps_reached')
        ->and($json['usage']['input_tokens'])->toBe(52_000)
        ->and($json['usage']['cache_read_tokens'])->toBe(40_000)
        ->and($json['usage']['estimated_cost_usd'])->toBeGreaterThan(0)
        ->and($json['usage']['measured'])->toBeTrue();
});

it('marks usage unmeasured when the provider never reported any', function () {
    // A run that dies mid-loop — a provider error, a tool that throws — never
    // sees StreamEnd. Reporting zero cost would be a lie in the direction that
    // flatters us, so the estimate stands in and says so.
    fakeAgent([
        toolCallEvent('ReadFile', ['path' => 'a.php']),
        toolResultEvent('ReadFile', str_repeat('x', 40_000)),
        new Error('e', 'provider_error', 'provider exploded', false, 0),
    ]);

    [$json] = runJson();

    expect($json['usage']['measured'])->toBeFalse()
        ->and($json['usage']['input_tokens'])->toBe(0);
});

it('reports usage as measured on a clean run', function () {
    fakeAgent([textDelta('done'), streamEnd(in: 100, out: 20)]);

    [$json] = runJson();

    expect($json['usage']['measured'])->toBeTrue();
});

it('reports files the agent created, not only ones it modified', function () {
    // `git diff` only sees tracked files, so a run whose entire job was adding
    // a controller, a routes file and a test reported no changes at all — and
    // there was then no way to tell a working run from one that thrashed.
    $repo = sys_get_temp_dir().'/tackle-changed-files-'.bin2hex(random_bytes(4));
    mkdir($repo);
    file_put_contents($repo.'/tracked.txt', "old\n");
    shell_exec('git -C '.escapeshellarg($repo).' init -q . && git -C '.escapeshellarg($repo)
        .' add -A && git -C '.escapeshellarg($repo).' -c user.email=t@t -c user.name=t commit -qm init');

    file_put_contents($repo.'/tracked.txt', "changed\n");
    file_put_contents($repo.'/created.php', "<?php // brand new\n");

    $method = new ReflectionMethod(RunCommand::class, 'changedFiles');
    $files = $method->invoke(app(RunCommand::class), $repo);

    expect($files)->toContain('created.php')
        ->and($files)->toContain('tracked.txt');

    // Nothing is actually staged: the run's output stays reviewable as a whole.
    expect(trim((string) shell_exec('git -C '.escapeshellarg($repo).' diff --cached --name-only')))
        ->toBe('');

    shell_exec('rm -rf '.escapeshellarg($repo));
});

it('counts created files in the diff stat', function () {
    $repo = sys_get_temp_dir().'/tackle-diff-stat-'.bin2hex(random_bytes(4));
    mkdir($repo);
    file_put_contents($repo.'/tracked.txt', "old\n");
    shell_exec('git -C '.escapeshellarg($repo).' init -q . && git -C '.escapeshellarg($repo)
        .' add -A && git -C '.escapeshellarg($repo).' -c user.email=t@t -c user.name=t commit -qm init');

    file_put_contents($repo.'/created.php', "<?php // brand new\n");

    $method = new ReflectionMethod(RunCommand::class, 'diffStat');

    expect($method->invoke(app(RunCommand::class), $repo))->toContain('created.php');

    shell_exec('rm -rf '.escapeshellarg($repo));
});

it('fails fast with an actionable message when the provider has no key', function () {
    // Before this, the run booted a CI runner and died with "HTTP request
    // returned status code 401" — on every PR, if the misconfigured model was
    // the reviewer.
    config()->set('ai.providers.openai.key', null);
    fakeAgent([textDelta('never reached'), streamEnd()]);

    [$json, $exit] = runJson(['--provider' => 'openai']);

    expect($exit)->toBe(RunCommand::EXIT_ERROR)
        ->and($json['outcome'])->toBe('error')
        ->and($json['error'])->toContain("Provider 'openai' has no API key")
        ->and($json['usage']['measured'])->toBeFalse();
});
