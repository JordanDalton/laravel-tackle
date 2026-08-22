<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tackle\Agents\OnboardingAgent;
use Tackle\Tests\Fakes\FakeCodingAgent;
use Tackle\Tools\Delegate;
use Tackle\Tools\EditFile;
use Tackle\Tools\Glob;
use Tackle\Tools\ListRoutes;
use Tackle\Tools\ReadFile;
use Tackle\Tools\RunArtisan;
use Tackle\Tools\RunShell;
use Tackle\Tools\SearchCode;
use Tackle\Tools\WriteFile;

class CapturingFakeOnboardingAgent extends FakeCodingAgent
{
    public array $prompts = [];

    public function stream(mixed $prompt, array $attachments = [], mixed $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $this->prompts[] = is_string($prompt) ? $prompt : null;

        return parent::stream($prompt, $attachments, $provider, $model, $timeout);
    }
}

function fakeOnboardingAgent(array $events): CapturingFakeOnboardingAgent
{
    $agent = new CapturingFakeOnboardingAgent($events);
    app()->instance(OnboardingAgent::class, $agent);

    return $agent;
}

function tourEvents(string $text = "## What this app is\n\nA billing platform."): array
{
    return [
        new TextDelta('e', 'm', $text, 0),
        new StreamEnd('e', 'stop', new Usage(1000, 100), 0),
    ];
}

function onboardWorkspace(): string
{
    return rtrim(config('tackle.workspace'), DIRECTORY_SEPARATOR);
}

beforeEach(function () {
    // Each test starts with no docs/ left over from a previous one.
    $docs = onboardWorkspace().'/docs';

    if (is_dir($docs)) {
        foreach (glob($docs.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($docs);
    }

    @unlink(onboardWorkspace().'/TOUR.md');
});

// ---------------------------------------------------------------------------
// OnboardingAgent
// ---------------------------------------------------------------------------

it('OnboardingAgent only exposes read-only tools', function () {
    $tools = collect(app(OnboardingAgent::class)->tools())
        ->map(fn ($tool) => is_callable([$tool, 'name']) ? $tool->name() : class_basename($tool))
        ->all();

    expect($tools)->toContain(class_basename(ReadFile::class), class_basename(Glob::class), class_basename(SearchCode::class), class_basename(ListRoutes::class))
        ->not->toContain(class_basename(EditFile::class), class_basename(WriteFile::class), class_basename(RunShell::class), class_basename(RunArtisan::class));
});

it('OnboardingAgent offers Delegate only when subagents are registered', function () {
    $names = fn () => collect(app(OnboardingAgent::class)->tools())
        ->map(fn ($tool) => is_callable([$tool, 'name']) ? $tool->name() : class_basename($tool))
        ->all();

    expect($names())->toContain(class_basename(Delegate::class));

    config()->set('tackle.subagents', []);

    expect($names())->not->toContain(class_basename(Delegate::class));
});

it('OnboardingAgent instructions describe the tour and forbid changes', function () {
    $instructions = app(OnboardingAgent::class)->instructions();

    expect($instructions)
        ->toContain('read-only')
        ->toContain('What this app is')
        ->toContain('Running it locally')
        ->toContain('Where to be careful')
        ->toContain('Good first tasks')
        ->toContain('Do not suggest changes');
});

it('OnboardingAgent starts with an empty transcript', function () {
    expect(iterator_to_array(app(OnboardingAgent::class)->messages()))->toBe([]);
});

// ---------------------------------------------------------------------------
// ai:onboard command
// ---------------------------------------------------------------------------

it('ai:onboard command is registered', function () {
    expect(Artisan::all())->toHaveKey('ai:onboard');
});

it('requires a TTY unless --write is passed', function () {
    $this->artisan('ai:onboard')
        ->expectsOutputToContain('requires an interactive TTY')
        ->assertExitCode(1);
});

it('refuses --ask without a terminal', function () {
    $this->artisan('ai:onboard', ['--ask' => true])
        ->expectsOutputToContain('requires an interactive TTY')
        ->assertExitCode(1);
});

it('refuses --ask together with --write', function () {
    $this->artisan('ai:onboard', ['--ask' => true, '--write' => true])
        ->expectsOutputToContain('nothing to --write')
        ->assertExitCode(1);
});

it('writes the tour to docs/ONBOARDING.md with --write and no terminal', function () {
    $agent = fakeOnboardingAgent(tourEvents());

    $this->artisan('ai:onboard', ['--write' => true])
        ->expectsOutputToContain('Tour written to docs/ONBOARDING.md')
        ->assertExitCode(0);

    $path = onboardWorkspace().'/docs/ONBOARDING.md';

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))
        ->toStartWith("# Onboarding\n")
        ->toContain('Generated by `php artisan ai:onboard --write`')
        ->toContain('A billing platform.')
        ->and($agent->prompts)->toHaveCount(1)
        ->and($agent->prompts[0])->toContain('full onboarding tour');
});

it('writes to the path given to --write', function () {
    fakeOnboardingAgent(tourEvents());

    $this->artisan('ai:onboard', ['--write' => 'TOUR.md'])
        ->expectsOutputToContain('Tour written to TOUR.md')
        ->assertExitCode(0);

    expect(file_exists(onboardWorkspace().'/TOUR.md'))->toBeTrue();
});

it('scopes the tour and the document to --focus', function () {
    $agent = fakeOnboardingAgent(tourEvents('Billing lives in app/Billing.'));

    $this->artisan('ai:onboard', ['--write' => true, '--focus' => 'billing'])
        ->expectsOutputToContain('Focus: billing')
        ->assertExitCode(0);

    expect($agent->prompts[0])
        ->toContain('ONE area')
        ->toContain('billing')
        ->and(file_get_contents(onboardWorkspace().'/docs/ONBOARDING.md'))
        ->toStartWith("# Onboarding — billing\n");
});

it('refuses a --write path outside the project', function () {
    fakeOnboardingAgent(tourEvents());

    $this->artisan('ai:onboard', ['--write' => '../escaped.md'])
        ->expectsOutputToContain('must point inside the project')
        ->assertExitCode(1);

    expect(file_exists(dirname(onboardWorkspace()).'/escaped.md'))->toBeFalse();
});

it('refuses a --write path that is not markdown', function () {
    fakeOnboardingAgent(tourEvents());

    $this->artisan('ai:onboard', ['--write' => 'docs/onboarding.php'])
        ->expectsOutputToContain('expects a markdown file')
        ->assertExitCode(1);
});

it('writes nothing when the agent produces no tour', function () {
    fakeOnboardingAgent([new StreamEnd('e', 'stop', new Usage(10, 0), 0)]);

    $this->artisan('ai:onboard', ['--write' => true])
        ->expectsOutputToContain('produced no tour')
        ->assertExitCode(1);

    expect(file_exists(onboardWorkspace().'/docs/ONBOARDING.md'))->toBeFalse();
});

it('refuses to write a tour cut short by a mid-stream provider error', function () {
    // laravel/ai ends the turn normally after a provider error event, with
    // whatever text arrived before it — the truncation is silent unless the
    // command watches for the Error event itself.
    fakeOnboardingAgent([
        new TextDelta('e', 'm', "## What this app is\n\nA billing platform that ha", 0),
        new Error('e', 'overloaded_error', 'Overloaded', false, 0),
        new StreamEnd('e', 'tool_calls', new Usage(1000, 100), 0),
    ]);

    $this->artisan('ai:onboard', ['--write' => true])
        ->expectsOutputToContain('overloaded_error')
        ->expectsOutputToContain('Nothing written')
        ->assertExitCode(1);

    expect(file_exists(onboardWorkspace().'/docs/ONBOARDING.md'))->toBeFalse();
});

it('refuses to write a tour that hit the output length limit', function () {
    fakeOnboardingAgent([
        new TextDelta('e', 'm', "## What this app is\n\nA billing platform that ha", 0),
        new StreamEnd('e', 'length', new Usage(1000, 100), 0),
    ]);

    $this->artisan('ai:onboard', ['--write' => true])
        ->expectsOutputToContain("finish reason 'length'")
        ->expectsOutputToContain('Nothing written')
        ->assertExitCode(1);

    expect(file_exists(onboardWorkspace().'/docs/ONBOARDING.md'))->toBeFalse();
});

it('fails cleanly when the agent errors in --write mode', function () {
    app()->instance(OnboardingAgent::class, new FakeCodingAgent([], new RuntimeException('provider down')));

    $this->artisan('ai:onboard', ['--write' => true])
        ->expectsOutputToContain('provider down')
        ->assertExitCode(1);

    expect(file_exists(onboardWorkspace().'/docs/ONBOARDING.md'))->toBeFalse();
});
