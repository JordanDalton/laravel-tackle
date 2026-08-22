<?php

use Tackle\Agents\DefaultCodingAgent;
use Tackle\Agents\ExplainAgent;
use Tackle\Agents\HealingAgent;
use Tackle\Agents\OnboardingAgent;
use Tackle\Agents\ReviewAgent;
use Tackle\Agents\TestWriterAgent;
use Tackle\Agents\UpgradeAgent;
use Tackle\Contracts\CodingAgent;

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
