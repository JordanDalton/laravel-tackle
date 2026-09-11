<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Agents\DefaultCodingAgent;
use Tackle\Tools\AbstractTool;

class ExamplePackageTool extends AbstractTool
{
    public function description(): string
    {
        return 'A tool contributed by a package.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        return 'package result';
    }
}

it('discovers tagged package tools through the normal tool pipeline', function () {
    app()->tag([ExamplePackageTool::class], 'tackle.tools');
    config()->set('tackle.tools', ['ExamplePackageTool']);

    $tools = [...app(DefaultCodingAgent::class)->tools()];

    expect($tools)->toHaveCount(1);
    expect((string) $tools[0]->handle(new Request))->toBe('package result');
});

it('excludes package tools absent from the explicit allowlist', function () {
    app()->tag([ExamplePackageTool::class], 'tackle.tools');
    config()->set('tackle.tools', ['ReadFile']);

    $tools = [...app(DefaultCodingAgent::class)->tools()];

    expect($tools)->toHaveCount(1);
    $name = is_callable([$tools[0], 'name']) ? $tools[0]->name() : class_basename($tools[0]);
    expect($name)->toBe('ReadFile');
});
