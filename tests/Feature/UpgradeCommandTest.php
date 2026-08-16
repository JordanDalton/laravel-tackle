<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Tackle\Agents\UpgradeAgent;
use Tackle\Tests\Fakes\FakeCodingAgent;

class CapturingFakeUpgradeAgent extends FakeCodingAgent
{
    public ?string $prompt = null;

    public function stream(mixed $prompt, array $attachments = [], mixed $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $this->prompt = is_string($prompt) ? $prompt : null;

        return parent::stream($prompt, $attachments, $provider, $model, $timeout);
    }
}

function fakeUpgradeAgent(array $events): CapturingFakeUpgradeAgent
{
    $agent = new CapturingFakeUpgradeAgent($events);
    app()->instance(UpgradeAgent::class, $agent);

    return $agent;
}

function upgradePrEvents(): array
{
    return [
        new TextDelta('e', 'm', 'Upgraded cleanly.', 0),
        new ToolResult('e', new Data\ToolResult('t', 'CreatePullRequest', [], 'Opened https://github.com/acme/app/pull/9'), true, null, 0),
        new StreamEnd('e', 'stop', new Usage(1000, 100), 0),
    ];
}

function fakeUpgradeComposer(array $majors): void
{
    Process::fake(function (PendingProcess $process) use ($majors) {
        // Composer runs as an array command; git (worktrees) runs as a string.
        $command = is_array($process->command) ? $process->command : explode(' ', (string) $process->command);

        if (in_array('outdated', $command, strict: true)) {
            return Process::result(json_encode(['installed' => $majors]));
        }

        if (in_array('why-not', $command, strict: true)) {
            return Process::result('laravel/framework is locked to version v11.44.0');
        }

        return Process::result('');
    });
}

it('prints available majors and their blockers with --audit', function () {
    fakeUpgradeComposer([
        ['name' => 'laravel/framework', 'version' => 'v11.44.0', 'latest' => 'v12.21.0', 'description' => 'The Laravel Framework.'],
    ]);

    $this->artisan('ai:upgrade', ['--audit' => true])
        ->expectsOutputToContain('Major upgrades available')
        ->expectsOutputToContain('v11.44.0 → v12.21.0')
        ->expectsOutputToContain('locked to version')
        ->assertExitCode(0);
});

it('reports a clean state with --audit when no majors are available', function () {
    fakeUpgradeComposer([]);

    $this->artisan('ai:upgrade', ['--audit' => true])
        ->expectsOutputToContain('latest major version')
        ->assertExitCode(0);
});

it('fails --audit cleanly when composer cannot run', function () {
    Process::fake(fn () => Process::result(output: '', errorOutput: 'composer.json not found', exitCode: 1));

    $this->artisan('ai:upgrade', ['--audit' => true])
        ->expectsOutputToContain('composer outdated failed')
        ->assertExitCode(1);
});

it('syncs the audit to a GitHub issue with --issue', function () {
    config()->set('tackle.github.token', 'test-token');
    config()->set('tackle.github.repo', 'acme/app');

    fakeUpgradeComposer([
        ['name' => 'pestphp/pest', 'version' => 'v4.7.8', 'latest' => 'v5.1.1', 'description' => ''],
    ]);

    Http::fake([
        'api.github.com/repos/acme/app/issues?*' => Http::response([]),
        'api.github.com/repos/acme/app/issues' => Http::response(['number' => 3, 'html_url' => 'https://github.com/acme/app/issues/3'], 201),
    ]);

    // --issue implies --audit; no TTY needed, so it is scheduler-safe.
    $this->artisan('ai:upgrade', ['--issue' => true])
        ->expectsOutputToContain('Created issue #3')
        ->assertExitCode(0);
});

it('fails --issue cleanly when GitHub is not configured', function () {
    config()->set('tackle.github.token', null);
    config()->set('tackle.github.repo', null);

    Process::fake(function (PendingProcess $process) {
        if (in_array('outdated', $process->command, strict: true)) {
            return Process::result(json_encode(['installed' => []]));
        }

        return Process::result(output: '', errorOutput: 'not logged in', exitCode: 1);
    });

    $this->artisan('ai:upgrade', ['--audit' => true, '--issue' => true])
        ->expectsOutputToContain('GitHub is not configured')
        ->assertExitCode(1);
});

it('requires a TTY for an interactive session', function () {
    fakeUpgradeComposer([]);

    $this->artisan('ai:upgrade', ['packages' => ['laravel/framework']])
        ->expectsOutputToContain('requires an interactive TTY')
        ->assertExitCode(1);
});

it('refuses headless mode without explicit packages', function () {
    fakeUpgradeComposer([]);

    $this->artisan('ai:upgrade', ['--headless' => true])
        ->expectsOutputToContain('requires explicit package names')
        ->assertExitCode(1);
});

it('rejects an invalid headless output format', function () {
    fakeUpgradeComposer([]);

    $this->artisan('ai:upgrade', ['packages' => ['pestphp/pest'], '--headless' => true, '--output' => 'xml'])
        ->expectsOutputToContain("Invalid --output value 'xml'")
        ->assertExitCode(1);
});

it('rejects a non-numeric ref-issue', function () {
    fakeUpgradeComposer([]);

    $this->artisan('ai:upgrade', ['packages' => ['pestphp/pest'], '--headless' => true, '--ref-issue' => 'abc'])
        ->expectsOutputToContain("Invalid --ref-issue value 'abc'")
        ->assertExitCode(1);
});

it('runs a headless upgrade to a PR and reports it as JSON', function () {
    fakeUpgradeComposer([
        ['name' => 'pestphp/pest', 'version' => 'v4.7.8', 'latest' => 'v5.1.1', 'description' => ''],
    ]);

    $agent = fakeUpgradeAgent(upgradePrEvents());

    $exit = Artisan::call('ai:upgrade', ['packages' => ['pestphp/pest'], '--headless' => true, '--output' => 'json', '--ref-issue' => '12']);
    $output = Artisan::output();
    $document = json_decode(substr($output, strpos($output, '{')), true);

    expect($exit)->toBe(0)
        ->and($document['ok'])->toBeTrue()
        ->and($document['package'])->toBe('pestphp/pest')
        ->and($document['outcome'])->toBe('completed')
        ->and($document['pr_url'])->toBe('https://github.com/acme/app/pull/9')
        ->and($agent->prompt)
        ->toContain('HEADLESS')
        ->toContain('Refs #12')
        ->toContain('pestphp/pest: v4.7.8 installed');
});

it('defaults the headless step ceiling to the UpgradeAgent attribute, not the ai:run config', function () {
    fakeUpgradeComposer([]);

    // 45 tool calls would exceed the old config default (40) but sits inside
    // the agent's #[MaxSteps] attribute — the session must complete.
    $events = array_map(
        fn (int $i) => new ToolCall('e', new Data\ToolCall('t', 'RunComposer', []), $i),
        range(1, 45),
    );
    $events[] = new StreamEnd('e', 'stop', new Usage(1000, 100), 0);

    fakeUpgradeAgent($events);

    $exit = Artisan::call('ai:upgrade', ['packages' => ['pestphp/pest'], '--headless' => true, '--output' => 'json']);
    $output = Artisan::output();
    $document = json_decode(substr($output, strpos($output, '{')), true);

    expect($exit)->toBe(0)
        ->and($document['outcome'])->toBe('completed')
        ->and($document['steps'])->toBe(45);
});

it('still enforces an explicit --max-steps in headless mode', function () {
    fakeUpgradeComposer([]);

    $events = array_map(
        fn (int $i) => new ToolCall('e', new Data\ToolCall('t', 'RunComposer', []), $i),
        range(1, 45),
    );

    fakeUpgradeAgent($events);

    $exit = Artisan::call('ai:upgrade', ['packages' => ['pestphp/pest'], '--headless' => true, '--output' => 'json', '--max-steps' => '10']);
    $output = Artisan::output();
    $document = json_decode(substr($output, strpos($output, '{')), true);

    expect($exit)->toBe(4)
        ->and($document['outcome'])->toBe('max_steps_reached');
});

it('reports a headless agent error without dying', function () {
    fakeUpgradeComposer([]);

    app()->instance(UpgradeAgent::class, new FakeCodingAgent([], new RuntimeException('provider unreachable')));

    $exit = Artisan::call('ai:upgrade', ['packages' => ['pestphp/pest'], '--headless' => true, '--output' => 'json']);
    $output = Artisan::output();
    $document = json_decode(substr($output, strpos($output, '{')), true);

    expect($exit)->toBe(1)
        ->and($document['ok'])->toBeFalse()
        ->and($document['outcome'])->toBe('error')
        ->and($document['error'])->toBe('provider unreachable');
});

it('accepts multiple packages for a batch', function () {
    fakeUpgradeComposer([]);

    // The TTY gate fires before any session starts — this asserts the
    // variadic signature parses, not the interactive flow itself.
    $this->artisan('ai:upgrade', ['packages' => ['pestphp/pest', 'spatie/laravel-permission']])
        ->expectsOutputToContain('requires an interactive TTY')
        ->assertExitCode(1);
});
