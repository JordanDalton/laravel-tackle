<?php

namespace Tackle\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;
use Tackle\Events\ToolCalled;
use Tackle\Events\ToolCalling;

/**
 * Wraps a tool so user-defined hooks (config tackle.hooks) and Laravel events
 * fire around its execution — pre_tool hooks and ToolCalling before (both may
 * block; pre_tool hooks may also rewrite arguments), ToolCalled and post_tool
 * hooks after. The wrapped tool keeps its identity: laravel/ai resolves tool
 * names via name() when present, so this decorator forwards the inner tool's
 * resolved name.
 *
 * On laravel/ai versions without ToolNameResolver, wrapping would rename every
 * tool to "EventedTool" and break dispatch — wrap() detects that and returns
 * the tools untouched (events simply don't fire there).
 */
class EventedTool implements Tool
{
    public function __construct(private readonly Tool $inner) {}

    /**
     * @param  iterable<mixed>  $tools
     * @return array<mixed>
     */
    public static function wrap(iterable $tools): array
    {
        $tools = is_array($tools) ? $tools : iterator_to_array($tools);

        if (! self::supported()) {
            return $tools;
        }

        return array_map(
            fn ($tool) => $tool instanceof Tool && ! $tool instanceof self ? new self($tool) : $tool,
            $tools,
        );
    }

    public static function supported(): bool
    {
        return class_exists(ToolNameResolver::class);
    }

    public function name(): string
    {
        return ToolNameResolver::resolve($this->inner);
    }

    public function inner(): Tool
    {
        return $this->inner;
    }

    public function description(): Stringable|string
    {
        return $this->inner->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->inner->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        $hooks = app(HookRunner::class);
        $arguments = $request->all();

        $pre = $hooks->preTool($this->name(), $arguments);

        if ($pre->blocked) {
            return (string) $pre->message;
        }

        if ($pre->arguments !== null && $pre->arguments !== $arguments) {
            $arguments = $pre->arguments;
            $request = new Request($arguments, $request->toolCallId());
        }

        $veto = Event::until(new ToolCalling($this->name(), $arguments));

        if ($veto === false) {
            return 'Refused: a ToolCalling event listener vetoed this call.';
        }

        if (is_string($veto)) {
            return $veto;
        }

        $start = microtime(true);

        $result = $this->inner->handle($request);
        $durationMs = round((microtime(true) - $start) * 1000, 2);

        ToolCalled::dispatch($this->name(), $arguments, (string) $result, $durationMs);

        $hooks->postTool($this->name(), $arguments, (string) $result, $durationMs);

        return $result;
    }
}
