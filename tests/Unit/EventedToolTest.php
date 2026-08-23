<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Tools\Request;
use Tackle\Agents\DefaultCodingAgent;
use Tackle\Events\ToolCalled;
use Tackle\Events\ToolCalling;
use Tackle\Support\EventedTool;
use Tackle\Tools\AbstractTool;

class EventedTestEchoTool extends AbstractTool
{
    public function description(): string
    {
        return 'Echoes its input.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        return 'echo: '.$request->string('value', '');
    }
}

it('forwards the inner tool name so laravel/ai dispatch keeps working', function () {
    $wrapped = new EventedTool(new EventedTestEchoTool);

    expect($wrapped->name())->toBe('EventedTestEchoTool')
        ->and((string) $wrapped->description())->toBe('Echoes its input.');
});

it('wraps only Tool instances and passes everything else through', function () {
    $tool = new EventedTestEchoTool;
    $notATool = new stdClass;

    $wrapped = EventedTool::wrap([$tool, $notATool]);

    expect($wrapped[0])->toBeInstanceOf(EventedTool::class)
        ->and($wrapped[1])->toBe($notATool);
})->skip(fn () => ! EventedTool::supported(), 'wrapping is a no-op without laravel/ai ToolNameResolver');

it('does not double-wrap', function () {
    $once = EventedTool::wrap([new EventedTestEchoTool]);
    $twice = EventedTool::wrap($once);

    expect($twice[0])->toBe($once[0]);
});

it('dispatches ToolCalling before and ToolCalled after execution', function () {
    $calling = [];
    $called = [];

    Event::listen(ToolCalling::class, function (ToolCalling $event) use (&$calling) {
        $calling[] = $event;
    });
    Event::listen(ToolCalled::class, function (ToolCalled $event) use (&$called) {
        $called[] = $event;
    });

    $result = (new EventedTool(new EventedTestEchoTool))->handle(new Request(['value' => 'hi']));

    expect($result)->toBe('echo: hi')
        ->and($calling)->toHaveCount(1)
        ->and($calling[0]->tool)->toBe('EventedTestEchoTool')
        ->and($calling[0]->arguments)->toBe(['value' => 'hi'])
        ->and($called)->toHaveCount(1)
        ->and($called[0]->result)->toBe('echo: hi')
        ->and($called[0]->durationMs)->toBeGreaterThanOrEqual(0);
});

it('lets a listener veto a call by returning false', function () {
    Event::listen(ToolCalling::class, fn () => false);

    $result = (new EventedTool(new EventedTestEchoTool))->handle(new Request(['value' => 'hi']));

    expect($result)->toContain('vetoed');
});

it('uses a string returned by a listener as the refusal message', function () {
    Event::listen(ToolCalling::class, fn () => 'Not during business hours.');

    $result = (new EventedTool(new EventedTestEchoTool))->handle(new Request(['value' => 'hi']));

    expect($result)->toBe('Not during business hours.');
});

it('does not run the tool when vetoed', function () {
    Event::listen(ToolCalling::class, fn () => false);

    $called = [];
    Event::listen(ToolCalled::class, function () use (&$called) {
        $called[] = true;
    });

    (new EventedTool(new EventedTestEchoTool))->handle(new Request([]));

    expect($called)->toBe([]);
});

it('wraps the default coding agent tool set with names intact', function () {
    $agent = app(DefaultCodingAgent::class);
    $tools = collect($agent->tools());

    expect($tools->first())->toBeInstanceOf(EventedTool::class)
        ->and($tools->map(fn ($t) => $t->name())->all())->toContain('ReadFile', 'EditFile', 'RunShell');
})->skip(fn () => ! EventedTool::supported(), 'wrapping is a no-op without laravel/ai ToolNameResolver');
