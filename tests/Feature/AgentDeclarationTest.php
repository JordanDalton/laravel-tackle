<?php

use Tackle\Agents\CachingCodingAgent;
use Tackle\Agents\DefaultCodingAgent;
use Tackle\Agents\ExplainAgent;
use Tackle\Agents\HealingAgent;
use Tackle\Agents\LeanCodingAgent;
use Tackle\Agents\OnboardingAgent;
use Tackle\Agents\ReviewAgent;
use Tackle\Agents\TestWriterAgent;
use Tackle\Agents\UpgradeAgent;
use Tackle\Contracts\CodingAgent;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\AutoApproveInteraction;
use Tackle\Support\DenyInteraction;

/**
 * laravel/ai narrows and widens its Agent contract between releases, and an
 * incompatible method signature is a compile-time fatal — the class cannot be
 * declared at all, so ai:code, ai:run, and the healer all die on resolution.
 *
 * Nothing used to resolve DefaultCodingAgent, so the suite stayed green through
 * exactly that failure. Simply constructing each agent is the check.
 */
it('resolves every shipped agent against the installed laravel/ai contract', function (string $agent) {
    expect(app($agent))->toBeInstanceOf($agent);
})->with([
    DefaultCodingAgent::class,
    LeanCodingAgent::class,
    CachingCodingAgent::class,
    ReviewAgent::class,
    ExplainAgent::class,
    OnboardingAgent::class,
    TestWriterAgent::class,
    UpgradeAgent::class,
]);

it('constructs the healing agent, which takes a runtime workspace', function () {
    expect(new HealingAgent(sys_get_temp_dir()))->toBeInstanceOf(HealingAgent::class);
});

it('resolves the CodingAgent binding used by ai:code and ai:run', function () {
    expect(app(CodingAgent::class))->toBeInstanceOf(DefaultCodingAgent::class);
});

it('exposes the tools ai:run reports on', function () {
    // Resolve names the way laravel/ai does: name() when present (tools are
    // wrapped in EventedTool), class basename otherwise.
    $tools = collect(app(CodingAgent::class)->tools())
        ->map(fn ($tool) => is_callable([$tool, 'name']) ? $tool->name() : class_basename($tool))
        ->all();

    expect($tools)->toContain('ReadFile', 'EditFile', 'RunTests', 'AskUser', 'ConfirmAction');
});

// ---------------------------------------------------------------------------
// Headless runs
// ---------------------------------------------------------------------------

function toolNames(): array
{
    return collect(app(CodingAgent::class)->tools())
        ->map(fn ($tool) => is_callable([$tool, 'name']) ? $tool->name() : class_basename($tool))
        ->all();
}

function agentInstructions(): string
{
    $method = new ReflectionMethod(app(CodingAgent::class), 'instructions');

    return (string) $method->invoke(app(CodingAgent::class));
}

it('drops AskUser and ConfirmAction when nobody can answer', function () {
    // Their schemas cost ~340 tokens on every step of a headless run, for two
    // tools that auto-approve or auto-deny whatever the agent asks.
    app()->instance(InteractionPolicy::class, new AutoApproveInteraction);

    expect(toolNames())->not->toContain('AskUser')
        ->and(toolNames())->not->toContain('ConfirmAction')
        // Everything else stays.
        ->and(toolNames())->toContain('ReadFile', 'EditFile', 'RunTests');
});

it('drops them under --yes and without it alike', function () {
    app()->instance(InteractionPolicy::class, new DenyInteraction);

    expect(toolNames())->not->toContain('AskUser')
        ->and(toolNames())->not->toContain('ConfirmAction');
});

it('replaces the interaction rules with a finish-the-job rule when headless', function () {
    app()->instance(InteractionPolicy::class, new AutoApproveInteraction);

    $instructions = agentInstructions();

    expect($instructions)->not->toContain('User interaction — REQUIRED RULES')
        ->not->toContain('call AskUser with those options')
        // The one rule that matters more without a human, not less.
        ->toContain('open a pull request')
        ->toContain('issue_number')
        ->toContain('never end a turn with a question');
});

it('keeps the full interaction rules when a user is there', function () {
    $instructions = agentInstructions();

    expect($instructions)->toContain('User interaction — REQUIRED RULES')
        ->toContain('call AskUser with those options')
        ->toContain('Always call ConfirmAction before any destructive');
});

it('omits integration tools until their integration is configured', function () {
    config()->set('tackle.github.token', null);
    config()->set('tackle.sentry.auth_token', null);
    config()->set('tackle.tools', null);

    $names = collect(app(CodingAgent::class)->tools())
        ->map(fn ($t) => is_callable([$t, 'name']) ? $t->name() : class_basename($t))
        ->all();

    expect($names)
        ->toContain('ReadFile', 'EditFile') // always on
        ->not->toContain('CreatePullRequest', 'ReadGitHubIssue', 'CommitAndPush', 'ReadSentryIssue');
});

it('exposes GitHub tools once a token is configured', function () {
    config()->set('tackle.github.token', 'ghp_x');

    $names = collect(app(CodingAgent::class)->tools())
        ->map(fn ($t) => is_callable([$t, 'name']) ? $t->name() : class_basename($t))
        ->all();

    expect($names)->toContain('CreatePullRequest', 'ReadGitHubIssue', 'CommitAndPush');
});

it('restricts the toolset to tackle.tools when set', function () {
    config()->set('tackle.tools', ['ReadFile', 'EditFile', 'RunTests']);

    $names = collect(app(CodingAgent::class)->tools())
        ->map(fn ($t) => is_callable([$t, 'name']) ? $t->name() : class_basename($t))
        ->all();

    expect($names)->toEqualCanonicalizing(['ReadFile', 'EditFile', 'RunTests']);
});
