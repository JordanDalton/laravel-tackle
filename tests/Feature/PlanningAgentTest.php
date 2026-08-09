<?php

use Illuminate\Support\Facades\Artisan;
use Tackle\Agents\PlanningAgent;
use Tackle\Tools\EditFile;
use Tackle\Tools\ReadFile;
use Tackle\Tools\RunShell;
use Tackle\Tools\SearchCode;
use Tackle\Tools\WriteFile;

it('PlanningAgent only exposes read-only tools', function () {
    $agent = app(PlanningAgent::class);
    $tools = collect($agent->tools())->map(fn ($t) => get_class($t));

    expect($tools)->toContain(ReadFile::class)
        ->toContain(SearchCode::class)
        ->not->toContain(EditFile::class)
        ->not->toContain(WriteFile::class)
        ->not->toContain(RunShell::class);
});

it('PlanningAgent instructions demand a plan, not edits', function () {
    $instructions = app(PlanningAgent::class)->instructions();

    expect($instructions)->toContain('PLAN')
        ->and($instructions)->toContain('read-only');
});

it('ai:code exposes the --plan option', function () {
    $definition = Artisan::all()['ai:code']->getDefinition();

    expect($definition->hasOption('plan'))->toBeTrue();
});
