<?php

namespace Tackle\Contracts;

/**
 * A class-based hook declared in config('tackle.hooks'). Receives the event
 * payload (event name, tool, arguments, and — for post_tool — result and
 * duration) and decides what happens next.
 *
 * Implementing this interface is optional: any invokable class with the same
 * signature works. The interface exists for discoverability and type safety.
 */
interface ToolHook
{
    /**
     * @param  array<string, mixed>  $payload
     * @return null|false|string|array<string, mixed> null allows the call;
     *                                                false blocks it with a generic refusal; a string blocks it with
     *                                                that message; an array (pre_tool only) replaces the tool
     *                                                arguments and allows the call.
     */
    public function handle(array $payload): null|false|string|array;
}
