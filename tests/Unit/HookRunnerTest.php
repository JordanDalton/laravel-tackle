<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Contracts\ToolHook;
use Tackle\Events\ToolCalled;
use Tackle\Support\EventedTool;
use Tackle\Support\HookRunner;
use Tackle\Tools\AbstractTool;

class HookTestRecorder implements ToolHook
{
    public static array $payloads = [];

    public static mixed $decision = null;

    public function handle(array $payload): null|false|string|array
    {
        self::$payloads[] = $payload;

        return self::$decision;
    }
}

class HookTestInvokable
{
    public static mixed $decision = null;

    public function __invoke(array $payload): mixed
    {
        return self::$decision;
    }
}

class HookTestTool extends AbstractTool
{
    public static array $requests = [];

    public function description(): string
    {
        return 'Records the arguments it was called with.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        self::$requests[] = $request->all();

        return 'ran: '.$request->string('command', '');
    }
}

beforeEach(function () {
    HookTestRecorder::$payloads = [];
    HookTestRecorder::$decision = null;
    HookTestInvokable::$decision = null;
    HookTestTool::$requests = [];
});

it('allows everything when no hooks are configured', function () {
    $result = app(HookRunner::class)->preTool('RunShell', ['command' => 'ls']);

    expect($result->blocked)->toBeFalse()
        ->and($result->arguments)->toBe(['command' => 'ls']);
});

it('passes the payload to class hooks and allows on null', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['using' => HookTestRecorder::class],
    ]);

    $result = app(HookRunner::class)->preTool('RunShell', ['command' => 'ls']);

    expect($result->blocked)->toBeFalse()
        ->and(HookTestRecorder::$payloads)->toHaveCount(1)
        ->and(HookTestRecorder::$payloads[0])->toBe([
            'event' => 'pre_tool',
            'tool' => 'RunShell',
            'arguments' => ['command' => 'ls'],
        ]);
});

it('blocks with a generic message when a class hook returns false', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['using' => HookTestRecorder::class],
    ]);
    HookTestRecorder::$decision = false;

    $result = app(HookRunner::class)->preTool('RunShell', ['command' => 'ls']);

    expect($result->blocked)->toBeTrue()
        ->and($result->message)->toContain('pre_tool hook')
        ->and($result->message)->toContain('HookTestRecorder');
});

it('blocks with the returned string as the message', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['using' => HookTestRecorder::class],
    ]);
    HookTestRecorder::$decision = 'Use RunTests instead of raw phpunit.';

    $result = app(HookRunner::class)->preTool('RunShell', ['command' => 'phpunit']);

    expect($result->blocked)->toBeTrue()
        ->and($result->message)->toBe('Use RunTests instead of raw phpunit.');
});

it('rewrites arguments when a pre_tool class hook returns an array', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['using' => HookTestRecorder::class],
    ]);
    HookTestRecorder::$decision = ['command' => 'ls -la'];

    $result = app(HookRunner::class)->preTool('RunShell', ['command' => 'ls']);

    expect($result->blocked)->toBeFalse()
        ->and($result->arguments)->toBe(['command' => 'ls -la']);
});

it('supports plain invokable classes', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['using' => HookTestInvokable::class],
    ]);
    HookTestInvokable::$decision = 'Blocked by invokable.';

    $result = app(HookRunner::class)->preTool('RunShell', ['command' => 'ls']);

    expect($result->blocked)->toBeTrue()
        ->and($result->message)->toBe('Blocked by invokable.');
});

it('only runs hooks whose match pattern fits the tool name', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['match' => 'Edit*', 'using' => HookTestRecorder::class],
        ['match' => ['WriteFile', 'RunShell'], 'using' => HookTestRecorder::class],
        ['match' => 'ReadFile', 'using' => HookTestRecorder::class],
    ]);

    app(HookRunner::class)->preTool('RunShell', ['command' => 'ls']);

    expect(HookTestRecorder::$payloads)->toHaveCount(1);
});

it('stops at the first blocking hook', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['using' => HookTestInvokable::class],
        ['using' => HookTestRecorder::class],
    ]);
    HookTestInvokable::$decision = 'First hook blocks.';

    $result = app(HookRunner::class)->preTool('RunShell', ['command' => 'ls']);

    expect($result->message)->toBe('First hook blocks.')
        ->and(HookTestRecorder::$payloads)->toBeEmpty();
});

it('blocks when a shell hook exits 2, using stderr as the message', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['run' => 'echo "shell says no" 1>&2; exit 2'],
    ]);

    $result = app(HookRunner::class)->preTool('RunShell', ['command' => 'ls']);

    expect($result->blocked)->toBeTrue()
        ->and($result->message)->toBe('shell says no');
});

it('sends the JSON payload to shell hooks on stdin', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['run' => 'payload=$(cat); echo "$payload" 1>&2; exit 2'],
    ]);

    $result = app(HookRunner::class)->preTool('RunShell', ['command' => 'rm -rf /']);

    expect($result->blocked)->toBeTrue()
        ->and($result->message)->toContain('"event":"pre_tool"')
        ->and($result->message)->toContain('"tool":"RunShell"')
        ->and($result->message)->toContain('rm -rf /');
});

it('rewrites arguments from a shell hook stdout JSON', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['run' => 'echo \'{"arguments":{"command":"ls --safe"}}\''],
    ]);

    $result = app(HookRunner::class)->preTool('RunShell', ['command' => 'ls']);

    expect($result->blocked)->toBeFalse()
        ->and($result->arguments)->toBe(['command' => 'ls --safe']);
});

it('ignores a shell hook that fails with a non-blocking exit code', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['run' => 'exit 1'],
    ]);

    $result = app(HookRunner::class)->preTool('RunShell', ['command' => 'ls']);

    expect($result->blocked)->toBeFalse()
        ->and($result->arguments)->toBe(['command' => 'ls']);
});

it('ignores non-JSON stdout from an allowing shell hook', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['run' => 'echo "all good"'],
    ]);

    $result = app(HookRunner::class)->preTool('RunShell', ['command' => 'ls']);

    expect($result->blocked)->toBeFalse()
        ->and($result->arguments)->toBe(['command' => 'ls']);
});

it('runs post_tool hooks with result and duration', function () {
    config()->set('tackle.hooks.post_tool', [
        ['using' => HookTestRecorder::class],
    ]);

    app(HookRunner::class)->postTool('RunShell', ['command' => 'ls'], 'file.txt', 12.5);

    expect(HookTestRecorder::$payloads)->toHaveCount(1)
        ->and(HookTestRecorder::$payloads[0]['event'])->toBe('post_tool')
        ->and(HookTestRecorder::$payloads[0]['result'])->toBe('file.txt')
        ->and(HookTestRecorder::$payloads[0]['duration_ms'])->toBe(12.5);
});

it('runs session hooks with the event payload', function () {
    config()->set('tackle.hooks.session_start', [
        ['using' => HookTestRecorder::class],
    ]);

    app(HookRunner::class)->sessionEvent('session_start', ['command' => 'ai:code']);

    expect(HookTestRecorder::$payloads[0])->toBe([
        'event' => 'session_start',
        'command' => 'ai:code',
    ]);
});

it('blocks the wrapped tool call before the inner tool runs', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['match' => 'HookTestTool', 'using' => HookTestRecorder::class],
    ]);
    HookTestRecorder::$decision = 'Not on my watch.';

    $called = [];
    Event::listen(ToolCalled::class, function (ToolCalled $event) use (&$called) {
        $called[] = $event;
    });

    $wrapped = new EventedTool(new HookTestTool);
    $result = $wrapped->handle(new Request(['command' => 'ls']));

    expect((string) $result)->toBe('Not on my watch.')
        ->and(HookTestTool::$requests)->toBeEmpty()
        ->and($called)->toBeEmpty();
});

it('passes rewritten arguments through to the inner tool', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['using' => HookTestRecorder::class],
    ]);
    HookTestRecorder::$decision = ['command' => 'ls -la'];

    $called = [];
    Event::listen(ToolCalled::class, function (ToolCalled $event) use (&$called) {
        $called[] = $event;
    });

    $wrapped = new EventedTool(new HookTestTool);
    $result = $wrapped->handle(new Request(['command' => 'ls']));

    expect((string) $result)->toBe('ran: ls -la')
        ->and(HookTestTool::$requests)->toBe([['command' => 'ls -la']])
        ->and($called[0]->arguments)->toBe(['command' => 'ls -la']);
});

it('runs post_tool hooks after the wrapped tool executes', function () {
    config()->set('tackle.hooks.post_tool', [
        ['using' => HookTestRecorder::class],
    ]);

    $wrapped = new EventedTool(new HookTestTool);
    $wrapped->handle(new Request(['command' => 'ls']));

    expect(HookTestRecorder::$payloads)->toHaveCount(1)
        ->and(HookTestRecorder::$payloads[0]['tool'])->toBe('HookTestTool')
        ->and(HookTestRecorder::$payloads[0]['result'])->toBe('ran: ls');
});

it('never lets a crashing hook break the tool call', function () {
    config()->set('tackle.hooks.pre_tool', [
        ['using' => 'App\\Hooks\\DoesNotExist'],
    ]);

    $wrapped = new EventedTool(new HookTestTool);
    $result = $wrapped->handle(new Request(['command' => 'ls']));

    expect((string) $result)->toBe('ran: ls');
});
