<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Base class for all Tackle tools. Adjusting the laravel/ai Tool interface
 * import path is the only change needed if upstream renames it.
 */
abstract class AbstractTool implements Tool
{
    abstract public function description(): Stringable|string;

    abstract public function schema(JsonSchema $schema): array;

    abstract public function handle(Request $request): Stringable|string;

    /**
     * A string argument, as a string.
     *
     * Request::string() returns a Stringable, and a Stringable compares as
     * not-identical to '' and casts to true even when empty. Three tools were
     * checking emptiness that way and each shipped an empty argument: RunTests
     * ran with --filter='' and found no tests, GitDiff asked git for the
     * revision "^", RunLarastan set memory_limit to nothing. Cast once, here,
     * so a tool never sees the wrapper.
     */
    protected function arg(Request $request, string $key, string $default = ''): string
    {
        return (string) $request->string($key, $default);
    }
}
